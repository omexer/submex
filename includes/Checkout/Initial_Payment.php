<?php

namespace Submex\Checkout;

use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prices the first payment, which is not the same as the recurring one.
 *
 * A free trial means the product costs nothing today, and a sign-up fee is charged once on
 * top. Both were disclosed to the customer and neither reached the cart, so a "14 days
 * free" product billed its full price immediately and the sign-up fee was never taken.
 */
final class Initial_Payment {

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply' ), 20 );
	}

	/**
	 * @param \WC_Cart|mixed $cart
	 */
	public function apply( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			// A line bought once is not on a trial and owes no sign-up fee, whatever the product's own schedule says.
			if ( ! Subscription_Product::cart_item_is_subscription( $item, (string) $key ) ) {
				continue;
			}

			// Resolved once per line per request, so a second run cannot re-add the fee.
			$terms = Line_Terms::for_cart_item( $item, (string) $key );

			if ( ! $terms ) {
				continue;
			}

			// Everything that asks what this product costs per period must keep getting the
			// recurring price, not the one-off amount being charged today.
			Subscription_Product::remember_recurring_price( $item['data'], $terms->price() );

			$item['data']->set_price( (string) self::first_payment( $item['data'], $terms->price(), $terms ) );
		}
	}

	/**
	 * What the customer pays today for one of these.
	 */
	public static function first_payment( \WC_Product $product, float $recurring, ?Line_Terms $terms = null ): float {
		$terms = $terms ?? Line_Terms::for_product( $product );
		$fee   = (float) $terms->signup_fee()->decimal();

		return ( $terms->schedule()->has_trial() ? 0.0 : $recurring ) + $fee;
	}
}
