<?php
/**
 * What a subscription costs today: trials, sign-up fees, coupons, and the payment step a zero total would skip.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';

$equals = static function ( string $what, $got, $want ) use ( $check ): void {
	$check( $what, (string) $got === (string) $want, array( 'got' => $got, 'want' => $want ) );
};

$make = function ( float $price, int $trial, float $fee ) {
	$id = wp_insert_post( array( 'post_title' => 'SK probe', 'post_type' => 'product', 'post_status' => 'publish' ) );
	$p = wc_get_product( $id );
	$p->update_meta_data( '_submex_enabled', 'yes' );
	$p->set_regular_price( (string) $price );
	$p->set_price( (string) $price );
	$p->update_meta_data( '_submex_period', 'month' );
	$p->update_meta_data( '_submex_interval', 1 );
	$p->update_meta_data( '_submex_trial_days', $trial );
	$p->update_meta_data( '_submex_trial_period', 'day' );
	$p->update_meta_data( '_submex_signup_fee', (string) $fee );
	$p->save();
	return $id;
};

$cart_total = function ( int $id ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $id );
	WC()->cart->calculate_totals();
	return array( wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ), WC()->cart->needs_payment() );
};

echo "1. 14-day trial, \$5 sign-up fee, \$20/month\n";
$a = $make( 20, 14, 5 );
list( $total, $needs ) = $cart_total( $a );
$equals( 'charged today', $total, '5.00' );
$equals( 'payment step shown', $needs, true );
$equals( 'recurring price still 20', \Submex\Product\Subscription_Product::recurring_price( wc_get_product( $a ) )->decimal(), '20.00' );

echo "\n2. 14-day trial, no sign-up fee (the zero-total case)\n";
$b = $make( 20, 14, 0 );
list( $total, $needs ) = $cart_total( $b );
$equals( 'charged today', $total, '0.00' );
$equals( 'payment step still shown', $needs, true );

echo "\n3. Same, with the setting switched off\n";
update_option( \Submex\Checkout\Trial_Payment::OPTION, 'no' );
list( $total, $needs ) = $cart_total( $b );
$equals( 'payment step skipped', $needs, false );
delete_option( \Submex\Checkout\Trial_Payment::OPTION );

echo "\n4. No trial, \$5 sign-up fee\n";
$c = $make( 20, 0, 5 );
list( $total, $needs ) = $cart_total( $c );
$equals( 'charged today', $total, '25.00' );

echo "\n5. Totals recalculated twice in one request must not re-add the fee\n";
WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
$equals( 'still', wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ), '25.00' );

echo "\n6. Plain non-subscription product is untouched\n";
$d = wp_insert_post( array( 'post_title' => 'SK plain', 'post_type' => 'product', 'post_status' => 'publish' ) );
$p = wc_get_product( $d ); $p->set_regular_price( '20' ); $p->set_price( '20' ); $p->save();
list( $total, $needs ) = $cart_total( $d );
$equals( 'charged today', $total, '20.00' );

echo "\n7. No trial, but a coupon worth more than the first payment (zero total again)\n";
$e      = $make( 20, 0, 0 );
$coupon = new WC_Coupon();
$coupon->set_code( 'sb-zero-' . strtolower( wp_generate_password( 6, false, false ) ) );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( 25 );
$coupon->save();
$with_coupon = function ( int $id ) use ( $coupon ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $id );
	WC()->cart->apply_coupon( $coupon->get_code() );
	WC()->cart->calculate_totals();
	return array( wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ), WC()->cart->needs_payment() );
};
list( $total, $needs ) = $with_coupon( $e );
$equals( 'charged today', $total, '0.00' );
$equals( 'payment step still shown, so the renewal has a card', $needs, true );
list( $total, $needs ) = $with_coupon( $d );
$equals( 'a plain product made free by the same coupon skips it', $needs, false );
update_option( \Submex\Checkout\Trial_Payment::OPTION, 'no' );
list( $total, $needs ) = $with_coupon( $e );
$equals( 'and so does the subscription with the setting off', $needs, false );
delete_option( \Submex\Checkout\Trial_Payment::OPTION );

$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_product( wc_get_product( $e ), 1 );
$order->set_total( 0 );
$order->save();
$equals( 'a zero-total order for it asks for payment too (block checkout)', $order->needs_payment(), true );
$order->delete( true );
$coupon->delete( true );

echo "\n8. A free-forever plan: nothing today and nothing to come\n";
$f = $make( 0, 0, 0 );
list( $total, $needs ) = $cart_total( $f );
$equals( 'charged today', $total, '0.00' );
$equals( 'no payment step, since there is nothing to charge later either', $needs, false );
$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->add_product( wc_get_product( $f ), 1 );
$order->set_total( 0 );
$order->save();
$equals( 'nor for a zero-total order for it', $order->needs_payment(), false );
$order->delete( true );

WC()->cart->empty_cart();
foreach ( array( $a, $b, $c, $d, $e, $f ) as $id ) { wp_delete_post( $id, true ); }
submex_test_done( $fail );
