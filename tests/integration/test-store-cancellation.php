<?php
/**
 * A cancellation made from the admin tells the customer, as one made in My Account does.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$sent = array();
add_filter( 'wp_mail', function ( $a ) use ( &$sent ) { $sent[] = $a['to']; return $a; }, 999 );

$s = new Subscription();
$s->set_customer_id( 1 );
$s->set_currency( get_woocommerce_currency() );
$s->set_address( array( 'first_name' => 'Store', 'email' => 'storecancel@example.test' ), 'billing' );
$s->transition_to( Subscription_Status::Pending );
$s->save();
$s->transition_to( Subscription_Status::Active );
$s->save();

$c  = new \Submex\Rest\Subscriptions_Controller( \Submex\Plugin::instance()->get( 'activity' ), \Submex\Plugin::instance()->get( 'processor' ) );
$m  = new ReflectionMethod( $c, 'transition' );
$m->setAccessible( true );
$m->invoke( $c, $s, Subscription_Status::Cancelled, 'Cancelled by the store.' );

$check( 'customer is told', in_array( 'storecancel@example.test', $sent, true ), $sent );
$check( 'store is told', in_array( get_option( 'admin_email' ), $sent, true ), $sent );
$check( 'the subscription really is cancelled', str_contains( wc_get_order( $s->get_id() )->get_status(), 'cancelled' ), wc_get_order( $s->get_id() )->get_status() );

$s->delete( true );
submex_test_done( $fail );
