<?php
/**
 * Customers and merchants see what a renewal is actually doing: waiting on the payment provider, due to be paid from a link, or failed.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Processor;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Status_Presenter;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Manual_Gateway;
use Submex\Gateways\Test_Gateway;
use Submex\Lifecycle\Early_Renewal;

require __DIR__ . '/bootstrap.php';

global $wpdb;

$harness = new class() implements \Submex\Gateways\Recurring_Gateway {
	public string $next = 'pending';
	public int $charges = 0;

	public function id(): string { return Test_Gateway::ID; }
	public function title(): string { return 'Harness'; }
	public function model(): Gateway_Model { return Gateway_Model::Tokenized; }
	public function supports( string $f ): bool { return true; }
	public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'mandate' ); }
	public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
		++$this->charges;
		return match ( $this->next ) {
			'success' => Charge_Result::success( 'ch_' . $this->charges ),
			'decline' => Charge_Result::hard_decline( 'card_declined', 'Declined by the harness.' ),
			default   => Charge_Result::pending( 'dd_' . $this->charges, 'Submitted to the bank.' ),
		};
	}
	public function reconcile( Subscription $s, string $k ): ?Charge_Result { return null; }
	public function cancel_mandate( Subscription $s ): bool { return true; }
	public function update_payment_method( Subscription $s, string $t ): bool { return true; }
};

// What Pro's bKash and SSLCommerz are: a pay-by-link gateway under its own id.
$link_gateway = new class() extends Manual_Gateway {
	public function id(): string { return 'sk_test_pay_link'; }
};

$free = \Submex\Plugin::instance();
$free->get( 'gateways' )->add( $harness );
$free->get( 'gateways' )->add( $link_gateway );

$processor = $free->get( 'processor' );
$slots     = $free->get( 'charge_slots' );
$lock      = $free->get( 'lock' );
$account   = $free->get( 'account' );
$product   = submex_test_product();
$made      = array();
$users     = array();

// Amounts are asserted as text, so the store's price format is pinned too.
$options = array(
	Early_Renewal::OPTION                => 'yes',
	'submex_allow_auto_renew_toggle'     => 'yes',
	'woocommerce_currency'               => 'USD',
	'woocommerce_currency_pos'           => 'left',
	'woocommerce_price_thousand_sep'     => ',',
	'woocommerce_price_decimal_sep'      => '.',
	'woocommerce_price_num_decimals'     => '2',
);
$was     = array();
foreach ( $options as $name => $value ) {
	$was[ $name ] = get_option( $name, null );
	update_option( $name, $value );
}

$make = function ( string $method, int $due_offset = -HOUR_IN_SECONDS ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( $method );
	$s->set_address( array( 'first_name' => 'States', 'email' => 'states@example.test', 'country' => 'GB' ), 'billing' );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'States probe', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
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

$submit = function ( Subscription $s ) use ( $processor, $slots, $harness ): array {
	$harness->next = 'pending';
	$processor->process( $s->get_id() );
	$slot  = $slots->latest_unsettled( $s->get_id() );
	$order = $slot ? wc_get_order( (int) $slot->renewal_order_id ) : null;
	if ( ! $slot || ! $order instanceof WC_Order || Charge_Slot_Repository::STATE_PENDING !== $slot->state ) {
		submex_test_abort( 'the harness did not leave a pending renewal: ' . wp_json_encode( $slot ) );
	}
	return array( $slot, $order );
};

$page = function ( int $id = 0 ) use ( $account ): string {
	ob_start();
	$account->render( $id ? (string) $id : '' );
	return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) ob_get_clean() ), ENT_QUOTES ) ) );
};

$rest = function ( int $id ): array {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/subscriptions/' . $id ) );
	return array( $response->get_status(), (array) $response->get_data() );
};

$notes_saying = static function ( int $order_id, string $needle ): int {
	return count( array_filter( wc_get_order_notes( array( 'order_id' => $order_id ) ), static fn( $n ) => str_contains( $n->content, $needle ) ) );
};

$activity_saying = static function ( int $subscription_id, string $needle ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_activity WHERE subscription_id = %d AND message LIKE %s", $subscription_id, '%' . $wpdb->esc_like( $needle ) . '%' ) );
};

echo "\n1. A renewal waiting on the payment provider\n";
$s1                     = $make( Test_Gateway::ID );
$due1                   = $s1->get_next_payment();
list( $slot1, $order1 ) = $submit( $s1 );
// A retried period keeps the slot it was first claimed in, so the claim time is not when this payment was submitted.
$wpdb->update( $wpdb->prefix . 'submex_charge_slot', array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ), array( 'id' => (int) $slot1->id ) );
$s1                     = wc_get_order( $s1->get_id() );
$state                  = Status_Presenter::for( $s1 );
$today                  = wp_date( (string) get_option( 'date_format' ) );

$check( 'the status reads Active · Payment processing', 'Active · Payment processing' === $state['label'], $state );
$check( 'the detail names the amount and the day it was submitted, and asks nothing of the customer', str_contains( $state['detail'], '$20.00' ) && str_contains( $state['detail'], 'submitted on ' . $today ) && str_contains( $state['detail'], 'nothing you need to do' ), $state['detail'] );
$check( 'and does not offer its past due date as the next payment', ! str_contains( $state['detail'], 'Next payment' ) && $due1 === $s1->get_next_payment(), $state['detail'] );

$detail = $page( $s1->get_id() );
$check( 'My Account shows it on the subscription', str_contains( $detail, 'Payment processing' ) && str_contains( $detail, 'waiting for your bank or payment provider' ), substr( $detail, 0, 400 ) );
$check( 'without a paid-through date that has already passed', ! str_contains( $detail, 'stays active until ' . date_i18n( (string) get_option( 'date_format' ), strtotime( $due1 . ' UTC' ) ) ) && str_contains( $detail, 'stays active until the end of the current period' ), $detail );
$check( 'and on the list', str_contains( $page(), 'Active · Payment processing' ) );

$check( 'Pay early is not offered while it waits', ! Early_Renewal::is_available_for( $s1 ) && ! str_contains( $detail, 'Pay early' ), $detail );
$charges = $harness->charges;
$early   = Early_Renewal::charge( $s1 );
$check( 'and asking for it anyway charges nothing', ! $early['ok'] && $charges === $harness->charges, array( $early, $harness->charges ) );

list( $code, $data ) = $rest( $s1->get_id() );
$check( 'the admin payload says a payment is pending, since when, and on which order', 200 === $code && true === $data['payment_pending'] && abs( strtotime( $data['payment_pending_since'] . ' UTC' ) - time() ) < 120 && $order1->get_id() === $data['payment_pending_order']['id'] && $order1->get_edit_order_url() === $data['payment_pending_order']['url'], $data );

$table = new \Submex\Admin\Subscriptions_Table();
$cell  = $table->column_default( $s1, 'status' );
$check( 'the admin list shows it, linked to the renewal order, with how long it has waited', str_contains( $cell, 'Payment processing' ) && str_contains( $cell, esc_url( $order1->get_edit_order_url() ) ) && preg_match( '/submitted \d+ \w+ ago/', $cell ), $cell );
$check( 'and does not offer Renew now for it', ! str_contains( $table->column_default( $s1, 'subscription' ), 'Renew now' ) );

$_GET['subscription'] = (string) $s1->get_id();
ob_start();
$free->get( 'admin_menu' )->render_list();
$screen = (string) ob_get_clean();
unset( $_GET['subscription'] );
$check( 'the admin detail screen shows it with the order link', str_contains( $screen, 'Payment processing' ) && str_contains( $screen, 'waiting for the payment provider to confirm it' ) && str_contains( $screen, esc_url( $order1->get_edit_order_url() ) ), wp_strip_all_tags( $screen ) );
$check( 'and hides the button that would only resume it', ! str_contains( $screen, 'Process renewal now' ) );

$processor->process( $s1->get_id() );
$check( 'running the renewal again charges nothing and changes nothing', $charges === $harness->charges && Status_Presenter::for( wc_get_order( $s1->get_id() ) ) === $state );

$stranger = wp_insert_user( array( 'user_login' => 'sk_states_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'sb-states-' . wp_generate_password( 6, false, false ) . '@example.test', 'role' => 'customer' ) );
$users[]  = $stranger;
wp_set_current_user( $stranger );
$theirs = $page( $s1->get_id() );
list( $code ) = $rest( $s1->get_id() );
wp_set_current_user( 1 );
$check( 'another customer sees none of it', str_contains( $theirs, 'could not be found' ) && ! str_contains( $theirs, 'Payment processing' ), $theirs );
$check( 'nor can they read the admin payload', 403 === $code, $code );

echo "\n2. Once the provider confirms it\n";
$processor->resolve_pending( wc_get_order( $order1->get_id() ), Charge_Result::success( 'dd_1' ) );
$s1    = wc_get_order( $s1->get_id() );
$state = Status_Presenter::for( $s1 );
list( , $data ) = $rest( $s1->get_id() );
$check( 'the status is plain Active with a next payment in the future', 'Active' === $state['label'] && str_starts_with( $state['detail'], 'Next payment on' ) && strtotime( $s1->get_next_payment() . ' UTC' ) > time(), $state );
$check( 'Pay early is offered again', Early_Renewal::is_available_for( $s1 ) && str_contains( $page( $s1->get_id() ), 'Pay early' ) );
$check( 'and the admin payload no longer reports a pending payment', false === $data['payment_pending'] && null === $data['payment_pending_since'] && null === $data['payment_pending_order'], $data );

echo "\n3. Marked paid by hand, then failed by the provider\n";
$s3                     = $make( Test_Gateway::ID );
list( $slot3, $order3 ) = $submit( $s3 );
wc_get_order( $order3->get_id() )->update_status( 'processing', 'Marked paid by the store, as the harness.' );
$settled = $slots->find( $s3->get_id(), (int) $slot3->period_index );
$s3      = wc_get_order( $s3->get_id() );
$next3   = $s3->get_next_payment();
$check( 'marking it paid settles it early', Charge_Slot_Repository::STATE_PAID === $settled->state && strtotime( $next3 . ' UTC' ) === strtotime( $slot3->covers_to_gmt . ' UTC' ), array( $settled, $next3 ) );

$needle  = 'reported that the payment for renewal order #' . $order3->get_order_number() . ' failed';
$charges = $harness->charges;
$check( 'the late failure settles nothing', ! $processor->resolve_pending( wc_get_order( $order3->get_id() ), Charge_Result::hard_decline( 'payment_failed', 'The bank returned it.' ) ) );
$check( 'but the order says so, once', 1 === $notes_saying( $order3->get_id(), $needle ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order3->get_id() ) ), 'content' ) );
$check( 'and so does the subscription activity, once', 1 === $activity_saying( $s3->get_id(), $needle ) );
$check( 'telling the merchant to collect or cancel, and why the provider failed it', 1 === $notes_saying( $order3->get_id(), 'collect the payment from the customer, or cancel the subscription. Reason given: The bank returned it.' ) );
$s3 = wc_get_order( $s3->get_id() );
$check( 'nothing is undone or charged again', Subscription_Status::Active === $s3->get_status_enum() && $next3 === $s3->get_next_payment() && Charge_Slot_Repository::STATE_PAID === $slots->find( $s3->get_id(), (int) $slot3->period_index )->state && $charges === $harness->charges && wc_get_order( $order3->get_id() )->is_paid(), array( $s3->get_status(), $s3->get_next_payment() ) );

$processor->resolve_pending( wc_get_order( $order3->get_id() ), Charge_Result::hard_decline( 'payment_failed', 'The bank returned it.' ) );
$processor->resolve_pending( wc_get_order( $order3->get_id() ), Charge_Result::soft_decline( 'insufficient_funds', 'Not enough in the account.' ) );
$check( 'a second delivery adds nothing', 1 === $notes_saying( $order3->get_id(), $needle ) && 1 === $activity_saying( $s3->get_id(), $needle ) );

$s3b                      = $make( Test_Gateway::ID );
list( $slot3b, $order3b ) = $submit( $s3b );
wc_get_order( $order3b->get_id() )->update_status( 'processing', 'Marked paid by the store, as the harness.' );
$lock->acquire( $s3b->get_id() );
$processor->resolve_pending( wc_get_order( $order3b->get_id() ), Charge_Result::hard_decline( 'payment_failed', 'The bank returned it.' ) );
$needle_b = 'renewal order #' . $order3b->get_order_number() . ' failed';
$queued   = array( 'order_id' => $order3b->get_id(), 'outcome' => 'hard_decline', 'reference' => '', 'code' => 'payment_failed', 'message' => 'The bank returned it.' );
$check( 'under a running renewal\'s lock it is queued, not written', 0 === $notes_saying( $order3b->get_id(), $needle_b ) && (bool) as_next_scheduled_action( Renewal_Processor::ACTION_RESOLVE, $queued, 'submex' ) );
$lock->release( $s3b->get_id() );
as_unschedule_all_actions( Renewal_Processor::ACTION_RESOLVE, $queued, 'submex' );
$processor->resolve_queued( ...array_values( $queued ) );
$processor->resolve_queued( ...array_values( $queued ) );
$check( 'and the queued answer records it once', 1 === $notes_saying( $order3b->get_id(), $needle_b ) && 1 === $activity_saying( $s3b->get_id(), $needle_b ) );

$s3c                      = $make( Test_Gateway::ID );
list( $slot3c, $order3c ) = $submit( $s3c );
$processor->resolve_pending( wc_get_order( $order3c->get_id() ), Charge_Result::success( 'dd_c' ) );
$processor->resolve_pending( wc_get_order( $order3c->get_id() ), Charge_Result::hard_decline( 'payment_failed', 'A stale event.' ) );
$check( 'a renewal the provider itself confirmed is not reported as failed by a stale event', 0 === $notes_saying( $order3c->get_id(), 'after the order was marked paid' ) && 0 === $activity_saying( $s3c->get_id(), 'after the order was marked paid' ) );

$s3d                      = $make( Test_Gateway::ID );
list( $slot3d, $order3d ) = $submit( $s3d );
wc_get_order( $order3d->get_id() )->update_status( 'processing', 'Marked paid by the store, as the harness.' );
$processor->resolve_pending( wc_get_order( $order3d->get_id() ), Charge_Result::success( 'dd_d' ) );
$check( 'nor is one the provider later confirms', 0 === $activity_saying( $s3d->get_id(), 'after the order was marked paid' ) );

echo "\n4. Paid from a link, not failed\n";
$s4 = $make( Manual_Gateway::ID );
$processor->process( $s4->get_id() );
$s4     = wc_get_order( $s4->get_id() );
$order4 = Status_Presenter::payable_order( $s4 );
$state  = Status_Presenter::for( $s4 );
$action = Status_Presenter::primary_action( $s4 );
if ( Subscription_Status::OnHold !== $s4->get_status_enum() || ! $order4 ) {
	submex_test_abort( 'a manual renewal did not leave the subscription on hold with an order to pay: ' . $s4->get_status() );
}
$check( 'the badge says the renewal is due', 'Renewal due' === $state['label'], $state );
$check( 'the detail says it is ready to pay, with its amount', 'Your renewal of $20.00 is ready to pay.' === $state['detail'], $state['detail'] );
$check( 'the button pays that order', $action && 'Pay renewal' === $action['label'] && $order4->get_checkout_payment_url() === $action['url'], $action );
$detail = $page( $s4->get_id() );
$check( 'My Account reads as a renewal to pay, not a failure', str_contains( $detail, 'Renewal due' ) && str_contains( $detail, 'Pay renewal' ) && ! str_contains( $detail, 'Payment needed' ) && ! str_contains( $detail, "couldn't take" ) && ! str_contains( $detail, 'Confirm payment' ), $detail );
$check( 'the list too', str_contains( $page(), 'Renewal due' ) && ! str_contains( $page(), 'Payment needed' ) );

$s4b = $make( 'sk_test_pay_link' );
$processor->process( $s4b->get_id() );
$check( 'a pay-by-link gateway of another plugin reads the same', 'Renewal due' === Status_Presenter::for( wc_get_order( $s4b->get_id() ) )['label'] && 'Pay renewal' === ( Status_Presenter::primary_action( wc_get_order( $s4b->get_id() ) )['label'] ?? '' ) );

$s4c    = $make( Manual_Gateway::ID, 20 * DAY_IN_SECONDS );
$detail = $page( $s4c->get_id() );
$check( 'an active pay-by-link subscription never says it renews automatically', ! str_contains( $detail, 'Automatic renewal' ) && ! str_contains( $detail, 'automatic renewal' ) && ! str_contains( $detail, 'renews automatically' ) && str_contains( $detail, 'we send you a renewal to pay' ) && str_contains( $detail, 'Stop renewing' ), $detail );

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	// Handlers redirect and exit once they act; stop at the redirect and read the state.
	final class Submex_Test_Redirected extends Exception {}
}
$told     = array();
$redirect = static function ( $to ) {
	throw new Submex_Test_Redirected( (string) $to );
};
$listen   = static function ( $message ) use ( &$told ) {
	$told[] = $message;
	return $message;
};
add_filter( 'wp_redirect', $redirect, 1 );
add_filter( 'woocommerce_add_success', $listen );
$toggle = static function ( Subscription $s, string $to ) use ( $account ): void {
	$_POST = array(
		'submex_action'       => 'auto_renew',
		'submex_subscription' => (string) $s->get_id(),
		'submex_auto_renew'   => $to,
		'_wpnonce'            => wp_create_nonce( 'submex_auto_renew_' . $s->get_id() ),
	);
	try {
		$account->handle_actions();
	} catch ( Submex_Test_Redirected $e ) {
		unset( $e );
	}
	$_POST = array();
};
$toggle( $s4c, 'off' );
$toggle( $s4c, 'on' );
$check( 'turning it off and on tells the customer about renewals to pay, not automatic renewal', 2 === count( $told ) && ! str_contains( implode( ' ', $told ), 'utomatic' ) && str_contains( $told[0], 'not be asked to pay again' ), $told );
$told = array();
$toggle( $make( Test_Gateway::ID, 20 * DAY_IN_SECONDS ), 'off' );
$check( 'while an automatic one is told automatic renewal is off', 1 === count( $told ) && str_starts_with( $told[0], 'Automatic renewal is off' ), $told );
remove_filter( 'wp_redirect', $redirect, 1 );
remove_filter( 'woocommerce_add_success', $listen );

$s5            = $make( Test_Gateway::ID );
$harness->next = 'decline';
$processor->process( $s5->get_id() );
$s5    = wc_get_order( $s5->get_id() );
$state = Status_Presenter::for( $s5 );
// Submex Pro, when active, gives a failed renewal a grace period, and the wording names its end.
$failed = $s5->in_grace() ? sprintf( "We couldn't take your last payment. You keep access until %s.", wp_date( (string) get_option( 'date_format' ), (int) $s5->grace_ends_at() ) ) : "We couldn't take your last payment.";
$check( 'a declined card renewal keeps the failure wording', Subscription_Status::OnHold === $s5->get_status_enum() && 'Payment needed' === $state['label'] && $failed === $state['detail'] && 'Pay now' === ( Status_Presenter::primary_action( $s5 )['label'] ?? '' ), array( $s5->get_status(), $state ) );

$s6     = $make( Test_Gateway::ID, 20 * DAY_IN_SECONDS );
$detail = $page( $s6->get_id() );
$check( 'and an automatic subscription still says it renews automatically', str_contains( $detail, 'Automatic renewal' ) && str_contains( $detail, 'This subscription renews automatically.' ), $detail );

// ---- clean up -----------------------------------------------------------------------
foreach ( $made as $id ) {
	$renewals = $wpdb->get_col( $wpdb->prepare( 'SELECT renewal_order_id FROM %i WHERE subscription_id = %d AND renewal_order_id IS NOT NULL', $wpdb->prefix . 'submex_charge_slot', $id ) );
	foreach ( $renewals as $renewal_id ) {
		$r = wc_get_order( (int) $renewal_id );
		$r && $r->delete( true );
	}
	$free->get( 'scheduler' )->unschedule( $id );
	as_unschedule_all_actions( 'submex_dunning_retry', array( 'subscription_id' => $id ), 'submex' );
	$s = wc_get_order( $id );
	$s && $s->delete( true );
	$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'submex_activity', array( 'subscription_id' => $id ) );
}
foreach ( $users as $user ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user );
}
echo "\nAn extension names a state the free plugin does not know\n";
$plain  = Status_Presenter::for( $s1 );
$paused = static fn( $state ) => array(
	'label' => 'Paused',
	'tone'  => 'neutral',
);
add_filter( 'submex_status_presentation', $paused );
$named = Status_Presenter::for( $s1 );
remove_filter( 'submex_status_presentation', $paused );
$check( 'its label and tone are shown, and the detail it left out stays', 'Paused' === $named['label'] && 'neutral' === $named['tone'] && $plain['detail'] === $named['detail'], $named );
$broken = static fn() => 'not a state';
add_filter( 'submex_status_presentation', $broken );
$check( 'a filter that returns nonsense leaves the state as it was', $plain === Status_Presenter::for( $s1 ) );
remove_filter( 'submex_status_presentation', $broken );

foreach ( $was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
