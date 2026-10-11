<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subscription_Started extends Subscription_Email {

	public function __construct() {
		$this->id             = 'submex_subscription_started';
		$this->title          = __( 'Subscription started', 'submex' );
		$this->description    = __( 'Sent to the customer when a subscription becomes active.', 'submex' );
		$this->customer_email = true;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your subscription is active', 'submex' );
	}

	public function get_default_heading(): string {
		return __( 'Your subscription is active', 'submex' );
	}

	public function trigger( Subscription $subscription ): void {
		if ( $this->prepare( $subscription ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		return __( 'Thanks for subscribing. Everything is set up and running.', 'submex' );
	}

	protected function facts(): array {
		$facts = array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Amount', 'submex' )       => $this->amount( $this->subscription->get_total() ),
		);

		if ( $this->subscription->get_trial_end() ) {
			$facts[ __( 'Free trial ends', 'submex' ) ] = $this->date( $this->subscription->get_trial_end() );
		}

		$facts[ __( 'Next payment', 'submex' ) ] = $this->date( $this->subscription->get_next_payment() );

		return $facts;
	}

	protected function call_to_action(): ?array {
		return array(
			'label' => __( 'Manage your subscription', 'submex' ),
			'url'   => $this->manage_url(),
		);
	}

	protected function outro(): string {
		return __( 'You can cancel at any time from your account.', 'submex' );
	}
}
