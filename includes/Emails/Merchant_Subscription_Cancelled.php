<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;
use Submex\Lifecycle\Cancellation_Survey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Merchant_Subscription_Cancelled extends Subscription_Email {

	public function __construct() {
		$this->id             = 'submex_merchant_subscription_cancelled';
		$this->title          = __( 'Subscription cancelled (merchant)', 'submex' );
		$this->description    = __( 'Sent to the store when a subscription is cancelled, with the reason the customer gave.', 'submex' );
		$this->customer_email = false;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'A subscription was cancelled', 'submex' );
	}

	public function get_default_heading(): string {
		return __( 'Subscription cancelled', 'submex' );
	}

	public function trigger( Subscription $subscription ): void {
		if ( $this->prepare( $subscription ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		return sprintf(
			/* translators: 1: customer, 2: recurring amount */
			__( '%1$s cancelled a subscription worth %2$s.', 'submex' ),
			trim( $this->subscription->get_billing_first_name() . ' ' . $this->subscription->get_billing_last_name() ) ?: $this->subscription->get_billing_email(),
			$this->amount( $this->subscription->get_total() )
		);
	}

	protected function facts(): array {
		$facts = array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Customer', 'submex' )     => (string) $this->subscription->get_billing_email(),
		);

		// The survey stores a key; only it knows the label, including any a filter added.
		$survey = \Submex\Plugin::instance()->get( 'survey' );
		$reason = $survey instanceof Cancellation_Survey ? $survey->reason_for( $this->subscription ) : '';

		if ( '' !== $reason ) {
			$facts[ __( 'Reason given', 'submex' ) ] = $reason;
		}

		$detail = (string) $this->subscription->get_meta( '_submex_cancel_detail' );

		if ( '' !== $detail ) {
			$facts[ __( 'They said', 'submex' ) ] = $detail;
		}

		$end = $this->subscription->get_end_date() ?: $this->subscription->get_next_payment();

		if ( ! empty( $end ) ) {
			$facts[ __( 'Access ends', 'submex' ) ] = $this->date( $end );
		}

		return $facts;
	}
}
