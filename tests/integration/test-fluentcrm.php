<?php
/**
 * FluentCRM tags and lists follow the subscription in the free plugin, with or without Pro:
 * picked per product, given while it is live, kept through a grace period, and only what
 * Submex added is taken back, once.
 *
 * Runs against a stand-in of FluentCRM's API, so no real CRM is touched.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Integrations\FluentCRM;
use Submex\Integrations\Integrations;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

if ( function_exists( 'FluentCrmApi' ) ) {
	submex_test_abort( 'FluentCRM is active; this test drives a stand-in of it and must not reach a real CRM' );
}

$pro   = (bool) did_action( 'submex_pro_loaded' );
$label = $pro ? 'with Pro' : 'without Pro';

$tiles = static function (): array {
	wp_set_current_user( 1 );
	$items = rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/integrations' ) )->get_data()['integrations'] ?? array();
	return array_values( array_filter( (array) $items, static fn( $i ) => 'FluentCRM' === ( $i['title'] ?? '' ) ) );
};
$page = static function (): string {
	wp_set_current_user( 1 );
	ob_start();
	( new \Submex\Admin\Integrations_Page() )->render();
	$html  = (string) ob_get_clean();
	$at    = (int) strpos( $html, '>FluentCRM<' );
	$start = (int) strrpos( substr( $html, 0, $at ), '<div class="submex-tile">' );
	$end   = strpos( $html, '<div class="submex-tile">', $at );
	return substr( $html, $start, false === $end ? null : $end - $start );
};

echo "\n1. Registration ({$label})\n";
$boot_registry = \Submex\Plugin::instance()->get( 'integrations' );
$check( 'the engine picks integrations after plugins loading later than it have booted (FluentCRM boots on plugins_loaded 10)', 20 === has_action( 'plugins_loaded', array( $boot_registry, 'register' ) ) && false === has_action( 'submex_loaded', array( $boot_registry, 'register' ) ) );
$slugs = array_map( static fn( $i ) => $i->slug(), Integrations::integrations() );
$check( "{$label}, FluentCRM is registered exactly once", 1 === count( array_keys( $slugs, 'fluentcrm', true ) ), $slugs );
$check( 'without FluentCRM active it is not available', ! in_array( 'fluentcrm', Integrations::available(), true ) );
$tile = $tiles();
$check( "{$label}, the Integrations screen lists it once", 1 === count( $tile ), $tile );
$check( 'not active, with an Install button for its WordPress.org plugin', false === ( $tile[0]['active'] ?? null ) && 'fluent-crm' === ( $tile[0]['install'] ?? '' ), $tile );
$check( 'saying it is set per product', str_contains( (string) ( $tile[0]['hint'] ?? '' ), 'per product' ), $tile );
if ( ! $pro ) {
	$all = rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/integrations' ) )->get_data()['integrations'] ?? array();
	$check( 'without Pro it is the only tile: nothing locked or upsold', array( 'FluentCRM' ) === array_column( (array) $all, 'title' ), array_column( (array) $all, 'title' ) );
}
wp_set_current_user( 0 );
$check( 'someone who cannot manage subscriptions does not get the list', in_array( rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/integrations' ) )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( 1 );

require __DIR__ . '/stand-ins/fluentcrm.php';
$fcrm = &$GLOBALS['submex_fcrm'];

$tile = $tiles();
$check( 'with FluentCRM active the tile says Connected and offers no install', true === ( $tile[0]['active'] ?? null ) && '' === ( $tile[0]['install'] ?? 'x' ), $tile );
$check( 'and the screen shows it', str_contains( $page(), '>Connected<' ) && ! str_contains( $page(), 'submex-install' ), $page() );

$since_was = get_option( FluentCRM::OPTION_SINCE, null );

// The registry at boot saw no FluentCRM, so wire one now, as a request with it active would.
$registry = new Integrations();
$registry->register();
$check( 'registering it starts recording what Submex adds', (int) get_option( FluentCRM::OPTION_SINCE ) > 0 && (int) get_option( FluentCRM::OPTION_SINCE ) <= time() );
$check( 'and it is routed to', isset( $registry->all()['fluentcrm'] ) && $registry->all()['fluentcrm'] instanceof FluentCRM );

// ---- Fixtures ----------------------------------------------------------------------------
$product = new \Submex\Product\Simple_Subscription();
$product->set_name( 'SK FluentCRM' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$product->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
$product->save();

echo "\n2. Product fields\n";
$product->update_meta_data( FluentCRM::PRODUCT_TAGS, array( 7 ) );
ob_start();
do_action( 'submex_product_subscription_fields', $product );
$fields = (string) ob_get_clean();
$check( 'the product shows a tags and a lists picker', str_contains( $fields, 'name="_submex_fluentcrm_tags[]"' ) && str_contains( $fields, 'name="_submex_fluentcrm_lists[]"' ), $fields );
$check( "offering FluentCRM's own tags and lists", str_contains( $fields, '>VIP</option>' ) && str_contains( $fields, '>Members newsletter</option>' ), $fields );
$check( 'with the saved ones selected', (bool) preg_match( '/<option value="7"[^>]*selected/', $fields ) && ! preg_match( '/<option value="5"[^>]*selected/', $fields ), $fields );

$_POST[ FluentCRM::PRODUCT_TAGS ]  = array( '5', '7', '-4', 'x', '5' );
$_POST[ FluentCRM::PRODUCT_LISTS ] = array( '20' );
wp_set_current_user( 0 );
do_action( 'submex_save_product_subscription_fields', $product );
$check( 'someone who cannot edit the product saves nothing', array( 7 ) === $product->get_meta( FluentCRM::PRODUCT_TAGS ) && '' === $product->get_meta( FluentCRM::PRODUCT_LISTS ) );
wp_set_current_user( 1 );
do_action( 'submex_save_product_subscription_fields', $product );
$product->save();
$product = wc_get_product( $product->get_id() );
$check( 'a store manager saves them, keeping only real ids once each', array( 5, 7 ) === $product->get_meta( FluentCRM::PRODUCT_TAGS ) && array( 20 ) === $product->get_meta( FluentCRM::PRODUCT_LISTS ), array( $product->get_meta( FluentCRM::PRODUCT_TAGS ), $product->get_meta( FluentCRM::PRODUCT_LISTS ) ) );
unset( $_POST[ FluentCRM::PRODUCT_TAGS ], $_POST[ FluentCRM::PRODUCT_LISTS ] );

$users   = array();
$someone = function () use ( &$users ): array {
	$email = 'sb-fcrm-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
	$id    = wp_insert_user(
		array(
			'user_login' => 'submex_fcrm_' . strtolower( wp_generate_password( 8, false, false ) ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => $email,
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		submex_test_abort( 'could not create a customer: ' . $id->get_error_message() );
	}
	$users[] = $id;
	return array( $id, $email );
};

$made = array();
$make = function ( int $user, string $email, int $product_id, int $variation_id = 0 ) use ( &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( $user );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_address( array( 'first_name' => 'Fluent', 'email' => $email, 'country' => 'US' ), 'billing' );
	$i = new WC_Order_Item_Product();
	$i->set_props( array( 'name' => 'Fluent', 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => 1, 'subtotal' => '10', 'total' => '10' ) );
	$s->add_item( $i );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$made[] = $s->get_id();
	return $s;
};
$move = static function ( Subscription $s, Subscription_Status $to ): Subscription {
	$s = wc_get_order( $s->get_id() );
	$s->transition_to( $to );
	$s->save();
	return wc_get_order( $s->get_id() );
};
$holds = static fn( string $email, string $relation ): array => array_values( $GLOBALS['submex_fcrm']['contacts'][ $email ][ $relation ] ?? array() );
$calls = static fn( string $email, string $method ): array => array_values( array_filter( $GLOBALS['submex_fcrm']['calls'], static fn( $c ) => $c[1] === $email && $c[0] === $method ) );

$events = array(
	'granted' => 0,
	'revoked' => 0,
);
$count  = static function ( $slug ) use ( &$events ): void {
	if ( 'fluentcrm' === $slug ) {
		++$events[ str_replace( 'submex_integration_', '', current_action() ) ];
	}
};
add_action( 'submex_integration_granted', $count );
add_action( 'submex_integration_revoked', $count );

echo "\n3. Active, then a grace period, then over\n";
list( $user, $email )              = $someone();
$fcrm['contacts'][ $email ]        = array(
	'tags'  => array( 7 ),
	'lists' => array(),
);
$s                                 = $make( $user, $email, $product->get_id() );
$s                                 = $move( $s, Subscription_Status::Active );
$check( 'activating gives the contact the tags and the list', array( 5, 7 ) === array_values( array_intersect( array( 5, 7 ), $holds( $email, 'tags' ) ) ) && array( 20 ) === $holds( $email, 'lists' ), $fcrm['contacts'][ $email ] );
$check( 'adding only the tag it did not have', array( array( 'attachTags', $email, array( 5 ) ) ) === $calls( $email, 'attachTags' ), $calls( $email, 'attachTags' ) );
$check( 'and recording that one as added', array( 5 ) === get_user_meta( $user, FluentCRM::USER_TAGS, true ) && array( 20 ) === get_user_meta( $user, FluentCRM::USER_LISTS, true ) );

$registry->on_activated( $s );
$registry->on_status_changed( $s, Subscription_Status::Pending->value, Subscription_Status::Active->value );
$check( 'granting again changes nothing and reports nothing', 1 === count( $calls( $email, 'attachTags' ) ) && 1 === count( $calls( $email, 'attachLists' ) ) && 1 === $events['granted'], array( $fcrm['calls'], $events ) );
$check( 'nor does it count the tag the contact had as added', array( 5 ) === get_user_meta( $user, FluentCRM::USER_TAGS, true ) );

$grace = static fn( $ends, $sub ) => $sub->get_id() === $s->get_id() ? time() + DAY_IN_SECONDS : $ends;
add_filter( 'submex_grace_ends_at', $grace, 10, 2 );
$s = $move( $s, Subscription_Status::OnHold );
$check( 'fixture: on hold inside a grace period', $s->in_grace() );
$check( 'the grace period keeps the tags and the list', array( 5, 7 ) === array_values( array_intersect( array( 5, 7 ), $holds( $email, 'tags' ) ) ) && array( 20 ) === $holds( $email, 'lists' ) && 0 === $events['revoked'], $fcrm['contacts'][ $email ] );
remove_filter( 'submex_grace_ends_at', $grace, 10 );

do_action( 'submex_subscription_grace_ended', wc_get_order( $s->get_id() ) );
$check( 'when it ends unpaid the added tag and list go', ! in_array( 5, $holds( $email, 'tags' ), true ) && array() === $holds( $email, 'lists' ), $fcrm['contacts'][ $email ] );
$check( 'the tag the contact had before stays', in_array( 7, $holds( $email, 'tags' ), true ), $fcrm['contacts'][ $email ] );
$check( 'and nothing is left recorded as added', array() === get_user_meta( $user, FluentCRM::USER_TAGS, true ) && array() === get_user_meta( $user, FluentCRM::USER_LISTS, true ) );
do_action( 'submex_subscription_grace_ended', wc_get_order( $s->get_id() ) );
$registry->on_cancelled( wc_get_order( $s->get_id() ) );
$check( 'running the end again takes nothing twice', 1 === count( $calls( $email, 'detachTags' ) ) && 1 === count( $calls( $email, 'detachLists' ) ) && 1 === $events['revoked'], array( $fcrm['calls'], $events ) );

echo "\n4. Paid again, then cancelled\n";
$s = $move( $s, Subscription_Status::Active );
$check( 'paying again gives them back', in_array( 5, $holds( $email, 'tags' ), true ) && array( 20 ) === $holds( $email, 'lists' ) );
$s = $move( $s, Subscription_Status::Cancelled );
$check( 'cancelling takes them back, and leaves the tag the contact had', array( 7 ) === $holds( $email, 'tags' ) && array() === $holds( $email, 'lists' ), $fcrm['contacts'][ $email ] );

echo "\n5. Without grace, on hold takes it at once\n";
list( $user_h, $email_h ) = $someone();
$h                        = $move( $make( $user_h, $email_h, $product->get_id() ), Subscription_Status::Active );
$h                        = $move( $h, Subscription_Status::OnHold );
$check( 'a hold with no grace period takes the tags and list', array() === $holds( $email_h, 'tags' ) && array() === $holds( $email_h, 'lists' ), $fcrm['contacts'][ $email_h ] ?? null );

echo "\n6. Two subscriptions\n";
list( $user2, $email2 ) = $someone();
$one                    = $move( $make( $user2, $email2, $product->get_id() ), Subscription_Status::Active );
$two                    = $move( $make( $user2, $email2, $product->get_id() ), Subscription_Status::Active );
$one                    = $move( $one, Subscription_Status::Cancelled );
$check( 'one ending keeps what the other still grants', array( 5, 7 ) === array_values( array_intersect( array( 5, 7 ), $holds( $email2, 'tags' ) ) ) && array( 20 ) === $holds( $email2, 'lists' ), $fcrm['contacts'][ $email2 ] );
$two = $move( $two, Subscription_Status::Cancelled );
$check( 'the last one ending takes them back, though it added none itself', array() === $holds( $email2, 'tags' ) && array() === $holds( $email2, 'lists' ), $fcrm['contacts'][ $email2 ] );

echo "\n7. A variable product\n";
$parent = new \Submex\Product\Variable_Subscription();
$parent->set_name( 'SK FluentCRM Variable' );
$parent->set_status( 'publish' );
$parent->update_meta_data( Subscription_Product::META_ENABLED, 'yes' );
$parent->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$parent->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
$parent->update_meta_data( FluentCRM::PRODUCT_TAGS, array( 8 ) );
$parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_regular_price( '10' );
$variation->set_status( 'publish' );
$variation->save();
list( $user3, $email3 ) = $someone();
$v                      = $make( $user3, $email3, $parent->get_id(), $variation->get_id() );
$check( 'fixture: the line is the variation, which carries no FluentCRM settings', current( $v->get_items() )->get_product() instanceof WC_Product_Variation && '' === $variation->get_meta( FluentCRM::PRODUCT_TAGS ) );
$v = $move( $v, Subscription_Status::Active );
$check( "the parent's tag is given", array( 8 ) === $holds( $email3, 'tags' ), $fcrm['contacts'][ $email3 ] ?? null );
$v = $move( $v, Subscription_Status::Expired );
$check( 'and taken back when it ends', array() === $holds( $email3, 'tags' ), $fcrm['contacts'][ $email3 ] );

echo "\n8. Granted before Submex recorded what it added\n";
list( $user4, $email4 )      = $someone();
$fcrm['contacts'][ $email4 ] = array(
	'tags'  => array( 5, 7 ),
	'lists' => array( 20 ),
);
$old                         = $make( $user4, $email4, $product->get_id() );
$old->set_date_created( time() - YEAR_IN_SECONDS );
$old->save();
$old = $move( $old, Subscription_Status::Active );
$check( 'fixture: nothing new to add, so nothing is recorded', '' === get_user_meta( $user4, FluentCRM::USER_TAGS, true ) && 0 === count( $calls( $email4, 'attachTags' ) ) );
$old = $move( $old, Subscription_Status::Cancelled );
$check( 'ending it takes back what its product configures, as it always did', array() === $holds( $email4, 'tags' ) && array() === $holds( $email4, 'lists' ), $fcrm['contacts'][ $email4 ] );
$registry->on_cancelled( $old );
$check( 'and ending it again asks FluentCRM for nothing more', 1 === count( $calls( $email4, 'detachTags' ) ) && 1 === count( $calls( $email4, 'detachLists' ) ), $calls( $email4, 'detachTags' ) );

echo "\n9. Refusals\n";
$no_email            = $make( 0, '', $product->get_id() );
$before              = count( $fcrm['calls'] );
$no_email            = $move( $no_email, Subscription_Status::Active );
$check( 'a subscription with no billing email touches no contact', '' === $no_email->get_billing_email() && $before === count( $fcrm['calls'] ) );
$product_none        = submex_test_product();
list( $user6, $email6 ) = $someone();
$plain               = $move( $make( $user6, $email6, $product_none->get_id() ), Subscription_Status::Active );
$check( 'a product with nothing configured touches no contact', ! isset( $fcrm['contacts'][ $email6 ] ) );

class_alias( get_class( new class() {} ), 'SubmexPro\Integrations\FluentCRM' );
$check( 'beside an older Pro that ships its own copy, the free one stands down', ! ( new FluentCRM() )->is_available() && array() === FluentCRM::describe( array() ) );

// ---- Clean up --------------------------------------------------------------------------
remove_action( 'submex_integration_granted', $count );
remove_action( 'submex_integration_revoked', $count );
foreach ( $made as $id ) {
	\Submex\Plugin::instance()->get( 'scheduler' )->unschedule( $id );
	wc_get_order( $id )?->delete( true );
}
foreach ( $users as $id ) {
	wp_delete_user( $id );
}
$variation->delete( true );
$parent->delete( true );
$product->delete( true );
null === $since_was ? delete_option( FluentCRM::OPTION_SINCE ) : update_option( FluentCRM::OPTION_SINCE, $since_was );
wp_set_current_user( 0 );

submex_test_done( $fail );
