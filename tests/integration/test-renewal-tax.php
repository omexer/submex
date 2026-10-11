<?php
/**
 * A renewal charges what the checkout charged for the recurring line, whether the store enters prices with tax or without.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

require __DIR__ . '/bootstrap.php';

$plugin = \Submex\Plugin::instance();

$gateway = new class() implements Recurring_Gateway {
	/** @var string[] */
	public array $charged = array();
	/** @var string[] */
	public array $taxes = array();
	public function id(): string { return 'submex_test_renewal_tax'; }
	public function title(): string { return 'Renewal tax harness'; }
	public function model(): Gateway_Model { return Gateway_Model::Tokenized; }
	public function supports( string $f ): bool { return true; }
	public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'm' ); }
	public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
		$this->charged[] = wc_format_decimal( $r->get_total(), 2 );
		$this->taxes[]   = wc_format_decimal( $r->get_total_tax(), 2 );
		return Charge_Result::success( 'c-' . $r->get_id() );
	}
	public function reconcile( Subscription $s, string $k ): ?Charge_Result { return null; }
	public function cancel_mandate( Subscription $s ): bool { return true; }
	public function update_payment_method( Subscription $s, string $t ): bool { return true; }
};
$plugin->get( 'gateways' )->add( $gateway );

foreach ( array( 'GB', 'DE', 'CH' ) as $country ) {
	if ( WC_Tax::find_rates( array( 'country' => $country ) ) ) {
		submex_test_abort( "this site already has tax rates for {$country}; the expected totals would be wrong" );
	}
}

$options = array(
	'woocommerce_calc_taxes'          => 'yes',
	'woocommerce_prices_include_tax'  => 'yes',
	'woocommerce_default_country'     => 'GB',
	'woocommerce_tax_based_on'        => 'billing',
	'woocommerce_tax_round_at_subtotal' => 'no',
);
$saved = array();
foreach ( $options as $name => $value ) {
	$saved[ $name ] = get_option( $name, null );
	update_option( $name, $value );
}

$rates = array(
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'GB', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) ),
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'DE', 'tax_rate' => '19.0000', 'tax_rate_name' => 'MwSt', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) ),
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CH', 'tax_rate' => '7.7000', 'tax_rate_name' => 'MWST', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) ),
);
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

$products = array();
$product  = static function ( string $price, bool $taxable ) use ( &$products ): WC_Product {
	$p = new \Submex\Product\Simple_Subscription();
	$p->set_name( 'SK renewal tax probe' );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->set_tax_status( $taxable ? 'taxable' : 'none' );
	$p->update_meta_data( \Submex\Product\Subscription_Product::META_PERIOD, 'month' );
	$p->update_meta_data( \Submex\Product\Subscription_Product::META_INTERVAL, 1 );
	$p->save();
	$products[] = $p->get_id();
	return wc_get_product( $p->get_id() );
};

$orders = array();

// Checkout through the real cart, then the renewal through the real processor.
$buy_and_renew = static function ( WC_Product $p, string $country, int $qty = 1, bool $exempt = false, bool $unflagged = false ) use ( $plugin, $gateway, &$orders ): array {
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	WC()->customer->set_billing_country( $country );
	WC()->customer->set_shipping_country( $country );
	WC()->customer->set_is_vat_exempt( $exempt );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $p->get_id(), $qty );
	WC()->cart->calculate_totals();

	$order_id = WC()->checkout()->create_order(
		array(
			'billing_country'  => $country,
			'shipping_country' => $country,
			'billing_email'    => 'renewaltax@example.test',
			'payment_method'   => '',
		)
	);
	WC()->cart->empty_cart();
	WC()->customer->set_is_vat_exempt( false );

	if ( is_wp_error( $order_id ) ) {
		submex_test_abort( 'checkout failed: ' . $order_id->get_error_message() );
	}

	$order = wc_get_order( $order_id );
	$order->set_payment_method( $gateway->id() );
	$order->save();
	$orders[] = $order_id;

	$plugin->get( 'subscription_factory' )->from_checkout( $order_id );
	$order = wc_get_order( $order_id );
	$sub   = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );

	if ( ! $sub instanceof Subscription ) {
		submex_test_abort( 'no subscription was created from the checkout' );
	}

	$shows = wc_format_decimal( $sub->get_total(), 2 );
	if ( $unflagged ) {
		$sub->delete_meta_data( 'is_vat_exempt' );
	}
	$sub->transition_to( Subscription_Status::Active );
	$sub->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
	$sub->save();

	$before = count( $gateway->charged );
	$plugin->get( 'processor' )->process( $sub->get_id() );
	$charged = $gateway->charged[ $before ] ?? 'none';
	$monthly = $plugin->get( 'stats' )->monthly_value( wc_get_order( $sub->get_id() ) )->decimal();

	global $wpdb;
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	foreach ( $wpdb->get_col( $wpdb->prepare( $hpos ? "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_submex_subscription_id' AND meta_value = %d" : "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_submex_subscription_id' AND meta_value = %d", $sub->get_id() ) ) as $id ) {
		$orders[] = (int) $id;
	}
	$orders[] = $sub->get_id();

	return array(
		'checkout'     => wc_format_decimal( $order->get_total(), 2 ),
		'checkout_tax' => wc_format_decimal( $order->get_total_tax(), 2 ),
		'renewal'      => $charged,
		'shows'        => $shows,
		'mrr'          => $monthly,
		'renewal_tax'  => $gateway->taxes[ $before ] ?? 'none',
	);
};

echo "\nPrices entered with tax\n";
$got = $buy_and_renew( $product( '12.00', true ), 'GB' );
$check( 'checkout charges the price as entered', '12.00' === $got['checkout'] && '2.00' === $got['checkout_tax'], $got );
$check( 'and so does the renewal', '12.00' === $got['renewal'], $got );
$check( 'which is what the subscription shows', '12.00' === $got['shows'], $got );

$got = $buy_and_renew( $product( '9.99', true ), 'GB' );
$check( 'an awkward price renews at what checkout charged', $got['checkout'] === $got['renewal'], $got );

$got = $buy_and_renew( $product( '12.00', true ), 'DE' );
$check( 'a customer taxed at another rate renews at what checkout charged', '11.90' === $got['checkout'] && $got['checkout'] === $got['renewal'], $got );

$got = $buy_and_renew( $product( '12.00', false ), 'GB' );
$check( 'a product that is not taxable renews at its price', '12.00' === $got['checkout'] && '12.00' === $got['renewal'], $got );

$got = $buy_and_renew( $product( '12.00', true ), 'GB', 1, true );
$check( 'a VAT-exempt customer pays no tax at checkout', '10.00' === $got['checkout'] && '0.00' === $got['checkout_tax'], $got );
$check( 'nor at renewal', '10.00' === $got['renewal'] && '0.00' === $got['renewal_tax'], $got );

$got = $buy_and_renew( $product( '12.00', true ), 'GB', 1, true, true );
$check( 'an older subscription with no exemption flag of its own renews tax-free when its checkout was exempt', '10.00' === $got['renewal'] && '0.00' === $got['renewal_tax'], $got );

$got = $buy_and_renew( $product( '12.00', true ), 'GB' );
$mrr_inclusive = $got['mrr'];

echo "\nPrices entered without tax\n";
update_option( 'woocommerce_prices_include_tax', 'no' );
$got = $buy_and_renew( $product( '10.00', true ), 'GB' );
$check( 'checkout adds the tax', '12.00' === $got['checkout'] && '2.00' === $got['checkout_tax'], $got );
$check( 'and so does the renewal', '12.00' === $got['renewal'], $got );
$check( 'which is what the subscription shows', '12.00' === $got['shows'], $got );

$got = $buy_and_renew( $product( '10.00', true ), 'DE' );
$check( 'at the customer\'s own rate', '11.90' === $got['checkout'] && $got['checkout'] === $got['renewal'], $got );

$got = $buy_and_renew( $product( '12.00', false ), 'GB' );
$check( 'a product that is not taxable renews at its price', '12.00' === $got['checkout'] && '12.00' === $got['renewal'], $got );

$got = $buy_and_renew( $product( '10.00', true ), 'GB', 1, true );
$check( 'a VAT-exempt customer pays no tax at checkout or renewal', '10.00' === $got['checkout'] && '10.00' === $got['renewal'] && '0.00' === $got['renewal_tax'], $got );

$got = $buy_and_renew( $product( '10.00', true ), 'GB' );
$check( 'monthly recurring revenue leaves tax out, the same whichever way prices are entered', '10.00' === $got['mrr'] && $mrr_inclusive === $got['mrr'], array( $mrr_inclusive, $got['mrr'] ) );

echo "\nRounding: price x quantity x rate x rounding setting\n";
foreach ( array( 'yes', 'no' ) as $inclusive ) {
	update_option( 'woocommerce_prices_include_tax', $inclusive );
	$drift = array();
	foreach ( array( 'yes', 'no' ) as $at_subtotal ) {
		update_option( 'woocommerce_tax_round_at_subtotal', $at_subtotal );
		foreach ( array( '0.99', '9.99', '19.99' ) as $price ) {
			$p = $product( $price, true );
			foreach ( array( 1, 3 ) as $qty ) {
				foreach ( array( 'GB', 'DE', 'CH' ) as $country ) {
					$got = $buy_and_renew( $p, $country, $qty );
					if ( $got['checkout'] !== $got['renewal'] ) {
						$drift[] = "{$price} x{$qty} {$country} round-at-subtotal={$at_subtotal}: checkout {$got['checkout']}, renewal {$got['renewal']}";
					}
				}
			}
		}
	}
	$check( ( 'yes' === $inclusive ? 'prices with tax' : 'prices without tax' ) . ': every renewal equals its checkout', array() === $drift, $drift );
}

foreach ( array_unique( $orders ) as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( $products as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $rates as $rate_id ) {
	WC_Tax::_delete_tax_rate( $rate_id );
}
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

submex_test_done( $fail );
