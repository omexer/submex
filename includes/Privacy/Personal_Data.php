<?php

namespace Submex\Privacy;

use Submex\Data\Activity_Repository;
use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR export and erasure for subscription data.
 *
 * Erasure anonymises rather than deletes. Removing the billing record while the mandate
 * is still live at the gateway leaves charges nobody can stop or trace, so an active
 * subscription is cancelled first and only then scrubbed.
 */
class Personal_Data {

	private const GROUP = 'submex';

	public function __construct( private readonly Activity_Repository $activity ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	public function add_exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Subscriptions', 'submex' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	public function add_eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Subscriptions', 'submex' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 */
	public function export( $email, $page = 1 ): array {
		$items = array();

		foreach ( $this->subscriptions_for( $email ) as $subscription ) {
			$items[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Subscriptions', 'submex' ),
				'item_id'     => 'subscription-' . $subscription->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Subscription', 'submex' ),
						'value' => '#' . $subscription->get_id(),
					),
					array(
						'name'  => __( 'Status', 'submex' ),
						'value' => $subscription->get_status(),
					),
					array(
						'name'  => __( 'Billing', 'submex' ),
						'value' => sprintf( '%d / %s', $subscription->get_billing_interval(), $subscription->get_billing_period() ),
					),
					array(
						'name'  => __( 'Recurring total', 'submex' ),
						'value' => wp_strip_all_tags( $subscription->get_formatted_order_total() ),
					),
					array(
						'name'  => __( 'Next payment', 'submex' ),
						'value' => (string) $subscription->get_next_payment(),
					),
					array(
						'name'  => __( 'Trial ends', 'submex' ),
						'value' => (string) $subscription->get_trial_end(),
					),
					array(
						'name'  => __( 'Payment method', 'submex' ),
						'value' => $subscription->get_payment_method_title() ?: $subscription->get_payment_method(),
					),
					array(
						'name'  => __( 'Billing email', 'submex' ),
						'value' => $subscription->get_billing_email(),
					),
					array(
						'name'  => __( 'Billing name', 'submex' ),
						'value' => trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * @param string $email
	 * @param int    $page
	 */
	public function erase( $email, $page = 1 ): array {
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( $this->subscriptions_for( $email ) as $subscription ) {
			$status = $subscription->get_status_enum();

			// Never scrub a subscription that can still take money.
			if ( $status && ! $status->is_terminal() ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: %d: subscription number */
					__( 'Subscription #%d is still active. Cancel it before erasing, so billing stops at the payment gateway first.', 'submex' ),
					$subscription->get_id()
				);
				continue;
			}

			$this->anonymise( $subscription );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Strip identity, keep the financial record.
	 */
	private function anonymise( Subscription $subscription ): void {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$subscription->set_address(
				array(
					'first_name' => __( 'Removed', 'submex' ),
					'last_name'  => '',
					'company'    => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => '',
					'state'      => '',
					'postcode'   => '',
					'phone'      => '',
					'email'      => '',
				),
				$type
			);
		}

		$subscription->set_customer_id( 0 );
		$subscription->save();

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_NOTE,
			'Personal data erased at the customer\'s request.'
		);
	}

	/**
	 * @return Subscription[]
	 */
	private function subscriptions_for( string $email ): array {
		$user = get_user_by( 'email', $email );

		$found = array();

		if ( $user ) {
			$found = Subscription_Query::get(
				array(
					'limit'       => -1,
					'status'      => null,
					'customer_id' => $user->ID,
				)
			);
		}

		// Guest checkouts have no user, so fall back to the billing email on the record.
		$by_email = Subscription_Query::get(
			array(
				'limit'         => -1,
				'status'        => null,
				'billing_email' => $email,
			)
		);

		$all = array();
		foreach ( array_merge( $found, $by_email ) as $subscription ) {
			$all[ $subscription->get_id() ] = $subscription;
		}

		return array_values( $all );
	}
}
