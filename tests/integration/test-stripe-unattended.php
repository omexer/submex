<?php
/**
 * Stripe outcomes that arrive without the customer: an unknown charge is matched to its own
 * attempt only, a payment made on the pay page counts even if they never came back, slow
 * debits wait instead of failing, the store's own errors are not the customer's decline,
 * refunds reach Stripe, and switching Stripe off keeps saved cards renewing.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;
use Submex\Gateways\Stripe\Stripe_Gateway;

require __DIR__ . '/bootstrap.php';

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

$ok   = static fn( array $body ): array => array( 'ok' => true, 'status' => 200, 'body' => $body, 'error' => '', 'code' => '' );
$err  = static fn( int $status, string $code ): array => array( 'ok' => false, 'status' => $status, 'body' => array(), 'error' => $code, 'code' => $code );
$made = array();

add_filter( 'submex_gateway_for_subscription', static fn( $gateway, $s ) => Stripe_Gateway::ID === $s->get_payment_method() ? new Stripe_Gateway( $stripe ) : $gateway, 10, 2 );

$subscribe = static function () use ( &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Stripe_Gateway::ID );
	$s->set_billing_email( 'sb-unattended@example.test' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$s->update_meta_data( Stripe_Gateway::META_CUSTOMER, 'cus_saved' );
	$s->update_meta_data( Stripe_Gateway::META_METHOD, 'pm_saved' );

	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Unattended probe', 'product_id' => submex_test_product()->get_id(), 'quantity' => 1, 'subtotal' => '29', 'total' => '29' ) );
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

$renewal = static function ( Subscription $s ): ?WC_Order {
	$orders = array_values(
		array_filter(
			wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'created_via' => 'submex_renewal' ) ),
			static fn( $o ) => (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id()
		)
	);

	return $orders[0] ?? null;
};

$process = static function ( Subscription $s ): Subscription {
	\Submex\Plugin::instance()->get( 'processor' )->process( $s->get_id() );

	return wc_get_order( $s->get_id() );
};

$slot = static function ( Subscription $s, int $index = 0 ): ?object {
	return \Submex\Plugin::instance()->get( 'charge_slots' )->find( $s->get_id(), $index );
};

echo "\n1. An unknown outcome is matched to its own attempt\n";
$gateway        = new Stripe_Gateway( $stripe );
$s              = $subscribe();
$stripe->routes = array( 'GET /v1/payment_intents/search' => $ok( array( 'data' => array( array( 'id' => 'pi_other_period', 'status' => 'succeeded', 'created' => time() ) ) ) ) );
$stripe->calls  = array();
$gateway->reconcile( $s, 'key-this-attempt' );
$query = urldecode( $stripe->sent( 'GET /v1/payment_intents/search' )[0][0] ?? '' );
$check( 'Stripe is searched by this attempt\'s key, not the subscription', str_contains( $query, 'metadata["submex_key"]:"key-this-attempt"' ), $query );
$stripe->routes = array( 'POST /v1/payment_intents' => $ok( array( 'id' => 'pi_new', 'status' => 'succeeded' ) ) );
$stripe->calls  = array();
$probe          = wc_create_order();
$probe->set_currency( 'USD' );
$probe->set_total( '29' );
$probe->save();
$made[] = $probe;
$gateway->charge_renewal( $s, $probe, 'key-sent' );
$check( 'and every charge carries its key for that search', 'key-sent' === ( $stripe->sent( 'POST /v1/payment_intents' )[0][1]['metadata']['submex_key'] ?? '' ) );

// A charge sent by the previous version, left charging across the upgrade.
$s              = $subscribe();
$stripe->routes = array( 'POST /v1/payment_intents' => $err( 500, 'api_error' ) );
\Submex\Plugin::instance()->get( 'processor' )->process( $s->get_id() );
$open           = \Submex\Plugin::instance()->get( 'charge_slots' )->latest_unsettled( $s->get_id() );
$stripe->routes = array(
	'GET /v1/payment_intents/search?query=' . rawurlencode( 'metadata["submex_key"]' ) => $ok( array( 'data' => array() ) ),
	'GET /v1/payment_intents/search'                                                  => $ok(
		array(
			'data' => array(
				array( 'id' => 'pi_old_other', 'status' => 'succeeded', 'created' => time(), 'metadata' => array( 'submex_subscription' => (string) $s->get_id(), 'submex_order' => '999999' ) ),
				array( 'id' => 'pi_old_this', 'status' => 'succeeded', 'created' => time(), 'metadata' => array( 'submex_subscription' => (string) $s->get_id(), 'submex_order' => (string) $open->renewal_order_id ) ),
			),
		)
	),
);
$verdict = ( new Stripe_Gateway( $stripe ) )->reconcile( $s, 'key-from-before' );
$check( 'an older charge with no key is found by its own renewal order', $verdict && $verdict->is_success() && 'pi_old_this' === $verdict->reference, $verdict ? $verdict->describe() : null );

$conflict = static fn( $pre, $args, $url ) => str_starts_with( $url, 'https://api.stripe.com' )
	? array( 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters.' ) ) ), 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array(), 'filename' => null )
	: $pre;
add_filter( 'pre_http_request', $conflict, 10, 3 );
$result = ( new Stripe_Gateway( new Stripe_Client( 'sk_test_conflict' ) ) )->charge_renewal( $subscribe(), $probe, 'key-conflict' );
remove_filter( 'pre_http_request', $conflict, 10 );
$check( 'Stripe\'s idempotency conflict, which has no code, is retried and not a decline', $result->is_transient(), $result->describe() );

echo "\n2. Paid on the pay page, never came back\n";
$s              = $subscribe();
$stripe->routes = array( 'POST /v1/payment_intents' => $err( 402, 'authentication_required' ) );
$s              = $process( $s );
$order          = $renewal( $s );
$check( 'the renewal waits for the customer', $order && Subscription_Status::OnHold === $s->get_status_enum(), $s->get_status() );
$order->update_meta_data( '_submex_stripe_session', 'cs_paid_page' );
$order->save();
$stripe->routes = array(
	'GET /v1/checkout/sessions/cs_paid_page' => $ok( array( 'id' => 'cs_paid_page', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_saved', 'payment_intent' => array( 'id' => 'pi_paid_page', 'payment_method' => 'pm_new_card' ) ) ),
	'POST /v1/payment_intents'               => $ok( array( 'id' => 'pi_second_charge', 'status' => 'succeeded' ) ),
);
$stripe->calls = array();
( new Stripe_Checkout_Gateway( $stripe ) )->check_session( $order->get_id() );
$order = wc_get_order( $order->get_id() );
$s     = wc_get_order( $s->get_id() );
$check( 'the scheduled check finds the payment', $order->is_paid() && 'pi_paid_page' === $order->get_transaction_id(), array( $order->get_status(), $order->get_transaction_id() ) );
$check( 'and the subscription is active again', Subscription_Status::Active === $s->get_status_enum(), $s->get_status() );
$check( 'without charging the card a second time', array() === $stripe->sent( 'POST /v1/payment_intents' ), $stripe->calls );

$s              = $subscribe();
$stripe->routes = array( 'POST /v1/payment_intents' => $err( 402, 'card_declined' ) );
$s              = $process( $s );
$order          = $renewal( $s );
$order->update_meta_data( '_submex_stripe_session', 'cs_paid_page' );
$order->save();
$stripe->routes = array(
	'GET /v1/checkout/sessions/cs_paid_page' => $ok( array( 'id' => 'cs_paid_page', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_saved', 'payment_intent' => array( 'id' => 'pi_paid_page', 'payment_method' => 'pm_new_card' ) ) ),
	'POST /v1/payment_intents'               => $ok( array( 'id' => 'pi_second_charge', 'status' => 'succeeded' ) ),
);
$stripe->calls = array();
$result        = ( new Stripe_Gateway( $stripe ) )->charge_renewal( $s, $order, 'key-retry' );
$check( 'a retry of a renewal already paid on its page takes no second payment', $result->is_success() && 'pi_paid_page' === $result->reference && array() === $stripe->sent( 'POST /v1/payment_intents' ), $result->describe() );
$check( 'and renewals follow the card the customer paid with', 'pm_new_card' === wc_get_order( $s->get_id() )->get_meta( Stripe_Gateway::META_METHOD ) );

echo "\n3. A debit that takes days\n";
$s              = $subscribe();
$stripe->routes = array( 'POST /v1/payment_intents' => $ok( array( 'id' => 'pi_debit', 'status' => 'processing' ) ) );
$s              = $process( $s );
$order          = $renewal( $s );
$check( 'is pending, not declined', 'pending' === ( $slot( $s )->state ?? '' ) && Subscription_Status::Active === $s->get_status_enum(), array( $slot( $s )->state ?? null, $s->get_status() ) );
$check( 'and Stripe is asked again later', (bool) as_next_scheduled_action( Stripe_Gateway::ACTION_CHECK_INTENT, array( 'order_id' => $order->get_id(), 'intent_id' => 'pi_debit' ) ) );
$stripe->routes = array( 'GET /v1/payment_intents/pi_debit' => $ok( array( 'id' => 'pi_debit', 'status' => 'succeeded' ) ) );
( new Stripe_Gateway( $stripe ) )->check_intent( $order->get_id(), 'pi_debit' );
$check( 'which settles it once it clears', 'paid' === ( $slot( $s )->state ?? '' ) && wc_get_order( $order->get_id() )->is_paid(), $slot( $s )->state ?? null );
as_unschedule_all_actions( Stripe_Gateway::ACTION_CHECK_INTENT );

echo "\n4. The store's problems are not the customer's decline\n";
foreach ( array( array( 401, 'api_key_expired' ), array( 429, 'rate_limit' ), array( 409, 'idempotency_key_in_use' ), array( 400, 'idempotency_error' ) ) as [ $status, $code ] ) {
	$stripe->routes = array( 'POST /v1/payment_intents' => $err( $status, $code ) );
	$result         = ( new Stripe_Gateway( $stripe ) )->charge_renewal( $subscribe(), $probe, 'key-' . $code );
	$check( "{$status} {$code} is retried, not a decline", $result->is_transient() && ! $result->is_definitive_decline(), $result->describe() );
}

$stripe->routes = array( 'POST /v1/payment_intents' => $err( 401, 'api_key_expired' ) );
( new Stripe_Gateway( $stripe ) )->charge_renewal( $subscribe(), $probe, 'key-revoked' );
$check( 'a refused key is remembered for the admin warning', (bool) get_option( Stripe_Gateway::OPTION_KEY_REJECTED ) );
$stripe->routes = array( 'POST /v1/payment_intents' => $ok( array( 'id' => 'pi_fixed', 'status' => 'succeeded' ) ) );
( new Stripe_Gateway( $stripe ) )->charge_renewal( $subscribe(), $probe, 'key-fixed' );
$check( 'and forgotten once a charge goes through', ! get_option( Stripe_Gateway::OPTION_KEY_REJECTED ) );

echo "\n5. Refunds reach Stripe\n";
$paid = wc_create_order();
$paid->set_currency( 'USD' );
$paid->set_total( '29' );
$paid->set_payment_method( Stripe_Gateway::ID );
$paid->set_transaction_id( 'pi_refundable' );
$paid->save();
$made[]         = $paid;
$stripe->routes = array( 'POST /v1/refunds' => $ok( array( 'id' => 're_harness' ) ) );
$stripe->calls  = array();
$refunded       = ( new Stripe_Checkout_Gateway( $stripe ) )->process_refund( $paid->get_id(), 10.5 );
$sent           = $stripe->sent( 'POST /v1/refunds' )[0][1] ?? array();
$check( 'a partial refund is sent for that payment and amount', true === $refunded && 'pi_refundable' === ( $sent['payment_intent'] ?? '' ) && 1050 === ( $sent['amount'] ?? null ), array( $refunded, $sent ) );

echo "\n6. Stripe switched off for new sign-ups\n";
$enabled  = get_option( 'submex_stripe_enabled' );
$services = new ReflectionProperty( \Submex\Plugin::class, 'services' );
$booted   = $services->getValue( \Submex\Plugin::instance() );
$services->setValue( \Submex\Plugin::instance(), array( 'stripe_client' => new Stripe_Client( 'sk_test_switched_off' ) ) + $booted );
update_option( 'submex_stripe_enabled', 'no' );
$registry = new \Submex\Gateways\Gateway_Registry();
do_action( 'submex_register_gateways', $registry );
$check( 'saved cards keep renewing through Stripe', null !== $registry->get( Stripe_Gateway::ID ) );
update_option( 'submex_stripe_enabled', $enabled );
$services->setValue( \Submex\Plugin::instance(), $booted );

foreach ( $made as $thing ) {
	if ( $thing instanceof Subscription && ( $r = $renewal( $thing ) ) ) {
		$r->delete( true );
	}
	$thing->delete( true );
}

submex_test_done( $fail );
