<?php

namespace Submex\Gateways;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tokenized gateway that returns scripted results.
 *
 * This is how the renewal pipeline gets tested at all: no real sandbox will produce a
 * timeout, then a soft decline, then a success on demand, and PayPal cannot be
 * time-travelled because it owns its own schedule. See Developer Guide 8.1.
 *
 * Registered only when SUBMEX_ENABLE_TEST_GATEWAY is defined, and it refuses to charge
 * when WP_DEBUG is off so it cannot be turned on quietly in production.
 */
class Test_Gateway implements Recurring_Gateway {

	public const ID = 'submex_test';

	private const SCRIPT_META    = '_submex_test_script';
	private const CURSOR_META    = '_submex_test_cursor';
	private const RECONCILE_META = '_submex_test_reconcile';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Submex test gateway (development only)', 'submex' );
	}

	public function model(): Gateway_Model {
		return Gateway_Model::Tokenized;
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, array( 'amount_change', 'date_change', 'pause', 'multiple_subs', 'refunds', 'payment_method_change' ), true );
	}

	public function create_mandate( Subscription $subscription, \WC_Order $initial ): Charge_Result {
		return Charge_Result::success( 'test_mandate_' . $subscription->get_id() );
	}

	/**
	 * Pop the next outcome off the subscription's script; repeat the last entry forever.
	 */
	public function charge_renewal( Subscription $subscription, \WC_Order $renewal, string $idempotency_key ): Charge_Result {
		// A misconfiguration must not look like a flaky network: gateway_error is transient
		// and would be retried forever, so refuse definitively and say why.
		if ( ! $this->is_safe_to_run() ) {
			return Charge_Result::hard_decline(
				'test_gateway_disabled',
				'The Submex test gateway is registered but WP_DEBUG is off, so it will not simulate charges.'
			);
		}

		$script = $this->script( $subscription );
		$cursor = (int) $subscription->get_meta( self::CURSOR_META );
		$step   = $script[ min( $cursor, count( $script ) - 1 ) ] ?? 'success';

		$subscription->update_meta_data( self::CURSOR_META, $cursor + 1 );
		$subscription->save();

		return $this->result_for( $step, $renewal, $idempotency_key );
	}

	/**
	 * Scripted reconciliation. Default is "no record", i.e. the charge never landed.
	 */
	public function reconcile( Subscription $subscription, string $idempotency_key ): ?Charge_Result {
		$verdict = (string) $subscription->get_meta( self::RECONCILE_META );

		return match ( $verdict ) {
			'success'      => Charge_Result::success( 'test_reconciled_' . substr( $idempotency_key, 0, 12 ) ),
			'hard_decline' => Charge_Result::hard_decline( 'card_declined', 'Reconciled as declined.' ),
			default        => null,
		};
	}

	/**
	 * What the gateway will claim happened when asked about an unknown charge.
	 */
	public static function set_reconcile_verdict( Subscription $subscription, string $verdict ): void {
		$subscription->update_meta_data( self::RECONCILE_META, $verdict );
		$subscription->save();
	}

	public function cancel_mandate( Subscription $subscription ): bool {
		return true;
	}

	public function update_payment_method( Subscription $subscription, string $token ): bool {
		$subscription->set_payment_token_id( (int) $token );
		$subscription->save();

		return true;
	}

	/**
	 * Script a subscription, e.g. array( 'soft_decline', 'gateway_error', 'success' ).
	 *
	 * @param string[] $steps
	 */
	public static function script_subscription( Subscription $subscription, array $steps ): void {
		$subscription->update_meta_data( self::SCRIPT_META, implode( ',', $steps ) );
		$subscription->update_meta_data( self::CURSOR_META, 0 );
		$subscription->save();
	}

	/**
	 * @return string[]
	 */
	private function script( Subscription $subscription ): array {
		$raw = (string) $subscription->get_meta( self::SCRIPT_META );

		return '' === $raw ? array( 'success' ) : array_map( 'trim', explode( ',', $raw ) );
	}

	private function result_for( string $step, \WC_Order $renewal, string $idempotency_key ): Charge_Result {
		return match ( $step ) {
			'requires_action' => Charge_Result::requires_action( $renewal->get_checkout_payment_url(), 'test_pi_' . $idempotency_key ),
			'soft_decline'    => Charge_Result::soft_decline( 'insufficient_funds', 'Test soft decline.' ),
			'hard_decline'    => Charge_Result::hard_decline( 'card_declined', 'Test hard decline.' ),
			'gateway_error'   => Charge_Result::error( 'Test transport failure.' ),
			default           => Charge_Result::success( 'test_charge_' . substr( $idempotency_key, 0, 12 ) ),
		};
	}

	/**
	 * Set only by the setup guide's "run a test renewal" tool, which drives this gateway
	 * on a throwaway subscription it creates and deletes itself.
	 */
	private static bool $sanctioned = false;

	public static function sanction(): void {
		self::$sanctioned = true;
	}

	private function is_safe_to_run(): bool {
		return self::$sanctioned || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
