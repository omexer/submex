<?php
/**
 * PayPal has one switch: Submex's "Enable PayPal" and WooCommerce's Payments toggle move
 * together, so turning it on in either place puts it at checkout.
 *
 * @package Submex
 */

use Submex\Gateways\Gateway_Switch;
use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Client;
use Submex\Gateways\PayPal\PayPal_Plans;

require __DIR__ . '/bootstrap.php';

global $wpdb;

$option   = 'submex_paypal_enabled';
$settings = Gateway_Switch::settings_name( PayPal_Checkout_Gateway::ID );
$keep     = array();
foreach ( array( $option, $settings, 'submex_paypal_client_id', 'submex_paypal_secret' ) as $name ) {
	$keep[ $name ] = get_option( $name, null );
}

$wc_on = static fn(): string => (string) ( ( (array) get_option( $settings, array() ) )['enabled'] ?? 'no' );
// Writes straight to the table, as two switches that disagreed before this release would be.
$raw = static function ( string $name, $value ) use ( $wpdb ): void {
	$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => maybe_serialize( $value ), 'autoload' => 'no' ) );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

echo "\n1. Either switch moves the other\n";
delete_option( $option );
delete_option( $settings );
update_option( $option, 'yes' );
$check( 'turning on Enable PayPal in Submex turns on the WooCommerce Payments toggle', 'yes' === $wc_on(), get_option( $settings ) );
update_option( $settings, array_merge( (array) get_option( $settings, array() ), array( 'enabled' => 'no', 'title' => 'Pay with PayPal' ) ) );
$check( 'turning the toggle off turns Submex\'s switch off', 'no' === get_option( $option ) );
$check( '  and leaves the gateway\'s other settings as they were', 'Pay with PayPal' === ( (array) get_option( $settings ) )['title'] );
update_option( $settings, array_merge( (array) get_option( $settings, array() ), array( 'enabled' => 'yes' ) ) );
$check( 'turning the toggle on turns Submex\'s switch on', 'yes' === get_option( $option ) );
update_option( $option, 'no' );
$check( 'turning Submex\'s switch off turns the toggle off, keeping the title', 'no' === $wc_on() && 'Pay with PayPal' === ( (array) get_option( $settings ) )['title'] );

echo "\n2. Switches that disagreed before this release\n";
$raw( $option, 'yes' );
$raw( $settings, array( 'enabled' => 'no' ) );
Gateway_Switch::reconcile( PayPal_Checkout_Gateway::ID, $option );
$check( 'Submex on and WooCommerce off: both end up on', 'yes' === get_option( $option ) && 'yes' === $wc_on() );
$raw( $option, 'no' );
$raw( $settings, array( 'enabled' => 'yes' ) );
Gateway_Switch::reconcile( PayPal_Checkout_Gateway::ID, $option );
$check( 'WooCommerce on and Submex off: both end up on, so renewals are registered too', 'yes' === get_option( $option ) && 'yes' === $wc_on() );
$raw( $option, 'no' );
$raw( $settings, array( 'enabled' => 'no' ) );
Gateway_Switch::reconcile( PayPal_Checkout_Gateway::ID, $option );
$check( 'both off: both stay off', 'no' === get_option( $option ) && 'no' === $wc_on() );

echo "\n3. At checkout\n";
update_option( 'submex_paypal_client_id', 'switch-test-id' );
update_option( 'submex_paypal_secret', 'switch-test-secret' );
delete_option( $settings );
update_option( $option, 'yes' );
WC()->initialize_session();
WC()->initialize_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( submex_test_product()->get_id() );
$client  = PayPal_Client::from_settings();
$gateway = new PayPal_Checkout_Gateway( $client, new PayPal_Plans( $client ) );
$check( 'with only Submex\'s Enable PayPal turned on, PayPal is offered for a subscription', $gateway->is_available() );
WC()->cart->empty_cart();

foreach ( $keep as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
