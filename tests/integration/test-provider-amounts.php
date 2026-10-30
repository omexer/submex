<?php
/**
 * Stripe is sent the amount in the currency's own minor units, whatever the store displays.
 *
 * @package Submex
 */

use Submex\Domain\Currency;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;
use Submex\Gateways\Stripe\Stripe_Gateway;
use Submex\Domain\Subscription;

require __DIR__ . '/bootstrap.php';

$stripe = new class( 'sk_test_harness' ) extends Stripe_Client {
	public array $calls = array();

	public function post( string $path, array $params, string $idempotency_key = '' ): array {
		$this->calls[] = array( $path, $params );
		$body          = '/v1/checkout/sessions' === $path ? array( 'id' => 'cs_amounts', 'url' => 'https://checkout.stripe.test/cs' ) : array( 'id' => 'pi_amounts', 'status' => 'succeeded' );

		return array( 'ok' => true, 'status' => 200, 'body' => $body, 'error' => '', 'code' => '' );
	}

	public function get( string $path ): array {
		return array( 'ok' => false, 'status' => 404, 'body' => array(), 'error' => 'unrouted', 'code' => '' );
	}
};

$decimals = get_option( 'woocommerce_price_num_decimals' );
$made     = array();

$order_for = static function ( string $currency, string $total ) use ( &$made ): WC_Order {
	$order = wc_create_order();
	$order->set_billing_email( 'sb-amounts@example.test' );
	$order->set_currency( $currency );
	$order->set_total( $total );
	$order->save();
	$made[] = $order;

	return $order;
};

$subscription = new Subscription();
$subscription->update_meta_data( Stripe_Gateway::META_CUSTOMER, 'cus_amounts' );
$subscription->update_meta_data( Stripe_Gateway::META_METHOD, 'pm_amounts' );

$cases = array(
	// currency, store decimals, order total, what Stripe must receive
	array( 'USD', 0, '29', 2900 ),
	array( 'USD', 2, '29.99', 2999 ),
	array( 'JPY', 2, '1000', 1000 ),
	array( 'KWD', 2, '10.25', 10250 ),
	array( 'KWD', 3, '10.255', 10260 ),
);

foreach ( $cases as [ $currency, $store_decimals, $total, $expected ] ) {
	update_option( 'woocommerce_price_num_decimals', $store_decimals );
	$label = "{$currency} {$total} on a store showing {$store_decimals} decimals";

	$stripe->calls = array();
	( new Stripe_Checkout_Gateway( $stripe ) )->process_payment( $order_for( $currency, $total )->get_id() );
	$sent = $stripe->calls[0][1]['line_items'][0]['price_data']['unit_amount'] ?? null;
	$check( "checkout sends {$expected} for {$label}", $expected === $sent, $sent );

	$stripe->calls = array();
	( new Stripe_Gateway( $stripe ) )->charge_renewal( $subscription, $order_for( $currency, $total ), 'k-amounts-' . $currency . $store_decimals );
	$sent = $stripe->calls[0][1]['amount'] ?? null;
	$check( "a renewal sends {$expected} for {$label}", $expected === $sent, $sent );
}

$check( 'ISO minor units: JPY has none, KWD three, USD two', 0 === Currency::decimals( 'JPY' ) && 3 === Currency::decimals( 'kwd' ) && 2 === Currency::decimals( 'USD' ) );
$check( 'a provider amount ignores the display setting', 2900 === Currency::minor_units( '29', 'USD' ) && 5 === Currency::minor_units( '5', 'JPY' ) );

update_option( 'woocommerce_price_num_decimals', $decimals );
foreach ( $made as $order ) {
	$order->delete( true );
}

submex_test_done( $fail );
