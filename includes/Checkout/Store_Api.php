<?php

namespace Submex\Checkout;

use Submex\Frontend\Disclosure;
use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart and Checkout blocks support.
 *
 * Blocks are WooCommerce's default checkout, so this is not an enhancement - without it
 * the recurring disclosure never renders and, more seriously, the Store API fires its own
 * order hook, so no subscription would be created at all.
 */
class Store_Api {

	public function __construct( private readonly Disclosure $disclosure ) {}

	public function register(): void {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_endpoint_data' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// The blocks add-to-cart route does not run the classic validation filter.
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'validate_add_to_cart' ), 10, 2 );
	}

	/**
	 * Expose subscription terms on the Store API so the blocks can render them.
	 */
	public function register_endpoint_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'submex',
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cart_data(): array {
		$items = Subscription_Product::cart_subscription_items();
		$key   = (string) array_key_first( $items );
		$terms = $items ? Line_Terms::for_cart_item( $items[ $key ], $key ) : null;

		if ( ! $terms ) {
			return array(
				'has_subscription' => false,
				'price_line'       => '',
				'lines'            => array(),
				'consent'          => '',
			);
		}

		$product = $items[ $key ]['data'];

		return array(
			'has_subscription' => true,
			'price_line'       => $this->plain( $this->disclosure->price_line( $product, $terms ) ),
			'lines'            => array_map(
				fn( array $line ): string => $this->plain( $line['text'] ),
				$this->disclosure->lines( $product, null, $terms )
			),
			'consent'          => $this->plain( $this->disclosure->sentence( $product, $terms ) ),
		);
	}

	/**
	 * Blocks render these as React text nodes, so markup and entities must both go.
	 *
	 * Stripping tags alone leaves things like &#2547; and &nbsp; which React prints
	 * literally, and the customer sees the raw entity instead of the currency symbol.
	 */
	private function plain( string $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cart_schema(): array {
		return array(
			'has_subscription' => array(
				'description' => __( 'Whether the cart contains a subscription.', 'submex' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'price_line'       => array(
				'description' => __( 'Headline recurring price.', 'submex' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'lines'            => array(
				'description' => __( 'Subscription terms, one self-contained fact per line.', 'submex' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'readonly'    => true,
			),
			'consent'          => array(
				'description' => __( 'The sentence shown before the customer places the order.', 'submex' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Mirror the classic one-subscription-per-cart rule onto the Store API route.
	 *
	 * @param \WC_Product $product
	 * @param array       $request
	 */
	public function validate_add_to_cart( $product, $request ): void {
		if ( ! $product instanceof \WC_Product || ! Subscription_Product::cart_has_subscription() ) {
			return;
		}

		$is_variation = $product->is_type( 'variation' );
		$recurring    = Cart_Validation::adding_subscription(
			$is_variation ? $product->get_parent_id() : $product->get_id(),
			$is_variation ? $product->get_id() : 0,
			(array) ( $request['cart_item_data'] ?? array() )
		);

		if ( ! $recurring ) {
			return;
		}

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'submex_one_subscription_per_order',
			esc_html( Cart_Validation::one_subscription_message() ),
			400
		);
	}

	public function enqueue(): void {
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'submex-blocks-checkout',
			SUBMEX_URL . 'assets/js/blocks-checkout.js',
			array( 'wp-element', 'wp-plugins', 'wp-i18n', 'wc-blocks-checkout' ),
			SUBMEX_VERSION,
			true
		);
	}
}
