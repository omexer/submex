<?php
/**
 * A subscription cancelled "at the end of the period" really ends when that period does.
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
require_once ABSPATH . 'wp-admin/includes/user.php';

$plugin = \Submex\Plugin::instance();

$gateway_for = static function ( string $id, Gateway_Model $model ): Recurring_Gateway {
	return new class( $id, $model ) implements Recurring_Gateway {
		public int $charges  = 0;
		public int $released = 0;
		public ?Charge_Result $answer  = null;
		public ?Charge_Result $verdict = null;
		public function __construct( private string $gateway_id, private Gateway_Model $gateway_model ) {}
		public function id(): string { return $this->gateway_id; }
		public function title(): string { return 'Period end harness'; }
		public function model(): Gateway_Model { return $this->gateway_model; }
		public function supports( string $f ): bool { return true; }
		public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'm' ); }
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
			++$this->charges;
			return $this->answer ?? Charge_Result::success( 'c-' . $r->get_id() );
		}
		public function reconcile( Subscription $s, string $k ): ?Charge_Result { return $this->verdict; }
		public function cancel_mandate( Subscription $s ): bool {
			++$this->released;
			return true;
		}
		public function update_payment_method( Subscription $s, string $t ): bool { return true; }
	};
};

$tokenized = $gateway_for( 'submex_test_period_end', Gateway_Model::Tokenized );
$managed   = $gateway_for( 'submex_test_period_end_managed', Gateway_Model::GatewayManaged );
$uncertain = $gateway_for( 'submex_test_period_end_uncertain', Gateway_Model::Tokenized );
$plugin->get( 'gateways' )->add( $tokenized );
$plugin->get( 'gateways' )->add( $managed );
$plugin->get( 'gateways' )->add( $uncertain );

$succeeded = 0;
add_action( 'submex_renewal_succeeded', function () use ( &$succeeded ) { ++$succeeded; } );

$sent = array();
add_filter( 'wp_mail', function ( $a ) use ( &$sent ) { $sent[] = $a['to']; return $a; }, 999 );

$product   = submex_test_product();
$processor = $plugin->get( 'processor' );
$scheduler = $plugin->get( 'scheduler' );
$made      = array();
$users     = array();

$customer = static function ( string $login ) use ( &$users ): int {
	$id = wp_insert_user( array( 'user_login' => $login . '_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
	if ( is_wp_error( $id ) ) {
		submex_test_abort( 'could not create a customer: ' . $id->get_error_message() );
	}
	$users[] = $id;
	return $id;
};

$make = static function ( Subscription_Status $status, ?string $next, Recurring_Gateway $gateway, int $customer_id = 1 ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( $customer_id );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( $gateway->id() );
	$s->set_address( array( 'first_name' => 'Period', 'email' => 'periodend@example.test' ), 'billing' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Period end probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( $next );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$first = Subscription_Status::Trialling === $status ? Subscription_Status::Trialling : Subscription_Status::Active;
	$s->transition_to( $first );
	$s->save();
	if ( $first !== $status ) {
		$s->transition_to( $status );
		$s->save();
	}
	$made[] = $s;
	return $s;
};

$ago   = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', time() - $seconds );
$fresh = static fn( Subscription $s ): Subscription => wc_get_order( $s->get_id() );

$rest       = new \Submex\Rest\Subscriptions_Controller( $plugin->get( 'activity' ), $processor );
$transition = new ReflectionMethod( $rest, 'transition' );
$transition->setAccessible( true );

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

$due_ids = new ReflectionMethod( $scheduler, 'due_ids' );
$due_ids->setAccessible( true );

$selected = static function ( Subscription $s ) use ( $scheduler, $due_ids ): bool {
	$cutoff = gmdate( 'Y-m-d H:i:s' );
	for ( $offset = 0; $offset < 10000; $offset += 500 ) {
		$ids = $due_ids->invoke( $scheduler, $cutoff, 500, $offset );
		if ( in_array( $s->get_id(), $ids, true ) ) {
			return true;
		}
		if ( count( $ids ) < 500 ) {
			return false;
		}
	}
	return false;
};

// What the hourly sweep would queue: selected by the query, then kept by its per-row check.
$swept = static function ( Subscription $s ) use ( $scheduler, $due_ids ): bool {
	$cutoff = gmdate( 'Y-m-d H:i:s' );
	for ( $offset = 0; $offset < 10000; $offset += 500 ) {
		$ids = $due_ids->invoke( $scheduler, $cutoff, 500, $offset );
		if ( in_array( $s->get_id(), $ids, true ) ) {
			$is_due = new ReflectionMethod( $scheduler, 'is_due' );
			$is_due->setAccessible( true );
			return $is_due->invoke( $scheduler, wc_get_order( $s->get_id() ), time() );
		}
		if ( count( $ids ) < 500 ) {
			return false;
		}
	}
	return false;
};

$has_access = static function ( int $user_id ) use ( $product ): bool {
	$m = new ReflectionMethod( \Submex\Frontend\Downloadable_Access::class, 'has_live_subscription' );
	$m->setAccessible( true );
	return $m->invoke( new \Submex\Frontend\Downloadable_Access(), $user_id, $product->get_id() );
};

$ended_log = static function ( Subscription $s ) use ( $plugin ): int {
	$rows = $plugin->get( 'activity' )->for_subscription( $s->get_id(), 200 );
	return count( array_filter( $rows, static fn( $r ) => 'Cancelled at the end of the paid period.' === $r->message ) );
};

$queued = static function ( string $hook, Subscription $s ): bool {
	return (bool) as_next_scheduled_action( $hook, array( 'subscription_id' => $s->get_id() ), Renewal_Scheduler::GROUP );
};

echo "\nPeriod over\n";
$ended_user = $customer( 'sk_period_ended' );
$ended      = $make( Subscription_Status::Active, $ago( HOUR_IN_SECONDS ), $tokenized, $ended_user );
$scheduler->schedule_next( $ended );
as_schedule_single_action( time() + DAY_IN_SECONDS, Renewal_Scheduler::ACTION_REMINDER, array( 'subscription_id' => $ended->get_id() ), Renewal_Scheduler::GROUP );
$transition->invoke( $rest, $ended, Subscription_Status::PendingCancel, 'Cancelled by the store.' );
$check( 'the cancellation itself told the customer and the store', 2 === count( $sent ), $sent );
$check( 'still has access while cancelling', $has_access( $ended_user ) );
$check( 'the sweep picks up a cancelling subscription whose period is over', $swept( $ended ) );

$sent = array();
$processor->process( $ended->get_id() );
$after = $fresh( $ended );
$check( 'it is cancelled once the period is over', Subscription_Status::Cancelled === $after->get_status_enum(), $after->get_status() );
$check( 'no charge was attempted', 0 === $tokenized->charges, $tokenized->charges );
$check( 'no renewal order was created', array() === $orders_for( $ended ), $orders_for( $ended ) );
$check( 'next payment is cleared', empty( $after->get_next_payment() ), $after->get_next_payment() );
$check( 'the queued renewal is unscheduled', ! $queued( Renewal_Scheduler::ACTION_RENEWAL, $ended ) );
$check( 'the queued reminder is unscheduled', ! $queued( Renewal_Scheduler::ACTION_REMINDER, $ended ) );
$check( 'the end is in the activity log', 1 === $ended_log( $ended ), $ended_log( $ended ) );
$check( 'access is gone', ! $has_access( $ended_user ) );
$check( 'no cancellation email goes a second time', array() === $sent, $sent );

echo "\nSecond run\n";
$processor->process( $ended->get_id() );
$check( 'still cancelled', Subscription_Status::Cancelled === $fresh( $ended )->get_status_enum(), $fresh( $ended )->get_status() );
$check( 'still no charge', 0 === $tokenized->charges, $tokenized->charges );
$check( 'still no renewal order', array() === $orders_for( $ended ), $orders_for( $ended ) );
$check( 'logged once, not twice', 1 === $ended_log( $ended ), $ended_log( $ended ) );
$check( 'nothing sent', array() === $sent, $sent );
$check( 'no longer swept', ! $swept( $ended ) );

echo "\nPeriod still running\n";
$live_user = $customer( 'sk_period_live' );
$live_next = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
$live      = $make( Subscription_Status::Active, $live_next, $tokenized, $live_user );
$transition->invoke( $rest, $live, Subscription_Status::PendingCancel, 'Cancelled by the store.' );
$check( 'not swept before its period ends', ! $swept( $live ) );
$processor->process( $live->get_id() );
$check( 'left cancelling', Subscription_Status::PendingCancel === $fresh( $live )->get_status_enum(), $fresh( $live )->get_status() );
$check( 'its paid-through date is untouched', $live_next === $fresh( $live )->get_next_payment(), $fresh( $live )->get_next_payment() );
$check( 'and the customer keeps access', $has_access( $live_user ) );
$check( 'nothing logged as ended', 0 === $ended_log( $live ), $ended_log( $live ) );

echo "\nAlready stuck before this fix\n";
$stuck = $make( Subscription_Status::PendingCancel, $ago( 40 * DAY_IN_SECONDS ), $tokenized );
$check( 'nothing is queued for it', ! $queued( Renewal_Scheduler::ACTION_RENEWAL, $stuck ) );
$check( 'the sweep picks it up', $swept( $stuck ) );
$processor->process( $stuck->get_id() );
$check( 'and it is ended', Subscription_Status::Cancelled === $fresh( $stuck )->get_status_enum(), $fresh( $stuck )->get_status() );
$check( 'without a charge', 0 === $tokenized->charges, $tokenized->charges );

echo "\nNo paid-through date\n";
$undated = $make( Subscription_Status::PendingCancel, null, $tokenized );
$check( 'the sweep picks it up', $swept( $undated ) );
$processor->process( $undated->get_id() );
$check( 'and it is ended', Subscription_Status::Cancelled === $fresh( $undated )->get_status_enum(), $fresh( $undated )->get_status() );

foreach ( array( Subscription_Status::Active, Subscription_Status::Trialling ) as $status ) {
	$dateless = $make( $status, null, $tokenized );
	$check( "{$status->value} with no next payment is not selected by the due query", ! $selected( $dateless ) );
	$check( "{$status->value} with no next payment is not swept", ! $swept( $dateless ) );
}

echo "\nGateway-managed plan\n";
$paypal = $make( Subscription_Status::PendingCancel, $ago( HOUR_IN_SECONDS ), $managed );
$processor->process( $paypal->get_id() );
$check( 'it is ended', Subscription_Status::Cancelled === $fresh( $paypal )->get_status_enum(), $fresh( $paypal )->get_status() );
$check( 'the gateway is told to stop billing', 1 === $managed->released, $managed->released );
$processor->process( $paypal->get_id() );
$check( 'and told only once', 1 === $managed->released, $managed->released );

echo "\nRefusals\n";
$locked = $make( Subscription_Status::PendingCancel, $ago( HOUR_IN_SECONDS ), $tokenized );
$lock   = new \Submex\Billing\Lock();
$lock->acquire( $locked->get_id() );
$processor->process( $locked->get_id() );
$lock->release( $locked->get_id() );
$check( 'another worker holding the lock stops it', Subscription_Status::PendingCancel === $fresh( $locked )->get_status_enum(), $fresh( $locked )->get_status() );

$clone = $make( Subscription_Status::PendingCancel, $ago( HOUR_IN_SECONDS ), $managed );
$clone->update_meta_data( '_submex_site_url', 'https://live-store.example.test' );
$clone->save();
$processor->process( $clone->get_id() );
$check( 'a staging clone does not end it', Subscription_Status::PendingCancel === $fresh( $clone )->get_status_enum(), $fresh( $clone )->get_status() );
$check( 'nor release the live mandate', 1 === $managed->released, $managed->released );

$held = $make( Subscription_Status::OnHold, $ago( HOUR_IN_SECONDS ), $tokenized );
$processor->process( $held->get_id() );
$check( 'an on-hold subscription is not ended by this', Subscription_Status::OnHold === $fresh( $held )->get_status_enum(), $fresh( $held )->get_status() );

echo "\nReactivated before the period ended\n";
$back = $make( Subscription_Status::Active, $ago( HOUR_IN_SECONDS ), $tokenized );
$transition->invoke( $rest, $back, Subscription_Status::PendingCancel, 'Cancelled by the store.' );
$transition->invoke( $rest, $fresh( $back ), Subscription_Status::Active, 'Changed their mind.' );
$processor->process( $back->get_id() );
$check( 'it is billed', 1 === $tokenized->charges, $tokenized->charges );
$check( 'with one renewal order', 1 === count( $orders_for( $back ) ), $orders_for( $back ) );
$check( 'and stays active', Subscription_Status::Active === $fresh( $back )->get_status_enum(), $fresh( $back )->get_status() );
$check( 'with its next payment in the future', strtotime( $fresh( $back )->get_next_payment() . ' UTC' ) > time(), $fresh( $back )->get_next_payment() );
$check( 'not logged as ended', 0 === $ended_log( $back ), $ended_log( $back ) );

echo "\nA renewal of unknown outcome is settled before ending\n";
$slot_of = static function ( Subscription $s ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d ORDER BY period_index DESC LIMIT 1', $wpdb->prefix . 'submex_charge_slot', $s->get_id() ) );
};
$timed_out = static function ( int $customer_id = 1 ) use ( $make, $ago, $uncertain, $processor, $rest, $transition, $fresh ): Subscription {
	$uncertain->answer = Charge_Result::error( 'Timed out.' );
	$s                 = $make( Subscription_Status::Active, $ago( HOUR_IN_SECONDS ), $uncertain, $customer_id );
	$processor->process( $s->get_id() );
	$uncertain->answer = null;
	$transition->invoke( $rest, $fresh( $s ), Subscription_Status::PendingCancel, 'Cancelled by the store.' );
	return $fresh( $s );
};

$landed_user = $customer( 'sk_period_landed' );
$landed      = $timed_out( $landed_user );
$check( 'setup: the timed-out charge left its slot charging', 'charging' === ( $slot_of( $landed )->state ?? '' ), $slot_of( $landed ) );
$charges_before     = $uncertain->charges;
$succeeded          = 0;
$uncertain->verdict = Charge_Result::success( 'pi_landed' );
$processor->process( $landed->get_id() );
$slot  = $slot_of( $landed );
$after = $fresh( $landed );
$check( 'money that landed keeps it cancelling, not cancelled', Subscription_Status::PendingCancel === $after->get_status_enum(), $after->get_status() );
$check( 'the slot is settled as paid', 'paid' === $slot->state, $slot->state );
$check( 'its renewal order is paid', wc_get_order( (int) $slot->renewal_order_id )->is_paid(), wc_get_order( (int) $slot->renewal_order_id )->get_status() );
$check( 'the paid-through date moves to the end of the period paid for', $slot->covers_to_gmt === $after->get_next_payment(), array( $slot->covers_to_gmt, $after->get_next_payment() ) );
$check( 'which is in the future', strtotime( $after->get_next_payment() . ' UTC' ) > time(), $after->get_next_payment() );
$check( 'the customer keeps access for it', $has_access( $landed_user ) );
$check( 'no new charge while reconciling', $charges_before === $uncertain->charges, $uncertain->charges );
$check( 'the renewal is announced once', 1 === $succeeded, $succeeded );
$processor->process( $landed->get_id() );
$check( 'a second run leaves it cancelling', Subscription_Status::PendingCancel === $fresh( $landed )->get_status_enum(), $fresh( $landed )->get_status() );
$check( 'and announces nothing again', 1 === $succeeded, $succeeded );
$check( 'nor charges', $charges_before === $uncertain->charges, $uncertain->charges );

$lost               = $timed_out();
$uncertain->verdict = null;
$succeeded          = 0;
$processor->process( $lost->get_id() );
$slot = $slot_of( $lost );
$check( 'a charge the gateway never recorded does not keep it: cancelled', Subscription_Status::Cancelled === $fresh( $lost )->get_status_enum(), $fresh( $lost )->get_status() );
$check( 'the slot is abandoned', 'abandoned' === $slot->state, $slot->state );
$check( 'its unpaid renewal order is cancelled', 'cancelled' === wc_get_order( (int) $slot->renewal_order_id )->get_status(), wc_get_order( (int) $slot->renewal_order_id )->get_status() );
$check( 'nothing announced as renewed', 0 === $succeeded, $succeeded );

$unreachable        = $timed_out();
$uncertain->verdict = Charge_Result::error( 'Gateway unreachable.' );
$processor->process( $unreachable->get_id() );
$check( 'an unanswered reconcile leaves it cancelling', Subscription_Status::PendingCancel === $fresh( $unreachable )->get_status_enum(), $fresh( $unreachable )->get_status() );
$check( 'with the slot still unknown', 'charging' === $slot_of( $unreachable )->state, $slot_of( $unreachable )->state );
$check( 'and still swept, to try again', $swept( $unreachable ) );

foreach ( $made as $s ) {
	foreach ( $orders_for( $s ) as $order_id ) {
		wc_get_order( $order_id )->delete( true );
	}
	$scheduler->unschedule( $s->get_id() );
	$s->delete( true );
}
foreach ( $users as $user_id ) {
	wp_delete_user( $user_id );
}

submex_test_done( $fail );
