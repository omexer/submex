<?php

namespace Submex\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What customers did on the way to cancelling: the source every retention number is read from.
 *
 * Times are UTC. Nothing outside this class reads the table directly.
 */
// Our own append-only table; there is no core API for it and stats must not be served stale.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Retention_Event_Repository {

	public const STARTED        = 'started';
	public const REASON         = 'reason';
	public const KEPT           = 'kept';
	public const OFFER_SHOWN    = 'offer_shown';
	public const OFFER_ACCEPTED = 'offer_accepted';
	public const CANCELLED      = 'cancelled';

	public const EVENTS = array( self::STARTED, self::REASON, self::KEPT, self::OFFER_SHOWN, self::OFFER_ACCEPTED, self::CANCELLED );

	// A customer reloading the reasons screen, or coming back to it, is one attempt.
	public const STARTED_WINDOW = 30 * MINUTE_IN_SECONDS;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'submex_retention_events';
	}

	/**
	 * Returns false when nothing was written: an unknown event, or a repeat start inside the window.
	 */
	public function log( int $subscription_id, int $customer_id, string $event, ?string $reason_id = null, ?int $offer_id = null ): bool {
		global $wpdb;

		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return false;
		}

		$now = time();

		if ( self::STARTED === $event && $this->last( $subscription_id, self::STARTED, $now - self::STARTED_WINDOW ) ) {
			return false;
		}

		return (bool) $wpdb->insert(
			self::table(),
			array(
				'subscription_id' => $subscription_id,
				'customer_id'     => $customer_id,
				'event'           => $event,
				'reason_id'       => null === $reason_id || '' === $reason_id ? null : substr( $reason_id, 0, 40 ),
				'offer_id'        => $offer_id,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * The newest row of one event for a subscription, optionally no older than a timestamp.
	 */
	public function last( int $subscription_id, string $event, ?int $since = null ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE subscription_id = %d AND event = %s AND created_at >= %s ORDER BY created_at DESC, id DESC LIMIT 1',
				self::table(),
				$subscription_id,
				$event,
				gmdate( 'Y-m-d H:i:s', $since ?? 0 )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * @return object[] Oldest first.
	 */
	public function for_subscription( int $subscription_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d ORDER BY created_at ASC, id ASC', self::table(), $subscription_id )
		);
	}

	/**
	 * Rows created in [from, to), oldest first.
	 *
	 * @param string[] $events Empty for every event.
	 * @return object[]
	 */
	public function between( int $from, int $to, array $events = array() ): array {
		global $wpdb;

		$events = array_values( array_intersect( $events, self::EVENTS ) );
		$sql    = 'SELECT * FROM %i WHERE created_at >= %s AND created_at < %s';
		$args   = array( self::table(), gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) );

		if ( $events ) {
			$sql .= ' AND event IN (' . implode( ', ', array_fill( 0, count( $events ), '%s' ) ) . ')';
			$args = array_merge( $args, $events );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders only, built above.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY created_at ASC, id ASC', $args ) );
	}

	/**
	 * How many rows of one event in [from, to).
	 */
	public function count( string $event, int $from, int $to ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s AND created_at >= %s AND created_at < %s', self::table(), $event, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) )
		);
	}

	/**
	 * How many rows of one event in [from, to), by reason id ('' for none).
	 *
	 * @return array<string, int>
	 */
	public function count_by_reason( string $event, int $from, int $to ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT reason_id, COUNT(*) AS total FROM %i WHERE event = %s AND created_at >= %s AND created_at < %s GROUP BY reason_id', self::table(), $event, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) )
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row->reason_id ] = (int) $row->total;
		}

		return $counts;
	}
}
