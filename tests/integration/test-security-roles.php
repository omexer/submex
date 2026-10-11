<?php
/**
 * A subscription can never make anyone an administrator, or take a store role away.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Lifecycle\Role_Management;

require __DIR__ . '/bootstrap.php';

$user_with = static function ( string $role ): WP_User {
	$id = wp_insert_user(
		array(
			'user_login' => 'sk_role_' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'sb-role-' . wp_generate_password( 6, false, false ) . '@example.test',
			'role'       => $role,
		)
	);

	return get_userdata( $id );
};

$subscribe = static function ( WP_User $user ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( $user->ID );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	clean_user_cache( $user->ID );

	return $s;
};

$before = get_option( 'submex_active_role', null );
$made   = array();

// The rule itself.
$check( 'administrator is never grantable', ! Role_Management::is_grantable( 'administrator' ) );
$check( 'nor shop manager', ! Role_Management::is_grantable( 'shop_manager' ) );
$check( 'nor editor, which can post unfiltered HTML', ! Role_Management::is_grantable( 'editor' ) );
$check( 'subscriber is', Role_Management::is_grantable( 'subscriber' ) );
$check( 'an unknown role is not', ! Role_Management::is_grantable( 'no_such_role' ) );
$check( 'the dropdown offers no administrative role', ! array_intersect( array( 'administrator', 'shop_manager', 'editor' ), array_keys( Role_Management::grantable_roles() ) ) );

// WooCommerce's settings API throws out a value that is not one of the options.
$settings = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $page ) {
	if ( 'submex' === $page->get_id() ) {
		$settings = $page->get_settings_for_section( '' );
	}
}
// Only the role field: save_fields() writes every field it is given, unchecked boxes as 'no'.
$role_field = array_values( array_filter( (array) $settings, static fn( $f ) => 'submex_active_role' === ( $f['id'] ?? '' ) ) );
WC_Admin_Settings::save_fields( $role_field, array( 'submex_active_role' => 'administrator' ) );
$check( 'saving Administrator through the settings form is refused', 'administrator' !== get_option( 'submex_active_role' ), get_option( 'submex_active_role' ) );

// The attack, with the value planted directly as an older version could have stored it.
update_option( 'submex_active_role', 'administrator' );
$manager = $user_with( 'shop_manager' );
$made[]  = $subscribe( $manager );
$check( 'a Shop Manager buying a subscription does not become an administrator', ! user_can( $manager->ID, 'manage_options' ) );

$customer = $user_with( 'customer' );
$made[]   = $subscribe( $customer );
$check( 'nor does a customer', ! user_can( $customer->ID, 'manage_options' ) );

// set_role() replaces every role, so the store's own staff must be left alone.
update_option( 'submex_active_role', 'subscriber' );
$staff  = $user_with( 'shop_manager' );
$made[] = $subscribe( $staff );
$check( 'a Shop Manager who buys a subscription keeps the store', user_can( $staff->ID, 'manage_woocommerce' ), get_userdata( $staff->ID )->roles );

// And the setting still does its job for the people it is for.
$member = $user_with( 'customer' );
$made[] = $subscribe( $member );
$check( 'a customer is given the safe role', in_array( 'subscriber', get_userdata( $member->ID )->roles, true ), get_userdata( $member->ID )->roles );

foreach ( $made as $s ) {
	$s->delete( true );
}
foreach ( array( $manager, $customer, $staff, $member ) as $u ) {
	wp_delete_user( $u->ID );
}
null === $before ? delete_option( 'submex_active_role' ) : update_option( 'submex_active_role', $before );

submex_test_done( $fail );
