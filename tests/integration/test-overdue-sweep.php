<?php
/**
 * The hourly sweep picks up overdue subscriptions, trials included, when a scheduled action was missed.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$make = function ( Subscription_Status $status ) {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$s->transition_to( $status );
	$s->save();
	return $s->get_id();
};

$trial  = $make( Subscription_Status::Trialling );
$active = $make( Subscription_Status::Active );

$scheduler = \Submex\Plugin::instance()->get( 'scheduler' );
$method    = new ReflectionMethod( $scheduler, 'due_ids' );
$method->setAccessible( true );
$due = $method->invoke( $scheduler, gmdate( 'Y-m-d H:i:s' ), 200, 0 );

$check( 'overdue active subscription is swept', in_array( $active, $due, true ), $due );
$check( 'overdue trial is swept', in_array( $trial, $due, true ), $due );

foreach ( array( $trial, $active ) as $id ) { wc_get_order( $id )->delete( true ); }
submex_test_done( $fail );
