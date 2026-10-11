<?php
/**
 * A charge the gateway accepts as pending waits for its answer: never charged or reconciled again, settled once either way.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Processor;
use Submex\Billing\Renewal_Scheduler;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Test_Gateway;
use Submex\Lifecycle\Early_Renewal;

require __DIR__ . '/bootstrap.php';

global $wpdb;

$harness = new class() implements \Submex\Gateways\Recurring_Gateway {
	public string $next       = 'pending';
	public int $charges       = 0;
	public int $reconciles    = 0;
	public array $keys        = array();
	public ?Charge_Result $verdict = null;

	public function id(): string { return Test_Gateway::ID; }
	public function title(): string { return 'Harness'; }
	public function model(): Gateway_Model { return Gateway_Model::Tokenized; }
	public function supports( string $f ): bool { return true; }
	public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'mandate' ); }
	public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
		++$this->charges;
		$this->keys[] = $k;
		if ( 'stale-link' === $this->next ) {
			$r->update_meta_data( '_submex_action_url', 'https://bank.example.test/confirm' );
			$r->save();
		}
		return match ( $this->next ) {
			'success' => Charge_Result::success( 'ch_' . $this->charges ),
			'unknown' => Charge_Result::error( 'Timed out, as the harness.' ),
			default   => Charge_Result::pending( 'dd_' . $this->charges, 'Submitted to the bank.' ),
		};
	}
	public function reconcile( Subscription $s, string $k ): ?Charge_Result {
		++$this->reconciles;
		return $this->verdict;
	}
	public function cancel_mandate( Subscription $s ): bool { return true; }
	public function update_payment_method( Subscription $s, string $t ): bool { return true; }
};

$free = \Submex\Plugin::instance();
$free->get( 'gateways' )->add( $harness );

$processor = $free->get( 'processor' );
$slots     = $free->get( 'charge_slots' );
$lock      = $free->get( 'lock' );
$product   = submex_test_product();
$made      = array();
$extra     = array();

$fired = array();
foreach ( array( 'submex_renewal_pending', 'submex_renewal_succeeded', 'submex_renewal_failed' ) as $hook ) {
	add_action(
		$hook,
		function ( $s ) use ( &$fired, $hook ) {
			$fired[ $hook ][ $s->get_id() ] = ( $fired[ $hook ][ $s->get_id() ] ?? 0 ) + 1;
		},
		10,
		1
	);
}
$count = static function ( string $hook, int $id ) use ( &$fired ): int {
	return $fired[ $hook ][ $id ] ?? 0;
};

$receipts = array();
add_filter(
	'wp_mail',
	function ( $args ) use ( &$receipts ) {
		if ( 'Your payment went through' === $args['subject'] ) {
			$receipts[] = $args['to'];
		}
		return $args;
	},
	999
);

$make = function ( string $email, int $due_offset = -HOUR_IN_SECONDS ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Test_Gateway::ID );
	$s->set_address( array( 'first_name' => 'Pending', 'email' => $email, 'country' => 'GB' ), 'billing' );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Pending probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + $due_offset ) );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s->get_id();
	return $s;
};

// Charged by the harness as pending; returns the pending slot and its renewal order.
$submit = function ( Subscription $s ) use ( $processor, $slots, $harness ): array {
	$harness->next = 'pending';
	$processor->process( $s->get_id() );
	$slot  = $slots->latest_unsettled( $s->get_id() );
	$order = $slot ? wc_get_order( (int) $slot->renewal_order_id ) : null;
	if ( ! $slot || ! $order instanceof WC_Order || Charge_Slot_Repository::STATE_PENDING !== $slot->state ) {
		submex_test_abort( 'a pending charge did not leave a pending slot with a renewal order: ' . wp_json_encode( $slot ) );
	}
	return array( $slot, $order );
};

$renewal_queued = static function ( int $id ): bool {
	return (bool) as_next_scheduled_action( Renewal_Scheduler::ACTION_RENEWAL, array( 'subscription_id' => $id ), Renewal_Scheduler::GROUP );
};

echo "\n1. A pending charge waits\n";
$s1                   = $make( 'pending-1@example.test' );
$due1                 = $s1->get_next_payment();
list( $slot1, $order1 ) = $submit( $s1 );
$s1                   = wc_get_order( $s1->get_id() );
$check( 'the gateway was asked once', 1 === $harness->charges, $harness->charges );
$check( 'the renewal order waits on hold, carrying the payment reference', 'on-hold' === $order1->get_status() && 'dd_1' === $order1->get_transaction_id(), array( $order1->get_status(), $order1->get_transaction_id() ) );
$check( 'the subscription keeps its status, and so its access', Subscription_Status::Active === $s1->get_status_enum(), $s1->get_status() );
$check( 'the next payment date does not move until the money is confirmed', $due1 === $s1->get_next_payment(), array( $due1, $s1->get_next_payment() ) );
$check( 'submex_renewal_pending fired once, and nothing else did', 1 === $count( 'submex_renewal_pending', $s1->get_id() ) && 0 === $count( 'submex_renewal_succeeded', $s1->get_id() ) && 0 === $count( 'submex_renewal_failed', $s1->get_id() ), $fired );
$check( 'no unknown-outcome retry is queued', ! $renewal_queued( $s1->get_id() ) && '' === $s1->get_meta( '_submex_unknown_retry' ), $s1->get_meta( '_submex_unknown_retry' ) );
$check( 'the customer cannot pay the order a second time', ! $order1->needs_payment() );
$wpdb->update( $wpdb->prefix . 'submex_charge_slot', array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => (int) $slot1->id ) );
$check( 'a day later it is not reported as a charge of unknown outcome', ! in_array( (int) $slot1->id, array_map( 'intval', wp_list_pluck( $slots->stuck_charging( 60 ), 'id' ) ), true ) );
$check( 'nor yet as waiting too long for its confirmation', ! in_array( (int) $slot1->id, array_map( 'intval', wp_list_pluck( $slots->stale_pending( 10 ), 'id' ) ), true ) );
$wpdb->update( $wpdb->prefix . 'submex_charge_slot', array( 'pending_gmt' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ), array( 'id' => (int) $slot1->id ) );
if ( ! class_exists( 'WC_Settings_Page' ) ) {
	include_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}
$card = array_values( array_filter( \Submex\Admin\Settings::status_checks(), static fn( $c ) => 'Payments awaiting confirmation' === $c['label'] ) )[0] ?? null;
$check( 'eleven days on, the status card flags it', $card && ! $card['ok'] && str_contains( $card['bad'], 'more than 10 days' ), $card );

$overdue = $make( 'pending-overdue@example.test' );
$free->get( 'scheduler' )->sweep();
$check( 'the overdue sweep leaves it alone, since nothing can move it', ! $renewal_queued( $s1->get_id() ) );
$check( 'while it still queues an ordinary overdue renewal', $renewal_queued( $overdue->get_id() ) );
$free->get( 'scheduler' )->unschedule( $overdue->get_id() );
$processor->process( $s1->get_id() );
$processor->process( $s1->get_id() );
$check( 'but running it again neither charges nor reconciles', 1 === $harness->charges && 0 === $harness->reconciles, array( $harness->charges, $harness->reconciles ) );
$check( 'and the slot is still pending', Charge_Slot_Repository::STATE_PENDING === $slots->find( $s1->get_id(), (int) $slot1->period_index )->state );
$free->get( 'scheduler' )->unschedule( $s1->get_id() );

echo "\n2. Confirmed, it settles once\n";
$check( 'a result that is not final settles nothing', ! $processor->resolve_pending( $order1, Charge_Result::pending( 'dd_1' ) ) && ! $processor->resolve_pending( $order1, Charge_Result::error( 'n/a' ) ) && Charge_Slot_Repository::STATE_PENDING === $slots->find( $s1->get_id(), (int) $slot1->period_index )->state );

// A run queued while it waited, as the sweep used to.
$free->get( 'scheduler' )->schedule_at( $s1->get_id(), time() + HOUR_IN_SECONDS );
$receipts = array();
$check( 'the confirmation settles it', $processor->resolve_pending( wc_get_order( $order1->get_id() ), Charge_Result::success( 'dd_1' ) ) );
$s1      = wc_get_order( $s1->get_id() );
$settled = $slots->find( $s1->get_id(), (int) $slot1->period_index );
$check( 'the slot is paid', Charge_Slot_Repository::STATE_PAID === $settled->state && 0 === (int) $settled->attempt_group, $settled );
$check( 'the order is paid', wc_get_order( $order1->get_id() )->is_paid(), wc_get_order( $order1->get_id() )->get_status() );
$check( 'the next payment is the end of the period just paid for', strtotime( (string) $s1->get_next_payment() . ' UTC' ) === strtotime( $slot1->covers_to_gmt . ' UTC' ), array( $s1->get_next_payment(), $slot1->covers_to_gmt ) );
$check( 'submex_renewal_succeeded fired once, with one receipt', 1 === $count( 'submex_renewal_succeeded', $s1->get_id() ) && array( 'pending-1@example.test' ) === $receipts, array( $fired, $receipts ) );
$check( 'the next renewal is queued at the new date, not left to the sweep', strtotime( $slot1->covers_to_gmt . ' UTC' ) === as_next_scheduled_action( Renewal_Scheduler::ACTION_RENEWAL, array( 'subscription_id' => $s1->get_id() ), Renewal_Scheduler::GROUP ), array( as_next_scheduled_action( Renewal_Scheduler::ACTION_RENEWAL, array( 'subscription_id' => $s1->get_id() ), Renewal_Scheduler::GROUP ), $slot1->covers_to_gmt ) );

$check( 'a repeated confirmation changes nothing', ! $processor->resolve_pending( wc_get_order( $order1->get_id() ), Charge_Result::success( 'dd_1' ) ) );
$check( 'nor does a late failure for the same payment', ! $processor->resolve_pending( wc_get_order( $order1->get_id() ), Charge_Result::hard_decline( 'payment_cancelled' ) ) );
do_action( 'woocommerce_payment_complete', $order1->get_id() );
$processor->settle_paid_order( $order1->get_id() );
$processor->process( $s1->get_id() );
$check( 'nothing settles, announces or charges twice', 1 === $count( 'submex_renewal_succeeded', $s1->get_id() ) && 1 === count( $receipts ) && 1 === $harness->charges && 'processing' === wc_get_order( $order1->get_id() )->get_status(), array( $fired, $receipts, $harness->charges ) );

echo "\n3. Declined, it fails once and takes the normal failure path\n";
$s3                   = $make( 'pending-3@example.test' );
list( $slot3, $order3 ) = $submit( $s3 );
$check( 'the decline settles it', $processor->resolve_pending( wc_get_order( $order3->get_id() ), Charge_Result::soft_decline( 'insufficient_funds', 'Not enough in the account.' ) ) );
$s3     = wc_get_order( $s3->get_id() );
$failed = $slots->find( $s3->get_id(), (int) $slot3->period_index );
$check( 'the slot failed, with a fresh key for the next attempt', Charge_Slot_Repository::STATE_FAILED === $failed->state && 1 === (int) $failed->attempt_group, $failed );
$check( 'the order failed and the subscription is on hold', 'failed' === wc_get_order( $order3->get_id() )->get_status() && Subscription_Status::OnHold === $s3->get_status_enum(), array( wc_get_order( $order3->get_id() )->get_status(), $s3->get_status() ) );
$check( 'submex_renewal_failed fired once', 1 === $count( 'submex_renewal_failed', $s3->get_id() ) );
$check( 'a repeated failure changes nothing', ! $processor->resolve_pending( wc_get_order( $order3->get_id() ), Charge_Result::hard_decline( 'mandate_cancelled' ) ) && 1 === $count( 'submex_renewal_failed', $s3->get_id() ) );

$charges_before = $harness->charges;
$s3->transition_to( Subscription_Status::Active, 'Retrying, as the harness.' );
$s3->save();
$processor->process( $s3->get_id() );
$check( 'a retry charges once more, with a new idempotency key', $charges_before + 1 === $harness->charges && end( $harness->keys ) !== $harness->keys[ count( $harness->keys ) - 2 ] && $slots->idempotency_key( $slots->find( $s3->get_id(), (int) $slot3->period_index ) ) === end( $harness->keys ), $harness->keys );
$check( 'on the same renewal order', (int) $slots->find( $s3->get_id(), (int) $slot3->period_index )->renewal_order_id === $order3->get_id() );

echo "\n4. Refusals\n";
$shop = wc_create_order();
$shop->set_total( '20' );
$shop->save();
$extra[] = $shop->get_id();
$check( 'an ordinary order is never settled', ! $processor->resolve_pending( $shop, Charge_Result::success( 'dd_x' ) ) && ! $shop->is_paid() );

$s4             = $make( 'pending-4@example.test' );
$harness->next  = 'unknown';
$processor->process( $s4->get_id() );
$slot4          = $slots->latest_unsettled( $s4->get_id() );
$order4         = $slot4 ? wc_get_order( (int) $slot4->renewal_order_id ) : null;
if ( ! $order4 instanceof WC_Order || Charge_Slot_Repository::STATE_CHARGING !== $slot4->state ) {
	submex_test_abort( 'the unknown outcome did not leave a charging slot' );
}
$check( 'a charge of unknown outcome is not settled by a webhook', ! $processor->resolve_pending( $order4, Charge_Result::success( 'dd_x' ) ) && Charge_Slot_Repository::STATE_CHARGING === $slots->find( $s4->get_id(), (int) $slot4->period_index )->state );

$charges_before     = $harness->charges;
$harness->verdict   = Charge_Result::pending( 'dd_found', 'Found at the gateway.' );
$free->get( 'scheduler' )->unschedule( $s4->get_id() );
$processor->process( $s4->get_id() );
$check( 'reconciled as pending, it waits without charging', $charges_before === $harness->charges && Charge_Slot_Repository::STATE_PENDING === $slots->find( $s4->get_id(), (int) $slot4->period_index )->state && 'dd_found' === wc_get_order( $order4->get_id() )->get_transaction_id(), array( $harness->charges, $slots->find( $s4->get_id(), (int) $slot4->period_index ) ) );
$reconciles_before = $harness->reconciles;
$processor->process( $s4->get_id() );
$check( 'and is not reconciled again', $reconciles_before === $harness->reconciles && $charges_before === $harness->charges );
$harness->verdict = null;

echo "\n5. Settled while a renewal run holds the subscription\n";
$lock->acquire( $s4->get_id() );
$check( 'nothing is settled under somebody else\'s lock', ! $processor->resolve_pending( wc_get_order( $order4->get_id() ), Charge_Result::hard_decline( 'mandate_cancelled', 'The customer cancelled the mandate.' ) ) && Charge_Slot_Repository::STATE_PENDING === $slots->find( $s4->get_id(), (int) $slot4->period_index )->state );
$queued = array(
	'order_id'  => $order4->get_id(),
	'outcome'   => 'hard_decline',
	'reference' => '',
	'code'      => 'mandate_cancelled',
	'message'   => 'The customer cancelled the mandate.',
);
$check( 'settling is queued for later', (bool) as_next_scheduled_action( Renewal_Processor::ACTION_RESOLVE, $queued, 'submex' ) );
$lock->release( $s4->get_id() );
$processor->resolve_queued( ...array_values( $queued ) );
as_unschedule_all_actions( Renewal_Processor::ACTION_RESOLVE, $queued, 'submex' );
$check( 'the queued answer settles it once', Charge_Slot_Repository::STATE_FAILED === $slots->find( $s4->get_id(), (int) $slot4->period_index )->state && 1 === $count( 'submex_renewal_failed', $s4->get_id() ) );
$processor->resolve_queued( ...array_values( $queued ) );
$check( 'and running it twice fails nothing twice', 1 === $count( 'submex_renewal_failed', $s4->get_id() ) );

echo "\n6. A subscription ending while its renewal is pending\n";
$s6                   = $make( 'pending-6@example.test' );
list( $slot6, $order6 ) = $submit( $s6 );
$s6                   = wc_get_order( $s6->get_id() );
$s6->transition_to( Subscription_Status::PendingCancel, 'Cancelled at period end, as the harness.' );
$s6->save();
$processor->process( $s6->get_id() );
$check( 'the period-end run waits for the gateway\'s answer', Subscription_Status::PendingCancel === wc_get_order( $s6->get_id() )->get_status_enum() && Charge_Slot_Repository::STATE_PENDING === $slots->find( $s6->get_id(), (int) $slot6->period_index )->state );
$processor->resolve_pending( wc_get_order( $order6->get_id() ), Charge_Result::soft_decline( 'insufficient_funds', 'Not enough in the account.' ) );
$check( 'declined, the ending customer is not chased', 0 === $count( 'submex_renewal_failed', $s6->get_id() ) && 'failed' === wc_get_order( $order6->get_id() )->get_status() );
$check( 'and the run it queues', $renewal_queued( $s6->get_id() ) );
$processor->process( $s6->get_id() );
$check( 'ends the subscription', Subscription_Status::Cancelled === wc_get_order( $s6->get_id() )->get_status_enum(), wc_get_order( $s6->get_id() )->get_status() );

$s7                   = $make( 'pending-7@example.test' );
list( $slot7, $order7 ) = $submit( $s7 );
$s7                   = wc_get_order( $s7->get_id() );
$s7->transition_to( Subscription_Status::PendingCancel, 'Cancelled at period end, as the harness.' );
$s7->save();
$processor->resolve_pending( wc_get_order( $order7->get_id() ), Charge_Result::success( 'dd_7' ) );
$s7 = wc_get_order( $s7->get_id() );
$check( 'confirmed, an ending subscription keeps the period it paid for', Subscription_Status::PendingCancel === $s7->get_status_enum() && strtotime( (string) $s7->get_next_payment() . ' UTC' ) === strtotime( $slot7->covers_to_gmt . ' UTC' ) && 1 === $count( 'submex_renewal_succeeded', $s7->get_id() ), array( $s7->get_status(), $s7->get_next_payment() ) );

$s8             = $make( 'pending-8@example.test' );
$harness->next  = 'unknown';
$processor->process( $s8->get_id() );
$slot8          = $slots->latest_unsettled( $s8->get_id() );
$s8             = wc_get_order( $s8->get_id() );
$s8->transition_to( Subscription_Status::PendingCancel, 'Cancelled at period end, as the harness.' );
$s8->save();
$harness->verdict = Charge_Result::pending( 'dd_8', 'Found at the gateway.' );
$free->get( 'scheduler' )->unschedule( $s8->get_id() );
$processor->process( $s8->get_id() );
$harness->verdict = null;
$order8           = wc_get_order( (int) $slots->find( $s8->get_id(), (int) $slot8->period_index )->renewal_order_id );
$check( 'an unknown charge found pending at period end is kept, not abandoned', Subscription_Status::PendingCancel === wc_get_order( $s8->get_id() )->get_status_enum() && Charge_Slot_Repository::STATE_PENDING === $slots->find( $s8->get_id(), (int) $slot8->period_index )->state && 'on-hold' === $order8->get_status(), array( wc_get_order( $s8->get_id() )->get_status(), $slots->find( $s8->get_id(), (int) $slot8->period_index ) ) );

echo "\n7. A renewal being paid on its pay page is not charged as well\n";
$s9 = $make( 'pending-9@example.test' );
$harness->next = 'pending';
$processor->process( $s9->get_id() );
$slot9  = $slots->latest_unsettled( $s9->get_id() );
$order9 = wc_get_order( (int) $slot9->renewal_order_id );
$processor->resolve_pending( $order9, Charge_Result::hard_decline( 'mandate_cancelled', 'The customer cancelled the mandate.' ) );
// What a Direct Debit or bank transfer chosen on the pay page leaves behind while it clears.
wc_get_order( $order9->get_id() )->update_status( 'on-hold', 'Awaiting the payment made on the pay page.' );
$charges_before = $harness->charges;
$s9             = wc_get_order( $s9->get_id() );
$s9->transition_to( Subscription_Status::Active, 'Retrying, as the harness.' );
$s9->save();
$processor->process( $s9->get_id() );
$check( 'a retry does not charge a renewal whose payment is clearing', $charges_before === $harness->charges, array( $charges_before, $harness->charges ) );
$check( 'and puts the subscription back on hold rather than leave it active and unpaid', Subscription_Status::OnHold === wc_get_order( $s9->get_id() )->get_status_enum(), wc_get_order( $s9->get_id() )->get_status() );
wc_get_order( $order9->get_id() )->update_status( 'failed', 'The pay-page payment failed.' );
$s9 = wc_get_order( $s9->get_id() );
$s9->transition_to( Subscription_Status::Active, 'Retrying, as the harness.' );
$s9->save();
$processor->process( $s9->get_id() );
$check( 'once that payment fails, the retry charges', $charges_before + 1 === $harness->charges, $harness->charges );

echo "\n8. Paying early by Direct Debit\n";
$early_was = get_option( Early_Renewal::OPTION, null );
update_option( Early_Renewal::OPTION, 'yes' );
$s10            = $make( 'pending-10@example.test', 20 * DAY_IN_SECONDS );
$harness->next  = 'pending';
$outcome        = Early_Renewal::charge( $s10 );
$check( 'the customer is told the payment is on its way, not that it failed', $outcome['ok'] && str_contains( $outcome['message'], 'on its way' ), $outcome );
null === $early_was ? delete_option( Early_Renewal::OPTION ) : update_option( Early_Renewal::OPTION, $early_was );

echo "\n9. A renewal that once asked the customer to confirm, then went pending\n";
$s11           = $make( 'pending-11@example.test' );
$harness->next = 'stale-link';
$processor->process( $s11->get_id() );
$slot11  = $slots->latest_unsettled( $s11->get_id() );
$order11 = $slot11 ? wc_get_order( (int) $slot11->renewal_order_id ) : null;
$check( 'the slot is pending', $slot11 && Charge_Slot_Repository::STATE_PENDING === $slot11->state, $slot11 );
$check( 'the old confirmation link is cleared', $order11 && '' === (string) $order11->get_meta( '_submex_action_url' ), $order11 ? $order11->get_meta( '_submex_action_url' ) : null );
$check( 'so the order cannot be paid while the provider collects', $order11 && ! $order11->needs_payment() );

// ---- clean up -----------------------------------------------------------------------
global $wpdb;
foreach ( $made as $id ) {
	$renewals = $wpdb->get_col( $wpdb->prepare( 'SELECT renewal_order_id FROM %i WHERE subscription_id = %d AND renewal_order_id IS NOT NULL', $wpdb->prefix . 'submex_charge_slot', $id ) );
	foreach ( $renewals as $renewal_id ) {
		$r = wc_get_order( (int) $renewal_id );
		$r && $r->delete( true );
	}
	$free->get( 'scheduler' )->unschedule( $id );
	// Pro's retry ladder, when it is active, answers the declines above.
	as_unschedule_all_actions( 'submex_dunning_retry', array( 'subscription_id' => $id ), 'submex' );
	$s = wc_get_order( $id );
	$s && $s->delete( true );
	$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $id ) );
}
foreach ( $extra as $id ) {
	$o = wc_get_order( $id );
	$o && $o->delete( true );
}

submex_test_done( $fail );
