<?php

namespace Submex\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit trail. Every status change and charge attempt writes a row.
 *
 * Context is JSON, and it never carries gateway payloads, card data or tokens —
 * only references (pi_xxx, sub_xxx) and decline codes.
 */
// Our own append-only table; there is no core API for it and nothing here is cacheable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class Activity_Repository {

	public const TYPE_STATUS_CHANGE   = 'status_change';
	public const TYPE_CHARGE_ATTEMPT  = 'charge_attempt';
	public const TYPE_SCHEDULE_CHANGE = 'schedule_change';
	public const TYPE_NOTE            = 'note';

	public function log( int $subscription_id, string $type, string $message, array $context = array(), ?string $actor = null ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'submex_activity',
			array(
				'subscription_id' => $subscription_id,
				'type'            => $type,
				'actor'           => $actor ?? $this->current_actor(),
				'message'         => $message,
				'context'         => $context ? wp_json_encode( $this->scrub( $context ) ) : null,
				'created_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return object[]
	 */
	public function for_subscription( int $subscription_id, int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}submex_activity WHERE subscription_id = %d ORDER BY created_gmt DESC, id DESC LIMIT %d",
				$subscription_id,
				$limit
			)
		);
	}

	private function current_actor(): string {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 'system';
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 'system';
		}

		return ( is_admin() ? 'admin:' : 'customer:' ) . $user_id;
	}

	/**
	 * Drop anything that looks like a secret before it reaches the log.
	 */
	private function scrub( array $context ): array {
		$blocked = array( 'token', 'access_token', 'secret', 'password', 'card', 'number', 'cvv', 'cvc', 'pan' );

		foreach ( $context as $key => $value ) {
			foreach ( $blocked as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					$context[ $key ] = '[redacted]';
					continue 2;
				}
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = $this->scrub( $value );
			}
		}

		return $context;
	}
}
