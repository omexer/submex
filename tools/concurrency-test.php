<?php
/**
 * Proves the guarantee the whole design rests on: a billing period can be charged once,
 * even when several workers reach it at the same instant.
 *
 * Everything else in this plugin is arranged around that claim. It cannot be tested in one
 * process, because one process cannot race itself - so this starts real ones, holds them
 * at a wall-clock barrier, and releases them together against the same subscription.
 *
 * Two rounds. The first calls claim_next() directly, which is the narrow question: does the
 * unique index let exactly one worker through. The second runs the whole renewal pipeline,
 * which is the question that matters: does exactly one charge reach the gateway.
 *
 * Usage, from the wp-docker directory:
 *   docker compose exec -T wordpress php /var/www/html/wp-content/plugins/submex/tools/concurrency-test.php
 */

define( 'SUBMEX_ENABLE_TEST_GATEWAY', true );
require_once '/var/www/html/wp-load.php';

use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Test_Gateway;

$options = getopt( '', array( 'worker::', 'mode::', 'subscription::', 'at::', 'out::' ) );

if ( isset( $options['worker'] ) ) {
	run_worker( $options );
	exit( 0 );
}

run_suite();

/**
 * One racing process: wait for the shared instant, then act, then report.
 */
function run_worker( array $options ): void {
	$id   = (int) ( $options['subscription'] ?? 0 );
	$at   = (float) ( $options['at'] ?? 0 );
	$mode = (string) ( $options['mode'] ?? 'claim' );
	$out  = (string) ( $options['out'] ?? '' );

	// Spin rather than sleep: the last microseconds are what makes this a race.
	while ( microtime( true ) < $at ) {
		usleep( 200 );
	}

	$result = array( 'worker' => (int) $options['worker'], 'outcome' => 'none', 'error' => '' );

	try {
		if ( 'claim' === $mode ) {
			$slots = new Charge_Slot_Repository();
			$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$slot  = $slots->claim_next( $id, $now, $now, $now->modify( '+1 month' ) );

			$result['outcome'] = $slot ? 'claimed' : 'lost';
			$result['period']  = $slot ? (int) $slot->period_index : null;
		} else {
			// The test gateway refuses to simulate a charge unless WP_DEBUG is on or it has
			// been sanctioned. Without this the race is a race to fail, which proves less.
			Test_Gateway::sanction();

			Submex\Plugin::instance()->get( 'gateways' )->add( new Test_Gateway() );
			Submex\Plugin::instance()->get( 'processor' )->process( $id );
			$result['outcome'] = 'ran';
		}
	} catch ( Throwable $e ) {
		$result['outcome'] = 'threw';
		$result['error']   = get_class( $e ) . ': ' . $e->getMessage();
	}

	if ( '' !== $out ) {
		file_put_contents( $out, wp_json_encode( $result ) );
	}
}

function run_suite(): void {
	global $wpdb;

	$workers = 8;
	$passed  = 0;
	$failed  = 0;

	$context = array();

	$check = static function ( string $label, $got, $want ) use ( &$passed, &$failed, &$context ): void {
		$ok = (string) $got === (string) $want;
		$ok ? $passed++ : $failed++;

		printf( "  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? (string) $got : "got {$got}, want {$want}" );

		// A race that fails once in twenty is useless without the evidence from that run.
		if ( ! $ok && $context ) {
			echo "        what the workers reported and what the ledger holds:\n";

			foreach ( $context['workers'] as $worker ) {
				printf( "          worker %-2s %s %s\n", $worker['worker'] ?? '?', $worker['outcome'] ?? '?', $worker['error'] ?? '' );
			}

			foreach ( $context['slots'] as $slot ) {
				printf( "          slot period=%s state=%s attempt=%s order=%s\n", $slot->period_index, $slot->state, $slot->attempt_group, $slot->renewal_order_id ?: '-' );
			}

			printf( "          missing reports: %d of %d\n", $context['expected'] - count( $context['workers'] ), $context['expected'] );
		}
	};

	echo "\n=== Round 1: " . $workers . " workers claim the same period ===\n";

	$subscription = make_subscription();
	$id           = $subscription->get_id();

	$results = race( $workers, 'claim', $id );

	$claimed = count( array_filter( $results, static fn( array $r ): bool => 'claimed' === ( $r['outcome'] ?? '' ) ) );
	$lost    = count( array_filter( $results, static fn( array $r ): bool => 'lost' === ( $r['outcome'] ?? '' ) ) );
	$threw   = array_filter( $results, static fn( array $r ): bool => 'threw' === ( $r['outcome'] ?? '' ) );

	$context = array(
		'workers'  => $results,
		'expected' => $workers,
		'slots'    => (array) $wpdb->get_results( $wpdb->prepare( "SELECT period_index, state, attempt_group, renewal_order_id FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d", $id ) ),
	);

	$check( 'workers that reported back', count( $results ), $workers );
	$check( 'exactly one claimed the period', $claimed, 1 );
	$check( 'every other worker was refused', $lost, $workers - 1 );
	$check( 'nobody crashed', count( $threw ), 0 );

	foreach ( $threw as $t ) {
		printf( "        %s\n", $t['error'] );
	}

	$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d", $id ) );
	$check( 'exactly one slot row in the ledger', $rows, 1 );

	cleanup( $id );

	echo "\n=== Round 2: " . $workers . " workers renew the same subscription ===\n";

	$subscription = make_subscription( true );
	$id           = $subscription->get_id();

	$results = race( $workers, 'pipeline', $id );

	$threw = array_filter( $results, static fn( array $r ): bool => 'threw' === ( $r['outcome'] ?? '' ) );

	$context = array(
		'workers'  => $results,
		'expected' => $workers,
		'slots'    => (array) $wpdb->get_results( $wpdb->prepare( "SELECT period_index, state, attempt_group, renewal_order_id FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d", $id ) ),
	);

	$check( 'workers that reported back', count( $results ), $workers );
	$check( 'nobody crashed', count( $threw ), 0 );

	foreach ( $threw as $t ) {
		printf( "        %s\n", $t['error'] );
	}

	$slots = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d", $id ) );
	$paid  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d AND state = 'paid'", $id ) );

	$check( 'exactly one charge slot', $slots, 1 );
	$check( 'exactly one of them paid', $paid, 1 );

	$orders = wc_get_orders(
		array(
			'limit'      => -1,
			'type'       => 'shop_order',
			'status'     => 'any',
			'meta_key'   => '_submex_subscription_id',
			'meta_value' => $id,
		)
	);

	$check( 'exactly one renewal order', count( $orders ), 1 );

	$charges = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}submex_activity WHERE subscription_id = %d AND message LIKE %s",
			$id,
			'%Charge attempt%'
		)
	);

	// The heart of it: more than one means a customer was charged twice.
	$check( 'exactly one charge attempt reached the gateway', $charges, 1 );

	$fresh = wc_get_order( $id );
	$check( 'subscription is active', (string) $fresh->get_status(), 'submex-active' );
	$check( 'next payment moved forward exactly once', $fresh->get_period_index(), 0 );

	cleanup( $id );

	printf( "\n%d passed, %d failed\n\n", $passed, $failed );

	exit( $failed > 0 ? 1 : 0 );
}

/**
 * Start the workers, release them together, collect what they say.
 */
function race( int $workers, string $mode, int $subscription_id ): array {
	$dir = sys_get_temp_dir() . '/submex-race-' . wp_generate_password( 8, false );
	mkdir( $dir, 0700, true );

	// Far enough ahead that every process is spinning on the barrier before it lifts.
	$at = microtime( true ) + 2.0;

	for ( $i = 1; $i <= $workers; $i++ ) {
		$command = sprintf(
			'php %s --worker=%d --mode=%s --subscription=%d --at=%.6F --out=%s > /dev/null 2>&1 &',
			escapeshellarg( __FILE__ ),
			$i,
			escapeshellarg( $mode ),
			$subscription_id,
			$at,
			escapeshellarg( $dir . '/' . $i . '.json' )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- starting the racers is the test.
		exec( $command );
	}

	$deadline = microtime( true ) + 120;
	$results  = array();

	while ( microtime( true ) < $deadline ) {
		$files = glob( $dir . '/*.json' );

		if ( count( (array) $files ) >= $workers ) {
			break;
		}

		usleep( 100000 );
	}

	foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		if ( is_array( $decoded ) ) {
			$results[] = $decoded;
		}

		wp_delete_file( $file );
	}

	rmdir( $dir );

	return $results;
}

function make_subscription( bool $scripted = false ): Subscription {
	$subscription = new Subscription();
	$subscription->set_currency( get_woocommerce_currency() );
	$subscription->set_billing_period( 'month' );
	$subscription->set_billing_interval( 1 );
	$subscription->set_payment_method( Test_Gateway::ID );
	$subscription->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - 60 ) );
	$subscription->transition_to( Subscription_Status::Active );
	$subscription->save();

	$subscription = wc_get_order( $subscription->get_id() );

	$fee = new WC_Order_Item_Fee();
	$fee->set_name( 'Concurrency test' );
	$fee->set_total( '10.00' );
	$subscription->add_item( $fee );
	$subscription->calculate_totals( false );
	$subscription->save();

	if ( $scripted ) {
		Test_Gateway::script_subscription( $subscription, array( 'success' ) );
	}

	return wc_get_order( $subscription->get_id() );
}

function cleanup( int $id ): void {
	global $wpdb;

	$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'submex_activity', array( 'subscription_id' => $id ) );

	foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => 'any', 'meta_key' => '_submex_subscription_id', 'meta_value' => $id ) ) as $order ) {
		$order->delete( true );
	}

	$subscription = wc_get_order( $id );

	if ( $subscription ) {
		$subscription->delete( true );
	}
}
