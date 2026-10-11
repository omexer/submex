<?php

namespace Submex\Checkout;

use Submex\Admin\Notice_Dismissals;
use Submex\Admin\Notices;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buying a subscription without logging in first.
 *
 * A subscription has to belong to someone: it is managed from My Account, it renews
 * against a stored mandate, and the customer needs a way back to cancel it. So a guest
 * who buys one gets an account made for them at checkout, before the subscription is
 * created - not a guest order that nobody can ever manage.
 *
 * Runs ahead of Subscription_Factory on both checkout paths so the order already has an
 * owner by the time the subscription is built from it.
 */
class Guest_Checkout {

	private const OPTION = 'submex_guest_checkout';

	public const ALLOW   = 'create_account';
	public const REQUIRE = 'require_login';

	private const NOTICE_UNREACHABLE = 'submex_guest_checkout_unreachable';

	public function register(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api' ), 10, 1 );

		// Priority 5 and 9: both must land before Subscription_Factory at 10.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'assign_owner' ), 5, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'assign_owner' ), 9, 1 );

		add_action( 'admin_notices', array( $this, 'warn_if_unreachable' ) );
	}

	public static function mode(): string {
		$mode = (string) get_option( self::OPTION, self::ALLOW );

		return self::REQUIRE === $mode ? self::REQUIRE : self::ALLOW;
	}

	public function validate_classic(): void {
		$error = $this->blocking_error( $this->posted_email() );

		if ( $error ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * @param \WP_Error $errors
	 */
	public function validate_store_api( $errors ) {
		$error = $this->blocking_error( $this->customer_email() );

		if ( $error && $errors instanceof \WP_Error ) {
			$errors->add( 'submex_login_required', $error );
		}

		return $errors;
	}

	/**
	 * Give the order an owner, creating the account if there is nobody to give it to.
	 *
	 * @param \WC_Order $order
	 */
	public function assign_owner( $order ): void {
		if ( ! $order instanceof \WC_Order || $order->get_customer_id() || ! Subscription_Product::cart_has_subscription() ) {
			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );

		if ( ! is_email( $email ) ) {
			return;
		}

		$user_id = $this->create_account( $email, $order );

		if ( $user_id ) {
			$order->set_customer_id( $user_id );
		}
	}

	/**
	 * Warn when the store's own settings make a subscription impossible to buy.
	 */
	public function warn_if_unreachable(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Notices::in_context() ) {
			return;
		}

		$unreachable = self::REQUIRE === self::mode()
			&& 'yes' !== get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' )
			&& 'yes' !== get_option( 'woocommerce_enable_checkout_login_reminder', 'no' );

		// Asked even when all is well, so a fixed setting that breaks again shows again.
		if ( ! Notice_Dismissals::shows_problem( self::NOTICE_UNREACHABLE, $unreachable ? 'unreachable' : '' ) ) {
			return;
		}

		printf(
			'<div class="%s"><p>%s</p><p><a class="button-link" href="%s">%s</a></p></div>',
			esc_attr( Notices::important( 'warning' ) ),
			esc_html__( 'Submex requires customers to log in before buying a subscription, but WooCommerce is not offering a login or sign-up on the checkout page. Customers will be turned away with no way forward.', 'submex' ),
			esc_url( Notice_Dismissals::problem_url( self::NOTICE_UNREACHABLE, 'unreachable' ) ),
			esc_html__( 'Dismiss', 'submex' )
		);
	}

	private function blocking_error( string $email = '' ): string {
		if ( is_user_logged_in() || ! Subscription_Product::cart_has_subscription() ) {
			return '';
		}

		if ( self::REQUIRE === self::mode() ) {
			return __( 'Please log in or create an account to buy a subscription. You will need one to manage or cancel it later.', 'submex' );
		}

		// Letting this through would create a subscription owned by nobody, which the
		// customer could never see or cancel. Better to stop here than to sell that.
		if ( '' !== $email && email_exists( $email ) ) {
			return __( 'You already have an account with this email address. Please log in to buy a subscription.', 'submex' );
		}

		return '';
	}

	private function posted_email(): string {
		// Nonce is WooCommerce's own, verified by the checkout process this runs inside.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		return is_email( $email ) ? $email : '';
	}

	private function customer_email(): string {
		$customer = function_exists( 'WC' ) && WC()->customer ? WC()->customer->get_billing_email() : '';
		$email    = sanitize_email( (string) $customer );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * An account already registered to this address is never claimed silently: the buyer
	 * has not proved they own it, and attaching the subscription would put a stranger's
	 * payment details and address inside somebody else's account.
	 */
	private function create_account( string $email, \WC_Order $order ): int {
		if ( email_exists( $email ) ) {
			return 0;
		}

		$username = wc_create_new_customer_username(
			$email,
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			)
		);

		$user_id = wc_create_new_customer(
			$email,
			$username,
			'',
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$order->add_order_note( __( 'An account was created for this customer so they can manage their subscription.', 'submex' ) );

		return (int) $user_id;
	}
}
