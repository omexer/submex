<?php
/**
 * Subscriptions created before lines were stored without tax are listed, and a repair makes their renewals match checkout.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Tax_Repair;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

require __DIR__ . '/bootstrap.php';

$plugin = \Submex\Plugin::instance();
$repair = $plugin->get( 'tax_repair' );

if ( ! $repair instanceof Renewal_Tax_Repair ) {
	submex_test_abort( 'the repair service is not registered' );
}

$since = strtotime( (string) get_option( Renewal_Tax_Repair::OPTION_SINCE, '' ) . ' UTC' );
if ( ! $since ) {
	submex_test_abort( 'the cut-over time was never recorded' );
}

$gateway = new class() implements Recurring_Gateway {
	/** @var string[] */
	public array $charged = array();
	public function id(): string { return 'submex_test_tax_repair'; }
	public function title(): string { return 'Tax repair harness'; }
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

if ( WC_Tax::find_rates( array( 'country' => 'GB' ) ) ) {
	submex_test_abort( 'this site already has tax rates for GB; the expected totals would be wrong' );
}

$options = array(
	'woocommerce_calc_taxes'         => 'yes',
	'woocommerce_prices_include_tax' => 'yes',
	'woocommerce_default_country'    => 'GB',
	'woocommerce_tax_based_on'       => 'billing',
);
$saved = array();
foreach ( $options as $name => $value ) {
	$saved[ $name ] = get_option( $name, null );
	update_option( $name, $value );
}
$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'GB', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

$p = new \Submex\Product\Simple_Subscription();
$p->set_name( 'SK tax repair probe' );
$p->set_status( 'publish' );
$p->set_regular_price( '12.00' );
$p->update_meta_data( \Submex\Product\Subscription_Product::META_PERIOD, 'month' );
$p->update_meta_data( \Submex\Product\Subscription_Product::META_INTERVAL, 1 );
$p->save();

$orders = array();

$buy = static function () use ( $plugin, $gateway, $p, &$orders ): Subscription {
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	WC()->customer->set_billing_country( 'GB' );
	WC()->customer->set_shipping_country( 'GB' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $p->get_id() );
	WC()->cart->calculate_totals();
	$order_id = WC()->checkout()->create_order( array( 'billing_country' => 'GB', 'billing_email' => 'taxrepair@example.test', 'payment_method' => '' ) );
	WC()->cart->empty_cart();
	if ( is_wp_error( $order_id ) ) {
		submex_test_abort( 'checkout failed: ' . $order_id->get_error_message() );
	}
	$order = wc_get_order( $order_id );
	$order->set_payment_method( $gateway->id() );
	$order->save();
	$orders[] = $order_id;
	$plugin->get( 'subscription_factory' )->from_checkout( $order_id );
	$sub = wc_get_order( (int) wc_get_order( $order_id )->get_meta( '_submex_subscription_id' ) );
	if ( ! $sub instanceof Subscription ) {
		submex_test_abort( 'no subscription was created from the checkout' );
	}
	$sub->transition_to( Subscription_Status::Active );
	$sub->save();
	$orders[] = $sub->get_id();
	return $sub;
};

// The shape a subscription had before this fix: the price as entered on the line, no tax, created earlier.
$make_old = static function ( Subscription $sub ) use ( $since ): Subscription {
	foreach ( $sub->get_items() as $line ) {
		$line->set_subtotal( '12.00' );
		$line->set_total( '12.00' );
		$line->set_taxes( false );
		$line->save();
	}
	$sub->remove_order_items( 'tax' );
	$sub->set_cart_tax( 0 );
	$sub->calculate_totals( false );
	$sub->set_date_created( $since - DAY_IN_SECONDS );
	$sub->save();
	return wc_get_order( $sub->get_id() );
};

$renew = static function ( Subscription $sub ) use ( $plugin, $gateway, &$orders ): string {
	$sub = wc_get_order( $sub->get_id() );
	$sub->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
	$sub->save();
	$before = count( $gateway->charged );
	$plugin->get( 'processor' )->process( $sub->get_id() );
	global $wpdb;
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	foreach ( $wpdb->get_col( $wpdb->prepare( $hpos ? "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_submex_subscription_id' AND meta_value = %d" : "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_submex_subscription_id' AND meta_value = %d", $sub->get_id() ) ) as $id ) {
		$orders[] = (int) $id;
	}
	return $gateway->charged[ $before ] ?? 'none';
};

$listed = static function () use ( $repair ): array {
	delete_transient( Renewal_Tax_Repair::CACHE );
	return $repair->affected_ids();
};

$repairs_logged = static function ( Subscription $sub ) use ( $plugin ): int {
	return count( array_filter( $plugin->get( 'activity' )->for_subscription( $sub->get_id(), 200 ), static fn( $r ) => str_starts_with( $r->message, 'Renewal tax repaired' ) ) );
};

$checkout_total = static function ( Subscription $sub ): string {
	return wc_format_decimal( wc_get_order( $sub->get_parent_order_id() )->get_total(), 2 );
};

echo "\nListing\n";
$old      = $make_old( $buy() );
$new      = $buy();
$repriced = $make_old( $buy() );
$repriced->update_meta_data( '_submex_renewal_price_applied', 'yes' );
$repriced->save();

$ids = $listed();
$check( 'an old-style subscription is listed', in_array( $old->get_id(), $ids, true ), $ids );
$check( 'a new one is not', ! in_array( $new->get_id(), $ids, true ), $ids );
$check( 'one a renewal price already rewrote is not', ! in_array( $repriced->get_id(), $ids, true ), $ids );
$check( 'setup: the old one renews with tax added twice', '14.40' === $renew( $old ), $gateway->charged );

update_option( 'woocommerce_prices_include_tax', 'no' );
$check( 'a store entering prices without tax lists nothing', ! in_array( $old->get_id(), $listed(), true ) );
update_option( 'woocommerce_prices_include_tax', 'yes' );

echo "\nRefusals\n";
$died = static function () {
	return static function ( $message ) {
		throw new RuntimeException( 'died' );
	};
};
// A handler that got past its checks would redirect and exit, which would end this test as a pass.
$redirected = static function () {
	throw new RuntimeException( 'redirected' );
};
add_filter( 'wp_die_handler', $died );
add_filter( 'wp_redirect', $redirected );
$attempt = static function ( int $user_id, string $nonce ) use ( $repair, $old ): string {
	wp_set_current_user( $user_id );
	$_GET = array( 'subscription' => (string) $old->get_id(), '_wpnonce' => $nonce );
	try {
		$repair->handle();
		return 'ran';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	} finally {
		$_GET = array();
		wp_set_current_user( 1 );
	}
};
$customer = wp_insert_user( array( 'user_login' => 'sk_taxrepair_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
wp_set_current_user( $customer );
$customer_nonce = wp_create_nonce( Renewal_Tax_Repair::ACTION . '_' . $old->get_id() );
wp_set_current_user( 1 );
$check( 'a customer cannot repair', 'died' === $attempt( $customer, $customer_nonce ) );
$check( 'nor can an admin without the nonce', 'died' === $attempt( 1, 'not-a-nonce' ) );
remove_filter( 'wp_die_handler', $died );
remove_filter( 'wp_redirect', $redirected );
$check( 'and the subscription is untouched', in_array( $old->get_id(), $listed(), true ) && 0 === $repairs_logged( $old ) );

echo "\nRepair\n";
$check( 'repair succeeds', $repair->repair( wc_get_order( $old->get_id() ) ) );
$check( 'the subscription now shows what checkout charged', $checkout_total( $old ) === wc_format_decimal( wc_get_order( $old->get_id() )->get_total(), 2 ), array( $checkout_total( $old ), wc_get_order( $old->get_id() )->get_total() ) );
$check( 'its next renewal equals the original checkout', $checkout_total( $old ) === $renew( $old ), array( $checkout_total( $old ), $gateway->charged ) );
$check( 'the repair is in the activity log', 1 === $repairs_logged( $old ), $repairs_logged( $old ) );
$check( 'and it is no longer listed', ! in_array( $old->get_id(), $listed(), true ) );

$total = wc_get_order( $old->get_id() )->get_total();
$check( 'repairing again does nothing', ! $repair->repair( wc_get_order( $old->get_id() ) ) );
$check( 'the total is unchanged', $total === wc_get_order( $old->get_id() )->get_total(), array( $total, wc_get_order( $old->get_id() )->get_total() ) );
$check( 'and nothing more is logged', 1 === $repairs_logged( $old ), $repairs_logged( $old ) );
$check( 'the next renewal still equals checkout', $checkout_total( $old ) === $renew( $old ), $gateway->charged );

foreach ( array_unique( $orders ) as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer );
wp_delete_post( $p->get_id(), true );
WC_Tax::_delete_tax_rate( $rate );
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
WC_Cache_Helper::invalidate_cache_group( 'taxes' );
delete_transient( Renewal_Tax_Repair::CACHE );

submex_test_done( $fail );
