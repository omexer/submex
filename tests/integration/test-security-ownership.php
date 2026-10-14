<?php
/**
 * A customer can act on their own subscription and nobody else's, even holding a valid nonce.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	// Handlers redirect and exit once they act; stop at the redirect and read the state.
	final class Submex_Test_Redirected extends Exception {}
}
add_filter(
	'wp_redirect',
	static function ( $to ) {
		throw new Submex_Test_Redirected( (string) $to );
	},
	1
);

$user = static function ( string $name ): int {
	return wp_insert_user(
		array(
			'user_login' => 'sk_' . $name . '_' . wp_generate_password( 5, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'sb-' . $name . '-' . wp_generate_password( 5, false, false ) . '@example.test',
			'role'       => 'customer',
		)
	);
};

$alice = $user( 'alice' );
$bob   = $user( 'bob' );

$subscription_for = static function ( int $owner ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( $owner );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();

	return $s;
};

$state = static function ( int $id ): array {
	$s = wc_get_order( $id );

	return array( $s->get_status(), $s->get_next_payment(), $s->get_meta( '_submex_cancel_reason' ), $s->get_meta( '_submex_auto_renew' ) );
};

$attempt = static function ( callable $handler, array $post ): void {
	$_POST    = $post;
	$_REQUEST = $post;

	try {
		$handler();
	} catch ( Submex_Test_Redirected $e ) {
		unset( $e );
	}

	$_POST    = array();
	$_REQUEST = array();
};

$account = \Submex\Plugin::instance()->get( 'account' );
$events  = \Submex\Plugin::instance()->get( 'retention_events' );
$flow_on = get_option( 'submex_retention_flow', null );
update_option( 'submex_retention_flow', 'yes' );

update_option( 'submex_allow_early_renewal', 'yes' );
update_option( 'submex_allow_auto_renew_toggle', 'yes' );

$actions = static function ( int $id ): array {
	return array(
		'cancel'              => array( 'submex_action' => 'cancel', 'submex_when' => 'immediate', 'submex_subscription' => $id, '_wpnonce' => wp_create_nonce( "submex_cancel_{$id}" ) ),
		'turn off auto-renew' => array( 'submex_action' => 'auto_renew', 'submex_auto_renew' => 'off', 'submex_subscription' => $id, '_wpnonce' => wp_create_nonce( "submex_auto_renew_{$id}" ) ),
		'pay a period early'  => array( 'submex_action' => 'renew_early', 'submex_subscription' => $id, '_wpnonce' => wp_create_nonce( "submex_renew_early_{$id}" ) ),
	);
};

foreach ( array( 'another customer' => $bob, 'a logged-out visitor' => 0 ) as $who => $attacker ) {
	$target = $subscription_for( $alice );
	$id     = $target->get_id();
	wp_set_current_user( $attacker );

	foreach ( $actions( $id ) as $what => $post ) {
		$before = $state( $id );
		$attempt( array( $account, 'handle_actions' ), $post );
		$check( "{$who} cannot {$what} somebody else's subscription", $before === $state( $id ), array( $before, $state( $id ) ) );
	}

	foreach ( array( 'keep', 'continue', 'decline' ) as $choice ) {
		$before = $state( $id );
		$attempt( array( $account, 'handle_actions' ), array( 'submex_action' => 'cancel_flow', 'submex_choice' => $choice, 'submex_reason' => 'too_expensive', 'submex_subscription' => $id, '_wpnonce' => wp_create_nonce( "submex_cancel_flow_{$id}" ) ) );
		$check( "{$who} cannot {$choice} through somebody else's cancellation flow, or write its reason", $before === $state( $id ), array( $before, $state( $id ) ) );
	}
	$check( '  and nothing is logged for it', array() === $events->for_subscription( $id ), $events->for_subscription( $id ) );

	$target->delete( true );
}

// And the owner still can: a check that refuses everybody would pass everything above.
$own  = $subscription_for( $alice );
$when = get_option( 'submex_cancellation_effective', null );
update_option( 'submex_cancellation_effective', 'immediate' );
wp_set_current_user( $alice );
$attempt( array( $account, 'handle_actions' ), $actions( $own->get_id() )['cancel'] );
$check( 'the owner can cancel their own subscription', str_contains( wc_get_order( $own->get_id() )->get_status(), 'cancelled' ), wc_get_order( $own->get_id() )->get_status() );
$own->delete( true );
null === $when ? delete_option( 'submex_cancellation_effective' ) : update_option( 'submex_cancellation_effective', $when );

null === $flow_on ? delete_option( 'submex_retention_flow' ) : update_option( 'submex_retention_flow', $flow_on );
delete_option( 'submex_allow_early_renewal' );
delete_option( 'submex_allow_auto_renew_toggle' );
wp_delete_user( $alice );
wp_delete_user( $bob );

submex_test_done( $fail );
