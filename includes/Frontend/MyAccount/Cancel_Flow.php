<?php

namespace Submex\Frontend\MyAccount;

use Submex\Data\Activity_Repository;
use Submex\Data\Retention_Event_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Lifecycle\Cancellation_Policy;
use Submex\Lifecycle\Cancellation_Survey;
use Submex\Lifecycle\Retention_Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Ask before cancelling" in My Account: the reasons screen, at most one offer, then the cancel.
 *
 * Every screen is a plain form and every one of them can cancel in a single submit. The
 * endpoint has already checked the nonce and that the customer owns the subscription.
 */
class Cancel_Flow {

	public const ACTION       = 'cancel_flow';
	public const VIEW         = 'submex_cancel';
	public const VIEW_REASONS = 'reasons';
	public const VIEW_OFFER   = 'offer';
	public const MESSAGE      = 'submex_message';
	public const OTHER        = 'other';

	public const KEEP     = 'keep';
	public const CONTINUE = 'continue';
	public const DECLINE  = 'decline';

	private const MAX_DETAIL = 2000;

	public function __construct(
		private readonly Retention_Event_Repository $events,
		private readonly Cancellation_Survey $survey,
		private readonly Activity_Repository $activity
	) {}

	public static function nonce_action( int $subscription_id ): string {
		return 'submex_' . self::ACTION . '_' . $subscription_id;
	}

	public static function details_url( Subscription $subscription ): string {
		return wc_get_account_endpoint_url( Account_Endpoint::ENDPOINT . '/' . $subscription->get_id() );
	}

	public static function view_url( Subscription $subscription, string $view = self::VIEW_REASONS ): string {
		return add_query_arg(
			array(
				self::VIEW => $view,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $subscription->get_id() ) ),
			),
			self::details_url( $subscription )
		);
	}

	/**
	 * Cancelled, ending, ended or replaced: there is nothing left to cancel.
	 */
	public static function is_over( Subscription $subscription ): bool {
		return in_array(
			$subscription->get_status_enum(),
			array( Subscription_Status::PendingCancel, Subscription_Status::Cancelled, Subscription_Status::Expired, Subscription_Status::Switched ),
			true
		);
	}

	/**
	 * Where to send the customer after an action: the details page, showing the store's
	 * confirmation message once, or the plain notice when that message is switched off.
	 *
	 * @param string                $type         cancel, pause or offer_accept.
	 * @param string                $notice       The one-line notice used when the message is off.
	 * @param array<string, string> $placeholders Only resume_date is replaced.
	 */
	public static function confirm( Subscription $subscription, string $type, string $notice, array $placeholders = array() ): string {
		$message = Retention_Flow::message( $type );
		$user_id = get_current_user_id();

		if ( ! $message || ! $message['enabled'] || ! $user_id || ! in_array( $type, Retention_Flow::message_types(), true ) ) {
			wc_add_notice( $notice );

			return self::details_url( $subscription );
		}

		set_transient(
			self::message_key( $user_id ),
			array(
				'subscription' => $subscription->get_id(),
				'type'         => $type,
				'placeholders' => array_map( 'strval', $placeholders ),
			),
			10 * MINUTE_IN_SECONDS
		);

		return add_query_arg( self::MESSAGE, '1', self::details_url( $subscription ) );
	}

	/**
	 * Draws the reasons or offer screen when the address asks for one.
	 *
	 * @return bool Whether it drew a screen in place of the details page.
	 */
	public function render( Subscription $subscription ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below, before anything is logged.
		$view  = isset( $_GET[ self::VIEW ] ) ? sanitize_key( wp_unslash( $_GET[ self::VIEW ] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $view, array( self::VIEW_REASONS, self::VIEW_OFFER ), true ) || ! Retention_Flow::is_on() ) {
			return false;
		}

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $subscription->get_id() ) ) ) {
			wc_print_notice( __( 'That request has expired. Please try again.', 'submex' ), 'error' );
			return false;
		}

		if ( self::is_over( $subscription ) ) {
			wc_print_notice( __( 'This subscription has already been cancelled.', 'submex' ), 'notice' );
			return false;
		}

		if ( ! Cancellation_Policy::allows( $subscription ) ) {
			wc_print_notice( Account_Endpoint::cancel_refused_message( $subscription ), 'error' );
			return false;
		}

		$this->events->log( $subscription->get_id(), (int) $subscription->get_customer_id(), Retention_Event_Repository::STARTED );

		$pending = self::VIEW_OFFER === $view ? $this->pending( $subscription ) : null;
		$offer   = $pending ? $this->offer_for( $subscription, $pending['reason'] ) : null;

		if ( null !== $offer && null !== $pending ) {
			wc_get_template(
				'myaccount/cancel-offer.php',
				array(
					'subscription' => $subscription,
					'offer'        => $offer,
					'reason_id'    => $pending['reason'],
					'detail'       => $pending['detail'],
					/**
					 * Filter the label of the button that turns an offer down and cancels.
					 *
					 * @param string       $text
					 * @param mixed        $offer
					 * @param Subscription $subscription
					 */
					'decline_text' => (string) apply_filters( 'submex_retention_decline_text', __( 'No thanks, continue cancelling', 'submex' ), $offer, $subscription ),
				),
				'',
				SUBMEX_PATH . 'templates/'
			);

			return true;
		}

		wc_get_template(
			'myaccount/cancel-reasons.php',
			array(
				'subscription' => $subscription,
				'reasons'      => $this->survey->reasons(),
				'display'      => Retention_Flow::display(),
				'endpoint'     => Account_Endpoint::ENDPOINT,
			),
			'',
			SUBMEX_PATH . 'templates/'
		);

		return true;
	}

	/**
	 * The confirmation message an action left for this customer, shown once.
	 */
	public function render_message( Subscription $subscription ): void {
		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag; what shows is the transient this user's own action set.
		if ( ! isset( $_GET[ self::MESSAGE ] ) || ! $user_id ) {
			return;
		}

		$flashed = get_transient( self::message_key( $user_id ) );

		if ( ! is_array( $flashed ) || (int) ( $flashed['subscription'] ?? 0 ) !== $subscription->get_id() ) {
			return;
		}

		delete_transient( self::message_key( $user_id ) );

		$message = Retention_Flow::message( (string) ( $flashed['type'] ?? '' ) );

		if ( ! $message ) {
			return;
		}

		$placeholders = (array) ( $flashed['placeholders'] ?? array() );

		wc_get_template(
			'myaccount/retention-message.php',
			array(
				'subscription' => $subscription,
				'title'        => $message['title'],
				'description'  => strtr( $message['description'], array( '{resume_date}' => esc_html( (string) ( $placeholders['resume_date'] ?? '' ) ) ) ),
				'button_text'  => $message['button_text'],
				'close_url'    => self::details_url( $subscription ),
			),
			'',
			SUBMEX_PATH . 'templates/'
		);
	}

	/**
	 * A submit from the reasons or offer screen.
	 *
	 * @param callable $cancel fn( string $reason, string $detail ): void, the endpoint's own cancel; it redirects.
	 */
	public function handle( Subscription $subscription, callable $cancel ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the endpoint verified the nonce before calling.
		$choice = isset( $_POST['submex_choice'] ) ? sanitize_key( wp_unslash( $_POST['submex_choice'] ) ) : self::CONTINUE;
		$reason = isset( $_POST['submex_reason'] ) ? sanitize_key( wp_unslash( $_POST['submex_reason'] ) ) : '';
		$detail = isset( $_POST['submex_detail'] ) ? sanitize_textarea_field( wp_unslash( $_POST['submex_detail'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A reason that is no longer offered is dropped rather than refused: the cancel must go through.
		if ( ! array_key_exists( $reason, $this->survey->reasons() ) ) {
			$reason = '';
		}

		$detail = self::OTHER === $reason ? mb_substr( trim( $detail ), 0, self::MAX_DETAIL ) : '';

		if ( self::is_over( $subscription ) ) {
			$this->forget( $subscription );
			wc_add_notice( __( 'This subscription has already been cancelled.', 'submex' ), 'notice' );
			$this->back( $subscription );
		}

		if ( ! Cancellation_Policy::allows( $subscription ) ) {
			$this->forget( $subscription );
			wc_add_notice( Account_Endpoint::cancel_refused_message( $subscription ), 'error' );
			$this->back( $subscription );
		}

		if ( self::KEEP === $choice ) {
			$this->forget( $subscription );
			$this->log( $subscription, Retention_Event_Repository::KEPT, $reason );
			$this->back( $subscription );
		}

		if ( self::DECLINE !== $choice ) {
			if ( '' !== $reason ) {
				$this->log( $subscription, Retention_Event_Repository::REASON, $reason );
			}

			if ( Retention_Flow::is_on() && null !== $this->offer_for( $subscription, $reason ) ) {
				set_transient(
					$this->pending_key( $subscription ),
					array(
						'reason' => $reason,
						'detail' => $detail,
					),
					HOUR_IN_SECONDS
				);

				wp_safe_redirect( self::view_url( $subscription, self::VIEW_OFFER ) );
				exit;
			}
		}

		$cancel( $reason, $detail );
	}

	/**
	 * After the endpoint has cancelled a subscription from this flow, and saved the reason on it.
	 */
	public function cancelled( Subscription $subscription, string $reason, string $detail ): void {
		$this->forget( $subscription );
		$this->log( $subscription, Retention_Event_Repository::CANCELLED, $reason );

		if ( '' === $reason ) {
			return;
		}

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_NOTE,
			sprintf( 'Cancellation reason: %s', $this->survey->label( $reason ) ),
			array( 'reason' => $reason )
		);

		do_action( 'submex_cancellation_reason_recorded', $subscription, $reason, $detail );
	}

	/**
	 * @return mixed
	 */
	private function offer_for( Subscription $subscription, string $reason ) {
		/**
		 * Filter the one offer to show a customer who is about to cancel, or null for none.
		 *
		 * @param mixed        $offer        Null, or an offer submex_retention_render_offer can draw.
		 * @param Subscription $subscription
		 * @param string       $reason_id    The reason picked, or empty when none was.
		 */
		return apply_filters( 'submex_retention_offer_for', null, $subscription, $reason );
	}

	/**
	 * @return array{reason: string, detail: string}|null
	 */
	private function pending( Subscription $subscription ): ?array {
		$pending = get_transient( $this->pending_key( $subscription ) );

		if ( ! is_array( $pending ) ) {
			return null;
		}

		return array(
			'reason' => (string) ( $pending['reason'] ?? '' ),
			'detail' => (string) ( $pending['detail'] ?? '' ),
		);
	}

	private function forget( Subscription $subscription ): void {
		delete_transient( $this->pending_key( $subscription ) );
	}

	private function log( Subscription $subscription, string $event, string $reason ): void {
		$this->events->log( $subscription->get_id(), (int) $subscription->get_customer_id(), $event, $reason );
	}

	private function back( Subscription $subscription ): never {
		wp_safe_redirect( self::details_url( $subscription ) );
		exit;
	}

	private function pending_key( Subscription $subscription ): string {
		return 'submex_retention_flow_' . get_current_user_id() . '_' . $subscription->get_id();
	}

	private static function message_key( int $user_id ): string {
		return 'submex_retention_message_' . $user_id;
	}
}
