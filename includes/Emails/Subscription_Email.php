<?php

namespace Submex\Emails;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for every Submex email.
 *
 * Extends WC_Email so each one inherits the merchant's own header, footer, colours and
 * template overrides. Subclasses describe content; they never render markup.
 *
 * UX Spec 12.2 - anatomy is always: what happened, what it means, ONE action,
 * what happens next with a date.
 */
abstract class Subscription_Email extends \WC_Email {

	protected ?Subscription $subscription = null;

	protected ?\WC_Order $related_order = null;

	public function __construct() {
		$this->template_base  = SUBMEX_PATH . 'templates/';
		$this->template_html  = 'emails/subscription-email.php';
		$this->template_plain = 'emails/plain/subscription-email.php';
		$this->placeholders   = array( '{subscription_number}' => '' );

		// customer_email is set by the subclass before this runs; do not overwrite it.

		parent::__construct();
	}

	/**
	 * One-sentence statement of what happened. Rendered as the lead paragraph.
	 */
	abstract protected function intro(): string;

	/**
	 * Label => value facts. Amounts and dates always concrete, never "soon".
	 *
	 * @return array<string, string>
	 */
	abstract protected function facts(): array;

	/**
	 * The single primary action, or null when there is nothing to do.
	 *
	 * @return array{label: string, url: string}|null
	 */
	protected function call_to_action(): ?array {
		return null;
	}

	/**
	 * What happens next. Always dated where a date exists.
	 */
	protected function outro(): string {
		return '';
	}

	protected function prepare( Subscription $subscription, ?\WC_Order $order = null ): bool {
		$this->subscription  = $subscription;
		$this->related_order = $order;
		$this->object        = $subscription;

		$this->placeholders['{subscription_number}'] = (string) $subscription->get_id();

		if ( ! $this->is_enabled() ) {
			return false;
		}

		$this->recipient = $this->customer_email
			? $subscription->get_billing_email()
			: $this->get_option( 'recipient', get_option( 'admin_email' ) );

		return (bool) $this->recipient;
	}

	protected function send_now(): void {
		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args(), '', $this->template_base );
	}

	private function template_args(): array {
		// WooCommerce renders these with no object attached when the merchant previews an
		// email from the settings screen, so nothing below may assume a subscription.
		$loaded = $this->subscription instanceof Subscription;

		return array(
			'email_heading'      => $this->get_heading(),
			'intro'              => $loaded ? $this->intro() : __( 'This is a preview. Real messages include the subscription details here.', 'submex' ),
			'facts'              => $loaded ? $this->facts() : array(),
			'cta'                => $loaded ? $this->call_to_action() : null,
			'outro'              => $loaded ? $this->outro() : '',
			'additional_content' => $this->get_additional_content(),
			'subscription'       => $this->subscription,
			'sent_to_admin'      => ! $this->customer_email,
			'email'              => $this,
		);
	}

	protected function amount( $value ): string {
		return wp_strip_all_tags( wc_price( (float) $value, array( 'currency' => $this->subscription ? $this->subscription->get_currency() : '' ) ) );
	}

	protected function date( ?string $gmt ): string {
		if ( empty( $gmt ) ) {
			return '';
		}

		return date_i18n( (string) get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * The customer's My Account view of this subscription.
	 */
	protected function manage_url(): string {
		return $this->subscription
			? wc_get_endpoint_url( 'subscriptions', (string) $this->subscription->get_id(), wc_get_page_permalink( 'myaccount' ) )
			: '';
	}

	public function get_default_additional_content(): string {
		return '';
	}
}
