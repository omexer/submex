<?php

namespace Submex\Frontend;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription_Status;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdraws downloadable files when the subscription that paid for them is not live.
 *
 * The question is whether the customer holds a live subscription for that product, not
 * whether any subscription for it has lapsed - someone who resubscribed after cancelling
 * has both, and must keep their files.
 */
class Downloadable_Access {

	public function register(): void {
		// The list My Account shows is built by this, and so is every other caller's.
		add_filter( 'woocommerce_customer_available_downloads', array( $this, 'filter_customer_downloads' ), 10, 2 );
		add_filter( 'woocommerce_order_get_downloadable_items', array( $this, 'filter_order_downloads' ), 10, 1 );
	}

	/**
	 * @param array|mixed $downloads
	 * @param int|mixed   $customer_id
	 */
	public function filter_customer_downloads( $downloads, $customer_id = 0 ) {
		return $this->filter( is_array( $downloads ) ? $downloads : array(), (int) $customer_id ?: get_current_user_id() );
	}

	public function filter_order_downloads( $items ) {
		return $this->filter( is_array( $items ) ? $items : array(), get_current_user_id() );
	}

	private function filter( array $downloads, int $user_id ): array {
		if ( ! $user_id ) {
			return $downloads;
		}

		foreach ( $downloads as $key => $download ) {
			$product_id = (int) ( $download['product_id'] ?? 0 );

			if ( ! $product_id || ! $this->paid_by_subscription( $product_id, (int) ( $download['order_id'] ?? 0 ) ) ) {
				continue;
			}

			if ( ! $this->has_live_subscription( $user_id, $product_id ) ) {
				unset( $downloads[ $key ] );
			}
		}

		return $downloads;
	}

	/**
	 * A subscription product's files always are; a normal product's only when the line that granted them started a subscription.
	 */
	private function paid_by_subscription( int $product_id, int $order_id ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( Subscription_Product::is_subscription( $product ) ) {
			return true;
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! (int) $order->get_meta( '_submex_subscription_id' ) ) {
			return false;
		}

		// A renewal order's lines are copies with nothing to ask; the subscription they renew is the answer.
		$source = 'submex_renewal' === $order->get_created_via() ? wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) ) : $order;

		if ( ! $source instanceof \WC_Order ) {
			return false;
		}

		foreach ( $source->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! in_array( $product_id, array( (int) $item->get_product_id(), (int) $item->get_variation_id() ), true ) ) {
				continue;
			}

			if ( $source !== $order || Subscription_Product::order_item_is_subscription( $item, $order ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_live_subscription( int $user_id, int $product_id ): bool {
		$found = false;

		$subscriptions = Subscription_Query::get(
			array(
				'customer_id' => $user_id,
				'status'      => array( Subscription_Status::Active->value, Subscription_Status::Trialling->value, Subscription_Status::PendingCancel->value, Subscription_Status::OnHold->value ),
				'limit'       => 50,
			)
		);

		foreach ( $subscriptions as $subscription ) {
			if ( Subscription_Status::OnHold === $subscription->get_status_enum() && ! $subscription->in_grace() ) {
				continue;
			}

			foreach ( $subscription->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id ) {
					$found = true;
					break 2;
				}
			}
		}

		/**
		 * Whether this customer may still have the product's files.
		 *
		 * A lapsed subscription is not always the end of access - a plan bought outright
		 * over a fixed number of payments can grant access beyond its own life - so an
		 * extension gets to widen this answer. It can only ever grant, never revoke.
		 *
		 * @param bool $found
		 * @param int  $user_id
		 * @param int  $product_id
		 */
		return (bool) apply_filters( 'submex_has_product_access', $found, $user_id, $product_id );
	}
}
