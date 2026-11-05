<?php
/**
 * A customer who cancels keeps the role until the period they paid for ends.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$saved = array( get_option( 'submex_active_role', null ), get_option( 'submex_inactive_role', null ) );
add_role( 'sk_member', 'SK Member', array( 'read' => true ) );
update_option( 'submex_active_role', 'sk_member' );
update_option( 'submex_inactive_role', 'customer' );

$user_id = wp_insert_user( array( 'user_login' => 'sk_role_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer', 'user_email' => 'sk-role-' . wp_generate_password( 6, false, false ) . '@example.test' ) );

$s = new Subscription();
$s->set_customer_id( $user_id );
$s->set_billing_period( 'month' );
$s->set_billing_interval( 1 );
$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) );
$s->transition_to( Subscription_Status::Pending );
$s->save();
$s->transition_to( Subscription_Status::Active );
$s->save();

$role = static fn(): array => (array) get_userdata( $user_id )->roles;
$check( 'active, the customer has the role', in_array( 'sk_member', $role(), true ), $role() );

$s->transition_to( Subscription_Status::PendingCancel );
$s->save();
$check( 'cancelling, they keep it', in_array( 'sk_member', $role(), true ), $role() );

$s->transition_to( Subscription_Status::Cancelled );
$s->save();
$check( 'once it has ended, they lose it', ! in_array( 'sk_member', $role(), true ), $role() );

$s->delete( true );
wp_delete_user( $user_id );
remove_role( 'sk_member' );
foreach ( array( 'submex_active_role', 'submex_inactive_role' ) as $i => $name ) {
	null === $saved[ $i ] ? delete_option( $name ) : update_option( $name, $saved[ $i ] );
}

submex_test_done( $fail );
