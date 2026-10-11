<?php
/**
 * A normal product's files bought on a subscription line go when that subscription ends; the same
 * product bought once keeps them; a subscription product's files are gated as they always were.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$plugin = \Submex\Plugin::instance();

$saved_taxes = get_option( 'woocommerce_calc_taxes', null );
update_option( 'woocommerce_calc_taxes', 'no' );

$file = new WC_Product_Download();
$file->set_name( 'pack.zip' );
$file->set_file( content_url( '/uploads/sbt-pack.zip' ) );

$product = new WC_Product_Simple();
$product->set_name( 'SK Line downloads pack' );
$product->set_status( 'publish' );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->set_downloadable( true );
$product->set_downloads( array( $file ) );
$product->save();

$sub_product = new \Submex\Product\Simple_Subscription();
$sub_product->set_name( 'SK Line downloads subscription' );
$sub_product->set_status( 'publish' );
$sub_product->set_regular_price( '10' );
$sub_product->set_virtual( true );
$sub_product->set_downloadable( true );
$sub_product->set_downloads( array( $file ) );
$sub_product->update_meta_data( \Submex\Product\Subscription_Product::META_PERIOD, 'month' );
$sub_product->update_meta_data( \Submex\Product\Subscription_Product::META_INTERVAL, 1 );
$sub_product->save();
$sub_product = wc_get_product( $sub_product->get_id() );

$customer = wp_insert_user(
	array(
		'user_login' => 'sbt_dl_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'sbt-dl-' . wp_generate_password( 6, false ) . '@example.test',
		'role'       => 'customer',
	)
);
if ( is_wp_error( $customer ) ) {
	submex_test_abort( 'could not create the customer' );
}

// What Pro does: the order line carries the choice, and the filter answers from it.
$is_line = static fn( $create, $line ) => '' !== (string) $line->get_meta( '_sbt_subscribe' ) ? true : $create;
add_filter( 'submex_create_subscription_for_item', $is_line, 10, 2 );

$orders = array();
$buy    = static function ( WC_Product $p, bool $subscribe, array $props = array() ) use ( $customer, $plugin, &$orders ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer ) + $props );
	$item  = new WC_Order_Item_Product();
	$item->set_product( $p );
	$item->set_quantity( 1 );
	$item->set_subtotal( $p->get_price() );
	$item->set_total( $p->get_price() );
	if ( $subscribe ) {
		$item->add_meta_data( '_sbt_subscribe', 'yes', true );
	}
	$order->add_item( $item );
	$order->set_billing_email( 'sbt-dl@example.test' );
	$order->calculate_totals();
	$order->save();
	$orders[] = $order->get_id();
	$plugin->get( 'subscription_factory' )->from_checkout( $order->get_id() );
	$order = wc_get_order( $order->get_id() );
	$order->update_status( 'completed' );
	return wc_get_order( $order->get_id() );
};

$listed = static function () use ( $customer ): array {
	wp_set_current_user( $customer );
	$ids = array_map( static fn( $d ) => (int) $d['order_id'], wc_get_customer_available_downloads( $customer ) );
	wp_set_current_user( 1 );
	sort( $ids );
	return $ids;
};
$on_order = static function ( WC_Order $order ) use ( $customer ): int {
	wp_set_current_user( $customer );
	$count = count( wc_get_order( $order->get_id() )->get_downloadable_items() );
	wp_set_current_user( 1 );
	return $count;
};

echo "\n1. A normal product bought on a subscription line, and again once\n";
$subscribed = $buy( $product, true );
$sub        = wc_get_order( (int) $subscribed->get_meta( '_submex_subscription_id' ) );
if ( ! $sub instanceof Subscription ) {
	submex_test_abort( 'no subscription was created for the line' );
}
$orders[] = $sub->get_id();
$once     = $buy( $product, false );
$check( 'the one-off order started no subscription', ! $once->get_meta( '_submex_subscription_id' ) );
$check( 'while the subscription is active, both orders list the file', array( $subscribed->get_id(), $once->get_id() ) === $listed(), $listed() );

$renewal = $buy( $product, false, array( 'created_via' => 'submex_renewal' ) );
$renewal->update_meta_data( '_submex_subscription_id', $sub->get_id() );
$renewal->save();
$check( 'and so does its renewal order', in_array( $renewal->get_id(), $listed(), true ), $listed() );

$sub->transition_to( Subscription_Status::Cancelled );
$sub->save();
$check( 'once it ends, only the one-off purchase keeps the file', array( $once->get_id() ) === $listed(), $listed() );
$account = array_values( array_map( static fn( $d ) => (int) $d['order_id'], ( new WC_Customer( $customer ) )->get_downloadable_products() ) );
$check( 'My Account lists the same', array( $once->get_id() ) === $account, $account );
$check( 'the subscription order shows no file', 0 === $on_order( $subscribed ) );
$check( 'nor does the renewal', 0 === $on_order( $renewal ) );
$check( 'the one-off order still does', 1 === $on_order( $once ) );

echo "\n2. Nothing hooked in\n";
remove_filter( 'submex_create_subscription_for_item', $is_line, 10 );
$plain = $buy( $product, true );
$check( 'the same line starts no subscription and keeps its file', ! $plain->get_meta( '_submex_subscription_id' ) && in_array( $plain->get_id(), $listed(), true ), $listed() );

echo "\n3. A subscription product, as before\n";
$bought = $buy( $sub_product, false );
$own    = wc_get_order( (int) $bought->get_meta( '_submex_subscription_id' ) );
if ( $own instanceof Subscription ) {
	$orders[] = $own->get_id();
}
$check( 'its file is listed while the subscription is live', in_array( $bought->get_id(), $listed(), true ), $listed() );
if ( $own instanceof Subscription ) {
	$own->transition_to( Subscription_Status::Cancelled );
	$own->save();
}
$check( 'and withdrawn once it ends', ! in_array( $bought->get_id(), $listed(), true ), $listed() );

foreach ( array_unique( $orders ) as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
$product->delete( true );
$sub_product->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
null === $saved_taxes ? delete_option( 'woocommerce_calc_taxes' ) : update_option( 'woocommerce_calc_taxes', $saved_taxes );

submex_test_done( $fail );
