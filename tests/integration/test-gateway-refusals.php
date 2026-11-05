<?php
/**
 * When a provider refuses the store, the merchant is told why: at checkout, and as soon as keys are saved.
 *
 * @package Submex
 */

use Submex\Admin\Gateway_Key_Check;
use Submex\Admin\Gateway_Notice;
use Submex\Admin\Gateway_Refusals;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;

require __DIR__ . '/bootstrap.php';

$saved = array();
foreach ( array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'woocommerce_submex_stripe_settings', 'submex_gateway_refusals' ) as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
delete_option( 'submex_gateway_refusals' );

// Stripe itself, answering each request with what the harness says.
$answer = array( 401, array( 'error' => array( 'message' => 'Invalid API Key provided' ) ) );
$asked  = 0;
$stripe = static function ( $pre, $args, $url ) use ( &$answer, &$asked ) {
	if ( ! str_starts_with( $url, 'https://api.stripe.com' ) ) {
		return $pre;
	}
	++$asked;
	return array( 'headers' => array(), 'body' => wp_json_encode( $answer[1] ), 'response' => array( 'code' => $answer[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $stripe, 10, 3 );

echo "\n1. At checkout\n";
$order = wc_create_order();
$order->set_billing_email( 'sb-refusals@example.test' );
$order->set_currency( 'USD' );
$order->set_total( '29' );
$order->save();
( new Stripe_Checkout_Gateway( new Stripe_Client( 'sk_test_refused' ) ) )->process_payment( $order->get_id() );
wc_clear_notices();
$refusal = Gateway_Refusals::all()[ Stripe_Checkout_Gateway::ID ] ?? array();
$check( 'the provider\'s reason is kept for the merchant', 'Invalid API Key provided' === ( $refusal['reason'] ?? '' ), $refusal );

// current() shows only a gateway that is switched on.
$stripe_settings            = (array) get_option( 'woocommerce_submex_stripe_settings', array() );
$stripe_settings['enabled'] = 'yes';
update_option( 'woocommerce_submex_stripe_settings', $stripe_settings );
WC()->payment_gateways()->init();
set_current_screen( 'woocommerce_page_wc-settings' );
ob_start();
( new Gateway_Notice() )->render();
$notice = (string) ob_get_clean();
$check( 'and shown to the merchant, naming the provider', str_contains( $notice, 'Invalid API Key provided' ) && str_contains( $notice, 'Stripe refused this store' ), $notice );

$answer = array( 200, array( 'id' => 'cs_ok', 'url' => 'https://checkout.stripe.test/cs_ok' ) );
( new Stripe_Checkout_Gateway( new Stripe_Client( 'sk_test_fine' ) ) )->process_payment( $order->get_id() );
$check( 'a payment that starts clears it', ! isset( Gateway_Refusals::all()[ Stripe_Checkout_Gateway::ID ] ) );

echo "\n2. When keys are saved\n";
$checker = new Gateway_Key_Check();
$checker->register();
update_option( 'submex_stripe_enabled', 'yes' );
$answer = array( 401, array( 'error' => array( 'message' => 'Invalid API Key provided' ) ) );
$asked  = 0;
update_option( 'submex_stripe_test_secret', 'sk_test_' . wp_generate_password( 8, false ) );
$check( 'nothing is asked until the save has finished', 0 === $asked, $asked );
$checker->queue();
$check( 'and then in the background, so saving never waits', 0 === $asked && (bool) as_next_scheduled_action( Gateway_Key_Check::ACTION, array( 'gateway_id' => Stripe_Checkout_Gateway::ID ) ), $asked );
as_unschedule_all_actions( Gateway_Key_Check::ACTION );
$checker->check( Stripe_Checkout_Gateway::ID );
$check( 'a key the provider refuses is reported', 'Invalid API Key provided' === ( Gateway_Refusals::all()[ Stripe_Checkout_Gateway::ID ]['reason'] ?? '' ) );

$answer = array( 200, array( 'object' => 'balance' ) );
update_option( 'submex_stripe_test_secret', 'sk_test_' . wp_generate_password( 8, false ) );
$checker->check( Stripe_Checkout_Gateway::ID );
$check( 'a key that works clears the report', ! isset( Gateway_Refusals::all()[ Stripe_Checkout_Gateway::ID ] ) );

$checker->queue();
as_unschedule_all_actions( Gateway_Key_Check::ACTION );
update_option( 'blogdescription', get_option( 'blogdescription' ) . ' ' );
$checker->queue();
$check( 'other settings ask nothing', ! as_next_scheduled_action( Gateway_Key_Check::ACTION ) );
update_option( 'blogdescription', rtrim( get_option( 'blogdescription' ) ) );

echo "\n3. What counts as the store being refused\n";
Gateway_Refusals::note( 'sk_gateway', 'SK', array( 'status' => 402, 'error' => 'Your card was declined.' ), 'stripe' );
Gateway_Refusals::note( 'sk_gateway', 'SK', array( 'status' => 500, 'error' => 'Server error' ), 'stripe' );
Gateway_Refusals::note( 'sk_gateway', 'SK', array( 'status' => 0, 'error' => 'Could not connect' ), 'stripe' );
$check( 'a declined card, an outage or a dropped connection is not', ! isset( Gateway_Refusals::all()['sk_gateway'] ) );
Gateway_Refusals::note( 'sk_gateway', 'SK', array( 'status' => 403, 'error' => 'Insufficient permissions' ), 'stripe' );
$check( 'a refused key is', isset( Gateway_Refusals::all()['sk_gateway'] ) );
$check( 'but a gateway that is not there is not shown', ! isset( Gateway_Refusals::current()['sk_gateway'] ) );
$first = Gateway_Refusals::all()['sk_gateway']['since'];
Gateway_Refusals::clear( 'sk_gateway' );
sleep( 1 );
Gateway_Refusals::note( 'sk_gateway', 'SK', array( 'status' => 403, 'error' => 'Insufficient permissions' ), 'stripe' );
$check( 'the same words later count as a new refusal, so a dismissal does not hide them', Gateway_Refusals::all()['sk_gateway']['since'] > $first );
Gateway_Refusals::clear( 'sk_gateway' );

remove_filter( 'pre_http_request', $stripe, 10 );
$order->delete( true );
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
