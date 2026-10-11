<?php
/**
 * A normal product's cart line sold as a subscription on terms of its own, through cart, disclosure,
 * checkout, the created subscription and its renewal, and the same product with nothing hooked in.
 *
 * @package Submex
 */

use Submex\Checkout\Cart_Validation;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;
use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';

$plugin = \Submex\Plugin::instance();

$equals = static function ( string $what, $got, $want ) use ( $check ): void {
	$check( $what, $got === $want, array( 'got' => $got, 'want' => $want ) );
};
$near   = static function ( string $what, string $got, int $want ) use ( $check ): void {
	$check( $what, abs( strtotime( $got . ' UTC' ) - $want ) < 120, array( 'got' => $got, 'want' => gmdate( 'Y-m-d H:i:s', $want ) ) );
};
$plain  = static fn( string $html ): string => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

$options = array(
	'woocommerce_calc_taxes'           => 'no',
	'woocommerce_currency_pos'         => 'left',
	Cart_Validation::OPTION_MIXED      => 'yes',
	'woocommerce_price_num_decimals'   => '2',
);
$saved   = array();
foreach ( $options as $name => $value ) {
	$saved[ $name ] = get_option( $name, null );
	update_option( $name, $value );
}

$gateway = new class() implements Recurring_Gateway {
	/** @var string[] */
	public array $charged = array();
	public function id(): string { return 'submex_test_line_terms'; }
	public function title(): string { return 'Line terms harness'; }
	public function model(): Gateway_Model { return Gateway_Model::Tokenized; }
	public function supports( string $f ): bool { return true; }
	public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'm' ); }
	public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
		$this->charged[] = wc_format_decimal( $r->get_total(), 2 );
		return Charge_Result::success( 'c-' . $r->get_id() );
	}
	public function reconcile( Subscription $s, string $k ): ?Charge_Result { return null; }
	public function cancel_mandate( Subscription $s ): bool { return true; }
	public function update_payment_method( Subscription $s, string $t ): bool { return true; }
};
$plugin->get( 'gateways' )->add( $gateway );

$normal = static function ( string $name, string $price ): WC_Product {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->save();
	return wc_get_product( $p->get_id() );
};

$coffee       = $normal( 'SK Line terms coffee', '30' );
$tea          = $normal( 'SK Line terms tea', '12' );
$subscription = submex_test_product();
$orders       = array();

// What an extension like Pro does: the line carries its choice, the order line keeps it, and both answer from it.
$terms_by_name = array(
	'fortnight' => array(
		'price'        => 24.0,
		'period'       => 'week',
		'interval'     => 2,
		'trial_length' => 0,
		'trial_period' => 'day',
		'signup_fee'   => 5.0,
	),
	'trial'     => array(
		'price'        => 24.0,
		'period'       => 'month',
		'interval'     => 1,
		'trial_length' => 7,
		'trial_period' => 'day',
		'signup_fee'   => 0.0,
	),
);
$configured    = array();
$hooks         = array(
	array( 'submex_cart_item_is_subscription', static fn( $recurring, $item ) => isset( $item['sbt_terms'] ) ? true : $recurring, 10, 2 ),
	array( 'submex_adding_subscription', static fn( $recurring, $product_id, $variation_id = 0, $data = array() ) => isset( $data['sbt_terms'] ) ? true : $recurring, 10, 4 ),
	array(
		'woocommerce_checkout_create_order_line_item',
		static function ( $line, $key, $values ) {
			if ( isset( $values['sbt_terms'] ) ) {
				$line->add_meta_data( '_sbt_terms', $values['sbt_terms'], true );
			}
		},
		10,
		3,
	),
	array( 'submex_create_subscription_for_item', static fn( $create, $line ) => '' !== (string) $line->get_meta( '_sbt_terms' ) ? true : $create, 10, 2 ),
	array(
		'submex_line_terms',
		static function ( $terms, $product, $cart_item, $order_item ) use ( $terms_by_name ) {
			$name = $cart_item['sbt_terms'] ?? ( $order_item ? (string) $order_item->get_meta( '_sbt_terms' ) : '' );
			return $terms_by_name[ $name ] ?? $terms;
		},
		10,
		4,
	),
	array(
		'submex_configure_subscription',
		static function ( $sub, $product, $line = null ) use ( &$configured ) {
			$configured[] = $line instanceof WC_Order_Item_Product ? (string) $line->get_meta( '_sbt_terms' ) : 'no line';
		},
		10,
		3,
	),
);
$hook_in  = static function () use ( $hooks ): void {
	foreach ( $hooks as $hook ) {
		add_filter( ...$hook );
	}
};
$hook_out = static function () use ( $hooks ): void {
	foreach ( $hooks as $hook ) {
		remove_filter( $hook[0], $hook[1], $hook[2] );
	}
};

wc_load_cart();
WC()->customer->set_billing_country( 'US' );

$fill = static function ( array $lines ): void {
	WC()->cart->empty_cart();
	foreach ( $lines as $line ) {
		WC()->cart->add_to_cart( $line[0], 1, 0, array(), $line[1] ?? array() );
	}
	WC()->cart->calculate_totals();
};
$total = static fn(): string => wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 );
$first = static function (): array {
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		return array( $key, $item );
	}
	return array( '', array() );
};

$checkout = static function () use ( $plugin, $gateway, &$orders ): array {
	$order_id = WC()->checkout()->create_order(
		array(
			'billing_country' => 'US',
			'billing_email'   => 'lineterms@example.test',
			'payment_method'  => '',
		)
	);
	if ( is_wp_error( $order_id ) ) {
		submex_test_abort( 'checkout failed: ' . $order_id->get_error_message() );
	}
	$orders[] = $order_id;
	$order    = wc_get_order( $order_id );
	$order->set_customer_id( 1 );
	$order->set_payment_method( $gateway->id() );
	$order->save();

	$plugin->get( 'subscription_factory' )->from_checkout( $order_id );
	$order = wc_get_order( $order_id );
	$sub   = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );
	if ( $sub instanceof Subscription ) {
		$orders[] = $sub->get_id();
	}
	return array( $order, $sub instanceof Subscription ? $sub : null );
};

$cart_text = static function () use ( $plugin, $plain ): array {
	$display = $plugin->get( 'product_display' );
	ob_start();
	$display->on_checkout_totals();
	$totals = $plain( str_replace( '</th>', '</th> ', (string) ob_get_clean() ) );
	ob_start();
	$display->before_place_order();
	$consent = $plain( (string) ob_get_clean() );
	$lines   = array();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$lines[] = $plain( (string) $display->on_cart_line( 'PRICE', $item, $key ) );
	}
	return array(
		'lines'   => $lines,
		'totals'  => $totals,
		'consent' => $consent,
		'blocks'  => $plugin->get( 'store_api' )->cart_data(),
	);
};

// ------------------------------------------------------------------ nothing hooked in

echo "\n1. With nothing hooked in, a normal product's line is what it was\n";
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ) ) );
$equals( 'charged its own price', $total(), '30.00' );
$equals( 'the cart holds no subscription', Subscription_Product::cart_has_subscription(), false );
list( $key, $item ) = $first();
$equals( 'the line is not a subscription', Subscription_Product::cart_item_is_subscription( $item, $key ), false );
$text = $cart_text();
$equals( 'its cart line says nothing more', $text['lines'], array( 'PRICE' ) );
$equals( 'no recurring total or consent at checkout', array( $text['totals'], $text['consent'] ), array( '', '' ) );
$equals( 'the block checkout has no subscription', $text['blocks']['has_subscription'], false );
list( $order, $sub ) = $checkout();
$equals( 'and checking out creates no subscription', $sub, null );

$fill( array( array( $subscription->get_id() ) ) );
list( $key, $item ) = $first();
$terms = Line_Terms::for_cart_item( $item, $key );
$equals( 'a subscription product line keeps the product\'s own terms', $terms ? $terms->to_array() : null, array( 'price' => 20.0, 'period' => 'month', 'interval' => 1, 'trial_length' => 0, 'trial_period' => 'day', 'signup_fee' => 0.0 ) );
$equals( 'and is charged its price', $total(), '20.00' );

// ------------------------------------------------------------------ a line on its own terms

$hook_in();

echo "\n2. A normal product sold every two weeks at 24 with a 5 sign-up fee\n";
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ) ) );
$equals( 'today: the first period plus the fee', $total(), '29.00' );
$equals( 'the cart holds a subscription', Subscription_Product::cart_has_subscription(), true );
$equals( 'payment is taken', WC()->cart->needs_payment(), true );
WC()->cart->calculate_totals();
$equals( 'recalculating does not re-add the fee or reprice the line', $total(), '29.00' );

$text = $cart_text();
$equals( 'the cart line states the line\'s terms', $text['lines'], array( 'PRICE$24.00 every 2 weeks' ) );
$equals( 'so does the recurring total', $text['totals'], 'Recurring $24.00 every 2 weeks' );
$equals( 'and the consent', $text['consent'], "You're starting a subscription. You'll be charged $24.00 every 2 weeks until you cancel." );
$equals(
	'the block checkout says the same',
	$text['blocks'],
	array(
		'has_subscription' => true,
		'price_line'       => '$24.00 every 2 weeks',
		'lines'            => array( '$5.00 sign-up fee today', 'First payment $29.00 today', 'Then $24.00 every 2 weeks', 'Cancel anytime' ),
		'consent'          => "You're starting a subscription. You'll be charged $24.00 every 2 weeks until you cancel.",
	)
);

$configured = array();
$started    = time();
list( $order, $sub ) = $checkout();
if ( ! $sub ) {
	submex_test_abort( 'no subscription was created for the line' );
}
$equals( 'the order charged 29', wc_format_decimal( $order->get_total(), 2 ), '29.00' );
$equals( 'the subscription bills every 2 weeks', array( $sub->get_billing_period(), (int) $sub->get_billing_interval() ), array( 'week', 2 ) );
$equals( 'at 24 a period', wc_format_decimal( $sub->get_total(), 2 ), '24.00' );
$equals( 'with no trial', array( (string) $sub->get_trial_end(), $sub->get_status_enum() ), array( '', Subscription_Status::Pending ) );
$near( 'first renewal two weeks from today', (string) $sub->get_next_payment(), $started + 2 * WEEK_IN_SECONDS );
$equals( 'configure hooks get the order line', $configured, array( 'fortnight' ) );

$order->payment_complete( 'harness' );
$sub = wc_get_order( $sub->get_id() );
$equals( 'paid, it is active', $sub->get_status_enum(), Subscription_Status::Active );
$near( 'still due two weeks from today', (string) $sub->get_next_payment(), $started + 2 * WEEK_IN_SECONDS );

$sub->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
$sub->save();
$plugin->get( 'processor' )->process( $sub->get_id() );
$equals( 'the renewal charges 24, not the product\'s 30', $gateway->charged, array( '24.00' ) );
$sub = wc_get_order( $sub->get_id() );
$near( 'and the next one is two weeks on', (string) $sub->get_next_payment(), time() - MINUTE_IN_SECONDS + 2 * WEEK_IN_SECONDS );

echo "\n3. The same product on a 7-day trial, then 24 a month\n";
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'trial' ) ) ) );
$equals( 'nothing today', $total(), '0.00' );
$equals( 'but payment is still taken, for the renewal', WC()->cart->needs_payment(), true );
$text = $cart_text();
$check( 'the disclosure states the trial and what follows', in_array( '7 days free', $text['blocks']['lines'], true ) && in_array( 'Then $24.00 every month', $text['blocks']['lines'], true ), $text['blocks']['lines'] );
$started = time();
list( $order, $sub ) = $checkout();
$equals( 'the subscription is trialling, monthly', array( $sub ? $sub->get_status_enum() : null, $sub ? $sub->get_billing_period() : null ), array( Subscription_Status::Trialling, 'month' ) );
$near( 'the trial ends in 7 days', (string) ( $sub ? $sub->get_trial_end() : '' ), $started + 7 * DAY_IN_SECONDS );
$near( 'and the first renewal is that day', (string) ( $sub ? $sub->get_next_payment() : '' ), $started + 7 * DAY_IN_SECONDS );
$equals( 'the zero-total order still asks for payment', $order->needs_payment(), true );

// ------------------------------------------------------------------ one subscription per order

echo "\n4. One subscription per order holds for such lines\n";
$cart_rules = $plugin->get( 'cart_rules' );
$add_check  = static function ( int $product_id, array $data = array() ) use ( $cart_rules ): array {
	wc_clear_notices();
	$passed = $cart_rules->validate_add_to_cart( true, $product_id, 1, 0, array(), $data );
	$errors = array_column( wc_get_notices( 'error' ), 'notice' );
	wc_clear_notices();
	return array( $passed, $errors );
};
$one_at_a_time = static fn( array $errors ): bool => 1 === count( $errors ) && str_contains( (string) $errors[0], 'one subscription at a time' );

$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ) ) );
list( $passed, $errors ) = $add_check( $tea->get_id(), array( 'sbt_terms' => 'fortnight' ) );
$check( 'a second such line is refused', false === $passed && $one_at_a_time( $errors ), $errors );
list( $passed, $errors ) = $add_check( $subscription->get_id() );
$check( 'so is a subscription product', false === $passed && $one_at_a_time( $errors ), $errors );
list( $passed, $errors ) = $add_check( $tea->get_id() );
$check( 'the same product bought once is fine', true === $passed && array() === $errors, $errors );

$fill( array( array( $subscription->get_id() ) ) );
list( $passed, $errors ) = $add_check( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) );
$check( 'and the other way round', false === $passed && $one_at_a_time( $errors ), $errors );

add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
$as_line = static function ( $data, $product_id ) use ( $tea ) {
	return (int) $product_id === $tea->get_id() ? $data + array( 'sbt_terms' => 'fortnight' ) : $data;
};
add_filter( 'woocommerce_add_cart_item_data', $as_line, 10, 2 );
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ) ) );
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array( 'id' => $tea->get_id(), 'quantity' => 1 ) ) );
$store = rest_do_request( $request );
$check( 'the block cart refuses one too', 400 === $store->get_status() && str_contains( (string) ( $store->get_data()['message'] ?? '' ), 'one subscription at a time' ) && 1 === count( WC()->cart->get_cart() ), array( $store->get_status(), $store->get_data()['message'] ?? '' ) );
remove_filter( 'woocommerce_add_cart_item_data', $as_line, 10 );

// A cart can still end up with two: a saved cart merged at login is never validated.
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ), array( $tea->get_id(), array( 'sbt_terms' => 'trial' ) ) ) );
wc_clear_notices();
WC()->checkout()->check_cart_items();
$errors = array_column( wc_get_notices( 'error' ), 'notice' );
wc_clear_notices();
$check( 'the classic checkout refuses a cart holding two', (bool) array_filter( $errors, static fn( $e ) => str_contains( (string) $e, 'one subscription at a time' ) ), $errors );
$codes = array();
try {
	( new \Automattic\WooCommerce\StoreApi\Utilities\CartController() )->validate_cart();
} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $invalid ) {
	$codes = $invalid->getError()->get_error_codes();
}
$check( 'and so does the block checkout', in_array( 'submex_one_subscription_per_order', $codes, true ), $codes );
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ), array( $tea->get_id() ) ) );
wc_clear_notices();
WC()->checkout()->check_cart_items();
$errors = array_column( wc_get_notices( 'error' ), 'notice' );
wc_clear_notices();
$check( 'one such line beside a one-off checks out', ! array_filter( $errors, static fn( $e ) => str_contains( (string) $e, 'one subscription' ) ), $errors );
remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

$hook_out();

echo "\n5. Unhooked again, the same line is a one-off at its own price\n";
$fill( array( array( $coffee->get_id(), array( 'sbt_terms' => 'fortnight' ) ) ) );
$equals( 'charged 30', $total(), '30.00' );
$equals( 'no subscription in the cart', Subscription_Product::cart_has_subscription(), false );
list( $order, $sub ) = $checkout();
$equals( 'and none from the order', $sub, null );

// ------------------------------------------------------------------ clean up

WC()->cart->empty_cart();
foreach ( array_unique( $orders ) as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
$coffee->delete( true );
$tea->delete( true );
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
