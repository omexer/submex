<?php

namespace Submex\Gateways;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual renewal: Submex raises the renewal order and the customer pays it themselves.
 *
 * This is the fallback for offline methods (BACS, cheque, COD) and for any gateway that
 * cannot be charged off-session.
 */
class Manual_Gateway implements Recurring_Gateway {

	public const ID = 'submex_manual';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Manual renewal', 'submex' );
	}

	public function model(): Gateway_Model {
		return Gateway_Model::Manual;
	}

	public function supports( string $feature ): bool {
		// Nothing is charged automatically, so the merchant can change anything freely.
		return in_array( $feature, array( 'amount_change', 'date_change', 'pause', 'multiple_subs' ), true );
	}

	public function create_mandate( Subscription $subscription, \WC_Order $initial ): Charge_Result {
		return Charge_Result::success();
	}

	/**
	 * Nothing to charge. The renewal order stays pending and the customer is emailed a
	 * link to pay it, so this reports as an action the customer must take.
	 */
	public function charge_renewal( Subscription $subscription, \WC_Order $renewal, string $idempotency_key ): Charge_Result {
		return Charge_Result::requires_action( $renewal->get_checkout_payment_url(), 'manual' );
	}

	/**
	 * Nothing is ever charged, so there is never a charge to reconcile.
	 */
	public function reconcile( Subscription $subscription, string $idempotency_key ): ?Charge_Result {
		return null;
	}

	public function cancel_mandate( Subscription $subscription ): bool {
		return true;
	}

	public function update_payment_method( Subscription $subscription, string $token ): bool {
		return false;
	}
}
