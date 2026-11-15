<?php

namespace Submex\Gateways\PayPal;

use Submex\Billing\Renewal_Order_Factory;
use Submex\Billing\Renewal_Scheduler;
use Submex\Data\Activity_Repository;
use Submex\Data\Charge_Slot_Repository;
use Submex\Data\Subscription_Query;
use Submex\Domain\Billing_Schedule;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives PayPal webhooks and mirrors them onto the subscription.
 *
 * For a gateway-managed plan this is the renewal path: PayPal charges, then tells us.
 * The endpoint verifies, deduplicates and returns 200 fast; all real work happens in an
 * Action Scheduler job so a slow handler can never cause PayPal to retry.
 */
class Webhook_Controller {

	public const ACTION_PROCESS = 'submex_paypal_process_webhook';

	private const SEEN_PREFIX = 'submex_pp_evt_';

	/** Records which PayPal transaction a renewal order came from. */
	public const META_TXN_ID = '_submex_paypal_txn_id';

	public function __construct(
		private readonly PayPal_Client $client,
		private readonly Charge_Slot_Repository $slots,
		private readonly Activity_Repository $activity,
		private readonly Renewal_Order_Factory $orders
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( self::ACTION_PROCESS, array( $this, 'process' ), 10, 1 );
	}

	public function register_route(): void {
		register_rest_route(
			'submex/v1',
			'/webhook/paypal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Authentication is the PayPal signature check inside handle(), which is
				// why this is deliberately public rather than capability-gated.
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$event = $request->get_json_params();

		if ( empty( $event['id'] ) || empty( $event['event_type'] ) ) {
			return new \WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		if ( ! $this->verify( $request, $event ) ) {
			return new \WP_REST_Response( array( 'error' => 'signature' ), 401 );
		}

		// Replay protection: PayPal retries, and a repeated PAYMENT.SALE.COMPLETED
		// must not produce a second renewal order.
		$seen_key = self::SEEN_PREFIX . md5( (string) $event['id'] );
		if ( get_transient( $seen_key ) ) {
			return new \WP_REST_Response( array( 'duplicate' => true ), 200 );
		}
		set_transient( $seen_key, 1, WEEK_IN_SECONDS );

		set_transient( self::SEEN_PREFIX . 'body_' . md5( (string) $event['id'] ), $event, HOUR_IN_SECONDS );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_PROCESS, array( 'event_id' => (string) $event['id'] ), Renewal_Scheduler::GROUP );
		} else {
			$this->process( (string) $event['id'] );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Ask PayPal whether the signature is genuine. Never trust the payload otherwise.
	 */
	private function verify( \WP_REST_Request $request, array $event ): bool {
		$webhook_id = PayPal_Client::credential( 'webhook_id' );

		if ( '' === $webhook_id ) {
			return false;
		}

		$response = $this->client->post(
			'/v1/notifications/verify-webhook-signature',
			array(
				'transmission_id'   => $request->get_header( 'paypal_transmission_id' ),
				'transmission_time' => $request->get_header( 'paypal_transmission_time' ),
				'cert_url'          => $request->get_header( 'paypal_cert_url' ),
				'auth_algo'         => $request->get_header( 'paypal_auth_algo' ),
				'transmission_sig'  => $request->get_header( 'paypal_transmission_sig' ),
				'webhook_id'        => $webhook_id,
				'webhook_event'     => $event,
			)
		);

		return $response['ok'] && 'SUCCESS' === ( $response['body']['verification_status'] ?? '' );
	}

	/**
	 * @param string $event_id
	 */
	public function process( $event_id ): void {
		$event = get_transient( self::SEEN_PREFIX . 'body_' . md5( (string) $event_id ) );

		if ( ! is_array( $event ) ) {
			return;
		}

		$subscription = $this->find_subscription( $event );

		if ( ! $subscription ) {
			return;
		}

		$type = (string) ( $event['event_type'] ?? '' );

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_NOTE,
			sprintf( 'PayPal webhook: %s', $type ),
			array( 'event_id' => (string) $event_id )
		);

		match ( $type ) {
			'PAYMENT.SALE.COMPLETED'              => $this->record_payment( $subscription, $event ),
			'BILLING.SUBSCRIPTION.ACTIVATED'      => $this->set_status( $subscription, Subscription_Status::Active ),
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->suspend( $subscription ),
			'BILLING.SUBSCRIPTION.CANCELLED'      => $this->cancel( $subscription ),
			'BILLING.SUBSCRIPTION.EXPIRED'        => $this->set_status( $subscription, Subscription_Status::Expired ),
			default                               => null,
		};
	}

	/**
	 * PayPal charged. Mirror it into a renewal order and advance our schedule.
	 */
	private function record_payment( Subscription $subscription, array $event ): void {
		$txn_id = (string) ( $event['resource']['id'] ?? '' );

		// Deduplicate on PayPal's transaction, not on the webhook event. Action Scheduler
		// can re-run a job, and PayPal can deliver the same payment under a new event id;
		// claiming a slot allocates a fresh index either way, so it cannot guard this.
		if ( '' !== $txn_id && $this->already_recorded( $subscription, $txn_id ) ) {
			$this->activity->log(
				$subscription->get_id(),
				Activity_Repository::TYPE_CHARGE_ATTEMPT,
				sprintf( 'PayPal payment %s was already recorded; ignoring the repeat.', $txn_id )
			);
			return;
		}

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		if ( $this->is_initial_payment( $subscription, $event ) ) {
			$this->record_initial_payment( $subscription, $txn_id );
			return;
		}

		$schedule    = Billing_Schedule::from_subscription( $subscription );
		$covers_from = $now;
		$covers_to   = $schedule->next_date_from( $covers_from );

		// Claim a slot even though we did not initiate the charge: it is what stops a
		// replayed webhook creating a second renewal order for the same period.
		$slot = $this->slots->claim_next( $subscription->get_id(), $now, $covers_from, $covers_to );

		if ( ! $slot ) {
			$this->activity->log(
				$subscription->get_id(),
				Activity_Repository::TYPE_CHARGE_ATTEMPT,
				'PayPal payment ignored: this period was already recorded.'
			);
			return;
		}

		$order = $this->orders->create( $subscription, $slot );
		$this->slots->attach_order( (int) $slot->id, $order->get_id() );

		$order->update_meta_data( self::META_TXN_ID, $txn_id );
		$order->save();
		// Before payment_complete(), whose hooks would otherwise settle this slot a second time.
		$this->slots->mark_paid( (int) $slot->id, $order->get_id() );
		$order->payment_complete( $txn_id );

		$status = $subscription->get_status_enum();

		if ( $status && $status->is_terminal() ) {
			// The money moved, so the order stands — but dating a next payment on a
			// subscription that has ended would hide that PayPal is still charging.
			$this->activity->log(
				$subscription->get_id(),
				Activity_Repository::TYPE_CHARGE_ATTEMPT,
				__( 'PayPal charged for a subscription that has already ended. Cancel the agreement in your PayPal account, and refund this payment if it was not due.', 'submex' )
			);
		} else {
			$subscription->set_next_payment( $covers_to->format( 'Y-m-d H:i:s' ) );
		}

		$subscription->set_period_index( (int) $slot->period_index );
		$this->set_status( $subscription, Subscription_Status::Active );

		do_action( 'submex_renewal_succeeded', $subscription, $order );
	}

	/**
	 * PayPal reports what it takes at approval like any other payment, but checkout's order already holds it.
	 *
	 * A checkout still unpaid is being paid by this, however late it comes from a pay link. One already
	 * paid may have this as the setup fee or first cycle reported separately, within hours; the first
	 * renewal is at least a day away.
	 */
	private function is_initial_payment( Subscription $subscription, array $event ): bool {
		$parent = $subscription->get_parent_order_id() ? wc_get_order( $subscription->get_parent_order_id() ) : null;

		if ( ! $parent instanceof \WC_Order || $parent->get_total() <= 0 || 1 !== $this->slots->next_index( $subscription->get_id() ) ) {
			return false;
		}

		$paid_at = $parent->get_date_paid() ?: $parent->get_date_created();

		if ( ! $parent->is_paid() || ! $paid_at ) {
			return true;
		}

		$taken = strtotime( (string) ( $event['resource']['create_time'] ?? '' ) ) ?: time();

		return $taken - $paid_at->getTimestamp() < 12 * HOUR_IN_SECONDS;
	}

	private function record_initial_payment( Subscription $subscription, string $txn_id ): void {
		$parent = $subscription->get_parent_order_id() ? wc_get_order( $subscription->get_parent_order_id() ) : null;

		if ( $parent instanceof \WC_Order && '' !== $txn_id && '' === (string) $parent->get_meta( self::META_TXN_ID ) ) {
			$parent->update_meta_data( self::META_TXN_ID, $txn_id );
			$parent->save();
		}

		// The customer approved and closed the tab, so no return ever marked it paid.
		if ( $parent instanceof \WC_Order && ! $parent->is_paid() ) {
			$parent->payment_complete( (string) $subscription->get_meta( PayPal_Gateway::META_SUBSCRIPTION_ID ) );
		}

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_CHARGE_ATTEMPT,
			sprintf( 'PayPal payment %s is the first payment, already paid by the checkout order.', $txn_id )
		);
	}

	/**
	 * Has this PayPal transaction already produced a renewal order?
	 */
	private function already_recorded( Subscription $subscription, string $txn_id ): bool {
		$existing = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'status'     => 'any',
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a gateway reference can only be found by its meta.
				'meta_key'   => self::META_TXN_ID,
				'meta_value' => $txn_id,
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $existing );
	}

	private function suspend( Subscription $subscription ): void {
		if ( $this->set_status( $subscription, Subscription_Status::OnHold ) ) {
			do_action( 'submex_gateway_payment_failed', $subscription );
		}
	}

	private function cancel( Subscription $subscription ): void {
		if ( $this->set_status( $subscription, Subscription_Status::Cancelled ) ) {
			do_action( 'submex_subscription_cancelled', $subscription, 'paypal' );
		}
	}

	/**
	 * @return bool Whether the status changed.
	 */
	private function set_status( Subscription $subscription, Subscription_Status $to ): bool {
		$current = $subscription->get_status_enum();
		$changed = $current && $current !== $to && $current->can_transition_to( $to );

		if ( $changed ) {
			$subscription->transition_to( $to );
		}

		$subscription->save();

		return $changed;
	}

	/**
	 * Resolve the subscription from PayPal's billing agreement reference.
	 */
	private function find_subscription( array $event ): ?Subscription {
		$paypal_id = (string) (
			$event['resource']['billing_agreement_id']
			?? $event['resource']['id']
			?? ''
		);

		if ( '' === $paypal_id ) {
			return null;
		}

		return Subscription_Query::find_by_meta( PayPal_Gateway::META_SUBSCRIPTION_ID, $paypal_id );
	}
}
