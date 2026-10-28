<?php
/**
 * Free takes card payments through its own Stripe: the checkout method, renewals, settings section, block checkout and
 * setup notice, under the option names, gateway ID and meta keys stores already have from Pro.
 *
 * @package Submex
 */

use Submex\Admin\Gateway_Notice;
use Submex\Blocks\Gateway_Support;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;
use Submex\Gateways\Stripe\Stripe_Gateway;

require __DIR__ . '/bootstrap.php';

$options = array(
	'submex_stripe_enabled'     => 'yes',
	'submex_stripe_live'        => 'no',
	'submex_stripe_test_secret' => 'sk_test_in_free',
	'submex_stripe_secret'      => 'sk_live_in_free',
);
$saved   = array();
foreach ( array_merge( array_keys( $options ), array( 'woocommerce_submex_stripe_settings' ) ) as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
foreach ( $options as $name => $value ) {
	update_option( $name, $value );
}

echo "\n1. Free owns Stripe\n";
$check( 'with no Pro, or a Pro from 0.54.0 on', \Submex\Plugin::owns_stripe() === ( ! defined( 'SUBMEX_PRO_VERSION' ) || version_compare( SUBMEX_PRO_VERSION, '0.54.0', '>=' ) ) );
$check( 'and this site\'s Pro, if any, is one of those', \Submex\Plugin::owns_stripe() );
$check( 'Stripe\'s client is one of free\'s services', \Submex\Plugin::instance()->get( 'stripe_client' ) instanceof Stripe_Client );

echo "\n2. Checkout\n";
$listed = WC()->payment_gateways()->payment_gateways()[ Stripe_Checkout_Gateway::ID ] ?? null;
$check( 'WooCommerce lists free\'s Stripe, under the ID stores already have', $listed instanceof Stripe_Checkout_Gateway && 'submex_stripe' === $listed->id, $listed ? get_class( $listed ) : null );
$check( 'named for Submex, not Pro', $listed && ! str_contains( $listed->get_method_title(), 'Pro' ), $listed ? $listed->get_method_title() : null );
$check( 'Manage under WooCommerce → Payments opens Submex → Settings → Stripe', $listed && \Submex\Admin\Settings_Page::section_url( 'stripe' ) === $listed->get_settings_url() );
$check( 'Stripe\'s return link is answered', $listed && false !== has_action( 'woocommerce_api_submex_stripe_return', array( $listed, 'handle_return' ) ) );

update_option( 'woocommerce_submex_stripe_settings', array( 'enabled' => 'yes' ) );
$fresh = new Stripe_Checkout_Gateway( Stripe_Client::from_settings() );
wc_load_cart();
WC()->cart->empty_cart();
$check( 'switched on with a key, it is offered', $fresh->is_available() );
update_option( 'submex_stripe_test_secret', '' );
$check( 'without the key for the chosen mode, it is not', ! ( new Stripe_Checkout_Gateway( Stripe_Client::from_settings() ) )->is_available() );
update_option( 'submex_stripe_test_secret', $options['submex_stripe_test_secret'] );

update_option( 'submex_stripe_enabled', 'no' );
$check( 'turning Submex\'s switch off turns checkout off too', 'no' === ( get_option( 'woocommerce_submex_stripe_settings' )['enabled'] ?? '' ), get_option( 'woocommerce_submex_stripe_settings' ) );
update_option( 'woocommerce_submex_stripe_settings', array( 'enabled' => 'yes' ) );
$check( 'and turning checkout on turns Submex\'s on', 'yes' === get_option( 'submex_stripe_enabled' ) );

echo "\n3. Renewals\n";
$check( 'renewals read the card from the same meta keys', '_submex_stripe_customer' === Stripe_Gateway::META_CUSTOMER && '_submex_stripe_payment_method' === Stripe_Gateway::META_METHOD );
$check( 'and the adapter has the checkout\'s ID', Stripe_Gateway::ID === Stripe_Checkout_Gateway::ID );

echo "\n4. Settings\n";
$woo = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$woo = $candidate;
	}
}
if ( ! $woo ) {
	submex_test_abort( 'the settings page is not registered' );
}
$check( 'Payments has a Stripe section', 'Stripe' === ( $woo->get_sections()['stripe'] ?? '' ) );
$fields = array_column( array_filter( $woo->get_settings_for_section( 'stripe' ), static fn( $f ) => ! in_array( $f['type'], array( 'title', 'sectionend' ), true ) ), 'type', 'id' );
$check(
	'with the same four options',
	array(
		'submex_stripe_enabled'     => 'checkbox',
		'submex_stripe_live'        => 'select',
		'submex_stripe_test_secret' => 'password',
		'submex_stripe_secret'      => 'password',
	) === $fields,
	$fields
);

$page   = rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/settings/stripe' ) );
$values = array();
foreach ( $page->get_data()['cards'] ?? array() as $card ) {
	foreach ( $card['rows'] as $row ) {
		$values[ $row['id'] ?? '' ] = $row['value'] ?? null;
	}
}
$check( 'Submex → Settings shows the saved values under Payments', 200 === $page->get_status() && 'payments' === ( $page->get_data()['group'] ?? '' ) && 'sk_test_in_free' === ( $values['submex_stripe_test_secret'] ?? '' ), array( $page->get_status(), $values ) );

echo "\n5. Block checkout\n";
$blocks = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class )->get_registered( Stripe_Checkout_Gateway::ID );
$check( 'Stripe is registered by free', $blocks && Gateway_Support::class === get_class( $blocks ), $blocks ? get_class( $blocks ) : null );
$check( 'and free\'s block script registers it', str_contains( (string) file_get_contents( SUBMEX_PATH . 'build/blocks.js' ), 'submex_stripe' ) );

echo "\n6. The setup notice\n";
$dismissed = get_user_meta( get_current_user_id(), 'submex_dismissed_notices', true );
delete_user_meta( get_current_user_id(), 'submex_dismissed_notices' );
$render = static function (): string {
	set_current_screen( 'woocommerce_page_wc-settings' );
	ob_start();
	( new Gateway_Notice() )->render();

	return (string) ob_get_clean();
};
$check( 'a key for the chosen mode says nothing about Stripe', ! str_contains( $render(), 'Stripe' ) );
update_option( 'submex_stripe_test_secret', '' );
$check( 'test mode without a test key says so', str_contains( $render(), 'test secret key is empty' ), $render() );
update_option( 'submex_stripe_live', 'yes' );
update_option( 'submex_stripe_secret', '' );
$check( 'live mode without a live key names live', str_contains( $render(), 'live secret key is empty' ), $render() );
update_option( 'submex_stripe_enabled', 'no' );
$check( 'switched off, nothing to say', ! str_contains( $render(), 'Stripe' ) );

foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
if ( $dismissed ) {
	update_user_meta( get_current_user_id(), 'submex_dismissed_notices', $dismissed );
}

submex_test_done( $fail );
