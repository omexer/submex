<?php

namespace Submex\Lifecycle;

use Submex\Data\Activity_Repository;
use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Letting a subscription run to the end of its paid period instead of renewing.
 *
 * Not the same as cancelling. The customer keeps everything they paid for until the
 * period they already bought runs out, and nothing is charged again. Offering this is
 * what stops "cancel" being the only way to say "not next month".
 */
class Auto_Renewal {

	public const META = '_submex_auto_renew';

	private const OPTION = 'submex_allow_auto_renew_toggle';

	public function __construct( private readonly Activity_Repository $activity ) {}

	public function register(): void {
		add_filter( 'submex_stop_billing', array( $this, 'stop_when_off' ), 5, 2 );
	}

	public static function is_offered(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Default on: a subscription created before this setting existed, or by a gateway
	 * that never set the meta, must keep renewing.
	 */
	public static function is_on( Subscription $subscription ): bool {
		return 'no' !== $subscription->get_meta( self::META );
	}

	public function set( Subscription $subscription, bool $on, string $actor = 'customer' ): void {
		$subscription->update_meta_data( self::META, $on ? 'yes' : 'no' );
		$subscription->save();

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_SCHEDULE_CHANGE,
			$on ? 'Automatic renewal turned back on.' : 'Automatic renewal turned off; this subscription will end when the paid period does.',
			array(),
			$actor
		);
	}

	/**
	 * @param string|false $stop
	 * @return string|false
	 */
	public function stop_when_off( $stop, Subscription $subscription ) {
		if ( $stop ) {
			return $stop;
		}

		return self::is_on( $subscription )
			? $stop
			: __( 'Automatic renewal was turned off.', 'submex' );
	}
}
