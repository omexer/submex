<?php
/**
 * A renewal run by Action Scheduler queues the next one itself, instead of waiting for the hourly sweep.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

require __DIR__ . '/bootstrap.php';

$plugin = \Submex\Plugin::instance();

$gateway = new class() implements Recurring_Gateway {
	public int $charges = 0;
	public bool $time_out = false;
	public function id(): string { return 'submex_test_schedule_next'; }
	public function title(): string { return 'Schedule next harness'; }
	public function model(): Gateway_Model { return Gateway_Model::Tokenized; }
	public function supports( string $f ): bool { return true; }
	public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'm' ); }
	public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
		++$this->charges;
		return $this->time_out ? Charge_Result::error( 'Timed out.' ) : Charge_Result::success( 'c-' . $r->get_id() );
	}
	public function reconcile( Subscription $s, string $k ): ?Charge_Result { return null; }
	public function cancel_mandate( Subscription $s ): bool { return true; }
	public function update_payment_method( Subscription $s, string $t ): bool { return true; }
};
$plugin->get( 'gateways' )->add( $gateway );

$product   = submex_test_product();
$scheduler = $plugin->get( 'scheduler' );
$made      = array();

$make = static function ( string $next ) use ( $product, $gateway, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( $gateway->id() );
	$s->set_address( array( 'first_name' => 'Schedule', 'email' => 'schedulenext@example.test' ), 'billing' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Schedule next probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( $next );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s;
	return $s;
};

// Pending renewal actions for one subscription: id => timestamp.
$pending = static function ( Subscription $s ): array {
	$out = array();
	foreach ( as_get_scheduled_actions( array( 'hook' => Renewal_Scheduler::ACTION_RENEWAL, 'args' => array( 'subscription_id' => $s->get_id() ), 'group' => Renewal_Scheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) ) as $id => $action ) {
		$out[ (int) $id ] = (int) $action->get_schedule()->get_date()->getTimestamp();
	}
	return $out;
};

// Through the Action Scheduler runner, which marks the action running while it executes.
$run = static function ( int $action_id ): void {
	ActionScheduler::runner()->process_action( $action_id, 'submex-test' );
};

$queue_now = static function ( Subscription $s ): int {
	return (int) as_schedule_single_action( time(), Renewal_Scheduler::ACTION_RENEWAL, array( 'subscription_id' => $s->get_id() ), Renewal_Scheduler::GROUP );
};

$orders_for = static function ( Subscription $s ): array {
	global $wpdb;
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	return array_map(
		'intval',
		$hpos
			? $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %d AND order_id <> %d', $wpdb->prefix . 'wc_orders_meta', '_submex_subscription_id', $s->get_id(), $s->get_id() ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %d AND post_id <> %d', $wpdb->postmeta, '_submex_subscription_id', $s->get_id(), $s->get_id() ) )
	);
};

echo "\nA renewal run by Action Scheduler\n";
$due = $make( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
$scheduler->schedule_next( $due );
$first = array_keys( $pending( $due ) );
$check( 'setup: one renewal is queued for the due date', 1 === count( $first ), $first );
$run( (int) ( $first[0] ?? 0 ) );

$after = wc_get_order( $due->get_id() );
$next  = strtotime( $after->get_next_payment() . ' UTC' );
$queue = $pending( $due );
$check( 'it charged', 1 === $gateway->charges, $gateway->charges );
$check( 'next payment advanced', $next > time(), $after->get_next_payment() );
$check( 'the next renewal is queued', 1 === count( $queue ), $queue );
$check( 'for the next payment date', array( $next ) === array_values( $queue ), array( $next, $queue ) );

echo "\nRun again\n";
$run( $queue_now( $due ) );
$check( 'nothing more is charged', 1 === $gateway->charges, $gateway->charges );
$check( 'still one renewal order', 1 === count( $orders_for( $due ) ), $orders_for( $due ) );
$check( 'still exactly one renewal queued, for the same date', array( $next ) === array_values( $pending( $due ) ), $pending( $due ) );

echo "\nA gateway timeout\n";
$gateway->time_out = true;
$gateway->charges  = 0;
$slow              = $make( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
$run( $queue_now( $slow ) );
$retry = $pending( $slow );
$check( 'exactly one retry is queued', 1 === count( $retry ), $retry );
$check( 'about two minutes out', 1 === count( $retry ) && abs( reset( $retry ) - ( time() + 2 * MINUTE_IN_SECONDS ) ) < 30, $retry );

$waits = array();
for ( $i = 0; $i < 5 && $pending( $slow ); $i++ ) {
	$before  = time();
	$ids     = array_keys( $pending( $slow ) );
	$run( $ids[0] );
	$queued  = $pending( $slow );
	$waits[] = $queued ? (int) round( ( reset( $queued ) - $before ) / MINUTE_IN_SECONDS ) : 0;
}
$check( 'each retry waits twice as long, then it stops', array( 4, 8, 16, 32, 0 ) === $waits, $waits );
$check( 'six attempts in all, not an endless loop', 6 === $gateway->charges, $gateway->charges );
$check( 'and nothing is left queued', array() === $pending( $slow ), $pending( $slow ) );
$gateway->time_out = false;

echo "\nDedupe outside a run\n";
$later = $make( gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) );
$scheduler->schedule_next( $later );
$scheduler->schedule_next( $later );
$check( 'scheduling twice queues one renewal', 1 === count( $pending( $later ) ), $pending( $later ) );
$scheduler->schedule_at( $later->get_id(), time() );
$check( 'nor does asking for an earlier time add a second', 1 === count( $pending( $later ) ), $pending( $later ) );

foreach ( $made as $s ) {
	foreach ( $orders_for( $s ) as $order_id ) {
		wc_get_order( $order_id )->delete( true );
	}
	$scheduler->unschedule( $s->get_id() );
	$s->delete( true );
}

submex_test_done( $fail );
