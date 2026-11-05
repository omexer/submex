<?php
/**
 * The amounts in a disclosure are the ones the store displays: with or without tax, on the shop's setting
 * on a product page and the cart's in the cart, on classic and block checkout alike.
 *
 * @package Submex
 */

use Submex\Checkout\Cart_Validation;
use Submex\Product\Line_Terms;

require __DIR__ . '/bootstrap.php';

wc_load_cart();

$plugin = \Submex\Plugin::instance();
$read   = static fn( string $html ): string => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

add_filter( 'pre_option_woocommerce_price_num_decimals', static fn() => '2' );
add_filter( 'pre_option_' . Cart_Validation::OPTION_MIXED, static fn() => 'yes' );
add_filter( 'pre_option_woocommerce_calc_taxes', static fn() => 'yes' );
add_filter(
	'woocommerce_find_rates',
	static fn(): array => array(
		1 => array(
			'rate'     => 20.0,
			'label'    => 'Test VAT',
			'shipping' => 'no',
			'compound' => 'no',
		),
	)
);

$store = array();
foreach ( array( 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart' ) as $option ) {
	add_filter( 'pre_option_' . $option, static function () use ( &$store, $option ) { return $store[ $option ]; } );
}
$prices = static function ( string $include, string $shop, string $cart ) use ( &$store ): void {
	$store = array(
		'woocommerce_prices_include_tax' => $include,
		'woocommerce_tax_display_shop'   => $shop,
		'woocommerce_tax_display_cart'   => $cart,
	);
};

$made = static function ( string $price, array $meta = array() ): WC_Product {
	$p = new \Submex\Product\Simple_Subscription();
	$p->set_name( 'PLFIX tax ' . wp_generate_password( 4, false ) );
	$p->set_status( 'publish' );
	$p->set_tax_status( 'taxable' );
	$p->set_regular_price( $price );
	$p->update_meta_data( \Submex\Product\Subscription_Product::META_PERIOD, 'month' );
	$p->update_meta_data( \Submex\Product\Subscription_Product::META_INTERVAL, 1 );
	foreach ( $meta as $key => $value ) {
		$p->update_meta_data( $key, $value );
	}
	return wc_get_product( $p->save() );
};

$plain_product = $made( '60' );
$fee_product   = $made( '60', array( \Submex\Product\Subscription_Product::META_SIGNUP_FEE => '5' ) );
$trial_product = $made( '60', array( \Submex\Product\Subscription_Product::META_TRIAL_DAYS => 7, \Submex\Product\Subscription_Product::META_SIGNUP_FEE => '5' ) );

$facts = static function ( WC_Product $product ) use ( $plugin, $read ): string {
	return $read( implode( ' | ', array_column( $plugin->get( 'disclosure' )->lines( wc_get_product( $product->get_id() ) ), 'text' ) ) );
};
$cart = static function ( WC_Product $product ) use ( $plugin ): array {
	WC()->cart->empty_cart();
	wc_clear_notices();
	WC()->cart->add_to_cart( $product->get_id() );
	WC()->cart->calculate_totals();
	$data          = $plugin->get( 'store_api' )->cart_data();
	$data['total'] = wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 );
	return $data;
};
$classic = static function () use ( $plugin, $read ): string {
	ob_start();
	$plugin->get( 'product_display' )->before_place_order();
	$consent = (string) ob_get_clean();
	ob_start();
	$plugin->get( 'product_display' )->on_checkout_totals();
	return $read( $consent . ' ' . ob_get_clean() );
};

echo "\nPrices entered without tax, shown with it\n";
$prices( 'no', 'incl', 'incl' );
$check( 'product page: first payment and recurring carry tax', str_contains( $facts( $plain_product ), 'First payment $72.00 today' ) && str_contains( $facts( $plain_product ), 'Then $72.00 every month' ), $facts( $plain_product ) );
$check( 'the sign-up fee and what it adds to', str_contains( $facts( $fee_product ), '$6.00 sign-up fee today' ) && str_contains( $facts( $fee_product ), 'First payment $78.00 today' ), $facts( $fee_product ) );
$check( 'after a trial', str_contains( $facts( $trial_product ), '$6.00 sign-up fee today' ) && (bool) preg_match( '/First payment \$72\.00 on /', $facts( $trial_product ) ), $facts( $trial_product ) );
$check( 'the headline and sentence on a product page', str_contains( $read( $plugin->get( 'disclosure' )->price_line( $plain_product ) ), '$72.00' ) && str_contains( $read( $plugin->get( 'disclosure' )->sentence( $plain_product ) ), '$72.00' ) );
$in = $cart( $fee_product );
$check( 'block checkout says what it charges', '78.00' === $in['total'] && str_contains( implode( ' | ', $in['lines'] ), 'First payment $78.00 today' ), $in );
$check( 'with the recurring amount in its headline and consent', str_contains( $in['price_line'], '$72.00' ) && str_contains( $in['consent'], '$72.00' ), $in );
$check( 'classic checkout too', 2 === substr_count( $classic(), '$72.00' ), $classic() );

echo "\nPrices entered with tax, shown without it\n";
$prices( 'yes', 'excl', 'excl' );
$check( 'product page', str_contains( $facts( $plain_product ), 'First payment $50.00 today' ) && str_contains( $facts( $plain_product ), 'Then $50.00 every month' ), $facts( $plain_product ) );
$in = $cart( $fee_product );
$check( 'block checkout', str_contains( implode( ' | ', $in['lines'] ), 'First payment $54.17 today' ) && str_contains( $in['consent'], '$50.00' ), $in );
$check( 'classic checkout', 2 === substr_count( $classic(), '$50.00' ), $classic() );

echo "\nThe shop and the cart can differ\n";
$prices( 'no', 'excl', 'incl' );
$check( 'the product page follows the shop setting', str_contains( $facts( $plain_product ), 'First payment $60.00 today' ), $facts( $plain_product ) );
$in = $cart( $plain_product );
$check( 'the cart follows the cart setting', str_contains( implode( ' | ', $in['lines'] ), 'First payment $72.00 today' ), $in );

echo "\nNothing changes as prices are shown as entered\n";
$prices( 'no', 'excl', 'excl' );
$check( 'without tax throughout', str_contains( $facts( $plain_product ), 'Then $60.00 every month' ) );
$prices( 'yes', 'incl', 'incl' );
$check( 'with tax throughout', str_contains( $facts( $plain_product ), 'Then $60.00 every month' ) );

WC()->cart->empty_cart();
foreach ( array( $plain_product, $fee_product, $trial_product ) as $product ) {
	$product->delete( true );
}
submex_test_done( $fail );
