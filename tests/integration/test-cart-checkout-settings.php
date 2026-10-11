<?php
/**
 * Cart & checkout settings: mixed carts refused in both directions and at both checkouts when
 * turned off, and the subscribe button on the product page and in product lists.
 *
 * @package Submex
 */

use Submex\Checkout\Cart_Validation;

require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	// Handlers redirect and exit once they act; stop at the redirect and read the state.
	final class Submex_Test_Redirected extends Exception {}
}
$stop_at_redirect = static function ( $to ) {
	throw new Submex_Test_Redirected( (string) $to );
};
add_filter( 'wp_redirect', $stop_at_redirect, 1 );
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

$absent   = new stdClass();
$options  = array( Cart_Validation::OPTION_MIXED, 'woocommerce_cart_redirect_after_add' );
$snapshot = array();
foreach ( $options as $option ) {
	$snapshot[ $option ] = get_option( $option, $absent );
}
update_option( 'woocommerce_cart_redirect_after_add', 'no' );

$save = static function ( array $values ): WP_REST_Response {
	wp_set_current_user( 1 );
	$request = new WP_REST_Request( 'POST', '/submex/v1/settings/checkout' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'values' => $values ) ) );
	return rest_do_request( $request );
};

$subscription = submex_test_product();
$one_time     = new WC_Product_Simple();
$one_time->set_name( 'SK Checkout One-time' );
$one_time->set_status( 'publish' );
$one_time->set_regular_price( '5' );
$one_time->save();

wc_load_cart();

$in_cart = static function (): array {
	return array_values( array_map( static fn( array $item ): int => (int) $item['product_id'], WC()->cart->get_cart() ) );
};

// The shop's own add-to-cart request, redirect included.
$classic_add = static function ( int $product_id ): array {
	wc_clear_notices();
	$_REQUEST['add-to-cart'] = (string) $product_id;
	$redirect                = null;
	try {
		WC_Form_Handler::add_to_cart_action();
	} catch ( Submex_Test_Redirected $redirected ) {
		$redirect = $redirected->getMessage();
	}
	unset( $_REQUEST['add-to-cart'] );
	$errors = array_column( wc_get_notices( 'error' ), 'notice' );
	wc_clear_notices();
	return array(
		'errors'   => $errors,
		'redirect' => $redirect,
	);
};

$store_add = static function ( int $product_id ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'id' => $product_id, 'quantity' => 1 ) ) );
	return rest_do_request( $request );
};

$fill = static function ( array $ids ): void {
	WC()->cart->empty_cart();
	foreach ( $ids as $id ) {
		WC()->cart->add_to_cart( $id );
	}
};

$classic_checkout_errors = static function (): array {
	wc_clear_notices();
	WC()->checkout()->check_cart_items();
	$errors = array_column( wc_get_notices( 'error' ), 'notice' );
	wc_clear_notices();
	return $errors;
};

$store_checkout_errors = static function (): array {
	try {
		( new \Automattic\WooCommerce\StoreApi\Utilities\CartController() )->validate_cart();
	} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $invalid ) {
		return $invalid->getError()->get_error_codes();
	}
	return array();
};

$mentions = static fn( array $messages ): bool => (bool) array_filter( $messages, static fn( $message ): bool => str_contains( (string) $message, 'checked out on their own' ) );

echo "\n1. The settings are on the Cart & checkout page, saved through the settings API\n";
$page   = rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/settings/checkout' ) )->get_data();
$fields = array();
foreach ( $page['cards'] ?? array() as $card ) {
	foreach ( $card['rows'] as $row ) {
		if ( isset( $row['id'] ) ) {
			$fields[ $row['id'] ] = $row + array( 'card' => $card['title'] );
		}
	}
}
$check( 'the guest checkout row is still there', isset( $fields['submex_guest_checkout'] ) );
$check( 'mixed checkout is a switch, on by default', 'checkbox' === ( $fields[ Cart_Validation::OPTION_MIXED ]['type'] ?? '' ) && 'yes' === ( $fields[ Cart_Validation::OPTION_MIXED ]['default'] ?? '' ) );
$check( 'no row for multiple subscriptions per cart', ! array_filter( array_keys( $fields ), static fn( $id ): bool => str_contains( (string) $id, 'multiple' ) ) );

delete_option( Cart_Validation::OPTION_MIXED );

echo "\n2. Mixed checkout on (the default): both directions allowed\n";
WC()->cart->empty_cart();
$first = $classic_add( $subscription->get_id() );
$then  = $classic_add( $one_time->get_id() );
$check( 'a one-time product joins a cart holding a subscription', array() === $first['errors'] && array() === $then['errors'] && array( $subscription->get_id(), $one_time->get_id() ) === $in_cart(), array( $first, $then, $in_cart() ) );
$check( '  and neither checkout objects', ! $mentions( $classic_checkout_errors() ) && ! in_array( 'submex_mixed_cart', $store_checkout_errors(), true ) );
WC()->cart->empty_cart();
$classic_add( $one_time->get_id() );
$after = $classic_add( $subscription->get_id() );
$check( 'a subscription joins a cart holding a one-time product', array() === $after['errors'] && 2 === count( $in_cart() ) );
$again = $classic_add( $subscription->get_id() );
$check( 'a second subscription is still refused, and nothing doubles', 1 === count( $again['errors'] ) && 2 === count( $in_cart() ) && str_contains( $again['errors'][0], 'one subscription at a time' ), $again );

echo "\n3. Mixed checkout off: refused both ways, on both add-to-cart routes, and at both checkouts\n";
$saved = $save( array( Cart_Validation::OPTION_MIXED => 'no' ) );
$check( 'the switch saves off', 200 === $saved->get_status() && 'no' === get_option( Cart_Validation::OPTION_MIXED ), $saved->get_data() );

WC()->cart->empty_cart();
$classic_add( $subscription->get_id() );
$refused = $classic_add( $one_time->get_id() );
$check( 'classic: a one-time product is refused beside a subscription, with a notice', $mentions( $refused['errors'] ) && array( $subscription->get_id() ) === $in_cart(), array( $refused, $in_cart() ) );
$refused_again = $classic_add( $one_time->get_id() );
$check( '  and again on a second try, the cart unchanged', $mentions( $refused_again['errors'] ) && array( $subscription->get_id() ) === $in_cart() );

WC()->cart->empty_cart();
$classic_add( $one_time->get_id() );
$refused = $classic_add( $subscription->get_id() );
$check( 'classic: a subscription is refused beside a one-time product', $mentions( $refused['errors'] ) && array( $one_time->get_id() ) === $in_cart(), array( $refused, $in_cart() ) );

WC()->cart->empty_cart();
$classic_add( $one_time->get_id() );
$store = $store_add( $subscription->get_id() );
$check( 'Store API: a subscription is refused beside a one-time product', 400 === $store->get_status() && str_contains( (string) ( $store->get_data()['message'] ?? '' ), 'checked out on their own' ) && array( $one_time->get_id() ) === $in_cart(), array( $store->get_status(), $store->get_data()['message'] ?? '', $in_cart() ) );

WC()->cart->empty_cart();
$classic_add( $subscription->get_id() );
$store = $store_add( $one_time->get_id() );
$check( 'Store API: a one-time product is refused beside a subscription', 400 === $store->get_status() && str_contains( (string) ( $store->get_data()['message'] ?? '' ), 'checked out on their own' ) && array( $subscription->get_id() ) === $in_cart(), array( $store->get_status(), $store->get_data()['message'] ?? '', $in_cart() ) );

WC()->cart->empty_cart();
$store = $store_add( $one_time->get_id() );
$check( 'Store API: a one-time product alone is fine', in_array( $store->get_status(), array( 200, 201 ), true ) && array( $one_time->get_id() ) === $in_cart(), $store->get_status() );

// A cart filled before the setting changed.
$fill( array( $subscription->get_id(), $one_time->get_id() ) );
$check( 'classic checkout refuses a mixed cart', $mentions( $classic_checkout_errors() ) );
$check( 'Store API checkout refuses a mixed cart', in_array( 'submex_mixed_cart', $store_checkout_errors(), true ) );
$fill( array( $subscription->get_id() ) );
$check( 'a subscription on its own checks out', ! $mentions( $classic_checkout_errors() ) && ! in_array( 'submex_mixed_cart', $store_checkout_errors(), true ) );
$fill( array( $one_time->get_id() ) );
$check( 'one-time products on their own check out', ! $mentions( $classic_checkout_errors() ) && ! in_array( 'submex_mixed_cart', $store_checkout_errors(), true ) );

delete_option( Cart_Validation::OPTION_MIXED );

echo "\n4. The subscribe button\n";
$out_of_stock = wc_get_product( $subscription->get_id() );
$render_loop  = static function ( WC_Product $item ): string {
	$GLOBALS['product'] = $item;
	ob_start();
	woocommerce_template_loop_add_to_cart();
	return (string) ob_get_clean();
};
$render_single = static function ( WC_Product $item ): string {
	$GLOBALS['product'] = $item;
	ob_start();
	woocommerce_simple_add_to_cart();
	return (string) ob_get_clean();
};

$check( 'the product page says Subscribe', 'Subscribe' === $subscription->single_add_to_cart_text() && str_contains( $render_single( $subscription ), '>Subscribe</button>' ) );
$check( 'product lists say Subscribe', 'Subscribe' === $subscription->add_to_cart_text() && str_contains( $render_loop( $subscription ), '>Subscribe</a>' ) );
$check( 'a one-time product keeps Add to cart', 'Add to cart' === $one_time->single_add_to_cart_text() && 'Add to cart' === $one_time->add_to_cart_text() );

$out_of_stock->set_stock_status( 'outofstock' );
$check( 'an out-of-stock subscription still says Read more in lists', 'Read more' === $out_of_stock->add_to_cart_text() );

WC()->cart->empty_cart();
wc_clear_notices();
unset( $GLOBALS['product'] );
remove_filter( 'wp_redirect', $stop_at_redirect, 1 );
remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
$one_time->delete( true );
foreach ( $snapshot as $option => $value ) {
	$absent === $value ? delete_option( $option ) : update_option( $option, $value );
}

submex_test_done( $fail );
