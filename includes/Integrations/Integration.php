<?php

namespace Submex\Integrations;

use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Something a subscription hands the customer while it is live. Add one through the submex_integrations filter.
 *
 * An integration may also have hooks(): void, called once at boot while it is available, for
 * events the grant/revoke rule cannot express, such as renewals.
 */
interface Integration {

	public function slug(): string;

	public function title(): string;

	/** Whether the plugin it connects to is active. */
	public function is_available(): bool;

	public function grant( Subscription $subscription ): void;

	public function revoke( Subscription $subscription ): void;

	public function render_fields( ?\WC_Product $product ): void;

	public function save_fields( \WC_Product $product ): void;
}
