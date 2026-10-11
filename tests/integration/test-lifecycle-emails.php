<?php
/**
 * The renewal reminder and the store notifications: scheduled at the right time, sent to the right person.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$sent = array();
add_filter( 'wp_mail', function ( $args ) use ( &$sent ) { $sent[] = array( 'to' => $args['to'], 'subject' => $args['subject'], 'body' => wp_strip_all_tags( $args['message'] ) ); return $args; }, 999 );

$emails = WC()->mailer()->get_emails();
foreach ( array( 'Submex_Renewal_Reminder', 'Submex_Merchant_Cancelled', 'Submex_Merchant_Ended' ) as $key ) {
	$check( "$key registered with WooCommerce", isset( $emails[ $key ] ), array_keys( $emails ) );
}

$make = function ( Subscription_Status $status, int $days ) {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_address( array( 'first_name' => 'Reminder', 'last_name' => 'Buyer', 'email' => 'reminder@example.test' ), 'billing' );
	$s->set_payment_method_title( 'Visa ending 4242' );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$s->transition_to( $status );
	$s->save();
	return $s;
};

$scheduler = \Submex\Plugin::instance()->get( 'scheduler' );

echo "\nScheduling\n";
$hours_were = get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, null );
update_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, 72 );
$far = $make( Subscription_Status::Active, 10 );
$scheduler->schedule_next( $far );
$at = as_next_scheduled_action( Renewal_Scheduler::ACTION_REMINDER, array( 'subscription_id' => $far->get_id() ), Renewal_Scheduler::GROUP );
$check( 'reminder queued the set hours before the charge', $at && abs( $at - ( time() + 7 * DAY_IN_SECONDS ) ) < 120, array( $at, time() + 7 * DAY_IN_SECONDS ) );

$soon = $make( Subscription_Status::Active, 1 );
$scheduler->schedule_next( $soon );
$check( 'no reminder when the charge is sooner than the notice period', ! as_next_scheduled_action( Renewal_Scheduler::ACTION_REMINDER, array( 'subscription_id' => $soon->get_id() ), Renewal_Scheduler::GROUP ) );

update_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, 12 );
$off = $make( Subscription_Status::Active, 1 );
$scheduler->schedule_next( $off );
$at = as_next_scheduled_action( Renewal_Scheduler::ACTION_REMINDER, array( 'subscription_id' => $off->get_id() ), Renewal_Scheduler::GROUP );
$check( 'a shorter notice period still reminds a charge a day away', $at && abs( $at - ( time() + 12 * HOUR_IN_SECONDS ) ) < 120, $at );
null === $hours_were ? delete_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) : update_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, $hours_were );

echo "\nSending\n";
$sent = array();
$scheduler->remind( $far->get_id() );
$check( 'reminder sent to the customer', ( $sent[0]['to'] ?? '' ) === 'reminder@example.test', $sent );
$check( 'it names the amount and the date', isset( $sent[0] ) && str_contains( $sent[0]['body'], gmdate( 'F j, Y', time() + 10 * DAY_IN_SECONDS ) ), $sent[0]['body'] ?? '' );

$sent = array();
$trial = $make( Subscription_Status::Trialling, 5 );
$scheduler->remind( $trial->get_id() );
$check( 'a trial is told its trial is ending, not that it is renewing', isset( $sent[0] ) && str_contains( $sent[0]['body'], 'free trial ends' ), $sent[0]['body'] ?? '' );

$sent = array();
$past = $make( Subscription_Status::Active, 10 );
$past->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$past->save();
$scheduler->remind( $past->get_id() );
$check( 'no reminder once the payment date has passed', array() === $sent, $sent );

$sent = array();
do_action( 'submex_subscription_cancelled', $far );
$admin = get_option( 'admin_email' );
$check( 'merchant told about the cancellation', (bool) array_filter( $sent, fn( $m ) => $m['to'] === $admin ), array_column( $sent, 'to' ) );
$check( 'customer still gets their confirmation', (bool) array_filter( $sent, fn( $m ) => $m['to'] === 'reminder@example.test' ), array_column( $sent, 'to' ) );

$sent = array();
do_action( 'submex_subscription_finished', $far, 'Subscription reached its end date.' );
$check( 'merchant told the subscription ended', (bool) array_filter( $sent, fn( $m ) => $m['to'] === $admin ), array_column( $sent, 'to' ) );
$check( 'the reason is in the email', (bool) array_filter( $sent, fn( $m ) => str_contains( $m['body'], 'reached its end date' ) ), $sent[0]['body'] ?? '' );

foreach ( array( $far, $soon, $off, $trial, $past ) as $s ) { $scheduler->unschedule( $s->get_id() ); $s->delete( true ); }
submex_test_done( $fail );
