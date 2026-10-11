<?php

namespace Submex\Checkout;

use Submex\Billing\Renewal_Scheduler;
use Submex\Data\Activity_Repository;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a completed checkout into a subscription.
 */
class Subscription_Factory {

	public function __construct(
		private readonly Charge_Slot_Repository $slots,
		private readonly Activity_Repository $activity,
		private readonly Renewal_Scheduler $scheduler
	) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'from_checkout' ), 10, 1 );
		// Block checkout goes through the Store API, which fires its own hook and passes
		// the order object rather than an ID. Without this, blocks create no subscription.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'from_store_api' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'activate_for_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'activate_for_order' ), 20, 1 );
	}

	/**
	 * @param int $order_id
	 */
	public function from_checkout( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || $this->existing_for_order( $order ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Subscription_Product::order_item_is_subscription( $item, $order ) ) {
				continue;
			}

			$product = $item->get_product();
			$terms   = $product ? Line_Terms::for_order_item( $item, $product ) : null;

			if ( ! $product || ! $terms ) {
				continue;
			}

			$this->create( $order, $item, $product, $terms );

			// One subscription per order in R1; the cart rule already enforces this.
			break;
		}
	}

	/**
	 * @param \WC_Order $order
	 */
	public function from_store_api( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->from_checkout( $order->get_id() );
		}
	}

	private function create( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product, Line_Terms $terms ): Subscription {
		$schedule = $terms->schedule();
		$now      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$subscription = new Subscription();
		$subscription->set_parent_order_id( $order->get_id() );
		$subscription->set_customer_id( $order->get_customer_id() );
		$subscription->set_currency( $order->get_currency() );
		$subscription->set_payment_method( $order->get_payment_method() );
		$subscription->set_payment_method_title( $order->get_payment_method_title() );
		$subscription->set_address( $order->get_address( 'billing' ), 'billing' );
		$subscription->set_address( $order->get_address( 'shipping' ), 'shipping' );

		$subscription->set_billing_period( $schedule->period() );
		$subscription->set_billing_interval( $schedule->interval() );

		$trial_end = $schedule->trial_end_from( $now );
		if ( $trial_end ) {
			$subscription->set_trial_end( $trial_end->format( 'Y-m-d H:i:s' ) );
		}

		// A trial is charged the day it ends, which is what the customer was told; without
		// a trial the first renewal is one full period from today, the initial payment
		// having been the parent order.
		$first_renewal = $trial_end ?? $schedule->next_date_from( $now );
		$subscription->set_next_payment( $first_renewal->format( 'Y-m-d H:i:s' ) );

		// Pin the origin site so a cloned staging copy refuses to bill real customers.
		$subscription->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );

		// The recurring amount, not what the first order came to: a trial makes that zero
		// and a one-off coupon would otherwise discount every renewal for ever.
		$quantity  = max( 1, (int) $item->get_quantity() );
		$recurring = $this->line_total_excluding_tax( $order, $product, $quantity, $terms );

		$copy = new \WC_Order_Item_Product();
		$copy->set_props(
			array(
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $quantity,
				'subtotal'     => $recurring,
				'total'        => $recurring,
			)
		);
		$subscription->add_item( $copy );

		$subscription->transition_to(
			$schedule->has_trial() ? Subscription_Status::Trialling : Subscription_Status::Pending
		);
		// WooCommerce's own flag, which calculate_taxes() reads; checkout set it for an exempt customer.
		$subscription->update_meta_data( 'is_vat_exempt', 'yes' === $order->get_meta( 'is_vat_exempt' ) ? 'yes' : 'no' );

		// Renewals recalculate tax the same way, so the subscription shows what they will charge.
		$subscription->calculate_totals( true );
		$subscription->save();

		// Slot 0 is the initial checkout payment, so the first renewal is index 1.
		$this->slots->claim(
			$subscription->get_id(),
			0,
			$now,
			$now,
			$trial_end ?? $schedule->next_date_from( $now )
		);
		$slot = $this->slots->find( $subscription->get_id(), 0 );
		if ( $slot ) {
			$this->slots->mark_paid( (int) $slot->id, $order->get_id() );
		}

		$order->update_meta_data( '_submex_subscription_id', $subscription->get_id() );
		$order->save();

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_STATUS_CHANGE,
			sprintf( 'Subscription created from order #%s.', $order->get_order_number() )
		);

		/**
		 * Fires once the subscription exists, so extensions can stamp their own
		 * configuration onto it from the product and the order line it was bought from.
		 *
		 * @param Subscription                $subscription
		 * @param \WC_Product                 $product
		 * @param \WC_Order_Item_Product|null $item     The order line. Null when fired from outside a checkout.
		 */
		do_action( 'submex_configure_subscription', $subscription, $product, $item );

		/**
		 * Fires when a subscription has been created from a checkout.
		 *
		 * @param Subscription $subscription
		 * @param \WC_Order    $order
		 */
		do_action( 'submex_subscription_created', $subscription, $order );

		return $subscription;
	}

	/**
	 * Line totals are stored without tax, as WooCommerce's cart stored the checkout's; renewals add tax back for the customer's address.
	 */
	private function line_total_excluding_tax( \WC_Order $order, \WC_Product $product, int $quantity, Line_Terms $terms ): string {
		$unit = $terms->recurring_price()->decimal();

		return wc_format_decimal(
			wc_get_price_excluding_tax(
				$product,
				array(
					'qty'   => $quantity,
					'price' => $unit,
					'order' => $order,
				)
			)
		);
	}

	/**
	 * Activate once the parent order is actually paid.
	 *
	 * @param int $order_id
	 */
	public function activate_for_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$subscription = $this->existing_for_order( $order );
		if ( ! $subscription ) {
			return;
		}

		// Renewal orders carry the same meta. Only the parent order starts a subscription;
		// without this, every successful renewal re-fires the "subscription started" flow.
		if ( $order->get_id() !== $subscription->get_parent_order_id() ) {
			return;
		}

		$status = $subscription->get_status_enum();
		$target = $subscription->get_trial_end() ? Subscription_Status::Trialling : Subscription_Status::Active;

		if ( $status && $status->can_transition_to( $target ) ) {
			$subscription->transition_to( $target );
			$subscription->save();
		}

		$this->scheduler->schedule_next( $subscription );

		do_action( 'submex_subscription_activated', $subscription );
	}

	private function existing_for_order( \WC_Order $order ): ?Subscription {
		$id = (int) $order->get_meta( '_submex_subscription_id' );

		if ( ! $id ) {
			return null;
		}

		$subscription = wc_get_order( $id );

		return $subscription instanceof Subscription ? $subscription : null;
	}
}
