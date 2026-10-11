<?php
/**
 * A subscription reads back through WooCommerce's legacy post store (HPOS off, or sync on) with every field intact.
 *
 * @package Submex
 */

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
$cpt  = new WC_Order_Data_Store_CPT();

$parent = wc_create_order();

$want = array(
	'billing_period'    => 'week',
	'billing_interval'  => 2,
	'trial_end'         => '2026-01-10 08:00:00',
	'next_payment'      => '2026-11-03 09:30:00',
	'end_date'          => '2027-06-01 00:00:00',
	'parent_order_id'   => $parent->get_id(),
	'payment_token_id'  => 7,
	'schedule_sync_day' => 15,
	'period_index'      => 3,
);

$s = new Subscription();
$s->set_customer_id( 1 );
$s->set_currency( get_woocommerce_currency() );
foreach ( $want as $prop => $value ) {
	$s->{"set_$prop"}( $value );
}
$s->transition_to( Subscription_Status::Pending );
$s->save();
$s->transition_to( Subscription_Status::Active );
$s->save();
$id = $s->get_id();

$failure = static function ( \Throwable $e ): string {
	return get_class( $e ) . ': ' . $e->getMessage();
};

$read_legacy = static function () use ( $cpt, $id ): Subscription {
	$read = new Subscription();
	$read->set_id( $id );
	$cpt->read( $read );
	return $read;
};

$fields = static function ( Subscription $read ) use ( $want ): array {
	$got = array();
	foreach ( array_keys( $want ) as $prop ) {
		$got[ $prop ] = $read->{"get_$prop"}();
	}
	return $got;
};

$same = static function ( array $got ) use ( $want ): bool {
	foreach ( $want as $prop => $value ) {
		if ( (string) $value !== (string) $got[ $prop ] ) {
			return false;
		}
	}
	return true;
};

if ( $hpos ) {
	echo "\nHPOS with compatibility sync\n";
	try {
		wc_get_container()->get( OrdersTableDataStore::class )->backfill_post_record( $s );
		$check( 'syncing the subscription to the posts table does not throw', true );
	} catch ( \Throwable $e ) {
		$check( 'syncing the subscription to the posts table does not throw', false, $failure( $e ) );
	}
}

echo "\nRead through the legacy store\n";
$legacy = null;
try {
	$legacy = $read_legacy();
	$check( 'reading does not throw', true );
} catch ( \Throwable $e ) {
	$check( 'reading does not throw', false, $failure( $e ) );
}

if ( $legacy ) {
	$check( 'every field round-trips', $same( $fields( $legacy ) ), $fields( $legacy ) );
	$check( 'the status round-trips', Subscription_Status::Active === $legacy->get_status_enum(), $legacy->get_status() );

	echo "\nSaved from that read\n";
	$legacy->save();
	wp_cache_flush();
	$check( 'the stored meta is intact', $same( $fields( wc_get_order( $id ) ) ), $fields( wc_get_order( $id ) ) );
	$check( 'and reads back through the legacy store again', $same( $fields( $read_legacy() ) ), $fields( $read_legacy() ) );

	echo "\nRe-read into the same object\n";
	$cpt->read( $legacy );
	$check( 'every field is read again, not left at its default', $same( $fields( $legacy ) ), $fields( $legacy ) );

	echo "\nValidation after the read\n";
	foreach ( array( array( 'billing_period', '' ), array( 'billing_period', 'fortnight' ), array( 'billing_interval', 0 ) ) as list( $prop, $bad ) ) {
		try {
			$legacy->{"set_$prop"}( $bad );
			$check( "set_$prop( " . wp_json_encode( $bad ) . ' ) is still refused', false );
		} catch ( WC_Data_Exception $e ) {
			$check( "set_$prop( " . wp_json_encode( $bad ) . ' ) is still refused', true );
		}
	}
}

wc_get_order( $id )->delete( true );
if ( get_post( $id ) ) {
	wp_delete_post( $id, true );
}
$parent->delete( true );

submex_test_done( $fail );
