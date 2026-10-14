<?php
/**
 * My Account links reach the subscription with and without pretty permalinks.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Account_Endpoint;
use Submex\Frontend\MyAccount\Cancel_Flow;

require __DIR__ . '/bootstrap.php';

$customer = wp_insert_user(
	array(
		'user_login' => 'sk_urls_' . wp_generate_password( 5, false, false ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => 'sb-urls-' . wp_generate_password( 5, false, false ) . '@example.test',
		'role'       => 'customer',
	)
);

$s = new Subscription();
$s->set_customer_id( $customer );
$s->set_currency( 'USD' );
$s->set_billing_period( 'month' );
$s->set_billing_interval( 1 );
$s->set_address( array( 'first_name' => 'Urls', 'email' => 'urls@example.test' ), 'billing' );
$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
$s->transition_to( Subscription_Status::Pending );
$s->save();
$s->transition_to( Subscription_Status::Active );
$s->save();
$id = $s->get_id();

$was = get_option( 'permalink_structure' );

$list = static function (): string {
	ob_start();
	\Submex\Plugin::instance()->get( 'account' )->render( '' );

	return (string) ob_get_clean();
};

wp_set_current_user( $customer );

foreach ( array( '' => 'plain', '/%postname%/' => 'pretty' ) as $structure => $label ) {
	update_option( 'permalink_structure', $structure );
	$GLOBALS['wp_rewrite']->init();

	$query = array();
	parse_str( (string) wp_parse_url( Account_Endpoint::url( $id ), PHP_URL_QUERY ), $query );
	$path = (string) wp_parse_url( Account_Endpoint::url( $id ), PHP_URL_PATH );
	$hit  = '' === $structure ? (string) $id === ( $query[ Account_Endpoint::ENDPOINT ] ?? '' ) : str_ends_with( untrailingslashit( $path ), '/' . Account_Endpoint::ENDPOINT . '/' . $id );

	$check( "$label: the subscription's address names it as the endpoint's value", $hit, Account_Endpoint::url( $id ) );
	$check( "$label: the cancel flow keeps that address", str_starts_with( Cancel_Flow::view_url( $s ), Account_Endpoint::url( $id ) ), Cancel_Flow::view_url( $s ) );
	$check( "$label: the list links to it", str_contains( $list(), esc_url( Account_Endpoint::url( $id ) ) ) );
}

update_option( 'permalink_structure', $was );
$GLOBALS['wp_rewrite']->init();
wp_set_current_user( 0 );
$s->delete( true );
wp_delete_user( $customer );

submex_test_done( $fail );
