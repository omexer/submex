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

if ( ! function_exists( 'submex_test_site_snapshot' ) ) {
	/**
	 * Gateway switches, store and tax settings, and the newest order id, so a test file leaves the site as it found it.
	 *
	 * @return array{switches: array<string, array{0: string, 1: string}>, last_order: int, store: array<string, mixed>, tax_rates: int[]}
	 */
	function submex_test_site_snapshot(): array {
		global $wpdb;

		$switches = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read raw, so restoring never runs the switches' mirroring hooks.
		foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'submex\\_submex\\_%\\_enabled' OR option_name LIKE 'woocommerce\\_submex\\_%\\_settings'" ) as $row ) {
			$switches[ $row->option_name ] = array( $row->option_value, $row->autoload );
		}

		$last = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
				'type'    => array_keys( wc_get_order_types() ),
			)
		);

		$store = array();

		foreach ( array( 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_default_country', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_currency' ) as $name ) {
			$store[ $name ] = get_option( $name, null );
		}

		return array(
			'switches'   => $switches,
			'last_order' => (int) ( $last[0] ?? 0 ),
			'store'      => $store,
			'tax_rates'  => array_map( 'intval', $wpdb->get_col( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates" ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * A gateway's two switches mirror each other, so restoring one through update_option left the other on for every later test.
	 */
	function submex_test_site_restore( array $before ): void {
		global $wpdb;

		$now = submex_test_site_snapshot();

		foreach ( array_diff_key( $now['switches'], $before['switches'] ) as $name => $unused ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		foreach ( $before['switches'] as $name => list( $value, $autoload ) ) {
			$wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $value,
					'autoload'     => $autoload,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// A test that stops part way skips its own restore, and every later test would then price with its tax.
		foreach ( $before['store'] as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}

		foreach ( array_diff( $now['tax_rates'], $before['tax_rates'] ) as $rate ) {
			WC_Tax::_delete_tax_rate( $rate );
		}

		$made = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'type'   => array_keys( wc_get_order_types() ),
			)
		);

		foreach ( $made as $id ) {
			if ( (int) $id > $before['last_order'] ) {
				( wc_get_order( $id ) ?: null )?->delete( true );
			}
		}
	}

	$submex_test_site_before = submex_test_site_snapshot();
	// Registered from a shutdown function so it runs after each test's own cleanup, not before it.
	register_shutdown_function(
		static function () use ( $submex_test_site_before ): void {
			register_shutdown_function( 'submex_test_site_restore', $submex_test_site_before );
		}
	);
}

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
