<?php

namespace Submex\Admin;

use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe and PayPal, whose checkout is Submex's own: their settings shown on their WooCommerce
 * payment pages, and a card each in the Payment Gateways group of the Integrations screen.
 */
final class Own_Gateways {

	/** Ahead of the alphabetical groups, beside Submex Pro's gateways. */
	public const CATEGORY_ORDER = 1;

	public function __construct(
		private readonly bool $owns_stripe,
		private readonly \Closure $stripe_ready,
		private readonly \Closure $paypal_ready
	) {}

	public function register(): void {
		add_filter( Gateway_Panel::FILTER, array( $this, 'panels' ) );
		add_filter( 'submex_admin_integrations', array( $this, 'describe' ) );
	}

	/**
	 * @return array<string, array{title: string, gateway: string, ready: \Closure}>
	 */
	private function gateways(): array {
		$gateways = array();

		if ( $this->owns_stripe ) {
			$gateways['stripe'] = array(
				'title'   => __( 'Stripe', 'submex' ),
				'gateway' => Stripe_Checkout_Gateway::ID,
				'ready'   => $this->stripe_ready,
			);
		}

		$gateways['paypal'] = array(
			'title'   => __( 'PayPal', 'submex' ),
			'gateway' => PayPal_Checkout_Gateway::ID,
			'ready'   => $this->paypal_ready,
		);

		return $gateways;
	}

	/**
	 * @param array $panels
	 */
	public function panels( $panels ): array {
		$panels = (array) $panels;

		foreach ( $this->gateways() as $section => $gateway ) {
			$panels[ $section ] = array( 'gateways' => array( $gateway['gateway'] ) );
		}

		return $panels;
	}

	/**
	 * @param array $integrations
	 */
	public function describe( $integrations ): array {
		$integrations = (array) $integrations;

		foreach ( $this->gateways() as $section => $gateway ) {
			$integrations[] = array(
				'key'            => 'gateway-' . $gateway['gateway'],
				'title'          => $gateway['title'],
				'requires'       => __( 'Submex', 'submex' ),
				'slug'           => '',
				'url'            => '',
				// Built in, so nothing to install: connected once renewals are on and its keys are in.
				'active'         => (bool) ( $gateway['ready'] )(),
				'category'       => __( 'Payment Gateways', 'submex' ),
				'category_order' => self::CATEGORY_ORDER,
				'description'    => sprintf(
					/* translators: %s: Stripe or PayPal */
					__( 'Built into Submex: customers pay with %s at checkout, and Submex renews their subscriptions.', 'submex' ),
					$gateway['title']
				),
				'icon'           => '',
				'configure_url'  => Settings_Page::section_url( $section ),
			);
		}

		return $integrations;
	}
}
