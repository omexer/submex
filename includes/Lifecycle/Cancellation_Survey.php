<?php

namespace Submex\Lifecycle;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why customers cancel: the reasons on offer, and the one each cancelled subscription gave.
 *
 * The reason is asked before the cancel, only when the store turns on "Ask before cancelling",
 * and it is always optional. See Retention_Flow.
 */
class Cancellation_Survey {

	public const META_REASON = '_submex_cancel_reason';
	public const META_DETAIL = '_submex_cancel_detail';

	/**
	 * The reasons a customer can pick, in the store's order.
	 *
	 * @return array<string, string>
	 */
	public function reasons(): array {
		return (array) apply_filters( 'submex_cancellation_reasons', Retention_Reasons::enabled() );
	}

	/**
	 * Any reason a subscription may have stored, including one since switched off or deleted.
	 */
	public function label( string $reason ): string {
		return (string) ( $this->reasons()[ $reason ] ?? Retention_Reasons::label( $reason ) );
	}

	public function reason_for( Subscription $subscription ): string {
		$reason = (string) $subscription->get_meta( self::META_REASON );

		return '' === $reason ? '' : $this->label( $reason );
	}

	/**
	 * Aggregate counts for the admin. Reads subscriptions rather than a separate table:
	 * the reason belongs to the subscription and duplicating it would let them diverge.
	 *
	 * @return array<string, int>
	 */
	public function tally(): array {
		$counts = array_fill_keys( array_keys( $this->reasons() + Retention_Reasons::labels() ), 0 );

		foreach ( \Submex\Data\Subscription_Query::get(
			array(
				'limit'  => -1,
				'status' => null,
			)
		) as $subscription ) {
			$reason = (string) $subscription->get_meta( self::META_REASON );

			if ( isset( $counts[ $reason ] ) ) {
				++$counts[ $reason ];
			}
		}

		return $counts;
	}
}
