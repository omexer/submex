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

	public static function is_immediate(): bool {
		return self::is_offered() && self::IMMEDIATELY === get_option( self::OPTION_WHEN, self::AT_PERIOD_END );
	}
}
