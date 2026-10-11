<?php
/**
 * Shared by every integration test.
 *
 * Each test is run through `wp eval-file`, so WordPress, WooCommerce and Submex are loaded
 * and this file shares the test's scope: it leaves `$check` and `$fail` behind for the test
 * to use, and the fixtures every test can rely on. A test ends with submex_test_done( $fail ),
 * which exits non-zero on any failure so the runner — and CI — sees it.
 *
 * @package Submex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_set_current_user( 1 );

// Deterministic regardless of what the site was set to: amounts are asserted as text.
update_option( 'woocommerce_currency', 'USD' );

$fail = 0;

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$fail ): void {
	if ( ! $ok ) {
		++$fail;
	}

	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  -> ' . wp_json_encode( $detail ) ) . "\n";
};

if ( ! function_exists( 'submex_test_done' ) ) {
	function submex_test_done( int $fail ): void {
		echo "\n" . ( $fail ? "{$fail} CHECK(S) FAILED" : 'all checks passed' ) . "\n";

		if ( $fail ) {
			WP_CLI::halt( 1 );
		}
	}
}

if ( ! function_exists( 'submex_test_abort' ) ) {
	/**
	 * Stop a test that cannot go on. A bare exit() reports success, which is how a test
	 * whose own setup failed once let the whole run pass.
	 */
	function submex_test_abort( string $why ): void {
		echo "ABORT {$why}\n";
		WP_CLI::halt( 1 );
	}
}

if ( ! function_exists( 'submex_test_product' ) ) {
	/**
	 * A plain monthly subscription product, found or made.
	 *
	 * Shared and read-only: a test that changes a product's terms makes its own, so the
	 * order tests run in can never change what they see.
	 */
	function submex_test_product( string $name = 'SK Harness Recurring', string $price = '20' ): WC_Product {
		$found = get_posts(
			array(
				'post_type'   => 'product',
				'title'       => $name,
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		if ( $found ) {
			$product = wc_get_product( (int) $found[0] );

			// A same-named product from somewhere else would make every test here lie.
			if ( ! \Submex\Product\Subscription_Product::is_subscription( $product ) ) {
				submex_test_abort( "the product called '{$name}' (#{$found[0]}) is not a subscription product; delete it and run again" );
			}

			return $product;
		}

		// The subscription class itself, so WooCommerce records the product type. Setting
		// the type term on a simple product afterwards leaves WooCommerce still loading it
		// as simple.
		$product = new \Submex\Product\Simple_Subscription();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->update_meta_data( \Submex\Product\Subscription_Product::META_PERIOD, 'month' );
		$product->update_meta_data( \Submex\Product\Subscription_Product::META_INTERVAL, 1 );
		$id = $product->save();

		return wc_get_product( $id );
	}
}

submex_test_product();
