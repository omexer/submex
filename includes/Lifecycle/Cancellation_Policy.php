<?php

namespace Submex\Lifecycle;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a customer may cancel from My Account, and when their cancellation takes effect.
 *
 * The store's own cancellations never read this.
 */
final class Cancellation_Policy {

	public const OPTION        = 'submex_allow_cancellation';
	public const OPTION_WHEN   = 'submex_cancellation_effective';
	public const AT_PERIOD_END = 'period_end';
	public const IMMEDIATELY   = 'immediate';

	public static function is_offered(): bool {
		return 'yes' === get_option( self::OPTION, 'yes' );
	}

	/**
	 * The store's setting, then anything the customer agreed to, such as a minimum term.
	 */
	public static function allows( Subscription $subscription ): bool {
		return self::is_offered() && (bool) apply_filters( 'submex_can_cancel', true, $subscription, get_current_user_id() );
	}

	/**
	 * When access ends if the customer cancels at the end of the period, as a UTC date, or null when unknown.
	 */
	public static function access_until( Subscription $subscription ): ?string {
		/**
		 * Filter when a cancel at the end of the period would end access, as a UTC "Y-m-d H:i:s" date.
		 *
		 * @param string|null  $until The next payment date, or null.
		 * @param Subscription $subscription
		 */
		$until = apply_filters( 'submex_cancel_access_until', $subscription->get_next_payment() ?: null, $subscription );

		return is_string( $until ) && '' !== $until && false !== strtotime( $until . ' UTC' ) ? $until : null;
	}

	public static function is_immediate(): bool {
		return self::is_offered() && self::IMMEDIATELY === get_option( self::OPTION_WHEN, self::AT_PERIOD_END );
	}
}
