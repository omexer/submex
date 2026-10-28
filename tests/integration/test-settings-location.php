<?php
/**
 * Submex's settings live under Submex → Settings only, and every way in still reaches them.
 *
 * @package Submex
 */

use Submex\Admin\Settings_Page;
use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;

require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	final class Submex_Test_Redirected extends Exception {}
}
add_filter(
	'wp_redirect',
	static function ( $to ) {
		throw new Submex_Test_Redirected( (string) $to );
	},
	1
);

$page = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$page = $candidate;
	}
}
$check( 'the settings are still defined for Submex → Settings to draw', null !== $page && isset( ( new Settings_Page() )->sections()['paypal'] ) );

$tabs = (array) apply_filters( 'woocommerce_settings_tabs_array', array() );
$check( 'WooCommerce → Settings has no Subscriptions tab', ! isset( $tabs['submex'] ), array_keys( $tabs ) );

$redirected = static function ( array $get ): string {
	$_GET = $get;
	try {
		( new Settings_Page() )->redirect_woo_tab();
	} catch ( Submex_Test_Redirected $e ) {
		return $e->getMessage();
	} finally {
		$_GET = array();
	}

	return '';
};

$to = $redirected(
	array(
		'page'                  => 'wc-settings',
		'tab'                   => 'submex',
		'section'               => 'license',
		'submex_license_notice' => 'activated',
	)
);
parse_str( (string) wp_parse_url( $to, PHP_URL_QUERY ), $query );
$check( 'an old link to the tab opens the same section of Submex → Settings', 'submex-settings' === ( $query['page'] ?? '' ) && 'license' === ( $query['section'] ?? '' ), $to );
$check( '  keeping what else it carried', 'activated' === ( $query['submex_license_notice'] ?? '' ), $to );
$check( 'another WooCommerce tab stays where it is', '' === $redirected( array( 'page' => 'wc-settings', 'tab' => 'checkout' ) ) );

$gateway = WC()->payment_gateways()->payment_gateways()[ PayPal_Checkout_Gateway::ID ] ?? null;
$check( 'Manage under WooCommerce → Payments opens Submex\'s PayPal settings', $gateway && Settings_Page::section_url( 'paypal' ) === $gateway->get_settings_url() );

submex_test_done( $fail );
