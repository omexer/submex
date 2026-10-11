<?php

namespace Submex\Billing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A short-lived, non-reentrant lock around one subscription's renewal, held in a wp_options row.
 *
 * The lock is an optimisation, not the safety guarantee. The charge-slot unique key is
 * what actually prevents a double charge; this just stops wasted work.
 */
// Our own row, and a cached read of it is exactly the stale answer a lock must not give.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Lock {

	private const TTL = 300;

	/** @var array<int, string> Subscription id => the "expiry|token" this instance wrote. */
	private array $held = array();

	public function acquire( int $subscription_id, int $ttl = self::TTL ): bool {
		$name  = $this->name( $subscription_id );
		$value = ( time() + $ttl ) . '|' . wp_generate_uuid4();

		// Someone holds it: take it over only if it has expired, and only one taker may.
		if ( ! $this->insert( $name, $value ) && ! ( $this->delete_expired( $name ) && $this->insert( $name, $value ) ) ) {
			return false;
		}

		$this->held[ $subscription_id ] = $value;

		return true;
	}

	public function release( int $subscription_id ): void {
		global $wpdb;

		$value = $this->held[ $subscription_id ] ?? null;
		unset( $this->held[ $subscription_id ] );

		if ( null === $value ) {
			return;
		}

		// Only while the row is still ours: once expired, another worker may have taken it over.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->name( $subscription_id ), $value ) );
		$this->forget_cached( $this->name( $subscription_id ) );
	}

	public function is_locked( int $subscription_id ): bool {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->name( $subscription_id ) ) );

		return null !== $value && $this->expiry( (string) $value ) > time();
	}

	/** @phpstan-impure */
	private function insert( string $name, string $value ): bool {
		global $wpdb;

		// Not add_option(): it checks get_option() and then upserts, so two workers can both win.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, $value ) );
		$this->forget_cached( $name );

		return 1 === $inserted;
	}

	private function delete_expired( string $name ): bool {
		global $wpdb;

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $current || $this->expiry( (string) $current ) >= time() ) {
			return false;
		}

		// Deleting the exact value read: of two takers of one expired lock, only the first finds it.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current ) );
		$this->forget_cached( $name );

		return 1 === $deleted;
	}

	private function forget_cached( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	private function expiry( string $value ): int {
		return (int) strtok( $value, '|' );
	}

	private function name( int $subscription_id ): string {
		return 'submex_lock_' . $subscription_id;
	}
}
