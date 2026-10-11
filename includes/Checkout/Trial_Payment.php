<?php

namespace Submex\Checkout;

use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the payment step on a checkout that costs nothing today.
 *
 * A trial with no sign-up fee totals zero, and so does a coupon worth more than the first
 * payment. WooCommerce skips payment entirely for a zero-total order: no gateway runs, so
 * no card is stored, and the first renewal has nothing to charge. Forcing the payment step
 * whenever a line will renew for money collects the mandate up front; a free-forever plan
 * has nothing coming to charge, so it is left alone.
 */
final class Trial_Payment {

	public const OPTION = 'submex_collect_payment_on_trial';

	public function register(): void {
		add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ), 10, 2 );
		// The block checkout never asks the cart — the Store API asks the draft order instead.
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'order_needs_payment' ), 10, 2 );
	}

	public static function is_enabled(): bool {
		return 'no' !== get_option( self::OPTION, 'yes' );
	}

	/**
	 * @param bool|mixed     $needs_payment
	 * @param \WC_Cart|mixed $cart
	 * @return bool|mixed
	 */
	public function cart_needs_payment( $needs_payment, $cart ) {
		if ( $needs_payment || ! self::is_enabled() || ! $cart instanceof \WC_Cart ) {
			return $needs_payment;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			$terms = Subscription_Product::cart_item_is_subscription( $item, (string) $key ) ? Line_Terms::for_cart_item( $item, (string) $key ) : null;

			if ( $terms && self::renews_for_money( $item['data'], $terms, $cart->get_applied_coupons() ) ) {
				return true;
			}
		}

		return $needs_payment;
	}

	/**
	 * @param bool|mixed      $needs_payment
	 * @param \WC_Order|mixed $order
	 * @return bool|mixed
	 */
	public function order_needs_payment( $needs_payment, $order ) {
		if ( $needs_payment || ! self::is_enabled() || ! $order instanceof \WC_Order ) {
			return $needs_payment;
		}

		// Only a zero total is ours to override. A false answer for any other reason — an
		// order already paid, or in a status payment cannot apply to — must stay false.
		if ( $order->get_total() > 0 || ! $order->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) ) {
			return $needs_payment;
		}

		if ( 'submex_renewal' === $order->get_created_via() ) {
			return $needs_payment;
		}

		foreach ( $order->get_items() as $item ) {
			$terms = $item instanceof \WC_Order_Item_Product && Subscription_Product::order_item_is_subscription( $item, $order ) ? Line_Terms::for_order_item( $item ) : null;

			if ( $terms && self::renews_for_money( $item->get_product(), $terms, $order->get_coupon_codes() ) ) {
				return true;
			}
		}

		return $needs_payment;
	}

	/**
	 * @param string[] $coupon_codes The checkout's coupons, some of which may carry forward.
	 */
	private static function renews_for_money( \WC_Product $product, Line_Terms $terms, array $coupon_codes ): bool {
		/**
		 * What each renewal of this product will charge, once anything carried forward from checkout applies.
		 *
		 * @param float       $amount       The recurring price the checkout priced it at.
		 * @param \WC_Product $product
		 * @param string[]    $coupon_codes
		 */
		$amount = (float) apply_filters( 'submex_checkout_renewal_amount', (float) $terms->recurring_price()->decimal(), $product, $coupon_codes );

		return $amount > 0;
	}
}
