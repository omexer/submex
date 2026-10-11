<?php
/**
 * A renewal moves the next payment on by exactly one period.
 *
 * Regression for a defect every subscription had until 0.17.0: advancing from the end of
 * the period just charged skipped a whole period, so a monthly subscription charged on
 * 20 September was next charged on 20 November. The harness that should have caught it
 * forced the due date into the past before each payment, overwriting the wrong answer
 * before anything read it — so this asserts on the date the code chose, untouched.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

require __DIR__ . '/bootstrap.php';

\Submex\Plugin::instance()->get( 'gateways' )->add(
	new class() implements Recurring_Gateway {
		public function id(): string {
			return 'submex_test_schedule';
		}
		public function title(): string {
			return 'Schedule test approver';
		}
		public function model(): Gateway_Model {
			return Gateway_Model::Tokenized;
		}
		public function supports( string $feature ): bool {
			return true;
		}
		public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result {
			return Charge_Result::success( 'm' );
		}
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
			return Charge_Result::success( 'c-' . $r->get_id() );
		}
		public function reconcile( Subscription $s, string $k ): ?Charge_Result {
			return null;
		}
		public function cancel_mandate( Subscription $s ): bool {
			return true;
		}
		public function update_payment_method( Subscription $s, string $t ): bool {
			return true;
		}
	}
);

$product = submex_test_product();

$subscribe = static function ( string $period, int $interval, string $due ) use ( $product ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( 'USD' );
	$s->set_billing_period( $period );
	$s->set_billing_interval( $interval );
	$s->set_payment_method( 'submex_test_schedule' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );

	$item = new WC_Order_Item_Product();
	$item->set_props(
		array(
			'name'       => 'Schedule probe',
			'product_id' => $product->get_id(),
			'quantity'   => 1,
			'subtotal'   => '20',
			'total'      => '20',
		)
	);
	$s->add_item( $item );
	$s->set_next_payment( $due );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();

	return $s;
};

// Straight from the meta table: wc_get_orders() can ignore a meta filter and return every
// order, which would make the duplicate check pass vacuously and the cleanup delete the lot.
$orders_for = static function ( Subscription $s ): array {
	global $wpdb;

	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %d AND order_id <> %d',
				$wpdb->prefix . 'wc_orders_meta',
				'_submex_subscription_id',
				$s->get_id(),
				$s->get_id()
			)
		)
	);
};

$renew = static function ( Subscription $s ): string {
	\Submex\Plugin::instance()->get( 'processor' )->process( $s->get_id() );

	return (string) wc_get_order( $s->get_id() )->get_next_payment();
};

$cases = array(
	array( 'month', 1, '2026-09-20 10:00:00', '2026-10-20 10:00:00', 'monthly moves one month, not two' ),
	array( 'week', 1, '2026-09-01 10:00:00', '2026-09-08 10:00:00', 'weekly moves one week' ),
	array( 'month', 3, '2026-01-15 10:00:00', '2026-04-15 10:00:00', 'every three months moves three months' ),
	array( 'year', 1, '2025-02-10 10:00:00', '2026-02-10 10:00:00', 'yearly moves one year' ),
	// The awkward one: a month from the 31st lands on the last day of a shorter month.
	array( 'month', 1, '2026-01-31 10:00:00', '2026-02-28 10:00:00', 'the 31st of January moves to the 28th of February' ),
);

$made   = array();
$policy = get_option( 'submex_catch_up_policy', null );

// One period per charge, isolated from the catch-up policy: these due dates are in the past,
// and under the default policy a past date moves further, which is tested below.
update_option( 'submex_catch_up_policy', 'charge_all' );

foreach ( $cases as list( $period, $interval, $due, $want, $label ) ) {
	$s      = $subscribe( $period, $interval, $due );
	$made[] = $s;
	$got    = $renew( $s );

	$check( $label, $want === $got, array( 'due' => $due, 'next' => $got, 'want' => $want ) );
}

// Two renewals in a row must land two periods on — the skip compounded, so it is worth
// checking that nothing drifts across more than one cycle either.
$s      = $subscribe( 'month', 1, '2026-03-05 09:00:00' );
$made[] = $s;
$first  = $renew( $s );
$s      = wc_get_order( $s->get_id() );
$s->set_next_payment( $first );
$s->save();
$second = $renew( $s );
$check( 'two renewals in a row land two months on', '2026-05-05 09:00:00' === $second, array( $first, $second ) );

// The catch-up policy, for a subscription whose scheduler stopped three months ago.
$overdue = gmdate( 'Y-m-d H:i:s', strtotime( '-3 months -2 days' ) );
$now     = time();

update_option( 'submex_catch_up_policy', 'rebase' );
$s      = $subscribe( 'month', 1, $overdue );
$made[] = $s;
$next   = $renew( $s );
$check( 'default policy: one charge covers the whole gap', 1 === count( $orders_for( $s ) ), count( $orders_for( $s ) ) );
$check( 'and the next payment lands in the future', strtotime( $next . ' UTC' ) > $now, $next );
$check( 'no more than a month out', strtotime( $next . ' UTC' ) <= strtotime( '+1 month +1 day', $now ), $next );
$check( 'on the original day of the month', substr( $overdue, 8, 2 ) === substr( $next, 8, 2 ), array( $overdue, $next ) );
$renew( $s );
$check( 'so running again charges nothing', 1 === count( $orders_for( $s ) ), count( $orders_for( $s ) ) );

update_option( 'submex_catch_up_policy', 'charge_all' );
$s      = $subscribe( 'month', 1, $overdue );
$made[] = $s;
$renew( $s );
$renew( $s );
$check( 'charge-every-period policy: each run charges the next missed month', 2 === count( $orders_for( $s ) ), count( $orders_for( $s ) ) );

null === $policy ? delete_option( 'submex_catch_up_policy' ) : update_option( 'submex_catch_up_policy', $policy );

foreach ( $made as $s ) {
	foreach ( $orders_for( $s ) as $order_id ) {
		wc_get_order( $order_id )->delete( true );
	}
	$s->delete( true );
}

submex_test_done( $fail );
