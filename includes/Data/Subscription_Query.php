<?php

namespace Submex\Data;

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query subscriptions without tripping over WooCommerce's status handling.
 *
 * wc_get_orders() resolves an omitted status — and 'any' — to the shop_order status
 * list, which never contains our es-* slugs, so a bare
 * wc_get_orders( array( 'type' => 'submex_sub' ) ) silently returns nothing. Statuses
 * must also be passed wc- prefixed even though the object reports them unprefixed.
 * Everything in Submex queries through here so that asymmetry lives in one place.
 */
class Subscription_Query {

	/**
	 * @param array<string, mixed> $args Standard wc_get_orders() args.
	 * @return Subscription[]|int[]
	 */
	public static function get( array $args = array() ): array {
		// paginate makes wc_get_orders return an object, which this signature forbids.
		unset( $args['paginate'] );

		$results = wc_get_orders( self::normalize( $args ) );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * A page of results plus the totals an API needs to describe it.
	 *
	 * @return array{items: Subscription[], total: int, pages: int}
	 */
	public static function paginate( array $args = array() ): array {
		$args['paginate'] = true;

		$results = wc_get_orders( self::normalize( $args ) );

		return array(
			'items' => is_object( $results ) ? (array) $results->orders : array(),
			'total' => is_object( $results ) ? (int) $results->total : 0,
			'pages' => is_object( $results ) ? (int) $results->max_num_pages : 0,
		);
	}

	private static function normalize( array $args ): array {
		$args['type']   = Subscription::TYPE;
		$args['status'] = self::prefix_statuses( $args['status'] ?? null );

		return $args;
	}

	/**
	 * @return int[]
	 */
	public static function ids( array $args = array() ): array {
		$args['return'] = 'ids';

		return array_map( 'intval', self::get( $args ) );
	}

	/**
	 * Subscriptions eligible to be billed.
	 *
	 * @return int[]
	 */
	public static function billable_ids( array $args = array() ): array {
		return self::ids( array_merge( $args, array( 'status' => Subscription_Status::Active->value ) ) );
	}

	/**
	 * Find one subscription by a meta value, across every status.
	 *
	 * Exists so callers never hand-roll wc_get_orders() for this: passing status 'any'
	 * silently matches only shop_order statuses and returns nothing for es-* records.
	 */
	public static function find_by_meta( string $meta_key, string $meta_value ): ?Subscription {
		if ( '' === $meta_value ) {
			return null;
		}

		$found = self::get(
			array(
				'limit'      => 1,
				'status'     => null,
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a stored gateway reference can only be found by its meta.
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$subscription = $found[0] ?? null;

		return $subscription instanceof Subscription ? $subscription : null;
	}

	/**
	 * Normalise a status argument into the wc- prefixed slugs the data store matches on.
	 *
	 * @param string|string[]|Subscription_Status|null $status
	 * @return string[]
	 */
	private static function prefix_statuses( $status ): array {
		if ( null === $status || 'any' === $status || array() === $status ) {
			return array_map(
				static fn( Subscription_Status $case ): string => 'wc-' . $case->value,
				Subscription_Status::cases()
			);
		}

		$statuses = is_array( $status ) ? $status : array( $status );

		return array_values(
			array_map(
				static function ( $one ): string {
					$slug = $one instanceof Subscription_Status ? $one->value : (string) $one;

					return 0 === strpos( $slug, 'wc-' ) ? $slug : 'wc-' . $slug;
				},
				$statuses
			)
		);
	}
}
