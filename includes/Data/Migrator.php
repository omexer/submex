<?php

namespace Submex\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema installer. Migrations are additive only and safe to re-run.
 *
 * Never runs from an activation hook: a store with 100k subscriptions would time out.
 * It runs on init behind a version option — not admin_init, which a store activated from
 * WP-CLI or updated in the background may not reach for days, while every renewal in
 * between fails against tables that do not exist. Action Scheduler takes over for
 * anything that has to touch existing rows.
 */
class Migrator {

	public const DB_VERSION = 2;

	private const OPTION = 'submex_db_version';

	public function register(): void {
		// Early in init, before anything else there reads the tables.
		add_action( 'init', array( $this, 'maybe_migrate' ), 5 );
	}

	public function maybe_migrate(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		$this->install();

		// Autoloaded, since it is now read on every request.
		update_option( self::OPTION, self::DB_VERSION, true );
	}

	/**
	 * Create or update every table. dbDelta is idempotent, so this is safe on every call.
	 */
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		// Derived index for "which subscriptions are due?" — rebuildable from order data.
		dbDelta(
			"CREATE TABLE {$prefix}submex_schedule (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL,
				next_payment_gmt DATETIME NULL DEFAULT NULL,
				trial_end_gmt DATETIME NULL DEFAULT NULL,
				end_gmt DATETIME NULL DEFAULT NULL,
				gateway VARCHAR(50) NOT NULL DEFAULT '',
				customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				site_url VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				UNIQUE KEY uq_sub (subscription_id),
				KEY idx_due (status, next_payment_gmt),
				KEY idx_customer (customer_id, status)
			) {$collate};"
		);

		// Append-only audit trail.
		dbDelta(
			"CREATE TABLE {$prefix}submex_activity (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(40) NOT NULL,
				actor VARCHAR(40) NOT NULL DEFAULT 'system',
				message TEXT NOT NULL,
				context LONGTEXT NULL,
				created_gmt DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_sub_time (subscription_id, created_gmt)
			) {$collate};"
		);

		// The double-charge guard. uq_slot is the whole feature — see Developer Guide 5.1.
		dbDelta(
			"CREATE TABLE {$prefix}submex_charge_slot (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id BIGINT UNSIGNED NOT NULL,
				period_index INT UNSIGNED NOT NULL,
				attempt_group SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				state VARCHAR(20) NOT NULL DEFAULT 'claimed',
				scheduled_for_gmt DATETIME NOT NULL,
				covers_from_gmt DATETIME NOT NULL,
				covers_to_gmt DATETIME NOT NULL,
				renewal_order_id BIGINT UNSIGNED NULL DEFAULT NULL,
				created_gmt DATETIME NOT NULL,
				pending_gmt DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_slot (subscription_id, period_index),
				KEY idx_state (state, scheduled_for_gmt)
			) {$collate};"
		);
	}

	/**
	 * Confirm the unique constraint actually exists.
	 *
	 * dbDelta is quietly unreliable about adding indexes to an existing table, and a
	 * missing uq_slot means the double-charge guard is gone with no other symptom.
	 */
	public function charge_slot_guard_intact(): bool {
		global $wpdb;

		// Direct query: there is no API for reading index metadata, and this guard is the
		// only way to notice dbDelta silently skipping the unique key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_results(
			"SHOW INDEX FROM {$wpdb->prefix}submex_charge_slot WHERE Key_name = 'uq_slot'"
		);

		return 2 === count( $index );
	}
}
