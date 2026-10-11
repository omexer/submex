<?php

namespace Submex\Checkout;

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Gateway_Model;
use Submex\Gateways\Gateway_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a renewal order's pay page offers, since paying it restarts the subscription (Renewal_Processor::settle_paid_order).
 */
final class Renewal_Order_Pay {

	public function __construct( private readonly Gateway_Registry $gateways ) {}

	public function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'gateways_for_renewal' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'payable_statuses' ), 10, 2 );
	}

	/**
	 * A plan-billed gateway such as PayPal would open a second agreement that bills alongside this one.
	 *
	 * @param array<string, \WC_Payment_Gateway>|mixed $available
	 * @return array<string, \WC_Payment_Gateway>|mixed
	 */
	public function gateways_for_renewal( $available ) {
		global $wp;

		$order_id = is_array( $available ) && isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || 'submex_renewal' !== $order->get_created_via() ) {
			return $available;
		}

		$subscription = self::subscription_for( $order );
		$method       = $subscription ? (string) $subscription->get_payment_method() : '';

		if ( ! $subscription || ! isset( $available[ $method ] ) || Gateway_Model::GatewayManaged === $this->gateways->for_subscription( $subscription )->model() ) {
			return array();
		}

		return array( $method => $available[ $method ] );
	}

	/**
	 * A renewal of a subscription that has ended cannot restart it, so it is not offered for payment.
	 *
	 * @param string[]        $statuses
	 * @param \WC_Order|mixed $order
	 * @return string[]
	 */
	public function payable_statuses( $statuses, $order = null ) {
		if ( ! $order instanceof \WC_Order || 'submex_renewal' !== $order->get_created_via() ) {
			return $statuses;
		}

		$subscription = self::subscription_for( $order );
		$status       = $subscription ? $subscription->get_status_enum() : null;

		if ( ! $status || ! ( Subscription_Status::OnHold === $status || $status->is_billable() ) ) {
			return array();
		}

		// Held for the customer to act (an invoice, a bank's confirmation), which they do on this page.
		if ( '' !== (string) $order->get_meta( '_submex_action_url' ) ) {
			$statuses   = (array) $statuses;
			$statuses[] = 'on-hold';
		}

		return $statuses;
	}

	private static function subscription_for( \WC_Order $order ): ?Subscription {
		$subscription = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );

		return $subscription instanceof Subscription ? $subscription : null;
	}
}
