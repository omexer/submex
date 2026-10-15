<?php
/**
 * The cancel form says when access ends, as an extension that moves that date sees it.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$customer = wp_insert_user(
	array(
		'user_login' => 'sk_until_' . wp_generate_password( 5, false, false ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => 'sb-until-' . wp_generate_password( 5, false, false ) . '@example.test',
		'role'       => 'customer',
	)
);

$was = get_option( 'submex_cancellation_effective', null );
update_option( 'submex_cancellation_effective', 'period_end' );

$next = time() + 20 * DAY_IN_SECONDS;
$s    = new Subscription();
$s->set_customer_id( $customer );
$s->set_currency( 'USD' );
$s->set_billing_period( 'month' );
$s->set_billing_interval( 1 );
$s->set_address( array( 'first_name' => 'Until', 'email' => 'until@example.test' ), 'billing' );
$s->set_next_payment( gmdate( 'Y-m-d H:i:s', $next ) );
$s->transition_to( Subscription_Status::Pending );
$s->save();
$s->transition_to( Subscription_Status::Active );
$s->save();

$page = static function () use ( $s ): string {
	ob_start();
	\Submex\Plugin::instance()->get( 'account' )->render( (string) $s->get_id() );

	return wp_strip_all_tags( (string) ob_get_clean() );
};
$day  = static fn( int $ts ): string => date_i18n( (string) get_option( 'date_format' ), $ts );

wp_set_current_user( $customer );

$check( 'by default the form gives the next payment date', str_contains( $page(), 'stays active until ' . $day( $next ) ) );

$later = time() + 45 * DAY_IN_SECONDS;
$moved = static fn() => gmdate( 'Y-m-d H:i:s', $later );
add_filter( 'submex_cancel_access_until', $moved );
$check( 'an extension that moves the end is believed', str_contains( $page(), 'stays active until ' . $day( $later ) ) );
remove_filter( 'submex_cancel_access_until', $moved );

$nonsense = static fn() => 'not a date';
add_filter( 'submex_cancel_access_until', $nonsense );
$check( 'a date that is not one names no date', str_contains( $page(), 'stays active until the end of the current period' ) );
remove_filter( 'submex_cancel_access_until', $nonsense );

wp_set_current_user( 0 );
$s->delete( true );
wp_delete_user( $customer );
null === $was ? delete_option( 'submex_cancellation_effective' ) : update_option( 'submex_cancellation_effective', $was );

submex_test_done( $fail );
