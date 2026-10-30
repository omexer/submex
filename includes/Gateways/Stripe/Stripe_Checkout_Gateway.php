<?php

namespace Submex\Gateways\Stripe;

use Submex\Admin\Settings_Page;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe at checkout, via a hosted Checkout Session in setup-and-pay mode.
 *
 * Hosted rather than an inline card field on purpose: the card never touches this site,
 * which keeps PCI scope at SAQ-A and means SCA is Stripe's problem at signup. The saved
 * payment method is what the recurring adapter charges later.
 */
class Stripe_Checkout_Gateway extends \WC_Payment_Gateway {

	public const ID = 'submex_stripe';

	public const ACTION_CHECK_SESSION = 'submex_stripe_check_session';

	public function __construct( private readonly Stripe_Client $client ) {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Stripe Subscriptions (Submex)', 'submex' );
		$this->method_description = __( 'Take card payments for subscriptions. Renewals are charged automatically against the saved card. Configure keys under Submex → Settings → Stripe.', 'submex' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Credit or debit card', 'submex' ) );
		$this->description = $this->get_option( 'description', __( 'You will be redirected to Stripe to pay securely.', 'submex' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . self::ID . '_return', array( $this, 'handle_return' ) );
	}

	/**
	 * Where WooCommerce → Payments → Manage goes: the credentials, not just the title.
	 */
	public function get_settings_url(): string {
		return Settings_Page::section_url( 'stripe' );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'submex' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer card payments for subscription purchases', 'submex' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'submex' ),
				'type'    => 'text',
				'default' => __( 'Credit or debit card', 'submex' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'submex' ),
				'type'    => 'textarea',
				'default' => __( 'You will be redirected to Stripe to pay securely.', 'submex' ),
			),
		);
	}

	public function is_available(): bool {
		if ( ! $this->client->is_configured() ) {
			return false;
		}

		// Paying an existing order, such as a declined renewal, has nothing to do with what is in the cart.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			// Switched off for new sign-ups, a renewal the saved card could not pay still needs a way to be paid.
			return ( 'yes' === $this->enabled || $this->paying_renewal() ) && $this->is_enabled_for_order_pay();
		}

		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		// Deliberately the cart, not is_checkout(): the block checkout asks over the Store
		// API, where is_checkout() is false, and the answer there has to be the same one
		// the classic checkout gets or the gateway appears for carts it cannot serve.
		$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;

		if ( ! $cart || $cart->is_empty() ) {
			return parent::is_available();
		}

		return Subscription_Product::cart_has_subscription() && parent::is_available();
	}

	private function paying_renewal(): bool {
		global $wp;
		$order = wc_get_order( absint( $wp->query_vars['order-pay'] ?? 0 ) );

		return $order instanceof \WC_Order && 'submex_renewal' === $order->get_created_via();
	}

	/**
	 * parent::is_available() without the enabled flag, which paying_renewal() may override.
	 */
	private function is_enabled_for_order_pay(): bool {
		$enabled       = $this->enabled;
		$this->enabled = 'yes';
		$available     = parent::is_available();
		$this->enabled = $enabled;

		return $available;
	}

	/**
	 * @param int $order_id
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		$response = $this->client->post( '/v1/checkout/sessions', $this->session_args( $order ) );

		if ( ! $response['ok'] || empty( $response['body']['url'] ) ) {
			$order->add_order_note( sprintf( 'Stripe: %s', $response['error'] ) );
			wc_add_notice( __( 'We could not start the card payment. Please try again or use another method.', 'submex' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_submex_stripe_session', $response['body']['id'] );
		$order->save();

		// A customer who pays and closes the tab never comes back to say so, and Stripe sends no webhook here.
		self::schedule_session_check( $order->get_id(), 20 * MINUTE_IN_SECONDS );

		return array(
			'result'   => 'success',
			'redirect' => (string) $response['body']['url'],
		);
	}

	/**
	 * A trial with no sign-up fee costs nothing today, and Stripe rejects a zero-amount
	 * payment. Setup mode stores the card without charging it, which is what a trial needs.
	 *
	 * @return array<string, mixed>
	 */
	private function session_args( \WC_Order $order ): array {
		$common = array(
			'customer_email'      => $order->get_billing_email(),
			// Stripe's default creates no customer, and a card without one cannot be charged again.
			'customer_creation'   => 'always',
			'client_reference_id' => (string) $order->get_id(),
			'success_url'         => $this->return_url( $order, 'success' ),
			'cancel_url'          => $this->return_url( $order, 'cancel' ),
			'metadata'            => array( 'submex_order' => (string) $order->get_id() ),
		);

		$amount = Stripe_Gateway::stripe_amount( $order->get_total(), $order->get_currency() );

		if ( $amount <= 0 ) {
			return $common + array(
				'mode'              => 'setup',
				'currency'          => strtolower( $order->get_currency() ),
				'setup_intent_data' => array( 'metadata' => array( 'submex_order' => (string) $order->get_id() ) ),
			);
		}

		return $common + array(
			'mode'                => 'payment',
			// Keep the card on file so renewals can be charged off-session later.
			'payment_intent_data' => array(
				'setup_future_usage' => 'off_session',
				'metadata'           => array( 'submex_order' => (string) $order->get_id() ),
			),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $order->get_currency() ),
						'unit_amount'  => $amount,
						'product_data' => array(
							'name' => sprintf(
								/* translators: %s: order number */
								__( 'Order %s', 'submex' ),
								$order->get_order_number()
							),
						),
					),
				),
			),
		);
	}

	public function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Stripe controls this redirect; the HMAC below is the check.
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$result   = sanitize_key( $_GET['submex_result'] ?? '' );
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! hash_equals( $this->return_token( $order ), $token ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		if ( 'success' !== $result && 'submex_renewal' === $order->get_created_via() ) {
			wc_add_notice( __( 'The card payment was cancelled, so this renewal is still unpaid.', 'submex' ), 'notice' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		if ( 'success' !== $result ) {
			wc_add_notice( __( 'The card payment was cancelled, so no subscription was started.', 'submex' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$this->complete_from_session( $order );

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Confirm with Stripe and capture the saved payment method for future renewals.
	 */
	public function complete_from_session( \WC_Order $order ): void {
		$session_id = (string) $order->get_meta( '_submex_stripe_session' );

		if ( '' === $session_id ) {
			return;
		}

		$session = $this->client->get( '/v1/checkout/sessions/' . rawurlencode( $session_id ) . '?expand[]=payment_intent&expand[]=setup_intent' );

		if ( ! $session['ok'] || ! $this->session_is_settled( $session['body'] ?? array() ) ) {
			$order->update_status( 'on-hold', __( 'Waiting for Stripe to confirm the payment.', 'submex' ) );
			return;
		}

		// Setup mode has no payment intent; the card lives on the setup intent instead.
		$intent   = $session['body']['payment_intent'] ?? $session['body']['setup_intent'] ?? array();
		$customer = (string) ( $session['body']['customer'] ?? '' );
		$method   = (string) ( $intent['payment_method'] ?? '' );

		// The recurring adapter charges these later; without them renewals cannot happen.
		$order->update_meta_data( Stripe_Gateway::META_CUSTOMER, $customer );
		$order->update_meta_data( Stripe_Gateway::META_METHOD, $method );
		$order->save();

		$this->attach_mandate( $order );

		$reference = (string) ( $intent['id'] ?? $session_id );

		// A retry charged it off-session while the customer was at Stripe; the same payment seen again is a revisited return link.
		if ( $order->is_paid() ) {
			if ( $order->get_transaction_id() !== $reference ) {
				$this->flag_second_capture( $order, $reference );
			}
			return;
		}

		$order->payment_complete( $reference );
	}

	/**
	 * Settle a Checkout payment the customer never returned from, until its session closes.
	 *
	 * @param int|string $order_id
	 */
	public function check_session( $order_id ): void {
		$order      = wc_get_order( (int) $order_id );
		$session_id = $order instanceof \WC_Order ? (string) $order->get_meta( '_submex_stripe_session' ) : '';

		if ( '' === $session_id || $order->is_paid() ) {
			return;
		}

		$session = $this->client->get( '/v1/checkout/sessions/' . rawurlencode( $session_id ) );
		$body    = $session['ok'] ? (array) $session['body'] : array();

		if ( $body && $this->session_is_settled( $body ) ) {
			$this->complete_from_session( $order );
			return;
		}

		// Stripe closes a session after a day; until then it may still be paid, and a failed lookup proves nothing.
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

		if ( ( ! $body || 'open' === ( $body['status'] ?? '' ) || 'complete' === ( $body['status'] ?? '' ) ) && time() - $created < 2 * DAY_IN_SECONDS ) {
			self::schedule_session_check( $order->get_id(), HOUR_IN_SECONDS );
		}
	}

	private static function schedule_session_check( int $order_id, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION_CHECK_SESSION, array( 'order_id' => $order_id ), \Submex\Billing\Renewal_Scheduler::GROUP );
		}
	}

	/**
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = wc_get_order( $order_id );
		$intent = $order instanceof \WC_Order ? (string) $order->get_transaction_id() : '';

		if ( ! str_starts_with( $intent, 'pi_' ) ) {
			return new \WP_Error( 'submex_stripe_refund', __( 'This order has no Stripe payment to refund.', 'submex' ) );
		}

		$params = array(
			'payment_intent' => $intent,
			'metadata'       => array( 'submex_order' => (string) $order->get_id() ),
		);

		if ( null !== $amount ) {
			$params['amount'] = Stripe_Gateway::stripe_amount( $amount, $order->get_currency() );
		}

		// WooCommerce records the refund before asking the gateway, so the newest one is this one.
		$refunds  = $order->get_refunds();
		$response = $this->client->post( '/v1/refunds', $params, 'submex_refund_' . ( $refunds ? $refunds[0]->get_id() : $order->get_id() . '_' . time() ) );

		if ( ! $response['ok'] ) {
			return new \WP_Error( 'submex_stripe_refund', $response['error'] );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: Stripe refund ID */
				__( 'Refunded %1$s through Stripe (%2$s).', 'submex' ),
				wc_price( (float) ( $amount ?? $order->get_total() ), array( 'currency' => $order->get_currency() ) ),
				(string) ( $response['body']['id'] ?? '' )
			)
		);

		return true;
	}

	private function flag_second_capture( \WC_Order $order, string $reference ): void {
		$message = sprintf( 'Stripe took a second payment (%1$s) for order #%2$s, which was already paid. Refund it in Stripe.', $reference, $order->get_order_number() );

		$order->add_order_note( $message );

		$activity     = \Submex\Plugin::instance()->get( 'activity' );
		$subscription = (int) $order->get_meta( '_submex_subscription_id' );

		if ( $subscription && $activity instanceof \Submex\Data\Activity_Repository ) {
			$activity->log(
				$subscription,
				\Submex\Data\Activity_Repository::TYPE_CHARGE_ATTEMPT,
				$message,
				array(
					'reference' => $reference,
					'refund'    => true,
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function session_is_settled( array $body ): bool {
		if ( 'setup' === ( $body['mode'] ?? '' ) ) {
			return 'complete' === ( $body['status'] ?? '' );
		}

		return 'paid' === ( $body['payment_status'] ?? '' );
	}

	private function attach_mandate( \WC_Order $order ): void {
		$id = (int) $order->get_meta( '_submex_subscription_id' );

		if ( ! $id ) {
			return;
		}

		$subscription = wc_get_order( $id );

		if ( ! $subscription instanceof \Submex\Domain\Subscription ) {
			return;
		}

		$result = ( new Stripe_Gateway( $this->client ) )->create_mandate( $subscription, $order );

		if ( ! $result->is_success() ) {
			$order->add_order_note( sprintf( 'Stripe: could not store the card for renewals — %s', $result->describe() ) );
		}
	}

	private function return_url( \WC_Order $order, string $result ): string {
		return add_query_arg(
			array(
				'order_id'      => $order->get_id(),
				'submex_result' => $result,
				'token'         => $this->return_token( $order ),
			),
			WC()->api_request_url( self::ID . '_return' )
		);
	}

	private function return_token( \WC_Order $order ): string {
		return hash_hmac( 'sha256', 'submex_stripe_return_' . $order->get_id() . $order->get_order_key(), wp_salt( 'auth' ) );
	}
}
