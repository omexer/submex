<?php
/**
 * The renewal lock has exactly one holder, even when the options cache is stale or two workers take over an expired lock at once.
 *
 * @package Submex
 */

use Submex\Billing\Lock;

require __DIR__ . '/bootstrap.php';

global $wpdb;

// Lock ids that belong to no subscription, so nothing real is ever blocked.
$base = 990000000 + wp_rand( 0, 999999 ) * 10;
$ids  = range( $base, $base + 6 );

$row = static function ( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'submex_lock_' . $id ) );
};

$plant = static function ( int $id, string $value ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", 'submex_lock_' . $id, $value ) );
	wp_cache_delete( 'submex_lock_' . $id, 'options' );
};

echo "\nOne holder\n";
$a = new Lock();
$b = new Lock();
$check( 'the first worker gets it', $a->acquire( $ids[0] ) );
$check( 'a second worker does not', ! $b->acquire( $ids[0] ) );
$check( 'nor does the holder a second time', ! $a->acquire( $ids[0] ) );
$check( 'it is not autoloaded', null !== $row( $ids[0] ) && ! in_array( $row( $ids[0] )->autoload, array( 'yes', 'on', 'auto-on', 'auto' ), true ), $row( $ids[0] ) );
$a->release( $ids[0] );
$check( 'released, it is free again', $b->acquire( $ids[0] ) );
$b->release( $ids[0] );

echo "\nA stale 'this option does not exist' cache\n";
$a->acquire( $ids[1], 600 );
$held    = $row( $ids[1] )->option_value ?? '';
$missing = wp_cache_get( 'notoptions', 'options' );
wp_cache_set( 'notoptions', array_merge( is_array( $missing ) ? $missing : array(), array( 'submex_lock_' . $ids[1] => true ) ), 'options' );
$check( 'a worker told the lock is absent still cannot take it', ! $b->acquire( $ids[1] ) );
$check( 'and the holder\'s row is untouched', $held === ( $row( $ids[1] )->option_value ?? '' ), array( $held, $row( $ids[1] ) ) );
$a->release( $ids[1] );

echo "\nAn expired lock\n";
$plant( $ids[2], ( time() - 60 ) . '|crashed-worker' );
$check( 'is taken over', $a->acquire( $ids[2] ) );
$check( 'by a fresh expiry', ( (int) strtok( $row( $ids[2] )->option_value ?? '', '|' ) ) > time(), $row( $ids[2] ) );
$check( 'and not by a second taker after it', ! $b->acquire( $ids[2] ) );
$a->release( $ids[2] );

echo "\nTwo workers taking over the same expired lock at once\n";
$plant( $ids[3], ( time() - 60 ) . '|crashed-worker' );
$c          = new Lock();
$b_won      = null;
$racing     = false;
$interleave = function ( $query ) use ( &$racing, &$b_won, $b, $ids ) {
	// C has read the expired row; B takes it over before C's delete runs.
	if ( ! $racing && str_starts_with( ltrim( $query ), 'DELETE' ) && str_contains( $query, "'submex_lock_{$ids[3]}'" ) ) {
		$racing = true;
		$b_won  = $b->acquire( $ids[3] );
	}
	return $query;
};
add_filter( 'query', $interleave );
$c_won = $c->acquire( $ids[3] );
remove_filter( 'query', $interleave );
$check( 'setup: the other worker got in first', true === $b_won, $b_won );
$check( 'only one of them holds it', ! $c_won, $c_won );
$c->release( $ids[3] );
$check( 'and the loser releasing leaves the winner holding it', $c->is_locked( $ids[3] ) && ! $c->acquire( $ids[3] ) );
$b->release( $ids[3] );

echo "\nRelease by somebody else\n";
$a->acquire( $ids[4] );
$b->release( $ids[4] );
$check( 'a worker that never held it cannot release it', $a->is_locked( $ids[4] ) && ! $b->acquire( $ids[4] ) );
$a->release( $ids[4] );

$slow = new Lock();
$check( 'setup: a slow worker holds a lock that has already run out', $slow->acquire( $ids[5], -60 ) );
$check( 'setup: a new worker takes over the slow worker\'s expired lock', $a->acquire( $ids[5] ) );
$slow->release( $ids[5] );
$check( 'the slow worker finishing late does not release the new holder\'s lock', $a->is_locked( $ids[5] ) && ! $b->acquire( $ids[5] ) );
$a->release( $ids[5] );

echo "\nA lock written by an older version\n";
$plant( $ids[6], (string) ( time() + 120 ) );
$check( 'still blocks while it runs', ! $a->acquire( $ids[6] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) ( time() - 5 ), 'submex_lock_' . $ids[6] ) );
wp_cache_delete( 'submex_lock_' . $ids[6], 'options' );
$check( 'and is taken over once expired', $a->acquire( $ids[6] ) );
$a->release( $ids[6] );

foreach ( $ids as $id ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'submex_lock_' . $id ) );
	wp_cache_delete( 'submex_lock_' . $id, 'options' );
}

submex_test_done( $fail );
