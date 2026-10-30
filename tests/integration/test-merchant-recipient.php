<?php
/**
 * Merchant emails go where the store's order emails go unless told otherwise, and say so.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';

$emails   = WC()->mailer()->get_emails();
$merchant = $emails['Submex_Merchant_New_Subscription'] ?? null;
$customer = $emails['Submex_Subscription_Started'] ?? null;

if ( ! $merchant || ! $customer ) {
	submex_test_abort( 'the Submex emails are not registered' );
}

$check( 'a merchant email has a recipient setting', isset( $merchant->form_fields['recipient'] ) );
$check( 'a customer email does not', ! isset( $customer->form_fields['recipient'] ) );

$was = get_option( 'woocommerce_new_order_settings', null );
update_option( 'woocommerce_new_order_settings', array( 'enabled' => 'yes', 'recipient' => 'orders@shop.example.test' ) );

$to  = array();
$spy = static function ( $args ) use ( &$to ) {
	$to[] = $args['to'];
	return $args;
};
add_filter( 'wp_mail', $spy );

$subscription = new \Submex\Domain\Subscription();
$subscription->set_billing_email( 'buyer@example.test' );
$subscription->save();
$merchant->trigger( $subscription );
$check( 'by default it goes to the new order recipient', in_array( 'orders@shop.example.test', $to, true ), $to );

remove_filter( 'wp_mail', $spy );
null === $was ? delete_option( 'woocommerce_new_order_settings' ) : update_option( 'woocommerce_new_order_settings', $was );
$subscription->delete( true );

submex_test_done( $fail );
