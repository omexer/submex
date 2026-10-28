<?php
/**
 * Stripe and a renewal the customer pays: the card method stays on a renewal's pay page, a 3-D Secure renewal is sent
 * there without Stripe's client secret, and money Stripe takes twice is flagged for refund — but only when it was twice.
 *
 * @package Submex
 */

use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;
use Submex\Gateways\Stripe\Stripe_Gateway;

require __DIR__ . '/bootstrap.php';

$free      = \Submex\Plugin::instance();
$processor = $free->get( 'processor' );
$slots     = $free->get( 'charge_slots' );
$product   = submex_test_product();
$made      = array();

// Stripe, answering from a table keyed by "METHOD path".
$api = new class( 'sk_test_harness' ) extends Stripe_Client {
	public array $routes = array();
	public array $calls  = array();
	public function post( string $path, array $params, string $idempotency_key = '' ): array { return $this->answer( 'POST ' . $path ); }
	public function get( string $path ): array { return $this->answer( 'GET ' . $path ); }
	private function answer( string $request ): array {
		$this->calls[] = $request;
		foreach ( $this->routes as $prefix => $response ) {
			if ( str_starts_with( $request, $prefix ) ) { return $response; }
		}
		return array( 'ok' => false, 'status' => 404, 'body' => array(), 'error' => 'unrouted ' . $request, 'code' => '' );
	}
};
$to_stripe = static function ( $gateway, $subscription ) use ( $api ) {
	return Stripe_Gateway::ID === $subscription->get_payment_method() ? new Stripe_Gateway( $api ) : $gateway;
};
add_filter( 'submex_gateway_for_subscription', $to_stripe, 10, 2 );

$make = function ( string $email ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Stripe_Gateway::ID );
	$s->set_address( array( 'first_name' => 'Pay', 'email' => $email, 'country' => 'US' ), 'billing' );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Stripe pay probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$s->update_meta_data( Stripe_Gateway::META_CUSTOMER, 'cus_pay' );
	$s->update_meta_data( Stripe_Gateway::META_METHOD, 'pm_pay' );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s->get_id();
	return $s;
};

global $wp;
$query_vars = $wp->query_vars;

echo "\n1. Where the card method is offered\n";
$plain = new WC_Product_Simple();
$plain->set_name( 'SK Stripe plain product' );
$plain->set_regular_price( '5' );
$plain->set_status( 'publish' );
$plain->save();
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $plain->get_id() );

$card          = new Stripe_Checkout_Gateway( new Stripe_Client( 'sk_test_harness' ) );
$card->enabled = 'yes';
$check( 'at checkout, it still needs a subscription in the cart', ! $card->is_available() );
$owed = wc_create_order();
$owed->set_total( '20' );
$owed->save();
$wp->query_vars['order-pay'] = $owed->get_id();
$check( 'on a pay page, whatever is in the cart does not hide it', $card->is_available() );
$wp->query_vars = $query_vars;

echo "\n2. A renewal the bank wants confirmed\n";
$confirmations = array();
$collect       = static function ( $args ) use ( &$confirmations ) {
	if ( 'Confirm your payment' === $args['subject'] ) {
		$confirmations[] = $args['message'];
	}
	return $args;
};
add_filter( 'wp_mail', $collect, 999 );

$secret      = 'pi_3ds_secret_harness';
$api->routes = array(
	'POST /v1/payment_intents' => array(
		'ok'     => false,
		'status' => 402,
		'code'   => 'authentication_required',
		'error'  => 'This payment requires authentication.',
		'body'   => array( 'error' => array( 'code' => 'authentication_required', 'payment_intent' => array( 'id' => 'pi_3ds', 'client_secret' => $secret ) ) ),
	),
);
$sca = $make( 'stripe-3ds@example.test' );
$processor->process( $sca->get_id() );
$slot  = $slots->latest_unsettled( $sca->get_id() );
$order = $slot ? wc_get_order( (int) $slot->renewal_order_id ) : null;
if ( ! $order instanceof WC_Order ) {
	submex_test_abort( 'the 3-D Secure renewal left no order' );
}
$action = (string) $order->get_meta( '_submex_action_url' );
$check( 'the customer is sent to the renewal\'s pay page', $order->get_checkout_payment_url() === $action, $action );
$check( 'by a link and an email that carry no client secret', 1 === count( $confirmations ) && ! str_contains( $action, $secret ) && ! str_contains( implode( '', $confirmations ), $secret ), $action );
remove_filter( 'wp_mail', $collect, 999 );

echo "\n3. Money Stripe takes twice\n";
$paid = $make( 'stripe-twice@example.test' );
$api->routes = array( 'POST /v1/payment_intents' => array( 'ok' => true, 'status' => 200, 'error' => '', 'code' => '', 'body' => array( 'id' => 'pi_off_session', 'status' => 'succeeded' ) ) );
$processor->process( $paid->get_id() );
$paid_slot  = $slots->find( $paid->get_id(), 0 );
$paid_order = $paid_slot ? wc_get_order( (int) $paid_slot->renewal_order_id ) : null;
if ( ! $paid_order instanceof WC_Order || Charge_Slot_Repository::STATE_PAID !== $paid_slot->state ) {
	submex_test_abort( 'the off-session renewal was not paid' );
}
$session = static fn( string $intent ): array => array(
	'GET /v1/checkout/sessions/cs_' => array( 'ok' => true, 'status' => 200, 'error' => '', 'code' => '', 'body' => array( 'id' => 'cs_x', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_pay', 'payment_intent' => array( 'id' => $intent, 'payment_method' => 'pm_pay' ) ) ),
	'GET /v1/payment_methods/' => array( 'ok' => true, 'status' => 200, 'error' => '', 'code' => '', 'body' => array( 'id' => 'pm_pay', 'customer' => 'cus_pay' ) ),
);
$flags = static fn( int $id ): int => count( array_filter( wp_list_pluck( $free->get( 'activity' )->for_subscription( $id, 50 ), 'message' ), static fn( $message ) => str_contains( (string) $message, 'Refund it in Stripe' ) ) );

$paid_order->update_meta_data( '_submex_stripe_session', 'cs_second' );
$paid_order->save();
$api->routes = $session( 'pi_second_capture' );
( new Stripe_Checkout_Gateway( $api ) )->complete_from_session( wc_get_order( $paid_order->get_id() ) );
$logged = implode( ' | ', wp_list_pluck( $free->get( 'activity' )->for_subscription( $paid->get_id(), 50 ), 'message' ) );
$check( 'a second capture of a paid renewal is flagged for refund', 1 === $flags( $paid->get_id() ) && str_contains( $logged, 'pi_second_capture' ), $logged );
$check( 'and the order keeps the payment it was settled with', 'pi_off_session' === wc_get_order( $paid_order->get_id() )->get_transaction_id() );

$first = $make( 'stripe-once@example.test' );
$checkout_order = wc_create_order();
$checkout_order->set_total( '20' );
$checkout_order->update_meta_data( '_submex_subscription_id', $first->get_id() );
$checkout_order->update_meta_data( '_submex_stripe_session', 'cs_once' );
$checkout_order->save();
$made[]      = $checkout_order->get_id();
$api->routes = $session( 'pi_checkout_once' );
( new Stripe_Checkout_Gateway( $api ) )->complete_from_session( wc_get_order( $checkout_order->get_id() ) );
$check( 'a checkout Stripe confirms is paid', wc_get_order( $checkout_order->get_id() )->is_paid() && 'pi_checkout_once' === wc_get_order( $checkout_order->get_id() )->get_transaction_id() );
( new Stripe_Checkout_Gateway( $api ) )->complete_from_session( wc_get_order( $checkout_order->get_id() ) );
$check( 'coming back from Stripe again is not a second capture', 0 === $flags( $first->get_id() ) && ! str_contains( implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $checkout_order->get_id() ) ), 'content' ) ), 'second payment' ) );

// ---- clean up -----------------------------------------------------------------------
remove_filter( 'submex_gateway_for_subscription', $to_stripe, 10 );
WC()->cart->empty_cart();
$plain->delete( true );
$owed->delete( true );

global $wpdb;
foreach ( $made as $id ) {
	$renewals = $wpdb->get_col( $wpdb->prepare( 'SELECT renewal_order_id FROM %i WHERE subscription_id = %d AND renewal_order_id IS NOT NULL', $wpdb->prefix . 'submex_charge_slot', $id ) );
	foreach ( $renewals as $renewal_id ) {
		$r = wc_get_order( (int) $renewal_id );
		$r && $r->delete( true );
	}
	$free->get( 'scheduler' )->unschedule( $id );
	$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'submex_activity', array( 'subscription_id' => $id ), array( '%d' ) );
	$o = wc_get_order( $id );
	$o && $o->delete( true );
}

submex_test_done( $fail );
