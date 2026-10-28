<?php
/**
 * A card taken at Stripe checkout can be charged again at renewal.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;
use Submex\Gateways\Stripe\Stripe_Gateway;

require __DIR__ . '/bootstrap.php';

// Stripe itself, answering from a routing table and recording every request.
$stripe = new class( 'sk_test_harness' ) extends Stripe_Client {
	public array $calls  = array();
	public array $routes = array();

	public function post( string $path, array $params, string $idempotency_key = '' ): array {
		$this->calls[] = array( 'POST ' . $path, $params, $idempotency_key );
		return $this->answer( 'POST ' . $path );
	}

	public function get( string $path ): array {
		$this->calls[] = array( 'GET ' . $path, array(), '' );
		return $this->answer( 'GET ' . $path );
	}

	public function sent( string $request ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => str_starts_with( $c[0], $request ) ) );
	}

	private function answer( string $request ): array {
		foreach ( $this->routes as $prefix => $response ) {
			if ( str_starts_with( $request, $prefix ) ) {
				return $response;
			}
		}

		return array( 'ok' => false, 'status' => 404, 'body' => array(), 'error' => 'unrouted: ' . $request, 'code' => '' );
	}
};

$ok      = static fn( array $body ): array => array( 'ok' => true, 'status' => 200, 'body' => $body, 'error' => '', 'code' => '' );
$refused = static fn( string $error ): array => array( 'ok' => false, 'status' => 400, 'body' => array(), 'error' => $error, 'code' => 'invalid_request_error' );

$product = submex_test_product();
$made    = array();

$route_to_harness = static function ( $gateway, $subscription ) use ( $stripe ) {
	return Stripe_Gateway::ID === $subscription->get_payment_method() ? new Stripe_Gateway( $stripe ) : $gateway;
};
add_filter( 'submex_gateway_for_subscription', $route_to_harness, 10, 2 );

// ---- Checkout asks Stripe for a customer -------------------------------------------
$checkout = new Stripe_Checkout_Gateway( $stripe );
$stripe->routes = array( 'POST /v1/checkout/sessions' => $ok( array( 'id' => 'cs_harness', 'url' => 'https://checkout.stripe.test/cs_harness' ) ) );

foreach ( array( '29' => 'payment', '0' => 'setup' ) as $total => $mode ) {
	$order = wc_create_order();
	$order->set_billing_email( 'sb-stripe@example.test' );
	$order->set_currency( 'USD' );
	$order->set_total( $total );
	$order->save();
	$made[] = $order;

	$stripe->calls = array();
	$checkout->process_payment( $order->get_id() );
	$params = $stripe->sent( 'POST /v1/checkout/sessions' )[0][1] ?? array();

	$check( "a {$mode}-mode checkout has Stripe create a customer", $mode === ( $params['mode'] ?? '' ) && 'always' === ( $params['customer_creation'] ?? '' ), $params );
}

// ---- The store's case: paid at checkout, no customer, renewal due ------------------
$first_order = static function ( string $method, string $intent ) use ( &$made ): WC_Order {
	$order = wc_create_order();
	$order->set_billing_email( 'sb-stripe@example.test' );
	$order->set_billing_first_name( 'Harness' );
	$order->set_payment_method( Stripe_Gateway::ID );
	$order->set_transaction_id( $intent );
	$order->update_meta_data( Stripe_Gateway::META_METHOD, $method );
	$order->update_meta_data( Stripe_Gateway::META_CUSTOMER, '' );
	$order->save();
	$made[] = $order;

	return $order;
};

$subscribe = static function ( WC_Order $first ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'day' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Stripe_Gateway::ID );
	$s->set_parent_order_id( $first->get_id() );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );

	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Stripe probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '29', 'total' => '29' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s;

	return $s;
};

$renew = static function ( Subscription $s, bool $even_if_not_due = false ): Subscription {
	\Submex\Plugin::instance()->get( 'processor' )->process( $s->get_id(), $even_if_not_due );

	return wc_get_order( $s->get_id() );
};

$healthy = array(
	'GET /v1/payment_methods/pm_first'          => $ok( array( 'id' => 'pm_first', 'customer' => null ) ),
	'POST /v1/customers'                        => $ok( array( 'id' => 'cus_harness' ) ),
	'POST /v1/payment_methods/pm_first/attach'  => $ok( array( 'id' => 'pm_first', 'customer' => 'cus_harness' ) ),
	'POST /v1/payment_intents'                  => $ok( array( 'id' => 'pi_renewal', 'status' => 'succeeded' ) ),
);

$s              = $subscribe( $first_order( 'pm_first', 'pi_first' ) );
$stripe->routes = $healthy;
$stripe->calls  = array();
$s              = $renew( $s );

$charge = $stripe->sent( 'POST /v1/payment_intents' )[0][1] ?? array();
$paid = wc_get_orders( array( 'limit' => -1, 'meta_key' => '_submex_subscription_id', 'meta_value' => $s->get_id(), 'status' => array( 'processing', 'completed' ), 'type' => 'shop_order' ) );
$paid = array_filter( $paid, static fn( $o ) => (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id() && 'pi_renewal' === $o->get_transaction_id() );
$check( 'the renewal is charged', Subscription_Status::Active === $s->get_status_enum() && 1 === count( $paid ) && strtotime( (string) $s->get_next_payment() ) > time(), array( 'status' => $s->get_status(), 'paid' => count( $paid ), 'next' => $s->get_next_payment() ) );
$check( 'against the card from checkout, through a new customer', 'cus_harness' === ( $charge['customer'] ?? '' ) && 'pm_first' === ( $charge['payment_method'] ?? '' ) && 'true' === ( $charge['off_session'] ?? '' ), $charge );
$check( 'the customer is kept for next time', 'cus_harness' === $s->get_meta( Stripe_Gateway::META_CUSTOMER ) && 'pm_first' === $s->get_meta( Stripe_Gateway::META_METHOD ) );
$key = (string) ( $stripe->sent( 'POST /v1/customers' )[0][2] ?? '' );
$check( 'the customer is created idempotently', str_starts_with( $key, 'submex_customer_' ) && str_ends_with( $key, '_' . $s->get_id() ), $key );

$stripe->calls = array();
$s             = $renew( $s, true );
$check( 'the next renewal charges straight away', 1 === count( $stripe->sent( 'POST /v1/payment_intents' ) ) && array() === $stripe->sent( 'POST /v1/customers' ) && array() === $stripe->sent( 'GET /v1/payment_methods' ), $stripe->calls );

// ---- Stripe refuses the card: on hold with the reason, then Reactivate recovers it --
$s              = $subscribe( $first_order( 'pm_first', 'pi_first' ) );
$stripe->routes = array( 'POST /v1/payment_methods/pm_first/attach' => $refused( 'This PaymentMethod cannot be attached.' ) ) + $healthy;
$stripe->calls  = array();
$s              = $renew( $s );

$check( 'a card Stripe will not save puts the subscription on hold', Subscription_Status::OnHold === $s->get_status_enum(), $s->get_status() );
$check( 'without charging anything', array() === $stripe->sent( 'POST /v1/payment_intents' ) );
$failed = array_values( array_filter( wc_get_orders( array( 'limit' => -1, 'status' => 'failed', 'type' => 'shop_order' ) ), static fn( $o ) => (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id() ) );
$note   = 1 === count( $failed ) ? implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $failed[0]->get_id() ) ), 'content' ) ) : '';
$check( 'and says why', str_contains( $note, 'cannot be attached' ), $note );

$first_key      = $stripe->sent( 'POST /v1/customers' )[0][2] ?? '';
$stripe->routes = $healthy;
$stripe->calls  = array();
$s->transition_to( Subscription_Status::Active, 'Reactivated by the harness.' );
$s->save();
$s = $renew( $s );

$check( 'reactivating charges the renewal it missed', Subscription_Status::Active === $s->get_status_enum() && 1 === count( $stripe->sent( 'POST /v1/payment_intents' ) ), array( 'status' => $s->get_status(), 'calls' => $stripe->calls ) );
$check( 'with the same customer the failed attempt made', $first_key === ( $stripe->sent( 'POST /v1/customers' )[0][2] ?? '' ) );

// ---- Gateway-level shapes -----------------------------------------------------------
$gateway = new Stripe_Gateway( $stripe );
$renewal = wc_create_order();
$renewal->set_currency( 'USD' );
$renewal->set_total( '29' );
$renewal->save();
$made[] = $renewal;

// A first order that recorded the payment but not the card.
$s              = $subscribe( $first_order( '', 'pi_first' ) );
$stripe->routes = array( 'GET /v1/payment_intents/pi_first' => $ok( array( 'id' => 'pi_first', 'payment_method' => 'pm_first' ) ) ) + $healthy;
$stripe->calls  = array();
$result         = $gateway->charge_renewal( $s, $renewal, 'k-intent' );
$check( 'the card is found from the first payment when the order did not keep it', $result->is_success() && 'pm_first' === ( $stripe->sent( 'POST /v1/payment_intents' )[0][1]['payment_method'] ?? '' ), $result->describe() );

// A card Stripe already holds against a customer is charged through that customer.
$s              = $subscribe( $first_order( 'pm_owned', 'pi_first' ) );
$stripe->routes = array( 'GET /v1/payment_methods/pm_owned' => $ok( array( 'id' => 'pm_owned', 'customer' => 'cus_existing' ) ) ) + $healthy;
$stripe->calls  = array();
$result         = $gateway->charge_renewal( $s, $renewal, 'k-owned' );
$check( 'a card that already has a customer keeps it', $result->is_success() && array() === $stripe->sent( 'POST /v1/customers' ) && 'cus_existing' === ( $stripe->sent( 'POST /v1/payment_intents' )[0][1]['customer'] ?? '' ), $stripe->calls );

// Nothing to recover at all.
$s              = $subscribe( $first_order( '', '' ) );
$stripe->calls  = array();
$result         = $gateway->charge_renewal( $s, $renewal, 'k-none' );
$check( 'no card anywhere is a clear hard decline', $result->is_definitive_decline() && str_contains( $result->describe(), 'add one' ) && array() === $stripe->calls, $result->describe() );

// Checkout that came back with a card but no customer (a session started before the fix).
$first          = $first_order( 'pm_first', 'pi_first' );
$s              = $subscribe( $first );
$stripe->routes = $healthy;
$result         = $gateway->create_mandate( $s, $first );
$check( 'checkout without a customer still leaves a chargeable subscription', $result->is_success() && 'cus_harness' === wc_get_order( $s->get_id() )->get_meta( Stripe_Gateway::META_CUSTOMER ), $result->describe() );

// ---- clean up -----------------------------------------------------------------------
remove_filter( 'submex_gateway_for_subscription', $route_to_harness, 10 );

global $wpdb;
foreach ( $made as $o ) {
	if ( $o instanceof Subscription ) {
		$renewals = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %d AND order_id <> %d', $wpdb->prefix . 'wc_orders_meta', '_submex_subscription_id', $o->get_id(), $o->get_id() ) );
		foreach ( $renewals as $id ) {
			$r = wc_get_order( (int) $id );
			$r && $r->delete( true );
		}
		\Submex\Plugin::instance()->get( 'scheduler' )->unschedule( $o->get_id() );
	}
	$fresh = wc_get_order( $o->get_id() );
	$fresh && $fresh->delete( true );
}

submex_test_done( $fail );
