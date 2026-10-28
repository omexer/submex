<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Say why a switched-on gateway is not being offered.
 *
 * A gateway with no credentials hides itself at checkout, which is right - a customer must
 * never pick a payment method that cannot work. But the merchant is left reading "Active"
 * on the payments screen while the checkout says there are no payment methods at all, with
 * nothing anywhere connecting the two. This is that connection.
 */
class Gateway_Notice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Notices::in_context() ) {
			return;
		}

		$this->notice(
			'submex_paypal_credentials',
			__( 'Submex is not offering a payment method at checkout.', 'submex' ),
			$this->paypal_credentials(),
			// A different field missing is a different problem, so a dismissed notice comes back for it.
			Notice_Dismissals::problem_key( array_keys( self::missing_credentials() ) ),
			'paypal'
		);

		$this->notice(
			'submex_stripe_credentials',
			__( 'Submex is not offering a payment method at checkout.', 'submex' ),
			$this->stripe_credentials(),
			// The live and test keys are different fields, so fixing one and emptying the other is a new problem.
			'yes' === get_option( 'submex_stripe_live', 'no' ) ? 'live' : 'test',
			'stripe'
		);

		// Separate on purpose: this one does not stop a customer paying, so saying it
		// alongside "not offered at checkout" would send the merchant looking for the
		// wrong thing entirely.
		$this->notice(
			'submex_paypal_webhook',
			__( 'PayPal renewals will not be recorded.', 'submex' ),
			$this->paypal_webhook(),
			$this->paypal_webhook() ? 'webhook_id' : '',
			'paypal'
		);
	}

	/**
	 * @param string[] $lines
	 */
	private function notice( string $id, string $heading, array $lines, string $problem, string $section ): void {
		// Asked even with nothing to say, so a fixed problem that comes back shows again.
		if ( ! Notice_Dismissals::shows_problem( $id, $lines ? $problem : '' ) ) {
			return;
		}

		echo '<div class="' . esc_attr( Notices::important( 'warning' ) ) . '"><p><strong>' . esc_html( $heading ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';

		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}

		printf(
			'</ul><p><a class="button" href="%s">%s</a> <a class="button-link" href="%s">%s</a></p></div>',
			esc_url( Settings_Page::section_url( $section ) ),
			esc_html__( 'Finish setting it up', 'submex' ),
			esc_url( Notice_Dismissals::problem_url( $id, $problem ) ),
			esc_html__( 'Dismiss', 'submex' )
		);
	}

	/**
	 * @return string[]
	 */
	private function stripe_credentials(): array {
		if ( ! \Submex\Plugin::owns_stripe() || 'yes' !== get_option( 'submex_stripe_enabled', 'no' ) ) {
			return array();
		}

		$live = 'yes' === get_option( 'submex_stripe_live', 'no' );

		if ( '' !== (string) get_option( $live ? 'submex_stripe_secret' : 'submex_stripe_test_secret', '' ) ) {
			return array();
		}

		// Naming the environment matters: the usual cause is the key in one box and the environment set to the other.
		return array(
			$live
				? __( 'Stripe is switched on and set to Live, but its live secret key is empty. Until it has one, Stripe is not offered at checkout.', 'submex' )
				: __( 'Stripe is switched on and set to Test, but its test secret key is empty. Until it has one, Stripe is not offered at checkout.', 'submex' ),
		);
	}

	/**
	 * Credentials decide whether PayPal appears at checkout at all.
	 *
	 * @return string[]
	 */
	private function paypal_credentials(): array {
		$missing = self::missing_credentials();

		if ( ! $missing ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: the PayPal fields that are empty. */
				__( 'PayPal is switched on but has no %s. Until it does, PayPal is not offered at checkout.', 'submex' ),
				implode( __( ' and ', 'submex' ), $missing )
			),
		);
	}

	/**
	 * @return array<string, string> Option name => what to call it.
	 */
	private static function missing_credentials(): array {
		if ( 'yes' !== get_option( 'submex_paypal_enabled', 'no' ) ) {
			return array();
		}

		$missing = array();

		foreach ( array(
			'submex_paypal_client_id' => __( 'client ID', 'submex' ),
			'submex_paypal_secret'    => __( 'secret', 'submex' ),
		) as $option => $label ) {
			if ( '' === (string) get_option( $option, '' ) ) {
				$missing[ $option ] = $label;
			}
		}

		return $missing;
	}

	/**
	 * The webhook ID does not gate the checkout: PayPal bills on its own schedule and
	 * tells Submex by webhook, and an unverifiable webhook is rejected, so every renewal
	 * goes unrecorded while the customer is charged.
	 *
	 * @return string[]
	 */
	private function paypal_webhook(): array {
		if ( 'yes' !== get_option( 'submex_paypal_enabled', 'no' ) || '' !== (string) get_option( 'submex_paypal_webhook_id', '' ) ) {
			return array();
		}

		// Nobody can pay at all without credentials, so promising charges would contradict
		// the notice above it.
		if ( $this->paypal_credentials() ) {
			return array();
		}

		return array(
			__( 'PayPal is switched on but has no webhook ID. Customers can pay, and PayPal will keep charging them, but Submex cannot verify what PayPal sends back — so renewals are rejected and never show against the subscription.', 'submex' ),
		);
	}
}
