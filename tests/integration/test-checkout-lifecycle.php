<?php
/**
 * A subscription starts when its checkout is paid, once: a trial grants nothing before then,
 * a cancelled checkout cancels what it created, the merchant hears about paid sign-ups only,
 * and a guest whose first payment failed can try again.
 *
 * @package Submex
 */

use Submex\Checkout\Guest_Checkout;
use Submex\Domain\Subscription_Status;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';

$plugin  = \Submex\Plugin::instance();
$factory = $plugin->get( 'subscription_factory' );
$made    = array();

$trial = new \Submex\Product\Simple_Subscription();
$trial->set_name( 'SK lifecycle trial' );
$trial->set_status( 'publish' );
$trial->set_regular_price( '20' );
$trial->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$trial->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
$trial->update_meta_data( Subscription_Product::META_TRIAL_DAYS, 7 );
$trial->update_meta_data( Subscription_Product::META_TRIAL_PERIOD, 'day' );
$trial->save();
$made[] = $trial;

$checkout = static function ( WC_Product $product, int $customer = 1 ) use ( $factory, &$made ): array {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'sb-lifecycle@example.test' );
	$order->calculate_totals( false );
	$order->save();
	$made[] = $order;

	$factory->from_checkout( $order->get_id() );
	$order        = wc_get_order( $order->get_id() );
	$subscription = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );
	$made[]       = $subscription;

	return array( $order, $subscription );
};

$started = 0;
$count   = static function () use ( &$started ) {
	++$started;
};
add_action( 'submex_subscription_activated', $count );

$sent = array();
$spy  = static function ( $args ) use ( &$sent ) {
	$sent[] = (string) $args['subject'];
	return $args;
};
add_filter( 'wp_mail', $spy );
$merchant_mails = static function () use ( &$sent ): int {
	return count( array_filter( $sent, static fn( $s ) => str_contains( strtolower( $s ), 'new subscription' ) ) );
};

echo "\n1. A trial, before and after its checkout is paid\n";
[ $order, $s ] = $checkout( $trial );
$check( 'is pending until paid, so grants nothing', Subscription_Status::Pending === $s->get_status_enum(), $s->get_status() );
$check( 'and the merchant is not told of a sign-up yet', 0 === $merchant_mails(), $sent );
$order->payment_complete( 'paid' );
$s = wc_get_order( $s->get_id() );
$check( 'paid, the trial starts', Subscription_Status::Trialling === $s->get_status_enum(), $s->get_status() );
$check( 'and the merchant is told once', 1 === $merchant_mails(), $sent );
$order = wc_get_order( $order->get_id() );
$order->update_status( 'completed' );
$check( 'completing the order later does not start it again', 1 === $started, $started );
$check( 'nor tell the merchant again', 1 === $merchant_mails(), $sent );

echo "\n2. Paid after WooCommerce cancelled the unpaid order\n";
[ $order, $s ] = $checkout( $trial );
$order->update_status( 'cancelled', 'Unpaid order cancelled - time limit reached.' );
$check( 'the subscription waits, granting nothing', Subscription_Status::Pending === wc_get_order( $s->get_id() )->get_status_enum(), wc_get_order( $s->get_id() )->get_status() );
wc_get_order( $order->get_id() )->payment_complete( 'paid-late' );
$check( 'and starts when the payment lands', Subscription_Status::Trialling === wc_get_order( $s->get_id() )->get_status_enum(), wc_get_order( $s->get_id() )->get_status() );

echo "\n3. The gateway activates it before the order is paid\n";
$started = 0;
$sent    = array();
[ $order, $s ] = $checkout( submex_test_product() );
$s->transition_to( Subscription_Status::Active, 'PayPal webhook, as the harness.' );
$s->save();
$order->payment_complete( 'returned-later' );
$check( 'paying still starts it, once', 1 === $started, $started );
$check( 'and the merchant is told', 1 === $merchant_mails(), $sent );

echo "\n4. A free trial taken with a manual payment method\n";
$started       = 0;
[ $order, $s ] = $checkout( $trial );
$order->set_total( '0' );
$order->save();
$order->update_status( 'on-hold', 'Awaiting a transfer of nothing.' );
$check( 'starts with nothing to pay', Subscription_Status::Trialling === wc_get_order( $s->get_id() )->get_status_enum() && 1 === $started, wc_get_order( $s->get_id() )->get_status() );

remove_action( 'submex_subscription_activated', $count );
remove_filter( 'wp_mail', $spy );

echo "\n5. A guest whose first payment failed\n";
wp_set_current_user( 0 );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( submex_test_product()->get_id() );
$email = 'sb-guest-retry-' . wp_generate_password( 6, false, false ) . '@example.test';
$guest = $plugin->get( 'guest_checkout' ) ?? new Guest_Checkout();

$first = wc_create_order();
$first->set_billing_email( $email );
$guest->assign_owner( $first );
$account = (int) $first->get_customer_id();
$check( 'gets an account on the first attempt', $account > 0 );

wc_clear_notices();
$_POST['billing_email'] = $email;
$guest->validate_classic();
$check( 'and is not told to log in when trying again', 0 === wc_notice_count( 'error' ), wc_get_notices( 'error' ) );

$second = wc_create_order();
$second->set_billing_email( $email );
$guest->assign_owner( $second );
$check( 'the second attempt uses that account', $account === (int) $second->get_customer_id(), $second->get_customer_id() );

update_user_meta( $account, 'wc_last_active', time() );
wc_clear_notices();
$guest->validate_classic();
$check( 'once the owner has logged in, the address needs a login again', 1 === wc_notice_count( 'error' ) );
delete_user_meta( $account, 'wc_last_active' );

WC()->session->set( 'submex_checkout_account', 0 );
wc_clear_notices();
$guest->validate_classic();
$check( 'from another session the address still needs a login', 1 === wc_notice_count( 'error' ) );

unset( $_POST['billing_email'] );
wc_clear_notices();
WC()->cart->empty_cart();
wp_set_current_user( 1 );
$first->delete( true );
$second->delete( true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $account );

foreach ( array_reverse( array_filter( $made ) ) as $thing ) {
	$thing->delete( true );
}

submex_test_done( $fail );
