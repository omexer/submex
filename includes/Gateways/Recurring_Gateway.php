<?php

namespace Submex\Gateways;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who owns the billing schedule.
 *
 * Conflating these two is the most expensive mistake available here: with Tokenized we
 * decide when to charge, with GatewayManaged the gateway does and we mirror it.
 */
enum Gateway_Model: string {
	case Tokenized      = 'tokenized';       // Mollie, Square — we own the schedule.
	case GatewayManaged = 'gateway_managed'; // PayPal, Paddle — they own it, we mirror via webhook.
	case Manual         = 'manual';          // Nobody charges; the customer pays an invoice.
}

interface Recurring_Gateway {

	public function id(): string;

	public function title(): string;

	public function model(): Gateway_Model;

	/**
	 * Feature probe: amount_change, date_change, pause, multiple_subs, refunds,
	 * payment_method_change.
	 */
	public function supports( string $feature ): bool;

	/**
	 * Set up the mandate or gateway-side plan during initial checkout.
	 */
	public function create_mandate( Subscription $subscription, \WC_Order $initial ): Charge_Result;

	/**
	 * Charge off-session against a stored mandate. Tokenized gateways only.
	 *
	 * Implementations MUST pass $idempotency_key to the gateway unchanged — it is the
	 * second net behind the charge-slot unique key.
	 */
	public function charge_renewal( Subscription $subscription, \WC_Order $renewal, string $idempotency_key ): Charge_Result;

	/**
	 * Ask the gateway what actually happened to a charge we never got an answer for.
	 *
	 * Returns null when the gateway has no record of it — only then is it safe to charge
	 * that slot again. Any other return is the definitive outcome. Without this, an
	 * unknown result would either be retried into a double charge or written off as a
	 * failure while the customer's money is gone.
	 */
	public function reconcile( Subscription $subscription, string $idempotency_key ): ?Charge_Result;

	/**
	 * Stop billing at the gateway. Must return false unless the gateway confirmed it —
	 * a subscription we believe is cancelled but which keeps charging is the worst bug.
	 */
	public function cancel_mandate( Subscription $subscription ): bool;

	public function update_payment_method( Subscription $subscription, string $token ): bool;
}
