<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A subscription that stops on its own — an instalment plan paid off, a fixed term
 * reached — never involves the customer, so nothing otherwise tells the store it ended.
 */
class Merchant_Subscription_Ended extends Subscription_Email {

	private string $reason = '';

	public function __construct() {
		$this->id             = 'submex_merchant_subscription_ended';
		$this->title          = __( 'Subscription ended (merchant)', 'submex' );
		$this->description    = __( 'Sent to the store when a subscription reaches its end and stops billing by itself.', 'submex' );
		$this->customer_email = false;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'A subscription has ended', 'submex' );
	}

	public function get_default_heading(): string {
		return __( 'Subscription ended', 'submex' );
	}

	public function trigger( Subscription $subscription, string $reason = '' ): void {
		$this->reason = $reason;

		if ( $this->prepare( $subscription ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		return sprintf(
			/* translators: 1: customer, 2: recurring amount */
			__( "%1\$s's subscription worth %2\$s has run its course and will not be charged again.", 'submex' ),
			trim( $this->subscription->get_billing_first_name() . ' ' . $this->subscription->get_billing_last_name() ) ?: $this->subscription->get_billing_email(),
			$this->amount( $this->subscription->get_total() )
		);
	}

	protected function facts(): array {
		$facts = array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Customer', 'submex' )     => (string) $this->subscription->get_billing_email(),
		);

		if ( '' !== $this->reason ) {
			$facts[ __( 'Why it ended', 'submex' ) ] = $this->reason;
		}

		return $facts;
	}

	protected function outro(): string {
		return __( 'This customer is a good candidate to invite back.', 'submex' );
	}
}
