<?php

namespace Submex\Gateways\PayPal;

use Submex\Admin\Settings_Page;
use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The PayPal payment method shown at checkout for subscription purchases.
 *
 * Separate from PayPal_Gateway, which is Submex's recurring adapter. This one is a real
 * WC_Payment_Gateway: it only appears when the cart holds a subscription, creates the
 * PayPal subscription, and hands the customer off to PayPal to approve it. The recurring
 * adapter then takes over once PayPal starts sending webhooks.
 */
class PayPal_Checkout_Gateway extends \WC_Payment_Gateway {

	public const ID = 'submex_paypal';

	public function __construct(
		private readonly PayPal_Client $client,
		private readonly PayPal_Plans $plans
	) {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayPal Subscriptions (Submex)', 'submex' );
		$this->method_description = __( 'Lets customers start a subscription with PayPal. Configure the credentials under Submex → Settings → PayPal.', 'submex' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'PayPal', 'submex' ) );
		$this->description = $this->get_option( 'description', __( 'Approve a recurring payment with your PayPal account.', 'submex' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . self::ID . '_return', array( $this, 'handle_return' ) );
	}

	/**
	 * Where WooCommerce → Payments → Manage goes: the credentials, not just the title.
	 */
	public function get_settings_url(): string {
		return Settings_Page::section_url( 'paypal' );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'submex' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer PayPal for subscription purchases', 'submex' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'submex' ),
				'type'    => 'text',
				'default' => __( 'PayPal', 'submex' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'submex' ),
				'type'    => 'textarea',
				'default' => __( 'Approve a recurring payment with your PayPal account.', 'submex' ),
			),
		);
	}

	/**
	 * Only offer this for carts that actually contain a subscription, and only when the
	 * credentials are present — an unconfigured gateway at checkout is a dead end.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled || ! $this->client->is_configured() ) {
			return false;
		}

		// Deliberately the cart, not is_checkout(): the block checkout asks over the Store
		// API, where is_checkout() is false, and the answer there has to be the same one
		// the classic checkout gets. No cart at all means the admin order screen, where
		// the method must not be hidden.
		$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;

		if ( ! $cart || $cart->is_empty() ) {
			return parent::is_available();
		}

		return Subscription_Product::cart_has_subscription() && parent::is_available();
	}

	/**
	 * Create the PayPal subscription and send the customer there to approve it.
	 *
	 * @param int $order_id
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		// A second agreement would bill alongside this one; the Store API pays orders without the pay page's gateway list.
		if ( $order instanceof \WC_Order && 'submex_renewal' === $order->get_created_via() ) {
			return $this->abort( $order, __( 'A renewal cannot be paid with PayPal.', 'submex' ) );
		}

		if ( ! $order instanceof \WC_Order ) {
			return $this->abort( null, __( 'This order does not contain a subscription.', 'submex' ) );
		}

		$item    = $this->subscription_item_in( $order );
		$product = $item ? $item->get_product() : null;
		$terms   = $item && $product ? Line_Terms::for_order_item( $item, $product ) : null;

		if ( ! $product || ! $terms ) {
			return $this->abort( $order, __( 'This order does not contain a subscription.', 'submex' ) );
		}

		$plan = $this->plans->plan_for( $product, $terms );
		if ( ! $plan['ok'] ) {
			return $this->abort( $order, $plan['error'] );
		}

		$response = $this->client->post(
			'/v1/billing/subscriptions',
			array(
				'plan_id'             => $plan['plan_id'],
				'custom_id'           => (string) $order->get_id(),
				'subscriber'          => array(
					'name'          => array(
						'given_name' => $order->get_billing_first_name(),
						'surname'    => $order->get_billing_last_name(),
					),
					'email_address' => $order->get_billing_email(),
				),
				'application_context' => array(
					'brand_name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'user_action'         => 'SUBSCRIBE_NOW',
					'shipping_preference' => 'NO_SHIPPING',
					'return_url'          => $this->return_url( $order, 'approved' ),
					'cancel_url'          => $this->return_url( $order, 'cancelled' ),
				),
			)
		);

		if ( ! $response['ok'] || empty( $response['body']['id'] ) ) {
			return $this->abort( $order, $response['error'] );
		}

		$order->update_meta_data( PayPal_Gateway::META_SUBSCRIPTION_ID, $response['body']['id'] );
		$order->update_meta_data( PayPal_Gateway::META_PLAN_ID, $plan['plan_id'] );
		$order->save();

		// The webhook handler looks the subscription up by this id, so it has to reach the
		// subscription record and not just the order. Without this every PayPal renewal
		// arrives, finds nothing, and is silently dropped.
		$this->attach_mandate( $order );

		$approve = $this->approval_link( $response['body'] );

		if ( '' === $approve ) {
			return $this->abort( $order, __( 'PayPal did not return an approval link.', 'submex' ) );
		}

		return array(
			'result'   => 'success',
			'redirect' => $approve,
		);
	}

	/**
	 * PayPal sends the customer back here after approving or cancelling.
	 */
	public function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- PayPal controls this redirect; the token below is the check.
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$result   = sanitize_key( $_GET['submex_result'] ?? '' );
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! hash_equals( $this->return_token( $order ), $token ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		if ( 'approved' !== $result ) {
			$order->update_status( 'cancelled', __( 'Customer cancelled the PayPal approval.', 'submex' ) );
			wc_add_notice( __( 'You cancelled the PayPal approval, so no subscription was started.', 'submex' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$this->activate_from_paypal( $order );

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Confirm with PayPal rather than trusting the redirect, then complete the order.
	 */
	private function activate_from_paypal( \WC_Order $order ): void {
		$paypal_id = (string) $order->get_meta( PayPal_Gateway::META_SUBSCRIPTION_ID );

		if ( '' === $paypal_id ) {
			return;
		}

		$response = $this->client->get( '/v1/billing/subscriptions/' . rawurlencode( $paypal_id ) );
		$status   = (string) ( $response['body']['status'] ?? '' );

		if ( ! $response['ok'] || ! in_array( $status, array( 'ACTIVE', 'APPROVED' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Waiting for PayPal to activate the subscription.', 'submex' ) );
			return;
		}

		$order->payment_complete( $paypal_id );
	}

	/**
	 * Hand the PayPal reference to the subscription created earlier in checkout.
	 */
	private function attach_mandate( \WC_Order $order ): void {
		$subscription = $this->subscription_for( $order );

		if ( ! $subscription ) {
			return;
		}

		$adapter = new PayPal_Gateway( $this->client );
		$result  = $adapter->create_mandate( $subscription, $order );

		if ( ! $result->is_success() ) {
			$order->add_order_note( sprintf( 'PayPal: could not link the subscription — %s', $result->describe() ) );
		}
	}

	private function subscription_for( \WC_Order $order ): ?\Submex\Domain\Subscription {
		$id = (int) $order->get_meta( '_submex_subscription_id' );

		if ( ! $id ) {
			return null;
		}

		$subscription = wc_get_order( $id );

		return $subscription instanceof \Submex\Domain\Subscription ? $subscription : null;
	}

	private function subscription_item_in( \WC_Order $order ): ?\WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Subscription_Product::order_item_is_subscription( $item, $order ) ) {
				return $item;
			}
		}

		return null;
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

	/**
	 * Ties the return URL to this specific order so it cannot be replayed for another.
	 */
	private function return_token( \WC_Order $order ): string {
		return hash_hmac( 'sha256', 'submex_paypal_return_' . $order->get_id() . $order->get_order_key(), wp_salt( 'auth' ) );
	}

	private function approval_link( array $body ): string {
		foreach ( $body['links'] ?? array() as $link ) {
			if ( 'approve' === ( $link['rel'] ?? '' ) ) {
				return (string) $link['href'];
			}
		}

		return '';
	}

	private function abort( ?\WC_Order $order, string $message ): array {
		if ( $order ) {
			$order->add_order_note( sprintf( 'PayPal: %s', $message ) );
		}

		wc_add_notice( __( 'We could not start your PayPal subscription. Please try again or use another payment method.', 'submex' ), 'error' );

		return array( 'result' => 'failure' );
	}
}
