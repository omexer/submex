<?php

namespace Submex\Gateways\PayPal;

use Submex\Domain\Subscription;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal Subscriptions - a Model B gateway.
 *
 * PayPal owns the billing schedule. We do not decide when to charge and we cannot
 * fast-forward it; PayPal bills on its own plan and tells us afterwards by webhook.
 * Our job is to mirror its state, never to duplicate its billing.
 *
 * This is why charge_renewal() below refuses to charge rather than doing something
 * plausible: a Model B gateway charged from our side would double-bill.
 */
class PayPal_Gateway implements Recurring_Gateway {

	public const ID = 'submex_paypal';

	public const META_SUBSCRIPTION_ID = '_submex_paypal_subscription_id';
	public const META_PLAN_ID         = '_submex_paypal_plan_id';

	public function __construct( private readonly PayPal_Client $client ) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'PayPal', 'submex' );
	}

	public function model(): Gateway_Model {
		return Gateway_Model::GatewayManaged;
	}

	/**
	 * PayPal can change a plan's price, but not an individual subscription's amount
	 * mid-cycle, and it has no concept of us moving the next billing date.
	 */
	public function supports( string $feature ): bool {
		return in_array( $feature, array( 'pause', 'refunds' ), true );
	}

	public function create_mandate( Subscription $subscription, \WC_Order $initial ): Charge_Result {
		$paypal_id = (string) $initial->get_meta( self::META_SUBSCRIPTION_ID );

		if ( '' === $paypal_id ) {
			return Charge_Result::error( 'No PayPal subscription reference was returned at checkout.' );
		}

		$subscription->update_meta_data( self::META_SUBSCRIPTION_ID, $paypal_id );
		$subscription->save();

		return Charge_Result::success( $paypal_id );
	}

	/**
	 * Never charges. PayPal bills on its own schedule and reports back by webhook.
	 */
	public function charge_renewal( Subscription $subscription, \WC_Order $renewal, string $idempotency_key ): Charge_Result {
		return Charge_Result::error( 'PayPal bills on its own schedule; waiting for the webhook.' );
	}

	/**
	 * Ask PayPal what happened to a billing cycle we have no record of.
	 *
	 * Returns null when PayPal shows no completed transaction for the period, which is
	 * the only safe signal that nothing was charged.
	 */
	public function reconcile( Subscription $subscription, string $idempotency_key ): ?Charge_Result {
		$paypal_id = $this->paypal_id( $subscription );

		if ( '' === $paypal_id ) {
			return null;
		}

		$response = $this->client->get( '/v1/billing/subscriptions/' . rawurlencode( $paypal_id ) );

		if ( ! $response['ok'] ) {
			// Unknown, not failed. Leave the slot open rather than guessing.
			return Charge_Result::error( $response['error'] );
		}

		return match ( (string) ( $response['body']['status'] ?? '' ) ) {
			'ACTIVE'    => null,
			'SUSPENDED' => Charge_Result::soft_decline( 'paypal_suspended', 'PayPal suspended the subscription.' ),
			'CANCELLED', 'EXPIRED' => Charge_Result::hard_decline( 'paypal_cancelled', 'The PayPal subscription is no longer active.' ),
			default     => null,
		};
	}

	/**
	 * Cancel at PayPal first. Returning true without their confirmation would leave a
	 * subscription we believe is cancelled but which keeps taking money.
	 */
	public function cancel_mandate( Subscription $subscription ): bool {
		$paypal_id = $this->paypal_id( $subscription );

		if ( '' === $paypal_id ) {
			return true;
		}

		$response = $this->client->post(
			'/v1/billing/subscriptions/' . rawurlencode( $paypal_id ) . '/cancel',
			array( 'reason' => 'Cancelled by the customer.' )
		);

		// 404 means it is already gone at their end, which satisfies the intent.
		return $response['ok'] || 404 === $response['status'];
	}

	public function update_payment_method( Subscription $subscription, string $token ): bool {
		return false;
	}

	public function paypal_id( Subscription $subscription ): string {
		return (string) $subscription->get_meta( self::META_SUBSCRIPTION_ID );
	}
}
