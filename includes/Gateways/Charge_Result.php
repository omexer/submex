<?php

namespace Submex\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why a charge ended the way it did.
 *
 * The four failure kinds drive completely different customer experiences, which is why
 * no gateway is ever allowed to return a bare boolean.
 */
enum Charge_Outcome: string {
	case Success        = 'success';
	case RequiresAction = 'requires_action';
	case SoftDecline    = 'soft_decline';
	case HardDecline    = 'hard_decline';
	case GatewayError   = 'gateway_error';
	case Pending        = 'pending';
}

/**
 * The result of one charge attempt.
 */
final class Charge_Result {

	private function __construct(
		public readonly Charge_Outcome $outcome,
		public readonly ?string $reference = null,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?string $action_url = null
	) {}

	public static function success( ?string $reference = null ): self {
		return new self( Charge_Outcome::Success, $reference );
	}

	/**
	 * The bank wants the customer to confirm (3DS/SCA). This is not a failure.
	 */
	public static function requires_action( string $action_url, ?string $reference = null ): self {
		return new self( Charge_Outcome::RequiresAction, $reference, null, null, $action_url );
	}

	/**
	 * Refused, but retrying later may work (insufficient funds, velocity limits).
	 */
	public static function soft_decline( string $code, string $message = '' ): self {
		return new self( Charge_Outcome::SoftDecline, null, $code, $message );
	}

	/**
	 * Refused permanently (stolen card, account closed). Do not auto-retry.
	 */
	public static function hard_decline( string $code, string $message = '' ): self {
		return new self( Charge_Outcome::HardDecline, null, $code, $message );
	}

	/**
	 * Submitted, but only confirmed days later (Direct Debit); never retried, the gateway settles it via Renewal_Processor::resolve_pending().
	 */
	public static function pending( string $reference, string $message = '' ): self {
		return new self( Charge_Outcome::Pending, $reference, null, $message );
	}

	/**
	 * We never got a definitive answer — timeout, 5xx, transport failure.
	 *
	 * The charge may well have succeeded, so this must never bump attempt_group.
	 */
	public static function error( string $message ): self {
		return new self( Charge_Outcome::GatewayError, null, null, $message );
	}

	public function is_success(): bool {
		return Charge_Outcome::Success === $this->outcome;
	}

	public function needs_customer_action(): bool {
		return Charge_Outcome::RequiresAction === $this->outcome;
	}

	/**
	 * A definitive no from the gateway. Only these bump attempt_group, so only these
	 * cause the next attempt to use a fresh idempotency key.
	 */
	public function is_definitive_decline(): bool {
		return in_array( $this->outcome, array( Charge_Outcome::SoftDecline, Charge_Outcome::HardDecline ), true );
	}

	public function is_pending(): bool {
		return Charge_Outcome::Pending === $this->outcome;
	}

	/**
	 * Unknown outcome — safe to re-enqueue, never safe to re-key.
	 */
	public function is_transient(): bool {
		return Charge_Outcome::GatewayError === $this->outcome;
	}

	public function should_enter_dunning(): bool {
		return Charge_Outcome::SoftDecline === $this->outcome;
	}

	public function describe(): string {
		return trim( $this->outcome->value . ( $this->code ? " ({$this->code})" : '' ) . ( $this->message ? ": {$this->message}" : '' ) );
	}
}
