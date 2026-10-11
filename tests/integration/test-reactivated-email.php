<?php
/**
 * The reactivated email: queued once, sent once, nothing when switched off or when the
 * subscription has moved on, and a renewal is never mistaken for a reactivation.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Emails\Mailer;
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

$scheduler = \Submex\Plugin::instance()->get( 'scheduler' );
$mailer    = WC()->mailer();
$emails    = $mailer->get_emails();

$absent  = new stdClass();
$options = array( $emails['Submex_Subscription_Reactivated']->get_option_key() );
$was = array();
foreach ( $options as $name ) {
	$was[ $name ] = get_option( $name, $absent );
}

// As a new request would see it: the mailer reads each email's switch once, when it is built.
$switch = static function ( string $key, string $on ) use ( $emails ): void {
	$option = $emails[ $key ]->get_option_key();
	update_option( $option, array_merge( (array) get_option( $option, array() ), array( 'enabled' => $on ) ) );
	WC()->mailer()->init();
};
$switch( 'Submex_Subscription_Reactivated', 'yes' );

$mail = array();
add_filter(
	'wp_mail',
	static function ( $args ) use ( &$mail ) {
		if ( str_ends_with( (string) $args['to'], '@notify.example.test' ) ) {
			$mail[] = array( 'to' => $args['to'], 'subject' => $args['subject'], 'body' => wp_strip_all_tags( $args['message'] ) );
		}
		return $args;
	},
	999
);
$to = static function ( Subscription $s ) use ( &$mail ): array {
	return array_values( array_filter( $mail, static fn( $m ) => $m['to'] === $s->get_billing_email() ) );
};

$product = submex_test_product();
$made    = array();
$make    = static function ( Subscription_Status $status, array $dates ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Test_Gateway::ID );
	$s->set_address( array( 'first_name' => 'Notify', 'email' => 'sub' . wp_generate_password( 6, false, false ) . '@notify.example.test', 'country' => 'US' ), 'billing' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$i = new WC_Order_Item_Product();
	$i->set_props( array( 'name' => 'Harness', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $i );
	foreach ( $dates as $prop => $offset ) {
		$s->{"set_$prop"}( gmdate( 'Y-m-d H:i:s', time() + $offset ) );
	}
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	if ( in_array( $status, array( Subscription_Status::OnHold, Subscription_Status::PendingCancel ), true ) ) {
		$s->transition_to( Subscription_Status::Active );
		$s->save();
	}
	$s->transition_to( $status );
	$s->save();
	$made[] = $s->get_id();
	return wc_get_order( $s->get_id() );
};

$pending = static function ( string $hook, ?int $subscription_id = null ): array {
	$found = array();
	foreach ( as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Renewal_Scheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) ) as $id => $action ) {
		if ( null === $subscription_id || (int) ( $action->get_args()['subscription_id'] ?? 0 ) === $subscription_id ) {
			$found[ $id ] = $action;
		}
	}
	return $found;
};
$run = static function ( array $actions ): void {
	foreach ( array_keys( $actions ) as $id ) {
		ActionScheduler_QueueRunner::instance()->process_action( $id, 'submex-test' );
	}
};

$day = DAY_IN_SECONDS;

echo "\n1. Reactivated\n";
$reactivations = static fn( Subscription $s ): array => $pending( Mailer::ACTION_REACTIVATED, $s->get_id() );

$undone = $make( Subscription_Status::Active, array( 'next_payment' => 10 * $day ) );
$undone->transition_to( Subscription_Status::PendingCancel );
$undone->save();
$request = new WP_REST_Request( 'POST', '/submex/v1/subscriptions/' . $undone->get_id() . '/actions' );
$request->set_param( 'action', 'reactivate' );
$check( 'fixture: the store reactivates a cancelling subscription', 200 === rest_do_request( $request )->get_status() );
$queued = $reactivations( $undone );
$check( 'it is queued once', 1 === count( $queued ), count( $queued ) );

$mail = array();
$run( $queued );
$got = $to( $undone );
$check( 'the customer is told once', 1 === count( $got ) && 'Your subscription is active again' === $got[0]['subject'], $got );
$again = reset( $queued );
$mailer_service = \Submex\Plugin::instance()->get( 'mailer' );
$mailer_service->send_reactivated( $again->get_args()['subscription_id'], $again->get_args()['at'] );
$check( 'running it again sends nothing', 1 === count( $to( $undone ) ) );

// Each reactivation is known by the second it happened in.
sleep( 1 );
$undone->transition_to( Subscription_Status::PendingCancel );
$undone->save();
$undone->transition_to( Subscription_Status::Active );
$undone->save();
$mail = array();
$run( $reactivations( $undone ) );
$check( 'a later reactivation is its own email', 1 === count( $to( $undone ) ), $to( $undone ) );

$switched = $make( Subscription_Status::Active, array( 'next_payment' => 10 * $day ) );
$switched->transition_to( Subscription_Status::PendingCancel );
$switched->save();
$switched->transition_to( Subscription_Status::Active );
$switched->save();
$switched->transition_to( Subscription_Status::Switched );
$switched->save();
$mail   = array();
$queued = $reactivations( $switched );
$run( $queued );
$check( 'withdrawn and then switched away in the same request: nothing is sent', 1 === count( $queued ) && array() === $mail, array( count( $queued ), $mail ) );

$resumed = $make( Subscription_Status::Active, array( 'next_payment' => 10 * $day ) );
do_action( 'submex_subscription_resumed', $resumed );
$mail = array();
$run( $reactivations( $resumed ) );
$check( 'a paused subscription resuming is told', 1 === count( $to( $resumed ) ), $to( $resumed ) );

$switch( 'Submex_Subscription_Reactivated', 'no' );
$muted = $make( Subscription_Status::Active, array( 'next_payment' => 10 * $day ) );
$muted->transition_to( Subscription_Status::PendingCancel );
$muted->save();
$muted->transition_to( Subscription_Status::Active );
$muted->save();
$mail   = array();
$queued = $reactivations( $muted );
$run( $queued );
$check( 'switched off, nothing is sent', 1 === count( $queued ) && array() === $mail, array( count( $queued ), $mail ) );
$switch( 'Submex_Subscription_Reactivated', 'yes' );

echo "\n2. Renewals are not reactivations\n";
$trial = $make( Subscription_Status::Trialling, array( 'trial_end' => -60, 'next_payment' => -60 ) );
\Submex\Plugin::instance()->get( 'processor' )->process( $trial->get_id() );
$check( 'fixture: the trial converted by renewing', Subscription_Status::Active === wc_get_order( $trial->get_id() )->get_status_enum(), wc_get_order( $trial->get_id() )->get_status() );
$active = $make( Subscription_Status::Active, array( 'next_payment' => -60 ) );
\Submex\Plugin::instance()->get( 'processor' )->process( $active->get_id() );
$check( 'fixture: an active subscription renewed', strtotime( (string) wc_get_order( $active->get_id() )->get_next_payment() . ' UTC' ) > time() );
$held = $make( Subscription_Status::OnHold, array( 'next_payment' => -60 ) );
$held->transition_to( Subscription_Status::Active, 'Retrying the failed payment.' );
$held->save();
$check( 'no reactivated email for a trial converting, a renewal, or a retry after a failed payment', array() === $reactivations( $trial ) && array() === $reactivations( $active ) && array() === $reactivations( $held ) );

foreach ( $made as $id ) {
	$scheduler->unschedule( $id );
	foreach ( $pending( Mailer::ACTION_REACTIVATED, $id ) as $action_id => $action ) {
		as_unschedule_action( Mailer::ACTION_REACTIVATED, $action->get_args(), Renewal_Scheduler::GROUP );
	}
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id IN (" . implode( ',', array_map( 'intval', $made ) ) . ')' );
foreach ( $was as $name => $value ) {
	if ( $absent === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

submex_test_done( $fail );
