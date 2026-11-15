<?php

namespace Submex\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The gateway that takes the money becomes the order's payment method, whatever the customer picked last in another tab.
 */
final class Payment_Claim {

	public static function claim( \WC_Order $order, string $gateway_id ): void {
		if ( '' === $gateway_id || $gateway_id === $order->get_payment_method() ) {
			return;
		}

		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ $gateway_id ] ?? null;
		$title    = $gateway instanceof \WC_Payment_Gateway ? wp_strip_all_tags( (string) $gateway->get_title() ) : $gateway_id;
		$chosen   = $order->get_payment_method_title() ?: $order->get_payment_method();

		$order->set_payment_method( $gateway_id );
		$order->set_payment_method_title( $title );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment method that took the payment, 2: payment method chosen last */
				__( 'Paid with %1$s, although %2$s was the method chosen last.', 'submex' ),
				$title,
				$chosen
			)
		);
		$order->save();
	}
}
