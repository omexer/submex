<?php

namespace Submex\Data;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes a subscription's own rows when the subscription itself is deleted.
 *
 * The charge ledger and the activity log live in their own tables, so nothing removed them
 * when the order was deleted and they accumulated for the life of the site - rows keyed to
 * a subscription that no longer exists, counted by every query that does not join back.
 *
 * Only on real deletion, never on trashing: a trashed subscription can be restored, and
 * restoring one whose charge history had been erased would let an already-charged period
 * be charged again.
 */
class Cleanup {

	public function register(): void {
		add_action( 'woocommerce_delete_order', array( $this, 'forget' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'forget_post' ), 10, 1 );
	}

	/**
	 * @param int $order_id
	 */
	public function forget( $order_id ): void {
		global $wpdb;

		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );

		// Only ours. A shop order sharing an id space with subscriptions must not have
		// somebody else's rows deleted on its behalf.
		if ( $order && ! $order instanceof Subscription ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own tables, never cached.
		$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $order_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'submex_activity', array( 'subscription_id' => $order_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Posts-table installs delete through WordPress rather than WooCommerce.
	 *
	 * @param int $post_id
	 */
	public function forget_post( $post_id ): void {
		if ( Subscription::TYPE === get_post_type( (int) $post_id ) ) {
			$this->forget( $post_id );
		}
	}

	/**
	 * Rows whose subscription is already gone, from before this existed.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_orphans(): int {
		global $wpdb;

		$removed = 0;

		foreach ( array( 'submex_charge_slot', 'submex_activity' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off sweep of our own table.
			$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT subscription_id FROM %i', $wpdb->prefix . $table ) );

			foreach ( $ids as $id ) {
				if ( wc_get_order( (int) $id ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, never cached.
				$removed += (int) $wpdb->delete( $wpdb->prefix . $table, array( 'subscription_id' => (int) $id ), array( '%d' ) );
			}
		}

		return $removed;
	}
}
