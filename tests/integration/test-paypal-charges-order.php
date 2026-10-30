<?php
/**
 * PayPal is asked for what the order comes to, not just the plan's price: quantity, one-off
 * items, shipping and the sign-up fee all reach PayPal, or PayPal is not used at all.
 *
 * @package Submex
 */

use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Client;
use Submex\Gateways\PayPal\PayPal_Plans;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';

$calls  = array();
$paypal = static function ( $pre, $args, $url ) use ( &$calls ) {
	if ( 'api-m.sandbox.paypal.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $pre;
	}
	$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	$calls[] = array( $path, is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : array() );
	$answer  = match ( $path ) {
		'/v1/oauth2/token'          => array( 'access_token' => 'harness-token', 'expires_in' => 3600 ),
		'/v1/catalogs/products'     => array( 'id' => 'PROD-CHARGES' ),
		'/v1/billing/plans'         => array( 'id' => 'P-CHARGES' ),
		'/v1/billing/subscriptions' => array(
			'id'    => 'I-CHARGES',
			'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/approve' ) ),
		),
		default                     => array(),
	};
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $answer ),
		'response' => array( 'code' => 201, 'message' => 'Created' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $paypal, 10, 3 );

$client_id = 'charges-' . wp_generate_password( 8, false );
$client    = new PayPal_Client( $client_id, 'secret', true );
$gateway   = new PayPal_Checkout_Gateway( $client, new PayPal_Plans( $client ) );
$factory   = \Submex\Plugin::instance()->get( 'subscription_factory' );
$made      = array();

$plan = static function ( string $name, array $meta ) use ( &$made ): WC_Product {
	$product = new \Submex\Product\Simple_Subscription();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( '20' );
	$product->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
	$product->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( $key, $value );
	}
	$product->save();
	$made[] = $product;

	return wc_get_product( $product->get_id() );
};

$one_off = new WC_Product_Simple();
$one_off->set_name( 'SK PayPal one-off' );
$one_off->set_status( 'publish' );
$one_off->set_regular_price( '200' );
$one_off->save();
$made[] = $one_off;

$checkout = static function ( WC_Product $product, int $quantity, bool $with_one_off, string $discount = '0' ) use ( $one_off, $factory, &$made ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => 1 ) );
	$order->add_product( $product, $quantity );
	if ( $with_one_off ) {
		$order->add_product( $one_off, 1 );
	}
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_props( array( 'method_title' => 'Flat', 'method_id' => 'flat_rate', 'total' => '5' ) );
	$order->add_item( $shipping );
	$order->set_billing_email( 'sb-paypal-charges@example.test' );
	$order->set_payment_method( PayPal_Checkout_Gateway::ID );
	$order->calculate_totals( false );
	if ( '0' !== $discount ) {
		$order->set_total( (string) ( (float) $order->get_total() - (float) $discount ) );
	}
	$order->save();
	$made[] = $order;

	$factory->from_checkout( $order->get_id() );
	$made[] = wc_get_order( (int) wc_get_order( $order->get_id() )->get_meta( '_submex_subscription_id' ) );

	return wc_get_order( $order->get_id() );
};

$sent = static function () use ( &$calls ): array {
	$posts = array_values( array_filter( $calls, static fn( $c ) => '/v1/billing/subscriptions' === $c[0] ) );

	return end( $posts )[1] ?? array();
};

echo "\n1. Two of the plan, a one-off item and shipping\n";
$monthly = $plan( 'SK PayPal charges monthly', array() );
$order   = $checkout( $monthly, 2, true );
$calls   = array();
$result  = $gateway->process_payment( $order->get_id() );
$body    = $sent();
$cycle   = $body['plan']['billing_cycles'][0] ?? array();
$check( 'the order costs 245', '245.00' === wc_format_decimal( $order->get_total(), 2 ), $order->get_total() );
$check( 'PayPal bills 40 a month, both of the plan', 1 === ( $cycle['sequence'] ?? null ) && '40.00' === ( $cycle['pricing_scheme']['fixed_price']['value'] ?? null ), $cycle );
$check( 'and takes the other 205 when the customer approves', '205.00' === ( $body['plan']['payment_preferences']['setup_fee']['value'] ?? null ), $body['plan'] ?? $body );
$check( 'the customer is sent to approve it', 'success' === ( $result['result'] ?? '' ), $result );
wc_clear_notices();

echo "\n2. A trial with a sign-up fee\n";
$trial = $plan(
	'SK PayPal charges trial',
	array(
		Subscription_Product::META_TRIAL_DAYS   => 7,
		Subscription_Product::META_TRIAL_PERIOD => 'day',
		Subscription_Product::META_SIGNUP_FEE   => '10',
	)
);
$order = $checkout( $trial, 1, false );
$calls = array();
$gateway->process_payment( $order->get_id() );
$body  = $sent();
$cycle = $body['plan']['billing_cycles'][0] ?? array();
$check( 'the regular cycle after the trial bills 20', 2 === ( $cycle['sequence'] ?? null ) && '20.00' === ( $cycle['pricing_scheme']['fixed_price']['value'] ?? null ), $cycle );
$check( 'and everything paid today is the setup fee', wc_format_decimal( $order->get_total(), 2 ) === ( $body['plan']['payment_preferences']['setup_fee']['value'] ?? null ), array( 'order' => $order->get_total(), 'plan' => $body['plan'] ?? null ) );
wc_clear_notices();

echo "\n3. An order that costs less than one renewal\n";
$order  = $checkout( $monthly, 1, false, '20' );
$calls  = array();
$result = $gateway->process_payment( $order->get_id() );
$check( 'is not sent to PayPal, which could not bill it correctly', 'failure' === ( $result['result'] ?? '' ) && array() === $sent(), $result );
wc_clear_notices();

echo "\n4. The pay-for-order page\n";
$gateway->enabled = 'yes';
$pay_page         = static function ( WC_Order $order ) use ( $gateway ): bool {
	global $wp;
	$wp->query_vars['order-pay'] = $order->get_id();
	$available                   = $gateway->is_available();
	unset( $wp->query_vars['order-pay'] );

	return $available;
};
$plain = wc_create_order( array( 'customer_id' => 1 ) );
$plain->add_product( $one_off, 1 );
$plain->calculate_totals( false );
$plain->save();
$made[] = $plain;
$check( 'offers PayPal for a subscription order', $pay_page( $checkout( $monthly, 1, false ) ) );
$check( 'but not for an order with no subscription in it', ! $pay_page( $plain ) );

remove_filter( 'pre_http_request', $paypal, 10 );
foreach ( array_reverse( array_filter( $made ) ) as $thing ) {
	$thing->delete( true );
}
delete_transient( 'submex_paypal_token_' . md5( $client_id . $client->base() ) );

submex_test_done( $fail );
