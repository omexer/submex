<?php

namespace Submex\Lifecycle;

use Submex\Billing\Renewal_Processor;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Gateway_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a customer pay the next period before it is due.
 *
 * The period paid for does not move: paying early settles the charge that was coming,
 * so the schedule is unchanged and nobody gains or loses a day.
 */
final class Early_Renewal {

	private const OUTCOMES = array( 'submex_renewal_succeeded', 'submex_renewal_pending', 'submex_renewal_failed', 'submex_early_renewal_declined', 'submex_renewal_requires_action' );

	public const OPTION = 'submex_allow_early_renewal';

	public static function is_offered(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Only a gateway Submex charges itself can be asked to charge today. A gateway that
	 * bills from a plan of its own, such as PayPal, has no way to bring a payment forward.
	 */
	public static function is_available_for( Subscription $subscription ): bool {
		if ( ! self::is_offered() ) {
			return false;
		}

		$status = $subscription->get_status_enum();

		if ( ! $status || ! $status->is_billable() || empty( $subscription->get_next_payment() ) ) {
			return false;
		}

		$registry = \Submex\Plugin::instance()->get( 'gateways' );

		if ( ! $registry instanceof Gateway_Registry || Gateway_Model::Tokenized !== $registry->for_subscription( $subscription )->model() ) {
			return false;
		}

		$slots = \Submex\Plugin::instance()->get( 'charge_slots' );
		$open  = $slots instanceof Charge_Slot_Repository ? $slots->latest_unsettled( $subscription->get_id() ) : null;

		// The pipeline resumes a submitted renewal rather than charging another, so an early payment would only fail.
		return ! $open || Charge_Slot_Repository::STATE_PENDING !== $open->state;
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function charge( Subscription $subscription ): array {
		$processor = \Submex\Plugin::instance()->get( 'processor' );

		if ( ! $processor instanceof Renewal_Processor || ! self::is_available_for( $subscription ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This subscription cannot be paid early.', 'submex' ),
			);
		}

		$guard = 'submex_renew_early_' . $subscription->get_id();

		// A real charge for a further period, so a double click must not pay two.
		if ( get_transient( $guard ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That payment was just made. Check your subscription before trying again.', 'submex' ),
			);
		}

		set_transient( $guard, 1, MINUTE_IN_SECONDS );

		// The pipeline reports itself through actions rather than a return value, and the
		// charge slot is what actually decides, so listen instead of assuming.
		$outcome = null;
		$id      = $subscription->get_id();

		$watch = static function ( Subscription $charged ) use ( &$outcome, $id ): void {
			if ( $charged->get_id() === $id && null === $outcome ) {
				$outcome = current_action();
			}
		};

		foreach ( self::OUTCOMES as $hook ) {
			add_action( $hook, $watch, 1, 1 );
		}

		$processor->process( $id, true );

		foreach ( self::OUTCOMES as $hook ) {
			remove_action( $hook, $watch, 1 );
		}

		if ( 'submex_renewal_succeeded' !== $outcome && 'submex_renewal_pending' !== $outcome ) {
			delete_transient( $guard );
		}

		return self::describe( $outcome, $id );
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	private static function describe( ?string $outcome, int $id ): array {
		$charged = wc_get_order( $id );
		$next    = $charged instanceof Subscription ? $charged->get_next_payment() : null;
		return match ( $outcome ) {
			'submex_renewal_succeeded'       => array(
				'ok'      => true,
				'message' => $next
					/* translators: %s: date of the next payment */
					? sprintf( __( 'Thank you — your next period is paid. Your next payment is now on %s.', 'submex' ), date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $next . ' UTC' ) ) )
					: __( 'Thank you — your next period is paid.', 'submex' ),
			),
			'submex_renewal_pending'         => array(
				'ok'      => true,
				'message' => __( 'Thank you — your payment is on its way. It can take a few working days to clear.', 'submex' ),
			),
			'submex_renewal_requires_action' => array(
				'ok'      => false,
				'message' => __( 'Your bank needs to confirm that payment. Check your email for the link.', 'submex' ),
			),
			'submex_renewal_failed',
			'submex_early_renewal_declined'  => array(
				'ok'      => false,
				'message' => __( 'That payment was declined, so nothing has been charged. Your subscription is unaffected.', 'submex' ),
			),
			default                          => array(
				'ok'      => false,
				'message' => __( 'We could not take that payment just now. Nothing has been charged.', 'submex' ),
			),
		};
	}
}
