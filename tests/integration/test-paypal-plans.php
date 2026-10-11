<?php
/**
 * PayPal billing plans are cached per set of terms, not per product: one product sold on two
 * schedules gets two plans, each billing what its line says, and asking again reuses them.
 *
 * @package Submex
 */

use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Client;
use Submex\Gateways\PayPal\PayPal_Gateway;
use Submex\Gateways\PayPal\PayPal_Plans;
use Submex\Product\Line_Terms;

require __DIR__ . '/bootstrap.php';

$equals = static function ( string $what, $got, $want ) use ( $check ): void {
	$check( $what, $got === $want, array( 'got' => $got, 'want' => $want ) );
};

$saved_decimals = get_option( 'woocommerce_price_num_decimals', null );
update_option( 'woocommerce_price_num_decimals', '2' );

// No request leaves the site: PayPal's sandbox host is answered here, and only it.
$calls  = array();
$plans  = 0;
$paypal = static function ( $pre, $args, $url ) use ( &$calls, &$plans ) {
	if ( 'api-m.sandbox.paypal.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $pre;
	}
	$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	$body    = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : array();
	$calls[] = array( $path, $body );
	$answer  = match ( $path ) {
		'/v1/oauth2/token'         => array( 'access_token' => 'harness-token', 'expires_in' => 3600 ),
		'/v1/catalogs/products'    => array( 'id' => 'PROD-HARNESS' ),
		'/v1/billing/plans'        => array( 'id' => 'P-HARNESS-' . ( ++$plans ) ),
		'/v1/billing/subscriptions' => array(
			'id'    => 'I-HARNESS',
			'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/approve' ) ),
		),
		default                    => array(),
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

$client_id = 'harness-' . wp_generate_password( 8, false );
$client    = new PayPal_Client( $client_id, 'secret', true );
$cache     = new PayPal_Plans( $client );

$product = new WC_Product_Simple();
$product->set_name( 'SK PayPal plans probe' );
$product->set_status( 'publish' );
$product->set_regular_price( '30' );
$product->save();
$product = wc_get_product( $product->get_id() );

$terms_by_name = array(
	'fortnight' => array( 'price' => 24.0, 'period' => 'week', 'interval' => 2, 'trial_length' => 0, 'trial_period' => 'day', 'signup_fee' => 5.0 ),
	'yearly'    => array( 'price' => 250.0, 'period' => 'year', 'interval' => 1, 'trial_length' => 14, 'trial_period' => 'day', 'signup_fee' => 0.0 ),
);
$line_terms    = static function ( $terms, $p, $cart_item, $order_item ) use ( $terms_by_name ) {
	return $order_item ? ( $terms_by_name[ (string) $order_item->get_meta( '_sbt_terms' ) ] ?? $terms ) : $terms;
};
$is_line       = static fn( $create, $line ) => '' !== (string) $line->get_meta( '_sbt_terms' ) ? true : $create;
add_filter( 'submex_line_terms', $line_terms, 10, 4 );
add_filter( 'submex_create_subscription_for_item', $is_line, 10, 2 );

$line = static function ( string $name ) use ( $product ): WC_Order_Item_Product {
	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->add_meta_data( '_sbt_terms', $name, true );
	return $item;
};
$plan_posts = static function () use ( &$calls ): array {
	return array_values( array_filter( $calls, static fn( $call ) => '/v1/billing/plans' === $call[0] ) );
};

echo "\n1. Two terms on one product, two plans\n";
$fortnight = $cache->plan_for( $product, Line_Terms::for_order_item( $line( 'fortnight' ) ) );
$yearly    = $cache->plan_for( $product, Line_Terms::for_order_item( $line( 'yearly' ) ) );
$equals( 'each gets its own plan', array( $fortnight['plan_id'], $yearly['plan_id'] ), array( 'P-HARNESS-1', 'P-HARNESS-2' ) );
$stored = wc_get_product( $product->get_id() )->get_meta( PayPal_Plans::META_PLANS );
$check( 'both are cached on the product', is_array( $stored ) && 2 === count( $stored ) && array( 'P-HARNESS-1', 'P-HARNESS-2' ) === array_values( array_intersect( array( 'P-HARNESS-1', 'P-HARNESS-2' ), $stored ) ), $stored );
$equals( 'under one PayPal product', count( array_filter( $calls, static fn( $call ) => '/v1/catalogs/products' === $call[0] ) ), 1 );

$posted = $plan_posts();
$regular = static fn( array $payload ): array => end( $payload['billing_cycles'] );
$equals(
	'the fortnightly plan bills 24 every 2 weeks, with a 5 setup fee',
	array( $regular( $posted[0][1] )['pricing_scheme']['fixed_price']['value'], $regular( $posted[0][1] )['frequency'], $posted[0][1]['payment_preferences']['setup_fee']['value'] ?? null, count( $posted[0][1]['billing_cycles'] ) ),
	array( '24.00', array( 'interval_unit' => 'WEEK', 'interval_count' => 2 ), '5.00', 1 )
);
$equals(
	'the yearly plan bills 250 a year after a 14-day trial, with no fee',
	array( $regular( $posted[1][1] )['pricing_scheme']['fixed_price']['value'], $regular( $posted[1][1] )['frequency'], $posted[1][1]['billing_cycles'][0]['frequency'], isset( $posted[1][1]['payment_preferences']['setup_fee'] ) ),
	array( '250.00', array( 'interval_unit' => 'YEAR', 'interval_count' => 1 ), array( 'interval_unit' => 'DAY', 'interval_count' => 14 ), false )
);

echo "\n2. Asking again reuses them\n";
$before = count( $plan_posts() );
$again  = $cache->plan_for( wc_get_product( $product->get_id() ), Line_Terms::for_order_item( $line( 'fortnight' ) ) );
$equals( 'the same plan comes back', $again['plan_id'], 'P-HARNESS-1' );
$equals( 'without creating another', count( $plan_posts() ), $before );

echo "\n3. A change to what PayPal is told makes a new plan\n";
$cap = static fn() => 6;
add_filter( 'submex_paypal_total_cycles', $cap );
$capped = $cache->plan_for( wc_get_product( $product->get_id() ), Line_Terms::for_order_item( $line( 'fortnight' ) ) );
remove_filter( 'submex_paypal_total_cycles', $cap );
$equals( 'a payment cap is a different plan', $capped['plan_id'], 'P-HARNESS-3' );
$equals( 'that stops after 6 payments', $regular( $plan_posts()[2][1] )['total_cycles'], 6 );
$own = $cache->plan_for( wc_get_product( $product->get_id() ) );
$equals( 'and the product\'s own price is another', array( $own['plan_id'], $regular( $plan_posts()[3][1] )['pricing_scheme']['fixed_price']['value'] ), array( 'P-HARNESS-4', '30.00' ) );

echo "\n4. Checkout with PayPal subscribes to the order line's plan\n";
$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_item( $line( 'yearly' ) );
$order->set_billing_email( 'paypalplans@example.test' );
$order->calculate_totals();
$order->save();
wc_load_cart();
$made   = array( $order );
$result = ( new PayPal_Checkout_Gateway( $client, $cache ) )->process_payment( $order->get_id() );
$sent   = array_values( array_filter( $calls, static fn( $call ) => '/v1/billing/subscriptions' === $call[0] ) );
$equals( 'the customer is sent to approve it', $result['result'] ?? '', 'success' );
$equals( 'on the yearly plan', array( $sent[0][1]['plan_id'] ?? '', wc_get_order( $order->get_id() )->get_meta( PayPal_Gateway::META_PLAN_ID ) ), array( 'P-HARNESS-2', 'P-HARNESS-2' ) );
wc_clear_notices();

remove_filter( 'submex_line_terms', $line_terms, 10 );
remove_filter( 'submex_create_subscription_for_item', $is_line, 10 );

$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_product( wc_get_product( $product->get_id() ), 1 );
$order->save();
$made[] = $order;
$result = ( new PayPal_Checkout_Gateway( $client, $cache ) )->process_payment( $order->get_id() );
$equals( 'with nothing hooked in, a normal product is no subscription', $result['result'] ?? '', 'failure' );
wc_clear_notices();

remove_filter( 'pre_http_request', $paypal, 10 );
foreach ( $made as $order ) {
	$order->delete( true );
}
$product->delete( true );
delete_transient( 'submex_paypal_token_' . md5( $client_id . $client->base() ) );
null === $saved_decimals ? delete_option( 'woocommerce_price_num_decimals' ) : update_option( 'woocommerce_price_num_decimals', $saved_decimals );

submex_test_done( $fail );
