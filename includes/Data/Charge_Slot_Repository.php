<?php

namespace Submex\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The charge-slot ledger.
 *
 * A slot is the right to call a gateway once. Winning the INSERT is what grants it —
 * locks can fail, unique constraints cannot. See Developer Guide 5.1.
 *
 * This is the one table in Submex that is a source of truth rather than an index.
 * Never truncate it in a migration.
 */
// Our own table, and the ledger is the source of truth for whether a customer has been
// charged — serving it from an object cache is exactly the wrong thing to do here.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Charge_Slot_Repository {

	public const STATE_CLAIMED   = 'claimed';
	public const STATE_CHARGING  = 'charging';
	public const STATE_PENDING   = 'pending';
	public const STATE_PAID      = 'paid';
	public const STATE_FAILED    = 'failed';
	public const STATE_ABANDONED = 'abandoned';

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'submex_charge_slot';
	}

	/**
	 * Claim the next slot for a subscription.
	 *
	 * Returns null when another worker already claimed it — that is the double-charge
	 * guard firing, and the caller must abort without contacting the gateway.
	 */
	public function claim_next(
		int $subscription_id,
		\DateTimeImmutable $scheduled_for,
		\DateTimeImmutable $covers_from,
		\DateTimeImmutable $covers_to
	): ?object {
		return $this->claim( $subscription_id, $this->next_index( $subscription_id ), $scheduled_for, $covers_from, $covers_to );
	}

	/**
	 * Claim a specific index. Returns null if it is already taken.
	 */
	public function claim(
		int $subscription_id,
		int $period_index,
		\DateTimeImmutable $scheduled_for,
		\DateTimeImmutable $covers_from,
		\DateTimeImmutable $covers_to
	): ?object {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// Losing this insert is expected under concurrency, so the DB error is not shown.
		$suppressed = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->insert(
			$this->table(),
			array(
				'subscription_id'   => $subscription_id,
				'period_index'      => $period_index,
				'attempt_group'     => 0,
				'state'             => self::STATE_CLAIMED,
				'scheduled_for_gmt' => $scheduled_for->format( 'Y-m-d H:i:s' ),
				'covers_from_gmt'   => $covers_from->format( 'Y-m-d H:i:s' ),
				'covers_to_gmt'     => $covers_to->format( 'Y-m-d H:i:s' ),
				'created_gmt'       => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppressed );

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( $subscription_id, $period_index );
	}

	/**
	 * Reopen an existing failed slot for another attempt. Never allocates a new index.
	 */
	public function begin_retry( int $subscription_id, int $period_index ): ?object {
		global $wpdb;

		$slot = $this->find( $subscription_id, $period_index );
		if ( ! $slot || self::STATE_FAILED !== $slot->state ) {
			return null;
		}

		$wpdb->update(
			$this->table(),
			array( 'state' => self::STATE_CHARGING ),
			array(
				'subscription_id' => $subscription_id,
				'period_index'    => $period_index,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		return $this->find( $subscription_id, $period_index );
	}

	public function mark_charging( int $slot_id ): void {
		$this->set_state( $slot_id, self::STATE_CHARGING );
	}

	public function mark_pending( int $slot_id ): void {
		global $wpdb;

		// The state first and on its own: it must hold even if the timestamp cannot be written.
		$this->set_state( $slot_id, self::STATE_PENDING );
		$wpdb->update( $this->table(), array( 'pending_gmt' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $slot_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * When the slot last went pending; slots from before that was recorded fall back to when they were claimed.
	 */
	public static function pending_since( object $slot ): string {
		return (string) ( $slot->pending_gmt ?? '' ) ?: (string) $slot->created_gmt;
	}

	/**
	 * Close a pending slot as paid or failed. Only one caller can win it, so a repeated webhook changes nothing.
	 */
	public function leave_pending( int $slot_id, string $state ): bool {
		global $wpdb;

		if ( ! in_array( $state, array( self::STATE_PAID, self::STATE_FAILED ), true ) ) {
			return false;
		}

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}submex_charge_slot SET state = %s, attempt_group = attempt_group + %d WHERE id = %d AND state = %s",
				$state,
				self::STATE_FAILED === $state ? 1 : 0,
				$slot_id,
				self::STATE_PENDING
			)
		);
	}

	public function mark_paid( int $slot_id, int $renewal_order_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'state'            => self::STATE_PAID,
				'renewal_order_id' => $renewal_order_id,
			),
			array( 'id' => $slot_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark paid a slot whose order was paid outside the renewal run. Only one caller can win it.
	 */
	public function settle_unpaid( int $slot_id, int $renewal_order_id ): bool {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}submex_charge_slot SET state = %s, renewal_order_id = %d WHERE id = %d AND state IN ( %s, %s, %s, %s )",
				self::STATE_PAID,
				$renewal_order_id,
				$slot_id,
				self::STATE_CLAIMED,
				self::STATE_CHARGING,
				self::STATE_PENDING,
				self::STATE_FAILED
			)
		);
	}

	/**
	 * A definitive decline. Bumps attempt_group so the next attempt gets a fresh
	 * idempotency key; a timeout must NOT come through here.
	 */
	public function mark_failed( int $slot_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}submex_charge_slot SET state = %s, attempt_group = attempt_group + 1 WHERE id = %d",
				self::STATE_FAILED,
				$slot_id
			)
		);
	}

	public function mark_abandoned( int $slot_id ): void {
		$this->set_state( $slot_id, self::STATE_ABANDONED );
	}

	public function attach_order( int $slot_id, int $renewal_order_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array( 'renewal_order_id' => $renewal_order_id ),
			array( 'id' => $slot_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public function find( int $subscription_id, int $period_index ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d AND period_index = %d",
				$subscription_id,
				$period_index
			)
		) ?: null;
	}

	/**
	 * The newest slot that has not reached a final state.
	 *
	 * A subscription with an unsettled slot must resume it rather than allocate a new
	 * index — allocating a new one after an unknown outcome is a double-charge path.
	 */
	public function latest_unsettled( int $subscription_id ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}submex_charge_slot
				 WHERE subscription_id = %d AND state IN ( %s, %s, %s, %s )
				 ORDER BY period_index DESC LIMIT 1",
				$subscription_id,
				self::STATE_CLAIMED,
				self::STATE_CHARGING,
				self::STATE_PENDING,
				self::STATE_FAILED
			)
		) ?: null;
	}

	public function next_index( int $subscription_id ): int {
		global $wpdb;

		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(period_index) FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d",
				$subscription_id
			)
		);

		return null === $max ? 0 : ( (int) $max ) + 1;
	}

	/**
	 * Slots stuck mid-charge past the lock TTL. Unknown, not failed — reconcile against
	 * the gateway before doing anything else.
	 *
	 * @return object[]
	 */
	public function stuck_charging( int $older_than_minutes = 15 ): array {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_minutes * MINUTE_IN_SECONDS ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}submex_charge_slot WHERE state = %s AND created_gmt < %s",
				self::STATE_CHARGING,
				$cutoff
			)
		);
	}

	/**
	 * Slots whose gateway still has not confirmed or failed them, most likely because its webhook never arrived, oldest first.
	 *
	 * @return object[]
	 */
	public function stale_pending( int $older_than_days = 10 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}submex_charge_slot WHERE state = %s AND COALESCE( pending_gmt, created_gmt ) < %s ORDER BY COALESCE( pending_gmt, created_gmt ) ASC",
				self::STATE_PENDING,
				gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Deterministic key derived from (site, subscription, period, attempt_group).
	 */
	public function idempotency_key( object $slot ): string {
		return sha1(
			implode(
				'|',
				array(
					get_option( 'siteurl' ),
					(int) $slot->subscription_id,
					(int) $slot->period_index,
					(int) $slot->attempt_group,
				)
			)
		);
	}

	private function set_state( int $slot_id, string $state ): void {
		global $wpdb;

		$wpdb->update( $this->table(), array( 'state' => $state ), array( 'id' => $slot_id ), array( '%s' ), array( '%d' ) );
	}
}
