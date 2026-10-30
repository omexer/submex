<?php
/**
 * PayPal's webhooks: the payment taken at approval is not a renewal, later ones are, and a
 * cancellation or failed payment at PayPal tells the customer the way one here would.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Gateway;
use Submex\Gateways\PayPal\Webhook_Controller;

require __DIR__ . '/bootstrap.php';

$plugin   = \Submex\Plugin::instance();
$webhooks = $plugin->get( 'paypal_webhooks' );
$factory  = $plugin->get( 'subscription_factory' );
$product  = submex_test_product();
$made     = array();

$deliver = static function ( string $type, array $resource ) use ( $webhooks ): void {
	$id = 'WH-' . wp_generate_password( 10, false );
	set_transient( 'submex_pp_evt_body_' . md5( $id ), array( 'id' => $id, 'event_type' => $type, 'resource' => $resource ), HOUR_IN_SECONDS );
	$webhooks->process( $id );
};

$subscribed = static function () use ( $product, $factory, &$made ): Subscription {
	$order = wc_create_order( array( 'customer_id' => 1 ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'sb-paypal-webhook@example.test' );
	$order->set_payment_method( PayPal_Checkout_Gateway::ID );
	$order->calculate_totals( false );
	$order->save();
	$made[] = $order;

	$factory->from_checkout( $order->get_id() );
	$order = wc_get_order( $order->get_id() );
	$order->payment_complete( 'I-WEBHOOK' );

	$subscription = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );
	$subscription->update_meta_data( PayPal_Gateway::META_SUBSCRIPTION_ID, 'I-' . $subscription->get_id() );
	$subscription->save();
	$made[] = $subscription;

	return $subscription;
};

$renewals = static function ( Subscription $s ): array {
	return array_values(
		array_filter(
			wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'created_via' => 'submex_renewal' ) ),
			static fn( $o ) => (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id()
		)
	);
};

$sent = array();
$spy  = static function ( $args ) use ( &$sent ) {
	$sent[] = (string) $args['subject'];
	return $args;
};
add_filter( 'wp_mail', $spy );

echo "\n1. The payment PayPal takes at approval\n";
$s    = $subscribed();
$next = $s->get_next_payment();
$sent = array();
$deliver( 'PAYMENT.SALE.COMPLETED', array( 'id' => 'SALE-FIRST', 'billing_agreement_id' => 'I-' . $s->get_id() ) );
$s = wc_get_order( $s->get_id() );
$check( 'books no renewal', array() === $renewals( $s ), count( $renewals( $s ) ) );
$check( 'leaves the next payment where it was', $next === $s->get_next_payment(), array( $next, $s->get_next_payment() ) );
$check( 'and sends no renewal receipt', ! array_filter( $sent, static fn( $subject ) => str_contains( strtolower( $subject ), 'receipt' ) ), $sent );
$check( 'the checkout order records the PayPal payment', 'SALE-FIRST' === wc_get_order( $s->get_parent_order_id() )->get_meta( Webhook_Controller::META_TXN_ID ) );

echo "\n2. The next payment PayPal takes, at its own hour, before checkout's time of day\n";
$deliver( 'PAYMENT.SALE.COMPLETED', array( 'id' => 'SALE-SECOND', 'billing_agreement_id' => 'I-' . $s->get_id(), 'create_time' => gmdate( 'Y-m-d\TH:i:s\Z', time() + MONTH_IN_SECONDS - 5 * HOUR_IN_SECONDS ) ) );
$s = wc_get_order( $s->get_id() );
$check( 'is a renewal', 1 === count( $renewals( $s ) ) && 'SALE-SECOND' === $renewals( $s )[0]->get_meta( Webhook_Controller::META_TXN_ID ), count( $renewals( $s ) ) );

echo "\n3. Approved, then the tab closed before returning\n";
$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_product( $product, 1 );
$order->set_billing_email( 'sb-paypal-webhook@example.test' );
$order->set_payment_method( PayPal_Checkout_Gateway::ID );
$order->calculate_totals( false );
$order->save();
$made[] = $order;
$factory->from_checkout( $order->get_id() );
$closed = wc_get_order( (int) wc_get_order( $order->get_id() )->get_meta( '_submex_subscription_id' ) );
$closed->update_meta_data( PayPal_Gateway::META_SUBSCRIPTION_ID, 'I-' . $closed->get_id() );
$closed->save();
$made[] = $closed;
$deliver( 'PAYMENT.SALE.COMPLETED', array( 'id' => 'SALE-CLOSED', 'billing_agreement_id' => 'I-' . $closed->get_id() ) );
$check( 'the payment PayPal reports marks the order paid', wc_get_order( $order->get_id() )->is_paid() );
$check( 'and starts the subscription', Subscription_Status::Active === wc_get_order( $closed->get_id() )->get_status_enum(), wc_get_order( $closed->get_id() )->get_status() );
$check( 'without a renewal', array() === $renewals( $closed ) );

echo "\n4. Cancelled and failed at PayPal\n";
$sent = array();
$deliver( 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', array( 'id' => 'I-' . $s->get_id() ) );
$s = wc_get_order( $s->get_id() );
$check( 'a failed payment puts it on hold', Subscription_Status::OnHold === $s->get_status_enum(), $s->get_status() );
$check( 'and tells the customer', (bool) array_filter( $sent, static fn( $subject ) => str_contains( $subject, "couldn't process your payment" ) ), $sent );

$sent = array();
$deliver( 'BILLING.SUBSCRIPTION.CANCELLED', array( 'id' => 'I-' . $s->get_id() ) );
$s = wc_get_order( $s->get_id() );
$check( 'a cancellation cancels it', Subscription_Status::Cancelled === $s->get_status_enum(), $s->get_status() );
$check( 'and is announced', (bool) array_filter( $sent, static fn( $subject ) => str_contains( strtolower( $subject ), 'cancel' ) ), $sent );

remove_filter( 'wp_mail', $spy );
foreach ( $made as $thing ) {
	foreach ( $renewals( $thing instanceof Subscription ? $thing : new Subscription() ) as $renewal ) {
		$renewal->delete( true );
	}
	$thing->delete( true );
}

submex_test_done( $fail );
