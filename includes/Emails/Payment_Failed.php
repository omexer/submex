<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;
use Submex\Frontend\MyAccount\Status_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A genuine decline. Never used for 3DS challenges - see Confirm_Payment.
 */
class Payment_Failed extends Subscription_Email {

	public function __construct() {
		$this->id             = 'submex_payment_failed';
		$this->title          = __( 'Payment failed', 'submex' );
		$this->description    = __( 'Sent to the customer when a renewal payment is declined.', 'submex' );
		$this->customer_email = true;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( "We couldn't process your payment", 'submex' );
	}

	public function get_default_heading(): string {
		return __( "We couldn't process your payment", 'submex' );
	}

	public function trigger( Subscription $subscription, ?\WC_Order $order = null ): void {
		if ( $this->prepare( $subscription, $order ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		$amount = $this->amount( $this->related_order ? $this->related_order->get_total() : $this->subscription->get_total() );
		$ends   = $this->subscription->in_grace() ? $this->subscription->grace_ends_at() : null;

		if ( ! $this->related_order ) {
			return sprintf(
				/* translators: 1: amount, 2: payment method, such as PayPal */
				__( "%2\$s couldn't collect %1\$s for your subscription, so it is on hold. Check the payment method in your %2\$s account; %2\$s will try again.", 'submex' ),
				$amount,
				$this->subscription->get_payment_method_title() ?: 'PayPal'
			);
		}

		$unpaid = Status_Presenter::pays_by_link( $this->subscription );

		if ( null !== $ends ) {
			return sprintf(
				$unpaid
					/* translators: 1: amount, 2: date the grace period ends */
					? __( 'Your subscription renewal of %1$s has not been paid yet. You keep access until %2$s. Pay before then to keep your subscription going.', 'submex' )
				/* translators: 1: amount, 2: date the grace period ends */
				: __( "We tried to charge %1\$s for your subscription today, but the payment didn't go through. You keep access until %2\$s. Pay before then to keep your subscription going.", 'submex' ),
				$amount,
				$this->date( gmdate( 'Y-m-d H:i:s', $ends ) )
			);
		}

		return sprintf(
			$unpaid
				/* translators: %s: amount */
				? __( 'Your subscription renewal of %s has not been paid yet. Your subscription is on hold until it is paid.', 'submex' )
			/* translators: %s: amount */
			: __( "We tried to charge %s for your subscription today, but the payment didn't go through. Your subscription is on hold until it is paid.", 'submex' ),
			$amount
		);
	}

	protected function facts(): array {
		return array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Amount due', 'submex' )   => $this->amount( $this->related_order ? $this->related_order->get_total() : $this->subscription->get_total() ),
		);
	}

	protected function call_to_action(): ?array {
		return $this->related_order
			? array(
				'label' => __( 'Pay now', 'submex' ),
				'url'   => $this->related_order->get_checkout_payment_url(),
			)
			: null;
	}

	protected function outro(): string {
		return __( 'Paying from the link above will restart your subscription straight away. If you have any questions, just reply to this email.', 'submex' );
	}
}
