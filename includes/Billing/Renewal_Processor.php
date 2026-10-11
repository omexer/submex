<?php

namespace Submex\Billing;

use Submex\Data\Activity_Repository;
use Submex\Data\Charge_Slot_Repository;
use Submex\Domain\Billing_Schedule;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Gateway_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The renewal pipeline. Everything else in Submex is UI around this.
 *
 * Developer Guide 7. The ordering is not stylistic: nothing contacts a gateway before
 * the charge slot is claimed, and the lock is always released in a finally.
 */
class Renewal_Processor {

	public const ACTION_SETTLE = 'submex_settle_paid_renewal';

	public const ACTION_RESOLVE = 'submex_resolve_pending_renewal';

	private const META_PAID_WHILE_PENDING = '_submex_paid_while_pending';

	public function __construct(
		private readonly Charge_Slot_Repository $slots,
		private readonly Activity_Repository $activity,
		private readonly Gateway_Registry $gateways,
		private readonly Renewal_Order_Factory $orders,
		private readonly Renewal_Scheduler $scheduler,
		private readonly Lock $lock
	) {}

	public function register(): void {
		add_action( Renewal_Scheduler::ACTION_RENEWAL, array( $this, 'process' ), 10, 1 );

		// BACS and cheque are marked paid by hand, which changes the status but never calls payment_complete().
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed', self::ACTION_SETTLE ) as $hook ) {
			add_action( $hook, array( $this, 'settle_paid_order' ), 10, 1 );
		}

		add_action( self::ACTION_RESOLVE, array( $this, 'resolve_queued' ), 10, 5 );
	}

	/**
	 * @param int $subscription_id
	 */
	/**
	 * @param int|string $subscription_id
	 * @param bool       $even_if_not_due Charge a period that is not due yet — paying early.
	 */
	public function process( $subscription_id, bool $even_if_not_due = false ): void {
		$subscription_id = (int) $subscription_id;

		if ( ! $this->lock->acquire( $subscription_id ) ) {
			return;
		}

		try {
			$this->run( $subscription_id, $even_if_not_due );
		} catch ( \Throwable $e ) {
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Renewal aborted: ' . $e->getMessage() );
		} finally {
			// A lock left set silently stops this subscription billing forever.
			$this->lock->release( $subscription_id );
		}
	}

	private function run( int $subscription_id, bool $even_if_not_due = false ): void {
		$subscription = wc_get_order( $subscription_id );

		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		$status = $subscription->get_status_enum();

		if ( Subscription_Status::PendingCancel === $status ) {
			$this->end_cancelled_period( $subscription );
			return;
		}

		if ( ! $status || ! $status->is_billable() ) {
			return;
		}

		/**
		 * Lets an extension end a subscription instead of renewing it — an instalment plan
		 * finishing, a fixed-term ending. Returning a reason string stops billing.
		 *
		 * @param string|false $reason
		 * @param Subscription $subscription
		 */
		$stop = apply_filters( 'submex_stop_billing', $this->end_date_reached( $subscription ), $subscription );

		if ( is_string( $stop ) && '' !== $stop ) {
			$this->finish( $subscription, $stop );
			return;
		}

		// Staging clones must never charge a real customer.
		if ( ! $this->is_billing_site( $subscription ) ) {
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Renewal halted: this site is not the one the subscription was created on.' );
			return;
		}

		$schedule = Billing_Schedule::from_subscription( $subscription );
		$gateway  = $this->gateways->for_subscription( $subscription );

		// 2a. Resume anything left unsettled before considering a new period. A slot stuck
		// in `charging` is an UNKNOWN outcome, not a failure, and must be reconciled with
		// the gateway before we ever charge again.
		$open = $this->slots->latest_unsettled( $subscription_id );
		if ( $open ) {
			$this->resume( $subscription, $gateway, $open );
			return;
		}

		// 2b. Only bill a period that is actually due, unless somebody asked for it now.
		// Paying early still covers the period that was coming, so the date does not shift.
		$due_at = $this->as_date( $subscription->get_next_payment() );
		if ( ! $even_if_not_due && $due_at && $due_at > new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) {
			return;
		}

		$covers_from = $due_at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$covers_to   = $this->covers_to( $schedule, $covers_from );

		// 2c. Claim the slot. Losing this insert means another worker already has it.
		$slot = $this->slots->claim_next( $subscription_id, $covers_from, $covers_from, $covers_to );
		if ( ! $slot ) {
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Renewal skipped: this billing period was already claimed.' );
			return;
		}

		$order = $this->orders->create( $subscription, $slot );
		$this->slots->attach_order( (int) $slot->id, $order->get_id() );

		$this->charge( $subscription, $gateway, $order, $slot );
	}

	/**
	 * Settle a slot left behind by an unknown outcome or a decline.
	 */
	private function resume( Subscription $subscription, $gateway, object $slot ): void {
		$subscription_id = $subscription->get_id();
		$order           = $slot->renewal_order_id ? wc_get_order( (int) $slot->renewal_order_id ) : null;

		if ( ! $order ) {
			$order = $this->orders->create( $subscription, $slot );
			$this->slots->attach_order( (int) $slot->id, $order->get_id() );
		}

		// Paid through its payment link while this waited; charging it too bills the period twice.
		if ( $order->is_paid() ) {
			$this->settle( $subscription, $order, $slot );
			return;
		}

		// Submitted and awaiting the gateway's answer; charging again would collect it twice.
		if ( Charge_Slot_Repository::STATE_PENDING === $slot->state ) {
			return;
		}

		// A payment started on its pay page (a bank transfer, a Direct Debit) is still clearing; a retry must not leave it active and unpaid.
		if ( Charge_Slot_Repository::STATE_FAILED === $slot->state && $order->has_status( 'on-hold' ) ) {
			$this->hold( $subscription, __( 'Waiting for the payment made on the renewal\'s pay page to clear.', 'submex' ) );
			return;
		}

		if ( Charge_Slot_Repository::STATE_CHARGING === $slot->state ) {
			$verdict = $gateway->reconcile( $subscription, $this->slots->idempotency_key( $slot ) );

			if ( $verdict ) {
				$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Reconciled unknown charge: ' . $verdict->describe() );
				$this->apply( $subscription, $order, $slot, $verdict );
				return;
			}

			// No record at the gateway, so the charge never landed. Same slot, same key.
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, sprintf( 'Gateway has no record of period %d; retrying the same slot.', $slot->period_index ) );
		}

		if ( Charge_Slot_Repository::STATE_FAILED === $slot->state ) {
			$slot = $this->slots->begin_retry( $subscription_id, (int) $slot->period_index );

			// No longer failed since it was read, so something else has settled it.
			if ( ! $slot ) {
				return;
			}
		}

		$this->charge( $subscription, $gateway, $order, $slot );
	}

	/**
	 * A renewal paid outside the renewal run: through its payment link, or marked paid by the store.
	 *
	 * @param int|string $order_id
	 */
	public function settle_paid_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		$slot  = $order instanceof \WC_Order && $order->is_paid() ? $this->unpaid_slot( $order ) : null;

		if ( ! $slot ) {
			return;
		}

		$subscription_id = (int) $slot->subscription_id;

		if ( ! $this->lock->acquire( $subscription_id ) ) {
			// A renewal run holds it; settling alongside could let that run bill the next period early.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::ACTION_SETTLE, array( 'order_id' => $order->get_id() ), Renewal_Scheduler::GROUP );
			}
			return;
		}

		try {
			$subscription = wc_get_order( $subscription_id );

			if ( $subscription instanceof Subscription ) {
				$this->settle( $subscription, $order, $slot );
			}
		} finally {
			$this->lock->release( $subscription_id );
		}
	}

	/**
	 * The charge slot this renewal order was raised for, while it is still unpaid.
	 */
	private function unpaid_slot( \WC_Order $order ): ?object {
		$slot = $this->slot_for( $order );

		return ! $slot || in_array( $slot->state, array( Charge_Slot_Repository::STATE_PAID, Charge_Slot_Repository::STATE_ABANDONED ), true ) ? null : $slot;
	}

	private function slot_for( \WC_Order $order ): ?object {
		$slot_id = (int) $order->get_meta( '_submex_charge_slot_id' );

		if ( 'submex_renewal' !== $order->get_created_via() || ! $slot_id ) {
			return null;
		}

		$slot = $this->slots->find( (int) $order->get_meta( '_submex_subscription_id' ), (int) $order->get_meta( '_submex_period_index' ) );

		return $slot && (int) $slot->id === $slot_id ? $slot : null;
	}

	/**
	 * Settle a renewal the gateway accepted as pending, with its final answer (a webhook, days later).
	 *
	 * @return bool Whether this call settled it. False when it was not pending, the answer is not final, or it was queued behind a running renewal.
	 */
	public function resolve_pending( \WC_Order $order, Charge_Result $result ): bool {
		$slot = $this->slot_for( $order );

		if ( ! $slot || ! ( $result->is_success() || $result->is_definitive_decline() ) ) {
			return false;
		}

		$late = Charge_Slot_Repository::STATE_PAID === $slot->state && $result->is_definitive_decline();

		if ( ! $late && Charge_Slot_Repository::STATE_PENDING !== $slot->state ) {
			return false;
		}

		$subscription_id = (int) $slot->subscription_id;

		if ( ! $this->lock->acquire( $subscription_id ) ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + MINUTE_IN_SECONDS,
					self::ACTION_RESOLVE,
					array(
						'order_id'  => $order->get_id(),
						'outcome'   => $result->outcome->value,
						'reference' => (string) $result->reference,
						'code'      => (string) $result->code,
						'message'   => (string) $result->message,
					),
					Renewal_Scheduler::GROUP
				);
			}
			return false;
		}

		try {
			if ( $late ) {
				$this->report_late_failure( $order->get_id(), $slot, $result );
				return false;
			}

			$subscription = wc_get_order( $subscription_id );

			if ( ! $subscription instanceof Subscription ) {
				return false;
			}

			return $result->is_success() ? $this->confirm( $subscription, $order, $slot, $result ) : $this->reject( $subscription, $order, $slot, $result );
		} finally {
			$this->lock->release( $subscription_id );
		}
	}

	/**
	 * @param int|string $order_id
	 * @param string     $outcome
	 * @param string     $reference
	 * @param string     $code
	 * @param string     $message
	 */
	public function resolve_queued( $order_id, $outcome, $reference = '', $code = '', $message = '' ): void {
		$order  = wc_get_order( (int) $order_id );
		$result = match ( (string) $outcome ) {
			'success'      => Charge_Result::success( (string) $reference ),
			'soft_decline' => Charge_Result::soft_decline( (string) $code, (string) $message ),
			'hard_decline' => Charge_Result::hard_decline( (string) $code, (string) $message ),
			default        => null,
		};

		if ( $order instanceof \WC_Order && $result ) {
			$this->resolve_pending( $order, $result );
		}
	}

	private function confirm( Subscription $subscription, \WC_Order $order, object $slot, Charge_Result $result ): bool {
		if ( ! $this->slots->leave_pending( (int) $slot->id, Charge_Slot_Repository::STATE_PAID ) ) {
			return false;
		}

		$this->log_resolved( $subscription, $slot, $result );

		// The slot is already paid, so the payment_complete hooks find nothing left to settle.
		$order->payment_complete( (string) $result->reference );

		// A run queued while this waited would otherwise stop the next renewal being queued at its date.
		$this->scheduler->unschedule( $subscription->get_id() );

		$this->credit( $subscription->get_id(), $order, $slot );

		return true;
	}

	private function reject( Subscription $subscription, \WC_Order $order, object $slot, Charge_Result $result ): bool {
		if ( ! $this->slots->leave_pending( (int) $slot->id, Charge_Slot_Repository::STATE_FAILED ) ) {
			return false;
		}

		$this->log_resolved( $subscription, $slot, $result );

		$status = $subscription->get_status_enum();

		if ( $status && ( Subscription_Status::OnHold === $status || $status->is_billable() ) ) {
			$this->decline( $subscription, $order, $result );
			return true;
		}

		$order->update_status( 'failed', $result->describe() );

		// Ending anyway: the period-end run closes it rather than chasing the payment.
		if ( Subscription_Status::PendingCancel === $status ) {
			$this->scheduler->schedule_at( $subscription->get_id(), time() );
		}

		return true;
	}

	/**
	 * The provider failed a renewal the store had already marked paid; only the merchant can decide whether to chase it.
	 */
	private function report_late_failure( int $order_id, object $slot, Charge_Result $result ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || 'yes' !== $order->get_meta( self::META_PAID_WHILE_PENDING ) ) {
			return;
		}

		$order->update_meta_data( self::META_PAID_WHILE_PENDING, 'failed' );
		$order->save_meta_data();

		$reason  = '' !== (string) $result->message ? (string) $result->message : (string) $result->code;
		$message = sprintf(
			/* translators: %s: renewal order number */
			__( 'The payment provider reported that the payment for renewal order #%s failed after the order was marked paid. Nothing was changed automatically: collect the payment from the customer, or cancel the subscription.', 'submex' ),
			$order->get_order_number()
		);

		if ( '' !== $reason ) {
			/* translators: %s: the reason the payment provider gave */
			$message .= ' ' . sprintf( __( 'Reason given: %s', 'submex' ), $reason );
		}

		$order->add_order_note( $message );

		$this->activity->log(
			(int) $slot->subscription_id,
			Activity_Repository::TYPE_CHARGE_ATTEMPT,
			$message,
			array(
				'reference'    => $result->reference,
				'code'         => $result->code,
				'period_index' => (int) $slot->period_index,
			)
		);
	}

	private function log_resolved( Subscription $subscription, object $slot, Charge_Result $result ): void {
		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_CHARGE_ATTEMPT,
			sprintf( 'Pending charge for period %d settled: %s', $slot->period_index, $result->describe() ),
			array(
				'reference'    => $result->reference,
				'code'         => $result->code,
				'period_index' => (int) $slot->period_index,
			)
		);
	}

	private function settle( Subscription $subscription, \WC_Order $order, object $slot ): void {
		if ( ! $this->slots->settle_unpaid( (int) $slot->id, $order->get_id() ) ) {
			return;
		}

		// The provider may still fail it, and resolve_pending must then tell the merchant rather than ignore it.
		if ( Charge_Slot_Repository::STATE_PENDING === $slot->state ) {
			$order->update_meta_data( self::META_PAID_WHILE_PENDING, 'yes' );
			$order->save_meta_data();
		}

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_CHARGE_ATTEMPT,
			sprintf( 'Renewal order #%s for period %d was paid outside the renewal run.', $order->get_order_number(), $slot->period_index ),
			array(
				'reference'    => $order->get_transaction_id(),
				'period_index' => (int) $slot->period_index,
			)
		);

		$this->adopt_payment_method( $subscription, $order );

		$this->credit( $subscription->get_id(), $order, $slot );
	}

	/**
	 * Move a subscription on for a renewal paid after its run, as far as its status still allows.
	 */
	private function credit( int $subscription_id, \WC_Order $order, object $slot ): void {
		$subscription = wc_get_order( $subscription_id );
		$status       = $subscription instanceof Subscription ? $subscription->get_status_enum() : null;

		// Still ending, but not before the end of the period just paid for.
		if ( $subscription instanceof Subscription && Subscription_Status::PendingCancel === $status ) {
			$subscription->set_next_payment( ( new \DateTimeImmutable( $slot->covers_to_gmt, new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' ) );
			$subscription->set_period_index( (int) $slot->period_index );
			$subscription->save();

			do_action( 'submex_renewal_succeeded', $subscription, $order );
			return;
		}

		if ( ! $subscription instanceof Subscription || ! $status || ! ( Subscription_Status::OnHold === $status || $status->is_billable() ) ) {
			$this->activity->log( (int) $slot->subscription_id, Activity_Repository::TYPE_NOTE, 'The subscription is no longer renewing, so this payment did not restart it. Refund it, or reactivate the subscription by hand.' );
			return;
		}

		$this->advance( $subscription, $slot );

		do_action( 'submex_renewal_succeeded', $subscription, $order );
	}

	/**
	 * Keep the card the customer just paid with; a fresh copy, so a gateway that cannot leaves the old mandate intact.
	 */
	private function adopt_payment_method( Subscription $subscription, \WC_Order $order ): void {
		// No transaction means no gateway took it: the store marked it paid.
		if ( '' === (string) $order->get_transaction_id() || $order->get_payment_method() !== $subscription->get_payment_method() ) {
			return;
		}

		$gateway   = $this->gateways->for_subscription( $subscription );
		$candidate = wc_get_order( $subscription->get_id() );

		if ( Gateway_Model::Tokenized !== $gateway->model() || ! $candidate instanceof Subscription ) {
			return;
		}

		try {
			$result = $gateway->create_mandate( $candidate, $order );
		} catch ( \Throwable $e ) {
			$result = null;
		}

		if ( ! $result || ! $result->is_success() ) {
			$this->activity->log( $subscription->get_id(), Activity_Repository::TYPE_NOTE, sprintf( '%s did not keep the payment method from order #%s, so renewals stay on the previous one.', $gateway->title(), $order->get_order_number() ) );
			return;
		}

		$candidate->save();

		$this->activity->log( $subscription->get_id(), Activity_Repository::TYPE_NOTE, sprintf( 'Renewals will now be charged to the payment method used for order #%s.', $order->get_order_number() ) );
	}

	private function charge( Subscription $subscription, $gateway, \WC_Order $order, object $slot ): void {
		// Gateway-managed plans bill themselves; we wait for the webhook.
		if ( Gateway_Model::GatewayManaged === $gateway->model() ) {
			$this->activity->log( $subscription->get_id(), Activity_Repository::TYPE_CHARGE_ATTEMPT, sprintf( 'Awaiting %s webhook for period %d.', $gateway->id(), $slot->period_index ) );
			return;
		}

		$this->slots->mark_charging( (int) $slot->id );
		$key = $this->slots->idempotency_key( $this->slots->find( $subscription->get_id(), (int) $slot->period_index ) ?? $slot );

		do_action( 'submex_before_renewal_charge', $subscription, $order );

		$result = $gateway->charge_renewal( $subscription, $order, $key );

		$this->apply( $subscription, $order, $slot, $result );
	}

	private function apply( Subscription $subscription, \WC_Order $order, object $slot, $result ): void {
		$subscription_id = $subscription->get_id();

		$this->activity->log(
			$subscription_id,
			Activity_Repository::TYPE_CHARGE_ATTEMPT,
			sprintf( 'Charge attempt for period %d: %s', $slot->period_index, $result->describe() ),
			array(
				'reference'    => $result->reference,
				'code'         => $result->code,
				'period_index' => (int) $slot->period_index,
			)
		);

		if ( $result->is_success() ) {
			// Before payment_complete(), whose hooks would otherwise settle this slot a second time.
			$this->slots->mark_paid( (int) $slot->id, $order->get_id() );
			$order->payment_complete( (string) $result->reference );
			$this->advance( $subscription, $slot );

			do_action( 'submex_renewal_succeeded', $subscription, $order );
			return;
		}

		if ( $result->needs_customer_action() ) {
			// Not a failure. No dunning, no attempt_group bump — the customer just has to confirm.
			$order->update_status( 'on-hold', __( 'Awaiting customer authentication.', 'submex' ) );
			$order->update_meta_data( '_submex_action_url', $result->action_url );
			$order->save();
			$this->hold( $subscription, __( 'Waiting for the customer to confirm payment.', 'submex' ) );

			do_action( 'submex_renewal_requires_action', $subscription, $order, $result );
			return;
		}

		if ( $result->is_pending() ) {
			// Not money yet, so the date waits; the subscription keeps its status, and its access, meanwhile.
			$this->slots->mark_pending( (int) $slot->id );
			$order->set_transaction_id( (string) $result->reference );
			// A link left from an earlier confirmation step would make the order payable again.
			$order->delete_meta_data( '_submex_action_url' );
			$order->update_status( 'on-hold', __( 'Payment submitted; awaiting confirmation from the payment provider.', 'submex' ) );

			do_action( 'submex_renewal_pending', $subscription, $order, $result );
			return;
		}

		if ( $result->is_transient() ) {
			// Outcome unknown. Leave the slot charging, keep the key, and try again later.
			$this->scheduler->schedule_retry( $subscription_id, (int) $slot->period_index );
			return;
		}

		// Definitive decline: bump attempt_group so the next attempt uses a fresh key.
		$this->slots->mark_failed( (int) $slot->id );
		$this->decline( $subscription, $order, $result );
	}

	private function decline( Subscription $subscription, \WC_Order $order, Charge_Result $result ): void {
		$order->update_status( 'failed', $result->describe() );

		/**
		 * Fires before a declined renewal puts the subscription on hold, so a grace period can start before access is reconsidered.
		 *
		 * @param Subscription  $subscription Not saved yet; the hold that follows saves it.
		 * @param \WC_Order     $order
		 * @param Charge_Result $result
		 */
		do_action( 'submex_before_failed_renewal_hold', $subscription, $order, $result );

		$this->hold( $subscription, $result->describe() );

		do_action( 'submex_renewal_failed', $subscription, $order, $result );
	}

	/**
	 * No renewal is charged on or after the end date; the period already paid for runs out.
	 *
	 * @return string|false
	 */
	private function end_date_reached( Subscription $subscription ) {
		$end = $this->as_date( $subscription->get_end_date() );
		$due = $this->as_date( $subscription->get_next_payment() );

		return $end && $due && $due >= $end ? 'Subscription reached its end date.' : false;
	}

	/**
	 * End a subscription that has reached its natural conclusion rather than renewing it.
	 */
	private function finish( Subscription $subscription, string $reason ): void {
		$this->close( $subscription, Subscription_Status::Expired, $reason );

		do_action( 'submex_subscription_finished', $subscription, $reason );
	}

	/**
	 * Carry out a cancellation the customer timed for the end of the period they paid for.
	 */
	private function end_cancelled_period( Subscription $subscription ): void {
		$paid_to = $this->as_date( $subscription->get_next_payment() );

		if ( $paid_to && $paid_to > new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) {
			return;
		}

		// Releasing the mandate from a staging clone would stop the live site's agreement.
		if ( ! $this->is_billing_site( $subscription ) ) {
			return;
		}

		$open = $this->slots->latest_unsettled( $subscription->get_id() );

		// The gateway's answer on a submitted renewal decides whether another period was paid for.
		if ( $open && Charge_Slot_Repository::STATE_PENDING === $open->state ) {
			return;
		}

		if ( $open && Charge_Slot_Repository::STATE_CHARGING === $open->state && ! $this->settle_before_ending( $subscription, $open ) ) {
			return;
		}

		// Not submex_subscription_finished: that means the plan ran its course, not that the customer left.
		$this->close( $subscription, Subscription_Status::Cancelled, __( 'Cancelled at the end of the paid period.', 'submex' ) );
	}

	/**
	 * Resolve a charge of unknown outcome before ending: if it landed, the customer paid for another period.
	 *
	 * @return bool Whether the subscription can end now.
	 */
	private function settle_before_ending( Subscription $subscription, object $slot ): bool {
		$subscription_id = $subscription->get_id();
		$verdict         = $this->gateways->for_subscription( $subscription )->reconcile( $subscription, $this->slots->idempotency_key( $slot ) );
		$order           = $slot->renewal_order_id ? wc_get_order( (int) $slot->renewal_order_id ) : null;

		if ( $verdict && $verdict->is_transient() ) {
			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, sprintf( 'Could not confirm the outcome of period %d before ending; will check again.', $slot->period_index ) );
			return false;
		}

		if ( $verdict && $verdict->is_pending() ) {
			if ( ! $order instanceof \WC_Order ) {
				$order = $this->orders->create( $subscription, $slot );
				$this->slots->attach_order( (int) $slot->id, $order->get_id() );
			}

			$this->apply( $subscription, $order, $slot, $verdict );
			return false;
		}

		if ( $verdict && $verdict->is_success() ) {
			if ( ! $order instanceof \WC_Order ) {
				$order = $this->orders->create( $subscription, $slot );
				$this->slots->attach_order( (int) $slot->id, $order->get_id() );
			}

			$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, 'Reconciled unknown charge before ending: ' . $verdict->describe() );

			// Paid before payment_complete, so a listener settling paid renewal orders finds nothing left to do.
			$this->slots->mark_paid( (int) $slot->id, $order->get_id() );
			$order->payment_complete( (string) $verdict->reference );

			$subscription->set_next_payment( $slot->covers_to_gmt );
			$subscription->set_period_index( (int) $slot->period_index );
			$subscription->save();

			$this->scheduler->schedule_at( $subscription_id, (int) strtotime( $slot->covers_to_gmt . ' UTC' ) );

			do_action( 'submex_renewal_succeeded', $subscription, $order );
			return false;
		}

		$this->activity->log( $subscription_id, Activity_Repository::TYPE_CHARGE_ATTEMPT, sprintf( 'Unknown charge for period %d never landed; abandoned before ending.', $slot->period_index ) );
		$this->slots->mark_abandoned( (int) $slot->id );

		if ( $order instanceof \WC_Order && ! $order->is_paid() ) {
			$order->update_status( 'cancelled', __( 'The subscription ended before this renewal was paid.', 'submex' ) );
		}

		return true;
	}

	private function close( Subscription $subscription, Subscription_Status $to, string $reason ): void {
		$this->activity->log( $subscription->get_id(), Activity_Repository::TYPE_STATUS_CHANGE, $reason );

		$subscription->set_next_payment( null );
		$subscription->transition_to( $to, $reason );
		$subscription->save();

		$this->scheduler->unschedule( $subscription->get_id() );
		$this->release_mandate( $subscription );
	}

	/**
	 * Tell a gateway that bills on a plan of its own that we are done.
	 *
	 * Unscheduling stops Submex asking for money; it does nothing to PayPal, which bills
	 * from its own plan and would go on charging a customer whose subscription has ended.
	 * A tokenized gateway only moves money when we ask it to, so it has nothing to undo.
	 */
	private function release_mandate( Subscription $subscription ): void {
		$gateway = $this->gateways->for_subscription( $subscription );

		if ( Gateway_Model::GatewayManaged !== $gateway->model() ) {
			return;
		}

		try {
			$released = $gateway->cancel_mandate( $subscription );
		} catch ( \Throwable $e ) {
			// The message can carry a gateway payload, so only the fact is recorded.
			$released = false;
		}

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_NOTE,
			$released
				/* translators: %s: payment gateway name */
				? sprintf( __( '%s was told to stop billing.', 'submex' ), $gateway->title() )
				/* translators: %s: payment gateway name */
				: sprintf( __( '%s could not be told to stop billing and may keep charging this customer. Cancel the agreement in your gateway account.', 'submex' ), $gateway->title() )
		);
	}

	/**
	 * The end of the period this charge pays for.
	 *
	 * Under the default catch-up policy a subscription that missed several renewals — a site
	 * whose scheduler stopped — is charged once, and that one charge covers the gap up to its
	 * next date in the future. Charging each missed period instead bills the customer several
	 * times at once for an outage they did not cause.
	 */
	private function covers_to( Billing_Schedule $schedule, \DateTimeImmutable $from ): \DateTimeImmutable {
		$to = $schedule->next_date_from( $from );

		if ( 'charge_all' === get_option( 'submex_catch_up_policy', 'rebase' ) ) {
			return $to;
		}

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		// Bounded, so a subscription dark for decades cannot spin here.
		for ( $step = 0; $to <= $now && $step < 1000; $step++ ) {
			$to = $schedule->next_date_from( $to );
		}

		return $to;
	}

	private function advance( Subscription $subscription, object $slot ): void {
		// The charge just taken covers the period ending at covers_to, which is the moment
		// the next one falls due. Advancing from it instead skipped a whole period.
		$next = new \DateTimeImmutable( $slot->covers_to_gmt, new \DateTimeZone( 'UTC' ) );

		$subscription->set_next_payment( $next->format( 'Y-m-d H:i:s' ) );
		$subscription->set_period_index( (int) $slot->period_index );

		if ( Subscription_Status::Active !== $subscription->get_status_enum() ) {
			$subscription->transition_to( Subscription_Status::Active );
		}

		$subscription->save();

		$this->scheduler->schedule_next( $subscription );
	}

	private function hold( Subscription $subscription, string $reason ): void {
		$current = $subscription->get_status_enum();

		if ( $current && $current->can_transition_to( Subscription_Status::OnHold ) ) {
			$subscription->transition_to( Subscription_Status::OnHold, $reason );
			$subscription->save();
		}
	}

	/**
	 * A cloned staging site inherits every subscription and will happily bill real people.
	 */
	private function is_billing_site( Subscription $subscription ): bool {
		$origin = (string) $subscription->get_meta( '_submex_site_url' );

		return '' === $origin || get_option( 'siteurl' ) === $origin;
	}

	private function as_date( $value ): ?\DateTimeImmutable {
		if ( empty( $value ) ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
