<?php

namespace Submex\Checkout;

use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart rules for recurring items.
 *
 * R1 allows one subscription per cart. Many gateways cannot hold more than one mandate
 * per order at all, and the ones that can create a support burden out of proportion to
 * the demand. Pro relaxes this per gateway once supports('multiple_subs') exists.
 */
class Cart_Validation {

	public const OPTION_MIXED = 'submex_allow_mixed_checkout';

	public function register(): void {
		// The Store API runs this filter too, turning the notice into its error.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_classic_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api_cart' ), 10, 1 );
	}

	public static function allows_mixed(): bool {
		return 'no' !== get_option( self::OPTION_MIXED, 'yes' );
	}

	/**
	 * @param bool  $passed
	 * @param int   $product_id
	 * @param int   $quantity
	 * @param int   $variation_id
	 * @param array $variations
	 * @param array $item_data
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $item_data = array() ) {
		if ( ! $passed ) {
			return $passed;
		}

		$refusal = $this->refusal( self::adding_subscription( (int) $product_id, (int) $variation_id, (array) $item_data ) );

		if ( null === $refusal ) {
			return $passed;
		}

		wc_add_notice( $refusal, 'error' );

		return false;
	}

	public function validate_classic_cart(): void {
		if ( self::holds_more_than_one() ) {
			wc_add_notice( self::one_at_a_time_message(), 'error' );
		}

		if ( $this->is_mixed_and_refused() ) {
			wc_add_notice( self::mixed_cart_message(), 'error' );
		}
	}

	/**
	 * @param \WP_Error $errors
	 */
	public function validate_store_api_cart( $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		if ( self::holds_more_than_one() ) {
			$errors->add( 'submex_one_subscription_per_order', self::one_at_a_time_message() );
		}

		if ( $this->is_mixed_and_refused() ) {
			$errors->add( 'submex_mixed_cart', self::mixed_cart_message() );
		}
	}

	private function refusal( bool $recurring ): ?string {
		if ( $recurring && Subscription_Product::cart_has_subscription() ) {
			return self::one_subscription_message();
		}

		if ( self::allows_mixed() ) {
			return null;
		}

		if ( $recurring && self::cart_has_one_time_item() ) {
			return __( 'Subscriptions are checked out on their own. Please complete the order in your cart first, or empty your cart, then add the subscription.', 'submex' );
		}

		if ( ! $recurring && Subscription_Product::cart_has_subscription() ) {
			return __( 'Subscriptions are checked out on their own. Please complete your subscription order first, then add other products.', 'submex' );
		}

		return null;
	}

	// Checked again at checkout: a cart can outlive the setting it was filled under.
	private function is_mixed_and_refused(): bool {
		return ! self::allows_mixed() && Subscription_Product::cart_has_subscription() && self::cart_has_one_time_item();
	}

	public static function one_subscription_message(): string {
		return __( 'You can only sign up for one subscription at a time. Please complete this order first, then start the next subscription.', 'submex' );
	}

	// Lines are added one at a time and each is checked, but a saved cart merged at login is not.
	private static function holds_more_than_one(): bool {
		return count( Subscription_Product::cart_subscription_items() ) > 1;
	}

	private static function one_at_a_time_message(): string {
		return __( 'You can only sign up for one subscription at a time. Please remove all but one subscription from your cart.', 'submex' );
	}

	private static function mixed_cart_message(): string {
		return __( 'Subscriptions are checked out on their own. Please remove either the subscription or the other products from your cart.', 'submex' );
	}

	/**
	 * @param array<string, mixed> $item_data
	 */
	public static function adding_subscription( int $product_id, int $variation_id = 0, array $item_data = array() ): bool {
		/**
		 * Whether the line being added is recurring. The choice is in the request, not on
		 * the product: a subscription product can be bought once, and a normal product can
		 * be subscribed to. Asked for every product added.
		 *
		 * @param bool  $recurring    Whether the product is a subscription product.
		 * @param int   $product_id
		 * @param int   $variation_id
		 * @param array $item_data    The cart item data being added, where the route passes it.
		 */
		return (bool) apply_filters( 'submex_adding_subscription', Subscription_Product::is_subscription( $product_id ), $product_id, $variation_id, $item_data );
	}

	/**
	 * A subscription product bought once counts as one-time, as it does everywhere else.
	 */
	private static function cart_has_one_time_item(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! Subscription_Product::cart_item_is_subscription( $item, (string) $key ) ) {
				return true;
			}
		}

		return false;
	}
}
