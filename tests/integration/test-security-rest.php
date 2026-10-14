<?php
/**
 * No Submex REST route answers a visitor or a customer, and a forged PayPal webhook is refused.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$customer = wp_insert_user(
	array(
		'user_login' => 'sk_rest_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => 'sb-rest-' . wp_generate_password( 6, false, false ) . '@example.test',
		'role'       => 'customer',
	)
);

$sub = new Subscription();
$sub->set_customer_id( 1 );
$sub->transition_to( Subscription_Status::Pending );
$sub->save();
$id = $sub->get_id();

// Valid bodies: WordPress validates before it checks permissions.
$bodies = array(
	'#/subscriptions/actions$#'      => array( 'ids' => array( $id ), 'action' => 'cancel' ),
	'#/subscriptions/\d+/actions$#'  => array( 'action' => 'cancel' ),
	'#/health/\d+/actions$#'         => array( 'action' => 'dismiss' ),
	'#/subscriptions/\d+$#'          => array( 'status' => 'submex-cancelled' ),
	'#/settings/\d+$#'               => array( 'values' => array( 'submex_allow_cancellation' => 'no' ) ),
	'#/settings/emails/\d+$#'        => array( 'values' => array( 'subject' => 'Hijacked' ) ),
	'#/retention/reasons$#'          => array( 'label' => 'Hijacked', 'order' => array( 'too_expensive' ) ),
);

$routes = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	// The namespace index is WordPress core's own and public for every plugin.
	if ( ! preg_match( '#^/submex/v1/.#', $route ) || str_contains( $route, 'webhook/paypal' ) ) {
		continue;
	}
	foreach ( $handlers as $handler ) {
		foreach ( array_keys( $handler['methods'] ) as $method ) {
			$routes[ $method . ' ' . $route ] = array( $method, $route );
		}
	}
}

$check( 'the routes were found', count( $routes ) >= 10, count( $routes ) );

foreach ( array( 'a visitor' => 0, 'a customer' => $customer ) as $who => $user ) {
	wp_set_current_user( $user );
	$open = array();

	foreach ( $routes as list( $method, $route ) ) {
		$path    = preg_replace( '#\(\?P<\w+>[^)]+\)#', (string) $id, $route );
		$request = new WP_REST_Request( $method, $path );

		if ( 'GET' !== $method ) {
			foreach ( $bodies as $pattern => $body ) {
				if ( preg_match( $pattern, $path ) ) {
					$request->set_body_params( $body );
					break;
				}
			}
		}

		$status = rest_do_request( $request )->get_status();

		if ( ! in_array( $status, array( 401, 403 ), true ) ) {
			$open[] = "{$method} {$path} -> {$status}";
		}
	}

	$check( "every protected route refuses {$who}", array() === $open, $open );
}

$check( 'and the subscription is untouched', 'submex-pending' === wc_get_order( $id )->get_status(), wc_get_order( $id )->get_status() );

// A forged "payment completed", shaped exactly like a real one.
wp_set_current_user( 0 );
$webhook = get_option( 'submex_paypal_webhook_id', null );

foreach ( array( '' => 'with no webhook ID', 'WH-FAKE' => 'with a webhook ID' ) as $value => $label ) {
	update_option( 'submex_paypal_webhook_id', $value );
	$event_id = 'WH-FORGED-' . wp_generate_password( 8, false, false );
	$request  = new WP_REST_Request( 'POST', '/submex/v1/webhook/paypal' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'id' => $event_id, 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => array( 'id' => 'TXN-FORGED', 'billing_agreement_id' => 'I-ANY' ) ) ) );

	$status = rest_do_request( $request )->get_status();
	$kept   = get_transient( 'submex_pp_evt_body_' . md5( $event_id ) );

	$check( "a forged PayPal webhook is refused {$label}", 401 === $status && false === $kept, array( $status, (bool) $kept ) );
}

null === $webhook ? delete_option( 'submex_paypal_webhook_id' ) : update_option( 'submex_paypal_webhook_id', $webhook );
$sub->delete( true );
wp_delete_user( $customer );

submex_test_done( $fail );
