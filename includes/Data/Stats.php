<?php

namespace Submex\Data;

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Money;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurring revenue and how it moved.
 *
 * The snapshot exists because history cannot be recomputed: once a subscription is
 * cancelled it no longer contributes to today's MRR and there is nothing left in the
 * data to say it once did. A figure not recorded on the day is lost.
 */
class Stats {

	public const ACTION_SNAPSHOT = 'submex_daily_snapshot';

	private const OPTION_HISTORY = 'submex_mrr_history';

	/** Just over a year, so a year-on-year comparison always has its other end. */
	private const KEEP_DAYS = 400;

	/** Average length of a Gregorian year and month; one derived from the other so a
	 * yearly plan normalises to exactly a twelfth. */
	private const DAYS_IN_YEAR  = 365.25;
	private const DAYS_IN_MONTH = self::DAYS_IN_YEAR / 12;

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ), 20 );
		add_action( self::ACTION_SNAPSHOT, array( $this, 'take_snapshot' ) );
	}

	public function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || as_next_scheduled_action( self::ACTION_SNAPSHOT, array(), Renewal_Scheduler::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::ACTION_SNAPSHOT, array(), Renewal_Scheduler::GROUP );
	}

	/**
	 * Monthly recurring revenue across every live subscription, in store currency.
	 */
	public function mrr(): Money {
		return $this->recurring_revenue()['mrr'];
	}

	/**
	 * How many live subscriptions MRR had to leave out.
	 */
	public function excluded_currency_count(): int {
		return $this->recurring_revenue()['excluded'];
	}

	/**
	 * Only subscriptions already in the store's currency are summed.
	 *
	 * Converting the rest would mean inventing an exchange rate, and adding them raw
	 * would mean adding yen to euros. They are counted and reported instead, so the
	 * figure is understated in a way the merchant can see rather than wrong silently.
	 *
	 * @return array{mrr: Money, excluded: int}
	 */
	private function recurring_revenue(): array {
		$store    = get_woocommerce_currency();
		$total    = Money::zero( $store );
		$excluded = 0;

		foreach ( Subscription_Query::get(
			array(
				'status' => $this->live_statuses(),
				'limit'  => -1,
			)
		) as $subscription ) {
			if ( ! $subscription instanceof Subscription ) {
				continue;
			}

			if ( $subscription->get_currency() !== $store ) {
				++$excluded;
				continue;
			}

			$total = $total->add( $this->monthly_value( $subscription ) );
		}

		return array(
			'mrr'      => $total,
			'excluded' => $excluded,
		);
	}

	/**
	 * A subscription's value normalised to one month, so a yearly plan and a weekly one
	 * can be added together at all.
	 */
	public function monthly_value( Subscription $subscription ): Money {
		// Revenue is reported without tax, so the figure is the same whichever way the store enters prices.
		$total = Money::from_decimal( $subscription->get_total(), $subscription->get_currency() )
			->subtract( Money::from_decimal( $subscription->get_total_tax(), $subscription->get_currency() ) );

		if ( $total->is_zero() ) {
			return $total;
		}

		$interval = max( 1, $subscription->get_billing_interval() );
		$days     = match ( (string) $subscription->get_billing_period() ) {
			'day'   => 1,
			'week'  => 7,
			'year'  => self::DAYS_IN_YEAR,
			default => self::DAYS_IN_MONTH,
		} * $interval;

		return Money::from_minor(
			(int) round( $total->minor() * ( self::DAYS_IN_MONTH / $days ) ),
			$total->currency(),
			$total->decimals()
		);
	}

	/**
	 * @return array<string, int> status slug => count
	 */
	public function counts_by_status(): array {
		$counts = array();

		foreach ( Subscription_Status::cases() as $status ) {
			$counts[ $status->value ] = count(
				Subscription_Query::ids(
					array(
						'status' => $status->value,
						'limit'  => -1,
					)
				)
			);
		}

		return $counts;
	}

	public function active_count(): int {
		return count(
			Subscription_Query::ids(
				array(
					'status' => $this->live_statuses(),
					'limit'  => -1,
				)
			)
		);
	}

	/**
	 * One row per day. Re-running on the same day overwrites rather than appends, so a
	 * manual run or a retried job cannot double-count.
	 */
	public function take_snapshot( string $date = '' ): void {
		$date    = '' === $date ? gmdate( 'Y-m-d' ) : $date;
		$history = $this->history();

		$history[ $date ] = array(
			'mrr'    => $this->mrr()->minor(),
			'active' => $this->active_count(),
		);

		ksort( $history );

		// Bounded on purpose: an option that only ever grows is autoloaded on every
		// request forever.
		if ( count( $history ) > self::KEEP_DAYS ) {
			$history = array_slice( $history, -self::KEEP_DAYS, null, true );
		}

		update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * @return array<string, array{mrr: int, active: int}>
	 */
	public function history( int $days = 0 ): array {
		$history = get_option( self::OPTION_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();

		return $days > 0 ? array_slice( $history, -$days, null, true ) : $history;
	}

	/**
	 * @return string[]
	 */
	private function live_statuses(): array {
		return array(
			Subscription_Status::Active->value,
			Subscription_Status::Trialling->value,
			Subscription_Status::PendingCancel->value,
		);
	}
}
