<?php

namespace Submex\Gateways\Stripe;

use Submex\Domain\Money;
use Submex\Domain\Subscription;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Recurring_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe as a Model A gateway: Submex owns the schedule and charges a stored payment
 * method off-session when a renewal falls due.
 *
 * Deliberately not Stripe Billing. Letting Stripe own the schedule would make it a second
 * source of truth for when a customer is charged, and reconciling two schedules is worse
 * than owning one.
 */
class Stripe_Gateway implements Recurring_Gateway {

	public const ID = 'submex_stripe';

	public const META_CUSTOMER = '_submex_stripe_customer';
	public const META_METHOD   = '_submex_stripe_payment_method';

	/** Codes Stripe considers permanent; retrying these only annoys the customer. */
	private const HARD_DECLINES = array(
		'stolen_card',
		'lost_card',
		'pickup_card',
		'card_not_supported',
		'invalid_account',
		'revocation_of_authorization',
		'revocation_of_all_authorizations',
		'transaction_not_allowed',
	);

	public function __construct( private readonly Stripe_Client $client ) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Stripe', 'submex' );
	}

	public function model(): Gateway_Model {
		return Gateway_Model::Tokenized;
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, array( 'amount_change', 'date_change', 'pause', 'multiple_subs', 'refunds', 'payment_method_change' ), true );
	}

	public function create_mandate( Subscription $subscription, \WC_Order $initial ): Charge_Result {
		$customer = (string) $initial->get_meta( self::META_CUSTOMER );
		$method   = (string) $initial->get_meta( self::META_METHOD );

		if ( '' === $customer && '' !== $method ) {
			$problem = $this->recover_mandate( $subscription );

			return null === $problem ? Charge_Result::success( $method ) : Charge_Result::error( $problem );
		}

		if ( '' === $customer || '' === $method ) {
			return Charge_Result::error( 'Stripe did not return a saved payment method for this order.' );
		}

		$subscription->update_meta_data( self::META_CUSTOMER, $customer );
		$subscription->update_meta_data( self::META_METHOD, $method );
		$subscription->save();

		return Charge_Result::success( $method );
	}

	/**
	 * Charge the stored method with the customer away.
	 *
	 * off_session tells Stripe this is a merchant-initiated transaction, which is what
	 * makes SCA exemptions apply. Without it European cards challenge on every renewal.
	 */
	public function charge_renewal( Subscription $subscription, \WC_Order $renewal, string $idempotency_key ): Charge_Result {
		$customer = (string) $subscription->get_meta( self::META_CUSTOMER );
		$method   = (string) $subscription->get_meta( self::META_METHOD );

		if ( '' === $customer || '' === $method ) {
			$problem = $this->recover_mandate( $subscription );

			if ( null !== $problem ) {
				return Charge_Result::hard_decline( 'no_payment_method', 'No stored Stripe payment method for this subscription. ' . $problem );
			}

			$customer = (string) $subscription->get_meta( self::META_CUSTOMER );
			$method   = (string) $subscription->get_meta( self::META_METHOD );
		}

		$amount = Money::from_decimal( $renewal->get_total(), $renewal->get_currency() );

		$response = $this->client->post(
			'/v1/payment_intents',
			array(
				'amount'         => $this->stripe_amount( $amount, $renewal->get_currency() ),
				'currency'       => strtolower( $renewal->get_currency() ),
				'customer'       => $customer,
				'payment_method' => $method,
				'off_session'    => 'true',
				'confirm'        => 'true',
				'description'    => sprintf( 'Renewal for subscription #%d', $subscription->get_id() ),
				'metadata'       => array(
					'submex_subscription' => (string) $subscription->get_id(),
					'submex_order'        => (string) $renewal->get_id(),
				),
			),
			$idempotency_key
		);

		return $this->interpret( $response, $renewal );
	}

	/**
	 * Ask Stripe what happened to a charge we never got an answer for.
	 *
	 * Looks the intent up by our idempotency key rather than assuming, so a timeout can
	 * never become a second charge.
	 */
	public function reconcile( Subscription $subscription, string $idempotency_key ): ?Charge_Result {
		$search = $this->client->get(
			'/v1/payment_intents/search?query=' . rawurlencode( sprintf( 'metadata["submex_subscription"]:"%d"', $subscription->get_id() ) ) . '&limit=5'
		);

		if ( ! $search['ok'] ) {
			return Charge_Result::error( $search['error'] );
		}

		foreach ( $search['body']['data'] ?? array() as $intent ) {
			if ( 'succeeded' === ( $intent['status'] ?? '' ) && $this->is_recent( $intent ) ) {
				return Charge_Result::success( (string) $intent['id'] );
			}
		}

		// No matching successful charge, so nothing landed and the slot is safe to retry.
		return null;
	}

	/**
	 * Before 0.18.3 checkout saved the card with no Stripe customer, so attach it to one now.
	 *
	 * @return string|null Why it could not be recovered, or null once the subscription can be charged.
	 */
	private function recover_mandate( Subscription $subscription ): ?string {
		$first = $subscription->get_parent_order_id() ? wc_get_order( $subscription->get_parent_order_id() ) : null;

		if ( ! $first instanceof \WC_Order ) {
			return 'Its first order is missing, so there is no card to recover.';
		}

		$method = (string) ( $subscription->get_meta( self::META_METHOD ) ?: $first->get_meta( self::META_METHOD ) );

		if ( '' === $method && str_starts_with( $first->get_transaction_id(), 'pi_' ) ) {
			$intent = $this->client->get( '/v1/payment_intents/' . rawurlencode( $first->get_transaction_id() ) );
			$method = $intent['ok'] ? (string) ( $intent['body']['payment_method'] ?? '' ) : '';
		}

		if ( '' === $method ) {
			return 'Stripe has no card from its first payment. Ask the customer to add one.';
		}

		$card = $this->client->get( '/v1/payment_methods/' . rawurlencode( $method ) );

		if ( ! $card['ok'] ) {
			return 'Stripe could not find the card from its first payment: ' . $card['error'];
		}

		$customer = (string) ( $card['body']['customer'] ?? '' );

		if ( '' === $customer ) {
			$created = $this->client->post(
				'/v1/customers',
				array(
					'email'    => $first->get_billing_email(),
					'name'     => trim( $first->get_formatted_billing_full_name() ),
					'metadata' => array( 'submex_subscription' => (string) $subscription->get_id() ),
				),
				// Reused by a retry after a failed attach; the site keeps stores sharing an account apart.
				'submex_customer_' . substr( md5( (string) get_option( 'siteurl' ) ), 0, 8 ) . '_' . $subscription->get_id()
			);
			$customer = $created['ok'] ? (string) ( $created['body']['id'] ?? '' ) : '';

			if ( '' === $customer ) {
				return 'Stripe could not create a customer for the card: ' . $created['error'];
			}

			$attached = $this->client->post( '/v1/payment_methods/' . rawurlencode( $method ) . '/attach', array( 'customer' => $customer ) );

			if ( ! $attached['ok'] ) {
				return 'Stripe would not save the card from its first payment for renewals, so the customer needs to add one: ' . $attached['error'];
			}
		}

		$subscription->update_meta_data( self::META_CUSTOMER, $customer );
		$subscription->update_meta_data( self::META_METHOD, $method );
		$subscription->save();

		return null;
	}

	public function cancel_mandate( Subscription $subscription ): bool {
		// Nothing to cancel at Stripe: we simply stop charging. Detaching the payment
		// method would break any other subscription sharing the same customer.
		return true;
	}

	public function update_payment_method( Subscription $subscription, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$subscription->update_meta_data( self::META_METHOD, $token );
		$subscription->save();

		return true;
	}

	/**
	 * Map a Stripe response onto the four outcomes the pipeline distinguishes.
	 */
	private function interpret( array $response, \WC_Order $renewal ): Charge_Result {
		if ( $response['ok'] ) {
			$intent = $response['body'];

			return match ( (string) ( $intent['status'] ?? '' ) ) {
				'succeeded'              => Charge_Result::success( (string) ( $intent['id'] ?? '' ) ),
				'requires_action',
				'requires_confirmation'  => Charge_Result::requires_action(
					$renewal->get_checkout_payment_url(),
					(string) ( $intent['id'] ?? '' )
				),
				default                  => Charge_Result::soft_decline( (string) ( $intent['status'] ?? 'unknown' ), 'Stripe did not complete the payment.' ),
			};
		}

		// A network or 5xx failure is an unknown outcome, not a decline.
		if ( 0 === $response['status'] || $response['status'] >= 500 ) {
			return Charge_Result::error( $response['error'] );
		}

		$code = $response['code'];

		// Not a failure: Stripe Checkout on the order's pay page authenticates them, so no client secret leaves Stripe.
		if ( 'authentication_required' === $code ) {
			return Charge_Result::requires_action(
				$renewal->get_checkout_payment_url(),
				(string) ( $response['body']['error']['payment_intent']['id'] ?? '' )
			);
		}

		return in_array( $code, self::HARD_DECLINES, true )
			? Charge_Result::hard_decline( $code, $response['error'] )
			: Charge_Result::soft_decline( $code ?: 'card_declined', $response['error'] );
	}

	/**
	 * Zero-decimal currencies (JPY, KRW) are sent as whole units, everything else in minor.
	 */
	private function stripe_amount( Money $amount, string $currency ): int {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

		if ( in_array( strtoupper( $currency ), $zero_decimal, true ) ) {
			return (int) round( (float) $amount->decimal() );
		}

		return $amount->minor();
	}

	private function is_recent( array $intent ): bool {
		return isset( $intent['created'] ) && ( time() - (int) $intent['created'] ) < DAY_IN_SECONDS;
	}
}
