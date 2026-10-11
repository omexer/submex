<?php
/**
 * A free trial signs up for nothing, keeps its card, and converts to a paid renewal at the real price.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Gateways\Test_Gateway;

require __DIR__ . '/bootstrap.php';

\Submex\Plugin::instance()->get( 'gateways' )->add(
	new class() implements \Submex\Gateways\Recurring_Gateway {
		public function id(): string { return Test_Gateway::ID; }
		public function title(): string { return 'Harness approver'; }
		public function model(): \Submex\Gateways\Gateway_Model { return \Submex\Gateways\Gateway_Model::Tokenized; }
		public function supports( string $f ): bool { return true; }
		public function create_mandate( Subscription $s, \WC_Order $o ): \Submex\Gateways\Charge_Result { return \Submex\Gateways\Charge_Result::success( 'mandate-' . $s->get_id() ); }
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): \Submex\Gateways\Charge_Result { return \Submex\Gateways\Charge_Result::success( 'charge-' . $r->get_id() ); }
		public function reconcile( Subscription $s, string $k ): ?\Submex\Gateways\Charge_Result { return null; }
		public function cancel_mandate( Subscription $s ): bool { return true; }
		public function update_payment_method( Subscription $s, string $t ): bool { return true; }
	}
);

$id = wp_insert_post( array( 'post_title' => 'SK trial flow', 'post_type' => 'product', 'post_status' => 'publish' ) );
$p  = wc_get_product( $id );
$p->set_regular_price( '20' ); $p->set_price( '20' );
$p->update_meta_data( '_submex_enabled', 'yes' );
$p->update_meta_data( '_submex_period', 'month' );
$p->update_meta_data( '_submex_interval', 1 );
$p->update_meta_data( '_submex_trial_days', 14 );
$p->update_meta_data( '_submex_trial_period', 'day' );
$p->save();
$p = wc_get_product( $id );

$addr = array( 'first_name' => 'Trial', 'last_name' => 'Buyer', 'address_1' => '1 Test St', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US', 'email' => 'trial@example.test' );

// The cart is what prices a checkout, so go through it.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $id );
WC()->cart->calculate_totals();
$check( 'cart costs nothing today', '0.00' === wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ), WC()->cart->get_total( 'edit' ) );

$order = wc_create_order( array( 'customer_id' => 1 ) );
foreach ( WC()->cart->get_cart() as $item ) { $order->add_product( $item['data'], $item['quantity'] ); }
$order->set_address( $addr, 'billing' );
$order->set_address( $addr, 'shipping' );
$order->set_payment_method( Test_Gateway::ID );
$order->calculate_totals();
$order->save();
$check( 'order totals nothing today', '0.00' === wc_format_decimal( $order->get_total(), 2 ), $order->get_total() );
$check( 'order still asks for payment', $order->needs_payment(), $order->get_status() );

do_action( 'woocommerce_checkout_order_processed', $order->get_id() );
$order = wc_get_order( $order->get_id() );
$order->update_status( 'processing' );

$sub = wc_get_order( (int) wc_get_order( $order->get_id() )->get_meta( '_submex_subscription_id' ) );
$check( 'subscription created', $sub instanceof Subscription );
if ( ! $sub instanceof Subscription ) { submex_test_abort( 'cannot continue without it' ); }

$check( 'status is trialling', str_contains( $sub->get_status(), 'trialling' ), $sub->get_status() );
$check( 'recurring amount is the real price, not the trial total', '20.00' === wc_format_decimal( $sub->get_total(), 2 ), $sub->get_total() );
$check( 'trial ends in 14 days', substr( (string) $sub->get_trial_end(), 0, 10 ) === gmdate( 'Y-m-d', strtotime( '+14 days' ) ), $sub->get_trial_end() );
$check( 'first payment falls on the day the trial ends', substr( (string) $sub->get_next_payment(), 0, 10 ) === substr( (string) $sub->get_trial_end(), 0, 10 ), array( $sub->get_next_payment(), $sub->get_trial_end() ) );

// Bring the renewal forward and let the real processor run it.
$sub->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$sub->save();
\Submex\Plugin::instance()->get( 'processor' )->process( $sub->get_id() );

global $wpdb;
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_submex_subscription_id' AND meta_value=%d AND order_id<>%d", $sub->get_id(), $order->get_id() ) );
$renewal = $ids ? wc_get_order( (int) end( $ids ) ) : null;
$check( 'a renewal order was raised', $renewal instanceof \WC_Order );
if ( $renewal ) {
	$check( 'the renewal charges the real price', '20.00' === wc_format_decimal( $renewal->get_total(), 2 ), $renewal->get_total() );
	$check( 'the renewal was paid', in_array( $renewal->get_status(), array( 'processing', 'completed' ), true ), $renewal->get_status() );
	$check( 'the subscription is now active', str_contains( wc_get_order( $sub->get_id() )->get_status(), 'active' ), wc_get_order( $sub->get_id() )->get_status() );
}

WC()->cart->empty_cart();
submex_test_done( $fail );
