<?php

namespace Submex\Admin;

use Submex\Billing\Renewal_Scheduler;
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

	public const ACTION = 'submex_gateway_key_check';

	/** @var array<string, bool> Gateways whose settings changed in this request. */
	private array $changed = array();

	public function register(): void {
		add_action( 'updated_option', array( $this, 'note_change' ), 10, 1 );
		add_action( 'added_option', array( $this, 'note_change' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'queue' ) );
		add_action( self::ACTION, array( $this, 'check' ) );
	}

	/**
	 * @return array<string, array{title: string, section: string, options: string[], check: callable(): ?string}>
	 */
	private function checks(): array {
		$checks = array(
			PayPal_Checkout_Gateway::ID => array(
				'title'   => 'PayPal',
				'section' => 'paypal',
				'options' => array( 'submex_paypal_enabled', 'submex_paypal_live', 'submex_paypal_sandbox_client_id', 'submex_paypal_sandbox_secret', 'submex_paypal_live_client_id', 'submex_paypal_live_secret', 'submex_paypal_client_id', 'submex_paypal_secret' ),
				'check'   => static function (): ?string {
					$client = PayPal_Client::from_settings();

					if ( ! $client->is_enabled() ) {
						return null;
					}

					$response = $client->get( '/v1/notifications/webhooks-event-types' );

					return in_array( $response['status'], array( 401, 403 ), true ) ? $response['error'] : null;
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

					// What checkout itself needs, so a restricted key without Balance access is not called broken.
					$response = $client->get( '/v1/checkout/sessions?limit=1' );

					return in_array( $response['status'], array( 401, 403 ), true ) ? $response['error'] : null;
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
	 * After the request, so one save of several keys asks once, with all of them in place; in the background, so saving never waits on a provider.
	 */
	public function queue(): void {
		foreach ( array_keys( $this->changed ) as $id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::ACTION, array( 'gateway_id' => $id ), Renewal_Scheduler::GROUP );
			} else {
				$this->check( $id );
			}
		}

		$this->changed = array();
	}

	/**
	 * @param string $gateway_id
	 */
	public function check( $gateway_id ): void {
		$check = $this->checks()[ (string) $gateway_id ] ?? null;

		if ( ! $check ) {
			return;
		}

		$problem = $check['check']();

		if ( null === $problem ) {
			Gateway_Refusals::clear( (string) $gateway_id );
		} else {
			Gateway_Refusals::record( (string) $gateway_id, $check['title'], $problem, $check['section'] );
		}
	}
}
