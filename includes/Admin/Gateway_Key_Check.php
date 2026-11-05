<?php

namespace Submex\Admin;

use Submex\Gateways\PayPal\PayPal_Checkout_Gateway;
use Submex\Gateways\PayPal\PayPal_Client;
use Submex\Gateways\Stripe\Stripe_Checkout_Gateway;
use Submex\Gateways\Stripe\Stripe_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks a provider whether its keys work as soon as they are saved, so a merchant hears it before a customer does.
 */
final class Gateway_Key_Check {

	/** @var array<string, bool> Gateways whose settings changed in this request. */
	private array $changed = array();

	public function register(): void {
		add_action( 'updated_option', array( $this, 'note_change' ), 10, 1 );
		add_action( 'added_option', array( $this, 'note_change' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'run' ) );
	}

	/**
	 * @return array<string, array{title: string, section: string, options: string[], check: callable(): ?string}>
	 */
	private function checks(): array {
		$checks = array(
			PayPal_Checkout_Gateway::ID => array(
				'title'   => 'PayPal',
				'section' => 'paypal',
				'options' => array( 'submex_paypal_enabled', 'submex_paypal_live', 'submex_paypal_client_id', 'submex_paypal_secret' ),
				'check'   => static function (): ?string {
					$client = PayPal_Client::from_settings();

					if ( ! $client->is_enabled() ) {
						return null;
					}

					$response = $client->get( '/v1/notifications/webhooks-event-types' );

					return $response['ok'] ? null : $response['error'];
				},
			),
		);

		if ( \Submex\Plugin::owns_stripe() ) {
			$checks[ Stripe_Checkout_Gateway::ID ] = array(
				'title'   => 'Stripe',
				'section' => 'stripe',
				'options' => array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'submex_stripe_secret' ),
				'check'   => static function (): ?string {
					$client = Stripe_Client::from_settings();

					if ( ! $client->is_enabled() ) {
						return null;
					}

					$response = $client->get( '/v1/balance' );

					return $response['ok'] || 0 === $response['status'] ? null : $response['error'];
				},
			);
		}

		/**
		 * Providers whose keys are checked when saved.
		 *
		 * @param array $checks gateway id => title, settings section, the options holding its keys, and a callable returning why they do not work, or null.
		 */
		return (array) apply_filters( 'submex_gateway_key_checks', $checks );
	}

	/**
	 * @param string $option
	 */
	public function note_change( $option ): void {
		if ( ! str_starts_with( (string) $option, 'submex_' ) ) {
			return;
		}

		foreach ( $this->checks() as $id => $check ) {
			if ( in_array( $option, $check['options'], true ) ) {
				$this->changed[ $id ] = true;
			}
		}
	}

	/**
	 * After the request, so one save of several keys asks once, with all of them in place.
	 */
	public function run(): void {
		foreach ( array_intersect_key( $this->checks(), $this->changed ) as $id => $check ) {
			$problem = $check['check']();

			if ( null === $problem ) {
				Gateway_Refusals::clear( $id );
			} else {
				Gateway_Refusals::record( $id, $check['title'], $problem, $check['section'] );
			}
		}

		$this->changed = array();
	}
}
