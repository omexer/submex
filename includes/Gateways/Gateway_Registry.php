<?php

namespace Submex\Gateways;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of recurring-capable gateways.
 */
class Gateway_Registry {

	/** @var array<string, Recurring_Gateway> */
	private array $gateways = array();

	public function register(): void {
		add_action( 'init', array( $this, 'load_gateways' ), 20 );
	}

	public function load_gateways(): void {
		$this->add( new Manual_Gateway() );

		// Never present on a production site unless the constant is deliberately defined.
		if ( defined( 'SUBMEX_ENABLE_TEST_GATEWAY' ) ) {
			$this->add( new Test_Gateway() );
		}

		/**
		 * Register additional recurring gateways.
		 *
		 * @param Gateway_Registry $registry
		 */
		do_action( 'submex_register_gateways', $this );
	}

	public function add( Recurring_Gateway $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	public function get( string $id ): ?Recurring_Gateway {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * @return array<string, Recurring_Gateway>
	 */
	public function all(): array {
		/**
		 * Filter the gateways Submex will consider for recurring payments.
		 *
		 * @param array<string, Recurring_Gateway> $gateways
		 */
		return apply_filters( 'submex_available_gateways', $this->gateways );
	}

	/**
	 * Resolve the gateway backing a subscription, falling back to manual invoicing so a
	 * missing or deactivated gateway never silently stops renewals.
	 */
	public function for_subscription( Subscription $subscription ): Recurring_Gateway {
		$gateway = $this->get( (string) $subscription->get_payment_method() );

		/**
		 * The gateway that renews this subscription.
		 *
		 * A subscription carries the WooCommerce payment method it was bought with, which
		 * is rarely the id of the thing able to charge it again: an extension renewing
		 * against another plugin's stored mandate is registered under its own id and
		 * claims the method here.
		 *
		 * @param Recurring_Gateway|null $gateway      Null when nothing matched by id.
		 * @param Subscription           $subscription
		 * @param Gateway_Registry       $registry
		 */
		$gateway = apply_filters( 'submex_gateway_for_subscription', $gateway, $subscription, $this );

		return $gateway instanceof Recurring_Gateway ? $gateway : $this->get( Manual_Gateway::ID );
	}
}
