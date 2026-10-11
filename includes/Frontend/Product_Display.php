<?php

namespace Submex\Frontend;

use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the disclosure on every surface it is legally required to appear on.
 *
 * Product page, cart, checkout and the confirmation email. Never behind a tab or an
 * accordion — a disclosure the customer has to open is not a disclosure.
 */
class Product_Display {

	public function __construct( private readonly Disclosure $disclosure ) {}

	public function register(): void {
		add_action( 'woocommerce_single_product_summary', array( $this, 'on_product_page' ), 11 );
		// Late, so it sees WooCommerce's finished markup - sale strikethrough and tax suffix
		// included - and adds to it rather than being overwritten by it.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'on_cart_line' ), 10, 3 );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'on_checkout_totals' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'before_place_order' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'single_button_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'list_button_text' ), 10, 2 );
	}

	public static function button_text(): string {
		/**
		 * Filter the add to cart button's text on subscription products.
		 *
		 * @param string $text
		 */
		return (string) apply_filters( 'submex_subscribe_button_text', __( 'Subscribe', 'submex' ) );
	}

	/**
	 * @param string $text
	 * @param mixed  $product
	 */
	public function single_button_text( $text, $product ): string {
		return Subscription_Product::is_subscription( $product ) ? self::button_text() : (string) $text;
	}

	/**
	 * Only where the button adds to the cart: "Select options" and "Read more" say what they do.
	 *
	 * @param string $text
	 * @param mixed  $product
	 */
	public function list_button_text( $text, $product ): string {
		if (
			! $product instanceof \WC_Product
			|| $product instanceof \WC_Product_Variable
			|| ! $product->is_purchasable()
			|| ! $product->is_in_stock()
			|| ! Subscription_Product::is_subscription( $product )
		) {
			return (string) $text;
		}

		return self::button_text();
	}

	public function on_product_page(): void {
		global $product;

		if ( $product instanceof \WC_Product && Subscription_Product::is_subscription( $product ) ) {
			echo wp_kses_post( $this->disclosure->render( $product ) );
		}
	}

	/**
	 * Everywhere WooCommerce prints a price: product page, shop, category, related products.
	 *
	 * @param string $html
	 * @param mixed  $product
	 */
	public function price_html( $html, $product ): string {
		return $product instanceof \WC_Product
			? $this->disclosure->price_html( (string) $html, $product )
			: (string) $html;
	}

	/**
	 * Per line item, because a cart can hold recurring and one-off products together.
	 */
	public function on_cart_line( $price_html, $cart_item, $cart_item_key ) {
		$terms = is_array( $cart_item ) && Subscription_Product::cart_item_is_subscription( $cart_item, (string) $cart_item_key )
			? Line_Terms::for_cart_item( $cart_item, (string) $cart_item_key )
			: null;

		if ( ! $terms ) {
			return $price_html;
		}

		return $price_html . '<span class="submex-cart-terms">' . esc_html( wp_strip_all_tags( $this->disclosure->price_line( $cart_item['data'], $terms ) ) ) . '</span>';
	}

	public function on_checkout_totals(): void {
		foreach ( $this->subscription_lines_in_cart() as list( $product, $terms ) ) {
			echo '<tr class="submex-recurring-total"><th>' . esc_html__( 'Recurring', 'submex' ) . '</th><td>'
				. wp_kses_post( $this->disclosure->price_line( $product, $terms ) ) . '</td></tr>';
		}
	}

	/**
	 * The sentence next to the Place Order button — the one that legally matters.
	 */
	public function before_place_order(): void {
		$lines = $this->subscription_lines_in_cart();

		if ( empty( $lines ) ) {
			return;
		}

		list( $product, $terms ) = $lines[0];

		// Amounts arrive wrapped in <bdi> so RTL cannot reorder them.
		echo '<p class="submex-checkout-consent">' . wp_kses( $this->disclosure->sentence( $product, $terms ), array( 'bdi' => array() ) ) . '</p>';
	}

	public function styles(): void {
		if ( ! is_product() && ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_register_style( 'submex-frontend', false, array(), SUBMEX_VERSION );
		wp_enqueue_style( 'submex-frontend' );
		wp_add_inline_style(
			'submex-frontend',
			'.submex-disclosure{margin:1em 0}
			 .submex-disclosure__facts{list-style:none;margin:0;padding:0;font-size:.9em;line-height:1.6;opacity:.85}
			 .submex-cart-terms{display:block;font-size:.85em;opacity:.8}
			 .submex-checkout-consent{margin:0 0 1em;font-size:.9em}
			 .submex-blocks-disclosure{padding:1em 0;border-top:1px solid rgba(0,0,0,.1)}
			 .submex-blocks-disclosure__price{font-weight:600;margin:0 0 .35em}
			 .submex-blocks-disclosure__facts{list-style:none;margin:0;padding:0;font-size:.9em;line-height:1.6;opacity:.85}
			 .submex-blocks-disclosure__consent{margin:.75em 0 0;font-size:.85em}'
		);
	}

	/**
	 * @return array<int, array{0: \WC_Product, 1: Line_Terms}>
	 */
	private function subscription_lines_in_cart(): array {
		$lines = array();

		foreach ( Subscription_Product::cart_subscription_items() as $key => $item ) {
			$terms = Line_Terms::for_cart_item( $item, $key );

			if ( $terms ) {
				$lines[] = array( $item['data'], $terms );
			}
		}

		return $lines;
	}
}
