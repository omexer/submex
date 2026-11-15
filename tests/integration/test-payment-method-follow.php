<?php
/**
 * Before its first payment a subscription follows the method its order is paid with, but never
 * leaves a PayPal agreement that can still bill, and the gateway that took the money claims the order.
 *
 * @package Submex
 */

use Submex\Checkout\Payment_Claim;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Client;
use Submex\Gateways\PayPal\PayPal_Gateway;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';

$status    = 'APPROVAL_PENDING';
$reachable = true;
$cancelled = array();
$paypal    = static function ( $pre, $args, $url ) use ( &$status, &$reachable, &$cancelled ) {
	if ( 'api-m.sandbox.paypal.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $pre;
	}

	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$code = 200;

	if ( '/v1/oauth2/token' === $path ) {
		$answer = array(
			'access_token' => 'follow-token',
			'expires_in'   => 3600,
		);
	} elseif ( ! $reachable ) {
		$answer = array();
		$code   = 500;
	} elseif ( str_ends_with( $path, '/cancel' ) ) {
		$cancelled[] = $path;
		$answer      = array();
		$code        = 204;
	} else {
		$answer = array( 'status' => $status );
	}

	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $answer ),
		'response' => array(
			'code'    => $code,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $paypal, 10, 3 );

// The site's own PayPal check uses its saved credentials; this one talks to the fake above.
global $wp_filter;
$hook  = 'submex_subscription_can_leave_payment_method';
$saved = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
remove_all_filters( $hook );
add_filter( $hook, array( new PayPal_Gateway( new PayPal_Client( 'follow-' . wp_generate_password( 8, false ), 'secret', true ) ), 'can_leave' ), 10, 4 );

$factory = \Submex\Plugin::instance()->get( 'subscription_factory' );
$made    = array();

$product = new \Submex\Product\Simple_Subscription();
$product->set_name( 'SK follow method' );
$product->set_status( 'publish' );
$product->set_regular_price( '20' );
$product->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$product->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
$product->save();
$made[] = $product;

$with = static function ( WC_Order $order, string $id, string $title ): WC_Order {
	$order = wc_get_order( $order->get_id() );
	$order->set_payment_method( $id );
	$order->set_payment_method_title( $title );
	$order->save();

	return $order;
};

// Checkout with PayPal: PayPal's process_payment records its subscription on the order before the customer approves.
$paypal_checkout = static function () use ( $factory, $product, $with, &$made ): array {
	$order = wc_create_order( array( 'customer_id' => 1 ) );
	$order->add_product( $product, 1 );
	$order->calculate_totals( false );
	$order->update_meta_data( PayPal_Gateway::META_SUBSCRIPTION_ID, 'I-FOLLOW' . wp_generate_password( 4, false ) );
	$order->save();
	$made[] = $order;
	$order  = $with( $order, PayPal_Checkout_Gateway::ID, 'PayPal' );

	$factory->from_checkout( $order->get_id() );
	$s      = wc_get_order( (int) wc_get_order( $order->get_id() )->get_meta( '_submex_subscription_id' ) );
	$made[] = $s;

	return array( $order, $s );
};

echo "\n1. PayPal chosen, never approved, then paid by card\n";
$status        = 'APPROVAL_PENDING';
[ $order, $s ] = $paypal_checkout();
$order         = $with( $order, 'sk_card', 'Card' );
$factory->from_checkout( $order->get_id() );
$s = wc_get_order( $s->get_id() );
$check( 'checking out again by card moves it off an unapproved PayPal agreement', 'sk_card' === $s->get_payment_method(), $s->get_payment_method() );
$check( 'and forgets that agreement, so its webhooks can never find this subscription', '' === (string) wc_get_order( $order->get_id() )->get_meta( PayPal_Gateway::META_SUBSCRIPTION_ID ) );
$check( 'nothing was cancelled at PayPal, as nothing was approved', array() === $cancelled, $cancelled );

echo "\n2. PayPal approved in one tab, card chosen in another\n";
$status        = 'APPROVED';
[ $order, $s ] = $paypal_checkout();
$order         = $with( $order, 'sk_card', 'Card' );
$factory->from_checkout( $order->get_id() );
$s = wc_get_order( $s->get_id() );
$check( 'choosing the card again does not leave an agreement the customer approved', PayPal_Checkout_Gateway::ID === $s->get_payment_method(), $s->get_payment_method() );
$order->payment_complete( 'card-paid' );
$s = wc_get_order( $s->get_id() );
$check( 'once the card has paid, the PayPal agreement is cancelled', 1 === count( $cancelled ), $cancelled );
$check( 'and the subscription renews on the card that paid', 'sk_card' === $s->get_payment_method() && Subscription_Status::Active === $s->get_status_enum(), array( $s->get_payment_method(), $s->get_status() ) );
$check( 'with no PayPal reference left on it', '' === (string) $s->get_meta( PayPal_Gateway::META_SUBSCRIPTION_ID ) );

echo "\n3. PayPal cannot be reached when the card pays\n";
$status        = 'ACTIVE';
$cancelled     = array();
[ $order, $s ] = $paypal_checkout();
$reachable     = false;
$order         = $with( $order, 'sk_card', 'Card' );
$order->payment_complete( 'card-paid' );
$reachable = true;
$s         = wc_get_order( $s->get_id() );
$check( 'it stays on PayPal rather than leave an agreement it could not stop', PayPal_Checkout_Gateway::ID === $s->get_payment_method(), $s->get_payment_method() );
$check( 'and cancels nothing it could not confirm', array() === $cancelled, $cancelled );

echo "\n4. Active before its order is paid\n";
$status        = 'APPROVAL_PENDING';
[ $order, $s ] = $paypal_checkout();
$s->transition_to( Subscription_Status::Active );
$s->save();
$order = $with( $order, 'sk_card', 'Card' );
$factory->from_checkout( $order->get_id() );
$check( 'a subscription a provider already started keeps its method', PayPal_Checkout_Gateway::ID === wc_get_order( $s->get_id() )->get_payment_method() );

echo "\n5. The gateway that took the money claims the order\n";
$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_product( $product, 1 );
$order->calculate_totals( false );
$order->save();
$made[] = $order;
$order  = $with( $order, PayPal_Checkout_Gateway::ID, 'PayPal' );
Payment_Claim::claim( $order, 'submex_stripe' );
$order = wc_get_order( $order->get_id() );
$check( 'a card paid in an older tab makes the order a card order', 'submex_stripe' === $order->get_payment_method(), $order->get_payment_method() );
$check( 'and says so on the order', str_contains( implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) ), 'although PayPal was the method chosen last' ) );
Payment_Claim::claim( $order, 'submex_stripe' );
$check( 'claiming again changes nothing and adds no second note', 1 === count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );

remove_filter( 'pre_http_request', $paypal, 10 );
remove_all_filters( $hook );
if ( $saved ) {
	$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back the site's own check.
}

foreach ( array_reverse( array_filter( $made ) ) as $thing ) {
	$thing->delete( true );
}

submex_test_done( $fail );
