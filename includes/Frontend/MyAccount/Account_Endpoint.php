<?php

namespace Submex\Frontend\MyAccount;

use Submex\Data\Activity_Repository;
use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Lifecycle\Cancellation_Policy;
use Submex\Lifecycle\Cancellation_Survey;
use Submex\Lifecycle\Retention_Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The customer's subscription screens under My Account. UX Spec 8 and 10.
 */
class Account_Endpoint {

	public const ENDPOINT = 'subscriptions';

	public function __construct(
		private readonly Activity_Repository $activity,
		private readonly Cancel_Flow $flow
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 10, 1 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	public function add_menu_item( $items ) {
		$new = array();

		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;

			// Sit directly under Orders, where customers already look.
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Subscriptions', 'submex' );
			}
		}

		return isset( $new[ self::ENDPOINT ] ) ? $new : $new + array( self::ENDPOINT => __( 'Subscriptions', 'submex' ) );
	}

	/**
	 * @param string $value Endpoint value: a subscription ID, or empty for the list.
	 */
	public function render( $value = '' ): void {
		$id = absint( $value );

		if ( $id ) {
			$subscription = $this->owned_subscription( $id );

			if ( ! $subscription ) {
				wc_print_notice( __( 'That subscription could not be found.', 'submex' ), 'error' );
				return;
			}

			if ( $this->flow->render( $subscription ) ) {
				return;
			}

			$this->flow->render_message( $subscription );

			wc_get_template(
				'myaccount/subscription-details.php',
				array(
					'subscription' => $subscription,
					'endpoint'     => self::ENDPOINT,
				),
				'',
				SUBMEX_PATH . 'templates/'
			);

			return;
		}

		wc_get_template(
			'myaccount/subscriptions.php',
			array(
				'subscriptions' => $this->for_current_customer(),
				'endpoint'      => self::ENDPOINT,
			),
			'',
			SUBMEX_PATH . 'templates/'
		);
	}

	/**
	 * Cancel first, ask why after. The cancellation is executed and confirmed before any
	 * survey is shown, which makes survey-as-a-barrier structurally impossible.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['submex_action'], $_POST['submex_subscription'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['submex_action'] ) );
		$id     = absint( wp_unslash( $_POST['submex_subscription'] ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'submex_' . $action . '_' . $id ) ) {
			wc_add_notice( __( 'That request has expired. Please try again.', 'submex' ), 'error' );
			return;
		}

		// Never trust the ID in the request: re-check ownership server side.
		$subscription = $this->owned_subscription( $id );
		if ( ! $subscription ) {
			wc_add_notice( __( 'That subscription could not be found.', 'submex' ), 'error' );
			return;
		}

		if ( 'auto_renew' === $action ) {
			$wanted = isset( $_POST['submex_auto_renew'] ) ? sanitize_key( wp_unslash( $_POST['submex_auto_renew'] ) ) : 'on';

			$this->set_auto_renew( $subscription, 'on' === $wanted );
			return;
		}

		if ( 'renew_early' === $action ) {
			$result = \Submex\Lifecycle\Early_Renewal::charge( $subscription );

			wc_add_notice( $result['message'], $result['ok'] ? 'success' : 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT . '/' . $subscription->get_id() ) );
			exit;
		}

		if ( 'cancel' === $action ) {
			$this->cancel( $subscription, Cancellation_Policy::is_immediate() );
		}

		if ( Cancel_Flow::ACTION === $action ) {
			$this->flow->handle(
				$subscription,
				function ( string $reason, string $detail ) use ( $subscription ): void {
					$this->cancel( $subscription, Cancellation_Policy::is_immediate(), compact( 'reason', 'detail' ) );
				}
			);
		}
	}

	private function set_auto_renew( Subscription $subscription, bool $on ): void {
		if ( ! \Submex\Lifecycle\Auto_Renewal::is_offered() ) {
			return;
		}

		$auto = \Submex\Plugin::instance()->get( 'auto_renewal' );

		if ( ! $auto instanceof \Submex\Lifecycle\Auto_Renewal ) {
			return;
		}

		$auto->set( $subscription, $on );

		if ( Status_Presenter::pays_by_link( $subscription ) ) {
			wc_add_notice(
				$on
					? __( 'Renewal is back on. At the end of each period we will send you a renewal to pay.', 'submex' )
					: __( 'Renewal is off. Your subscription stays active until the end of the period you have paid for, and you will not be asked to pay again.', 'submex' )
			);
		} else {
			wc_add_notice(
				$on
					? __( 'Automatic renewal is back on. Your subscription will keep renewing.', 'submex' )
					: __( 'Automatic renewal is off. Your subscription stays active until the end of the period you have paid for, and you will not be charged again.', 'submex' )
			);
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT . '/' . $subscription->get_id() ) );
		exit;
	}

	/**
	 * What the customer is told when they cannot cancel online.
	 */
	public static function cancel_refused_message( Subscription $subscription ): string {
		/**
		 * Filter the explanation shown when a subscription cannot be cancelled online.
		 *
		 * @param string       $message
		 * @param Subscription $subscription
		 */
		return (string) apply_filters(
			'submex_cancel_refused_message',
			__( 'This subscription cannot be cancelled online. Please contact us.', 'submex' ),
			$subscription
		);
	}

	/**
	 * @param array{reason: string, detail: string}|null $flow What the customer said on the way, when they came through "Ask before cancelling".
	 */
	private function cancel( Subscription $subscription, bool $immediately, ?array $flow = null ): void {
		// A resubmitted form would otherwise announce the cancellation, and send its emails, again.
		if ( Cancel_Flow::is_over( $subscription ) ) {
			wc_add_notice( __( 'This subscription has already been cancelled.', 'submex' ), 'notice' );
			wp_safe_redirect( Cancel_Flow::details_url( $subscription ) );
			exit;
		}

		if ( ! Cancellation_Policy::allows( $subscription ) ) {
			wc_add_notice( self::cancel_refused_message( $subscription ), 'error' );
			return;
		}

		$target = $immediately ? Subscription_Status::Cancelled : Subscription_Status::PendingCancel;

		// Saved with the cancel itself, so the merchant's email already has the reason.
		if ( null !== $flow ) {
			$said = array(
				Cancellation_Survey::META_REASON => $flow['reason'],
				Cancellation_Survey::META_DETAIL => $flow['detail'],
			);

			foreach ( $said as $key => $value ) {
				if ( '' === $value ) {
					$subscription->delete_meta_data( $key );
				} else {
					$subscription->update_meta_data( $key, $value );
				}
			}
		}

		try {
			$subscription->transition_to( $target, __( 'Cancelled by the customer.', 'submex' ) );
			$subscription->save();
		} catch ( \InvalidArgumentException $e ) {
			wc_add_notice( __( 'This subscription can no longer be cancelled.', 'submex' ), 'error' );
			return;
		}

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_STATUS_CHANGE,
			$immediately ? 'Cancelled immediately by the customer.' : 'Cancellation scheduled for the end of the period.'
		);

		if ( null !== $flow ) {
			$this->flow->cancelled( $subscription, $flow['reason'], $flow['detail'] );
		}

		do_action( 'submex_subscription_cancelled', $subscription, 'customer' );

		$notice = $immediately
			? __( 'Your subscription has been cancelled.', 'submex' )
			: __( 'Your subscription will end when the current period finishes. You will not be charged again.', 'submex' );

		if ( null === $flow ) {
			wc_add_notice( $notice );
			$to = wc_get_account_endpoint_url( self::ENDPOINT . '/' . $subscription->get_id() );
		} else {
			$to = Cancel_Flow::confirm( $subscription, Retention_Flow::MESSAGE_CANCEL, $notice );
		}

		wp_safe_redirect( add_query_arg( 'cancelled', '1', $to ) );
		exit;
	}

	/**
	 * @return Subscription[]
	 */
	private function for_current_customer(): array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return array();
		}

		$found = Subscription_Query::get(
			array(
				'customer_id' => $user_id,
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		return array_filter( $found, static fn( $s ) => $s instanceof Subscription );
	}

	private function owned_subscription( int $id ): ?Subscription {
		$subscription = wc_get_order( $id );

		if ( ! $subscription instanceof Subscription ) {
			return null;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || (int) $subscription->get_customer_id() !== $user_id ) {
			return null;
		}

		return $subscription;
	}
}
