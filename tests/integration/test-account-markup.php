<?php
/**
 * My Account on any theme: buttons carry the class block themes style, and a price says how often it recurs.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Status_Presenter;

require __DIR__ . '/bootstrap.php';

$subscription = new Subscription();
$subscription->set_customer_id( 1 );
$subscription->set_currency( 'USD' );
$subscription->set_billing_period( 'month' );
$subscription->set_billing_interval( 3 );
$subscription->set_payment_method( 'submex_test' );
$subscription->set_address( array( 'first_name' => 'Markup', 'email' => 'markup@example.test', 'country' => 'US' ), 'billing' );
$item = new WC_Order_Item_Product();
$item->set_props( array( 'name' => 'Markup probe', 'product_id' => submex_test_product()->get_id(), 'quantity' => 1, 'subtotal' => '40', 'total' => '40' ) );
$subscription->add_item( $item );
$subscription->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
$subscription->transition_to( Subscription_Status::Pending );
$subscription->calculate_totals( false );
$subscription->save();
$subscription->transition_to( Subscription_Status::Active );
$subscription->save();

$check( 'a price says how often it recurs', str_contains( wp_strip_all_tags( Status_Presenter::price( $subscription ) ), 'every 3 months' ), Status_Presenter::price( $subscription ) );

ob_start();
wc_get_template(
	'myaccount/subscription-details.php',
	array(
		'subscription' => wc_get_order( $subscription->get_id() ),
		'endpoint'     => 'subscriptions',
	),
	'',
	SUBMEX_PATH . 'templates/'
);
$html = (string) ob_get_clean();

preg_match_all( '/class="([^"]*\bsubmex-btn\b[^"]*)"/', $html, $buttons );
$check( 'the detail page has buttons', count( $buttons[1] ) > 0, $html );
$check( 'every one carries the class block themes style', array() === array_filter( $buttons[1], static fn( $c ) => ! str_contains( $c, 'wp-element-button' ) ), $buttons[1] );
$check( 'no form is nested in a paragraph', ! preg_match( '/<p[^>]*>\s*<form/', $html ) );

$subscription->delete( true );

submex_test_done( $fail );
