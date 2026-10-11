<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns the customer before the card is charged.
 *
 * A charge nobody expected is the most common cause of a chargeback, and of a
 * cancellation that would not otherwise have happened.
 */
class Renewal_Reminder extends Subscription_Email {

	public function __construct() {
		$this->id             = 'submex_renewal_reminder';
		$this->title          = __( 'Upcoming renewal', 'submex' );
		$this->description    = __( 'Sent to the customer before a subscription is charged. How far ahead is set under Submex → Settings → Email Notifications.', 'submex' );
		$this->customer_email = true;

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your subscription renews soon', 'submex' );
	}

	public function get_default_heading(): string {
		return __( 'Your subscription renews soon', 'submex' );
	}

	public function trigger( Subscription $subscription ): void {
		if ( $this->prepare( $subscription ) ) {
			$this->send_now();
		}
	}

	protected function intro(): string {
		$trialling = 'submex-trialling' === $this->subscription->get_status();

		return sprintf(
			$trialling
				/* translators: 1: amount, 2: date */
				? __( 'Your free trial ends on %2$s, and your first payment of %1$s will be taken that day.', 'submex' )
				/* translators: 1: amount, 2: date */
				: __( "We'll charge %1\$s on %2\$s to renew your subscription.", 'submex' ),
			$this->amount( $this->subscription->get_total() ),
			$this->date( $this->subscription->get_next_payment() )
		);
	}

	protected function facts(): array {
		return array(
			__( 'Subscription', 'submex' ) => '#' . $this->subscription->get_id(),
			__( 'Amount', 'submex' )       => $this->amount( $this->subscription->get_total() ),
			__( 'Payment date', 'submex' ) => $this->date( $this->subscription->get_next_payment() ),
			__( 'Paid with', 'submex' )    => (string) $this->subscription->get_payment_method_title(),
		);
	}

	protected function call_to_action(): ?array {
		$url = $this->manage_url();

		return $url ? array(
			'label' => __( 'Manage your subscription', 'submex' ),
			'url'   => $url,
		) : null;
	}

	protected function outro(): string {
		return __( 'Nothing to do if you want it to continue. You can change or cancel it any time before that date.', 'submex' );
	}
}
