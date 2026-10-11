<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Merchant_New_Subscription extends Subscription_Email {

	public function __construct() {
		$this->id             = 'submex_merchant_new_subscription';
		$this->title          = __( 'New subscription (merchant)', 'submex' );
		$this->description    = __( 'Sent to the store when a customer starts a subscription.', 'submex' );
		$this->customer_email = false;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'New subscription started', 'submex' );
	}

	public function get_default_heading(): string {
		return __( 'New subscription', 'submex' );
	}

	public function trigger( Subscription $subscription ): void {
		if ( $this->prepare( $subscription ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		return sprintf(
			/* translators: 1: customer name, 2: recurring amount */
			__( '%1$s started a subscription worth %2$s.', 'submex' ),
			trim( $this->subscription->get_billing_first_name() . ' ' . $this->subscription->get_billing_last_name() ) ?: $this->subscription->get_billing_email(),
			$this->amount( $this->subscription->get_total() )
		);
	}

	protected function facts(): array {
		return array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Customer', 'submex' )     => (string) $this->subscription->get_billing_email(),
			__( 'Next payment', 'submex' ) => $this->date( $this->subscription->get_next_payment() ),
		);
	}
}
