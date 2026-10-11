<?php

namespace Submex\Frontend\MyAccount;

use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Gateway_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a status into something a customer can read.
 *
 * Never shows a raw slug, and every state carries a text label rather than relying on
 * colour alone. UX Spec 7.2 and 14.
 */
class Status_Presenter {

	/**
	 * @return array{label: string, tone: string, detail: string}
	 */
	public static function for( Subscription $subscription ): array {
		$status = $subscription->get_status_enum();
		$state  = self::for_status( $subscription );

		if ( Subscription_Status::OnHold === $status && self::pays_by_link( $subscription ) ) {
			$order = self::payable_order( $subscription );

			if ( $order ) {
				return array(
					'label'  => __( 'Renewal due', 'submex' ),
					'tone'   => 'warning',
					/* translators: %s: amount of the renewal */
					'detail' => sprintf( __( 'Your renewal of %s is ready to pay.', 'submex' ), self::amount( $order ) ),
				);
			}
		}

		// Its next-payment date stays where it was until the provider answers, so showing it would read as overdue.
		$pending = $status && ( $status->is_billable() || Subscription_Status::PendingCancel === $status ) ? self::pending_charge( $subscription ) : null;

		if ( $pending ) {
			return array(
				/* translators: %s: subscription status, such as Active */
				'label'  => sprintf( __( '%s · Payment processing', 'submex' ), $state['label'] ),
				'tone'   => 'neutral',
				'detail' => sprintf(
					/* translators: 1: amount of the renewal, 2: date the payment was submitted */
					__( 'Your renewal payment of %1$s was submitted on %2$s and is waiting for your bank or payment provider to confirm it. There is nothing you need to do.', 'submex' ),
					self::amount( $pending['order'] ),
					wp_date( (string) get_option( 'date_format' ), $pending['since'] )
				),
			);
		}

		return $state;
	}

	/**
	 * @return array{label: string, tone: string, detail: string}
	 */
	private static function for_status( Subscription $subscription ): array {
		$status = $subscription->get_status_enum();
		$next   = $subscription->get_next_payment();
		$when   = $next ? date_i18n( (string) get_option( 'date_format' ), strtotime( $next . ' UTC' ) ) : '';

		return match ( $status ) {
			Subscription_Status::Trialling => array(
				'label'  => __( 'Free trial', 'submex' ),
				'tone'   => 'neutral',
				/* translators: %s: date of first payment */
				'detail' => $when ? sprintf( __( 'First payment on %s.', 'submex' ), $when ) : '',
			),
			Subscription_Status::Active => array(
				'label'  => __( 'Active', 'submex' ),
				'tone'   => 'positive',
				/* translators: %s: date of next payment */
				'detail' => $when ? sprintf( __( 'Next payment on %s.', 'submex' ), $when ) : '',
			),
			Subscription_Status::OnHold => array(
				'label'  => __( 'Payment needed', 'submex' ),
				'tone'   => 'warning',
				'detail' => self::payment_needed( $subscription ),
			),
			Subscription_Status::PendingCancel => array(
				'label'  => __( 'Cancelling', 'submex' ),
				'tone'   => 'neutral',
				/* translators: %s: date access ends */
				'detail' => $when ? sprintf( __( 'Active until %s. You will not be charged again.', 'submex' ), $when ) : '',
			),
			Subscription_Status::Cancelled => array(
				'label'  => __( 'Cancelled', 'submex' ),
				'tone'   => 'muted',
				'detail' => '',
			),
			Subscription_Status::Expired => array(
				'label'  => __( 'Ended', 'submex' ),
				'tone'   => 'muted',
				'detail' => '',
			),
			Subscription_Status::Switched => array(
				'label'  => __( 'Changed plan', 'submex' ),
				'tone'   => 'muted',
				'detail' => '',
			),
			default => array(
				'label'  => __( 'Pending', 'submex' ),
				'tone'   => 'neutral',
				'detail' => __( 'Waiting for the first payment to clear.', 'submex' ),
			),
		};
	}

	private static function payment_needed( Subscription $subscription ): string {
		$ends = $subscription->in_grace() ? $subscription->grace_ends_at() : null;

		if ( null === $ends ) {
			return __( "We couldn't take your last payment.", 'submex' );
		}

		/* translators: %s: date the grace period ends */
		return sprintf( __( "We couldn't take your last payment. You keep access until %s.", 'submex' ), wp_date( (string) get_option( 'date_format' ), $ends ) );
	}

	/**
	 * The one thing the customer should do about this subscription right now.
	 *
	 * A "Payment needed" card that offers no way to pay is the whole failure mode this
	 * exists to prevent. UX Spec 9.2.
	 *
	 * @return array{label: string, url: string}|null
	 */
	public static function primary_action( Subscription $subscription ): ?array {
		if ( Subscription_Status::OnHold !== $subscription->get_status_enum() ) {
			return null;
		}

		$order = self::payable_order( $subscription );
		if ( ! $order ) {
			return null;
		}

		if ( self::pays_by_link( $subscription ) ) {
			return array(
				'label' => __( 'Pay renewal', 'submex' ),
				'url'   => $order->get_checkout_payment_url(),
			);
		}

		// A 3DS challenge is not a failure, so it gets its own wording and its own link.
		$authenticate = (string) $order->get_meta( '_submex_action_url' );

		return $authenticate
			? array(
				'label' => __( 'Confirm payment', 'submex' ),
				'url'   => $authenticate,
			)
			: array(
				'label' => __( 'Pay now', 'submex' ),
				'url'   => $order->get_checkout_payment_url(),
			);
	}

	/**
	 * The most recent renewal order still awaiting payment.
	 */
	public static function payable_order( Subscription $subscription ): ?\WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => array( 'pending', 'failed', 'on-hold' ),
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- renewal orders are linked to their subscription by meta.
				'meta_key'   => '_submex_subscription_id',
				'meta_value' => $subscription->get_id(),
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$order = $orders[0] ?? null;

		return $order instanceof \WC_Order && ! $order->is_paid() ? $order : null;
	}

	/**
	 * The renewal submitted to the payment provider and still awaiting its answer.
	 *
	 * @return array{order: \WC_Order, since: int}|null
	 */
	public static function pending_charge( Subscription $subscription ): ?array {
		$slots = \Submex\Plugin::instance()->get( 'charge_slots' );
		$slot  = $slots instanceof Charge_Slot_Repository ? $slots->latest_unsettled( $subscription->get_id() ) : null;
		$order = $slot && Charge_Slot_Repository::STATE_PENDING === $slot->state && $slot->renewal_order_id ? wc_get_order( (int) $slot->renewal_order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		return array(
			'order' => $order,
			'since' => (int) strtotime( Charge_Slot_Repository::pending_since( $slot ) . ' UTC' ),
		);
	}

	/**
	 * Nothing charges these renewals: the customer pays each one from a link.
	 */
	public static function pays_by_link( Subscription $subscription ): bool {
		$registry = \Submex\Plugin::instance()->get( 'gateways' );

		return $registry instanceof Gateway_Registry && Gateway_Model::Manual === $registry->for_subscription( $subscription )->model();
	}

	private static function amount( \WC_Order $order ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Name the subscription by what it contains, not by its ID.
	 */
	public static function title( Subscription $subscription ): string {
		$names = array();

		foreach ( $subscription->get_items() as $item ) {
			$names[] = $item->get_name();
		}

		if ( empty( $names ) ) {
			/* translators: %s: subscription number */
			return sprintf( __( 'Subscription #%s', 'submex' ), $subscription->get_id() );
		}

		return implode( ', ', $names );
	}
}
