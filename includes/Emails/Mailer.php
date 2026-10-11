<?php

namespace Submex\Emails;

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Submex's emails with WooCommerce and connects them to pipeline events.
 *
 * Every message is a WC_Email so the merchant's existing branding, template overrides
 * and enable/disable toggles apply without extra settings of our own.
 */
class Mailer {

	private const CLASSES = array(
		'Subscription_Started'      => Subscription_Started::class,
		'Renewal_Receipt'           => Renewal_Receipt::class,
		'Payment_Failed'            => Payment_Failed::class,
		'Confirm_Payment'           => Confirm_Payment::class,
		'Subscription_Cancelled'    => Subscription_Cancelled::class,
		'Renewal_Reminder'          => Renewal_Reminder::class,
		'Subscription_Reactivated'  => Subscription_Reactivated::class,
		'Merchant_New_Subscription' => Merchant_New_Subscription::class,
		'Merchant_Cancelled'        => Merchant_Subscription_Cancelled::class,
		'Merchant_Ended'            => Merchant_Subscription_Ended::class,
	);

	/**
	 * WooCommerce's own order emails, suppressed for renewal orders.
	 *
	 * A renewal is not a new order from the customer's point of view, and Submex already
	 * sends a receipt. Without this the customer gets two emails per renewal.
	 */
	private const WOO_ORDER_EMAILS = array(
		'new_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
		'failed_order',
		'customer_failed_order',
	);

	public const ACTION_REACTIVATED = 'submex_reactivated_email';

	private const META_REACTIVATED = '_submex_reactivated_notified';

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'add_emails' ) );

		foreach ( self::WOO_ORDER_EMAILS as $submex_email_id ) {
			add_filter( "woocommerce_email_enabled_{$submex_email_id}", array( $this, 'suppress_for_renewals' ), 10, 2 );
		}

		add_action( 'submex_subscription_activated', array( $this, 'on_activated' ), 10, 1 );
		add_action( 'submex_subscription_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'submex_renewal_succeeded', array( $this, 'on_renewal_paid' ), 10, 2 );
		add_action( 'submex_renewal_failed', array( $this, 'on_renewal_failed' ), 10, 3 );
		add_action( 'submex_renewal_requires_action', array( $this, 'on_requires_action' ), 10, 3 );
		add_action( 'submex_subscription_cancelled', array( $this, 'on_cancelled' ), 10, 1 );
		add_action( 'submex_renewal_due_soon', array( $this, 'on_due_soon' ), 10, 1 );
		add_action( 'submex_subscription_finished', array( $this, 'on_finished' ), 10, 2 );
		add_action( 'submex_subscription_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'submex_subscription_resumed', array( $this, 'queue_reactivated' ), 10, 1 );
		add_action( self::ACTION_REACTIVATED, array( $this, 'send_reactivated' ), 10, 2 );
	}

	/**
	 * @param bool  $enabled
	 * @param mixed $order
	 */
	public function suppress_for_renewals( $enabled, $order ) {
		if ( $order instanceof \WC_Order && 'submex_renewal' === $order->get_created_via() ) {
			return false;
		}

		return $enabled;
	}

	public function add_emails( array $emails ): array {
		foreach ( self::CLASSES as $key => $class ) {
			$emails[ 'Submex_' . $key ] = new $class();
		}

		return $emails;
	}

	public function on_activated( Subscription $subscription ): void {
		$this->dispatch( 'Subscription_Started', array( $subscription ) );
	}

	public function on_created( Subscription $subscription, \WC_Order $order ): void {
		$this->dispatch( 'Merchant_New_Subscription', array( $subscription ) );
	}

	public function on_renewal_paid( Subscription $subscription, \WC_Order $order ): void {
		$this->dispatch( 'Renewal_Receipt', array( $subscription, $order ) );
	}

	public function on_renewal_failed( Subscription $subscription, \WC_Order $order, Charge_Result $result ): void {
		$this->dispatch( 'Payment_Failed', array( $subscription, $order ) );
	}

	public function on_requires_action( Subscription $subscription, \WC_Order $order, Charge_Result $result ): void {
		$this->dispatch( 'Confirm_Payment', array( $subscription, $order, (string) $result->action_url ) );
	}

	public function on_cancelled( Subscription $subscription ): void {
		$this->dispatch( 'Subscription_Cancelled', array( $subscription ) );
		$this->dispatch( 'Merchant_Cancelled', array( $subscription ) );
	}

	public function on_due_soon( Subscription $subscription ): void {
		/**
		 * Whether the renewal reminder goes; false when another email already covers this payment.
		 *
		 * @param bool         $send
		 * @param Subscription $subscription
		 */
		if ( ! apply_filters( 'submex_send_renewal_reminder', true, $subscription ) ) {
			return;
		}

		$this->dispatch( 'Renewal_Reminder', array( $subscription ) );
	}

	public function on_finished( Subscription $subscription, string $reason = '' ): void {
		$this->dispatch( 'Merchant_Ended', array( $subscription, $reason ) );
	}

	/**
	 * A withdrawn cancellation. Renewals never leave submex-cancelling for active, so none is mistaken for one.
	 *
	 * @param Subscription $subscription
	 * @param string       $from
	 * @param string       $to
	 */
	public function on_status_changed( $subscription, $from, $to ): void {
		if ( $subscription instanceof Subscription && Subscription_Status::PendingCancel->value === $from && Subscription_Status::Active->value === $to ) {
			$this->queue_reactivated( $subscription );
		}
	}

	/**
	 * Sent from the queue, not here: the status change is not saved yet, and may be undone in the same request.
	 */
	public function queue_reactivated( Subscription $subscription ): void {
		if ( function_exists( 'as_enqueue_async_action' ) && $subscription->get_id() ) {
			as_enqueue_async_action(
				self::ACTION_REACTIVATED,
				array(
					'subscription_id' => $subscription->get_id(),
					'at'              => time(),
				),
				Renewal_Scheduler::GROUP
			);
		}
	}

	/**
	 * @param int|string $subscription_id
	 * @param int|string $at When it was reactivated; a re-run for the same moment sends nothing.
	 */
	public function send_reactivated( $subscription_id, $at = 0 ): void {
		$subscription = wc_get_order( (int) $subscription_id );
		$status       = $subscription instanceof Subscription ? $subscription->get_status_enum() : null;

		if ( ! $status || ! $status->is_billable() || (string) $at === (string) $subscription->get_meta( self::META_REACTIVATED ) ) {
			return;
		}

		$subscription->update_meta_data( self::META_REACTIVATED, (string) $at );
		$subscription->save();

		$this->dispatch( 'Subscription_Reactivated', array( $subscription ) );
	}

	/**
	 * Whether one of these emails is switched on, by its key in CLASSES.
	 */
	public static function is_enabled( string $key ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		$email = WC()->mailer()->get_emails()[ 'Submex_' . $key ] ?? null;

		return $email instanceof \WC_Email && $email->is_enabled();
	}

	/**
	 * Look the email up through WooCommerce so merchant settings are respected.
	 */
	private function dispatch( string $key, array $args ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ 'Submex_' . $key ] ?? null;

		// Each email declares its own trigger() with its own arguments, so there is no
		// shared signature to put on the base class and no method to call blindly.
		if ( $email instanceof Subscription_Email && is_callable( array( $email, 'trigger' ) ) ) {
			call_user_func_array( array( $email, 'trigger' ), $args );
		}
	}
}
