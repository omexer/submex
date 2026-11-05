<?php

namespace Submex\Checkout;

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Status_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says what was signed up for on the thank-you page and in the customer's order email.
 */
class Order_Summary {

	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'thank_you' ), 5 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email' ), 10, 3 );
	}

	/**
	 * @param int|string $order_id
	 */
	public function thank_you( $order_id ): void {
		$subscription = self::for_order( wc_get_order( $order_id ) );

		if ( ! $subscription ) {
			return;
		}

		printf(
			'<section class="submex-order-summary"><h2>%1$s</h2><p>%2$s</p><p><a class="wp-element-button button" href="%3$s">%4$s</a></p></section>',
			esc_html__( 'Your subscription', 'submex' ),
			esc_html( self::sentence( $subscription ) ),
			esc_url( self::manage_url( $subscription ) ),
			esc_html__( 'Manage your subscription', 'submex' )
		);
	}

	/**
	 * @param \WC_Order $order
	 * @param bool      $sent_to_admin
	 * @param bool      $plain_text
	 */
	public function email( $order, $sent_to_admin = false, $plain_text = false ): void {
		$subscription = $sent_to_admin ? null : self::for_order( $order );

		if ( ! $subscription ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( self::sentence( $subscription ) ) . "\n" . esc_url_raw( self::manage_url( $subscription ) ) . "\n";
			return;
		}

		printf(
			'<h2>%1$s</h2><p>%2$s <a href="%3$s">%4$s</a></p>',
			esc_html__( 'Your subscription', 'submex' ),
			esc_html( self::sentence( $subscription ) ),
			esc_url( self::manage_url( $subscription ) ),
			esc_html__( 'Manage your subscription', 'submex' )
		);
	}

	/**
	 * The subscription this order started. A renewal order is about a payment, and says so itself.
	 *
	 * @param mixed $order
	 */
	private static function for_order( $order ): ?Subscription {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$subscription = wc_get_order( (int) $order->get_meta( '_submex_subscription_id' ) );

		return $subscription instanceof Subscription && $subscription->get_parent_order_id() === $order->get_id() ? $subscription : null;
	}

	private static function sentence( Subscription $subscription ): string {
		$price = wp_strip_all_tags( Status_Presenter::price( $subscription ) );
		$next  = $subscription->get_next_payment();

		// Unpaid, so its dates only start counting once it is paid.
		if ( Subscription_Status::Pending === $subscription->get_status_enum() || empty( $next ) ) {
			/* translators: %s: recurring price such as "$25.00 every month" */
			return sprintf( __( '%s. It starts once this order is paid.', 'submex' ), $price );
		}

		return sprintf(
			/* translators: 1: recurring price such as "$25.00 every month", 2: date */
			__( '%1$s. Your next payment is on %2$s.', 'submex' ),
			$price,
			date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $next . ' UTC' ) )
		);
	}

	private static function manage_url( Subscription $subscription ): string {
		return wc_get_endpoint_url( 'subscriptions', (string) $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
	}
}
