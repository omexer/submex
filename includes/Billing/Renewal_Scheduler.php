<?php

namespace Submex\Billing;

use Submex\Data\Activity_Repository;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues renewal work through Action Scheduler, and sweeps up whatever WP-Cron missed.
 *
 * Actions carry only the subscription ID. The period index is allocated at claim time,
 * so an early renewal that consumed the next slot simply causes the queued action to
 * find nothing to do rather than billing a stale period.
 */
class Renewal_Scheduler {

	public const ACTION_RENEWAL  = 'submex_scheduled_renewal';
	public const ACTION_REMINDER = 'submex_renewal_reminder';
	public const ACTION_SWEEP    = 'submex_sweep_overdue';
	public const GROUP           = 'submex';

	public const OPTION_REMINDER_HOURS = 'submex_renewal_reminder_hours';
	public const DEFAULT_HOURS         = 24;

	/** Cap per sweep so a site dark for a month does not fire thousands of charges at once. */
	private const SWEEP_BATCH = 50;

	// Due rows already holding a scheduled action are skipped; stop scanning past these.
	private const SWEEP_SCAN = 1000;

	private const META_UNKNOWN_RETRY = '_submex_unknown_retry';

	private const MAX_UNKNOWN_RETRIES = 5;

	public function __construct( private readonly Activity_Repository $activity ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_sweeper' ), 30 );
		add_action( self::ACTION_SWEEP, array( $this, 'sweep' ) );
		add_action( self::ACTION_REMINDER, array( $this, 'remind' ) );
	}

	/**
	 * WP-Cron does not fire on a site with no visitors, and a store can go a week without
	 * one. The sweeper is what stops that being silent revenue loss.
	 */
	public function ensure_sweeper(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::ACTION_SWEEP, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::ACTION_SWEEP, array(), self::GROUP );
		}
	}

	public function schedule_next( Subscription $subscription ): void {
		$next = $subscription->get_next_payment();
		if ( empty( $next ) ) {
			return;
		}

		$due = strtotime( $next . ' UTC' );

		$this->schedule_at( $subscription->get_id(), $due );
		$this->schedule_reminder( $subscription, $due );

		/**
		 * Fires once the next payment and its reminder are queued, for reminders of other dates.
		 *
		 * @param Subscription $subscription
		 */
		do_action( 'submex_next_payment_scheduled', $subscription );
	}

	/**
	 * Hours ahead for a reminder, never less than one: its email's own switch is what turns it off.
	 */
	public static function hours( string $option ): int {
		$hours = get_option( $option, self::DEFAULT_HOURS );

		return is_numeric( $hours ) ? max( 1, (int) $hours ) : self::DEFAULT_HOURS;
	}

	/**
	 * Warn the customer before the card is charged. Skipped when the payment is already
	 * closer than the warning period — a reminder that arrives after the charge is worse
	 * than none at all.
	 */
	private function schedule_reminder( Subscription $subscription, int $due ): void {
		$last = null !== self::ends_at( $subscription );

		$this->queue( self::ACTION_REMINDER, $subscription->get_id(), $last ? 0 : $due - self::hours( self::OPTION_REMINDER_HOURS ) * HOUR_IN_SECONDS );
	}

	/**
	 * One pending action per subscription and hook, at $at; none when $at has passed.
	 */
	private function queue( string $hook, int $subscription_id, int $at ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( 'subscription_id' => $subscription_id );

		as_unschedule_all_actions( $hook, $args, self::GROUP );

		if ( $at > time() ) {
			as_schedule_single_action( $at, $hook, $args, self::GROUP );
		}
	}

	/**
	 * When a subscription on its last paid period stops: the next payment on or after its end date is never charged.
	 */
	public static function ends_at( Subscription $subscription ): ?int {
		$end  = (string) $subscription->get_end_date();
		$next = (string) $subscription->get_next_payment();

		if ( '' === $end || '' === $next ) {
			return null;
		}

		$next_at = strtotime( $next . ' UTC' );

		return $next_at >= strtotime( $end . ' UTC' ) ? $next_at : null;
	}

	/**
	 * @param int|string $subscription_id
	 */
	public function remind( $subscription_id ): void {
		$subscription = wc_get_order( (int) $subscription_id );
		$status       = $subscription instanceof Subscription ? $subscription->get_status_enum() : null;

		if ( ! $subscription instanceof Subscription || ! $status || ! $status->is_billable() ) {
			return;
		}

		$next = $subscription->get_next_payment();

		// The date can have moved since this was queued — a payment taken early, a plan
		// switched — and warning about a charge that is no longer coming is a support call.
		if ( empty( $next ) || strtotime( $next . ' UTC' ) <= time() || null !== self::ends_at( $subscription ) ) {
			return;
		}

		/**
		 * Fires a few hours before a subscription is charged.
		 *
		 * @param Subscription $subscription
		 */
		do_action( 'submex_renewal_due_soon', $subscription );
	}

	/**
	 * Re-enqueue after an unknown outcome, with backoff. Capped so a permanently broken
	 * gateway cannot loop forever.
	 */
	public function schedule_retry( int $subscription_id, int $period_index ): void {
		$subscription = wc_get_order( $subscription_id );

		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		// Counted per period, so a later period's timeout starts again from the shortest wait.
		$last        = (array) $subscription->get_meta( self::META_UNKNOWN_RETRY );
		$same_period = isset( $last['period'] ) && (int) $last['period'] === $period_index;
		$attempt     = $same_period ? (int) ( $last['attempt'] ?? 0 ) + 1 : 1;

		if ( $attempt > self::MAX_UNKNOWN_RETRIES ) {
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Gave up re-trying an unknown gateway outcome; needs reconciliation.' );
			return;
		}

		$subscription->update_meta_data(
			self::META_UNKNOWN_RETRY,
			array(
				'period'  => $period_index,
				'attempt' => $attempt,
			)
		);
		$subscription->save();

		$this->schedule_at( $subscription_id, time() + ( ( 2 ** $attempt ) * MINUTE_IN_SECONDS ) );
	}

	public function schedule_at( int $subscription_id, int $timestamp ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( 'subscription_id' => $subscription_id );

		if ( $this->has_pending( self::ACTION_RENEWAL, $args ) ) {
			return;
		}

		as_schedule_single_action( max( $timestamp, time() ), self::ACTION_RENEWAL, $args, self::GROUP );
	}

	/**
	 * Pending only: as_next_scheduled_action() also counts the running action, so a renewal could never queue its successor.
	 */
	private function has_pending( string $hook, array $args ): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $ids );
	}

	public function unschedule( int $subscription_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_RENEWAL, array( 'subscription_id' => $subscription_id ), self::GROUP );
			as_unschedule_all_actions( self::ACTION_REMINDER, array( 'subscription_id' => $subscription_id ), self::GROUP );
		}

		/**
		 * Fires once a subscription's renewal and its reminder are no longer queued.
		 *
		 * @param int $subscription_id
		 */
		do_action( 'submex_renewals_unscheduled', $subscription_id );
	}

	/**
	 * Find subscriptions that are past due, or past the end of a cancelled period, with nothing queued, and queue them.
	 */
	public function sweep(): void {
		$now    = time();
		$cutoff = gmdate( 'Y-m-d H:i:s', $now );
		$queued = 0;

		for ( $offset = 0; $queued < self::SWEEP_BATCH && $offset < self::SWEEP_SCAN; $offset += self::SWEEP_BATCH ) {
			$due = $this->due_ids( $cutoff, self::SWEEP_BATCH, $offset );

			foreach ( $due as $subscription_id ) {
				$subscription = wc_get_order( $subscription_id );
				if ( ! $subscription instanceof Subscription ) {
					continue;
				}

				if ( ! $this->is_due( $subscription, $now ) ) {
					continue;
				}

				// A running renewal counts here: it queues its own successor once it has advanced the date.
				if ( as_next_scheduled_action( self::ACTION_RENEWAL, array( 'subscription_id' => $subscription_id ), self::GROUP ) ) {
					continue;
				}

				$this->schedule_at( $subscription_id, $now );
				++$queued;
			}

			if ( count( $due ) < self::SWEEP_BATCH ) {
				break;
			}
		}

		if ( $queued > 0 ) {
			do_action( 'submex_swept_overdue', $queued );
		}
	}

	/**
	 * Billable or cancelling subscriptions whose next payment is at or before the cutoff, soonest first.
	 *
	 * Read from the meta table directly: wc_get_orders() drops meta queries on legacy post
	 * storage and would return every subscription.
	 *
	 * @return int[]
	 */
	private function due_ids( string $cutoff, int $limit, int $offset ): array {
		global $wpdb;

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// A trial is billable the day it ends, so it has to be swept up too — otherwise a
		// missed action leaves the conversion charge unqueued for ever.
		$active    = 'wc-' . Subscription_Status::Active->value;
		$trialling = 'wc-' . Subscription_Status::Trialling->value;

		// A cancelled period ends on its next payment date; with no date there is nothing left to wait for.
		$ending = 'wc-' . Subscription_Status::PendingCancel->value;

		// A renewal awaiting its gateway's answer cannot move until that answer comes; queuing it only uses up the batch.
		$pending = Charge_Slot_Repository::STATE_PENDING;
		$slots   = $wpdb->prefix . 'submex_charge_slot';

		if ( $hpos ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API filters orders by meta and status together.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM %i o LEFT JOIN %i m ON m.order_id = o.id AND m.meta_key = %s WHERE o.type = %s AND ( ( o.status IN ( %s, %s, %s ) AND m.meta_value <> '' AND m.meta_value <= %s ) OR ( o.status = %s AND COALESCE( m.meta_value, '' ) = '' ) ) AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.subscription_id = o.id AND s.state = %s ) ORDER BY m.meta_value ASC, o.id ASC LIMIT %d OFFSET %d", $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', '_submex_next_payment', Subscription::TYPE, $active, $trialling, $ending, $cutoff, $ending, $slots, $pending, $limit, $offset ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API filters orders by meta on legacy storage.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND ( ( p.post_status IN ( %s, %s, %s ) AND m.meta_value <> '' AND m.meta_value <= %s ) OR ( p.post_status = %s AND COALESCE( m.meta_value, '' ) = '' ) ) AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.subscription_id = p.ID AND s.state = %s ) ORDER BY m.meta_value ASC, p.ID ASC LIMIT %d OFFSET %d", '_submex_next_payment', Subscription::TYPE, $active, $trialling, $ending, $cutoff, $ending, $slots, $pending, $limit, $offset ) );
		}

		return array_map( 'intval', (array) $ids );
	}

	private function is_due( Subscription $subscription, int $now ): bool {
		$next = $subscription->get_next_payment();

		if ( empty( $next ) ) {
			return Subscription_Status::PendingCancel === $subscription->get_status_enum();
		}

		return strtotime( $next . ' UTC' ) <= $now;
	}

	/**
	 * Is the queue actually running? Surfaced in Site Health — "renewals are not running"
	 * is the single most common support case for this class of plugin.
	 */
	public function queue_is_healthy(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$overdue = as_get_scheduled_actions(
			array(
				'group'        => self::GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'date_compare' => '<',
				'per_page'     => 1,
				'return'       => 'ids',
			)
		);

		return empty( $overdue );
	}
}
