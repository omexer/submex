<?php

namespace Submex\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes a gateway visible on the block checkout.
 *
 * A WC_Payment_Gateway is invisible there until it registers itself like this. Declaring
 * cart_checkout_blocks compatibility says only that nothing breaks - not that anything
 * appears - so without this a merchant sees the gateway marked Active and the customer
 * sees "There are no payment methods available".
 *
 * PayPal redirects to the provider, so there are no card fields to render here: a label,
 * a description, and the same availability rule the classic checkout uses.
 */
class Gateway_Support extends AbstractPaymentMethodType {

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	public function is_active(): bool {
		$gateway = $this->gateway();

		return $gateway instanceof \WC_Payment_Gateway && $gateway->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$asset = SUBMEX_PATH . 'build/blocks.asset.php';

		if ( ! is_readable( $asset ) ) {
			return array();
		}

		$asset = require $asset;

		// Registered once however many gateways ask for it; WordPress ignores a repeat.
		wp_register_script(
			'submex-blocks',
			SUBMEX_URL . 'build/blocks.js',
			// The two WooCommerce globals are externals at build time, so the asset file
			// cannot know about them; their handles have to be named here.
			array_merge( $asset['dependencies'], array( 'wc-blocks-registry', 'wc-settings' ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( 'submex-blocks', 'submex', SUBMEX_PATH . 'languages' );

		return array( 'submex-blocks' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->gateway();

		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array(),
		);
	}

	private function gateway(): ?\WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		return $gateways[ $this->name ] ?? null;
	}
}
