<?php

namespace Submex\Product;

use Submex\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription and Variable subscription as entries in the Product data dropdown.
 *
 * A checkbox buried inside Simple product was the wrong place to ask: it reads as an
 * option on a one-off product rather than a different kind of product, and merchants
 * missed it. Choosing the type is the same decision as choosing Variable.
 *
 * The classes still extend WC_Product_Simple and WC_Product_Variable, so every gateway,
 * tax rule and shipping method treats them exactly as before - the decorator that reads
 * the schedule off a product has not changed, only how a merchant says yes to it.
 */
class Product_Types {

	public const SIMPLE   = 'submex_subscription';
	public const VARIABLE = 'submex_variable_subscription';

	public function register(): void {
		add_filter( 'product_type_selector', array( $this, 'add_to_selector' ) );
		add_filter( 'woocommerce_product_class', array( $this, 'map_class' ), 10, 2 );
		add_filter( 'woocommerce_data_stores', array( $this, 'map_data_stores' ) );
		add_action( 'init', array( $this, 'register_terms' ), 5 );

		// Price, tax and inventory are hidden for any type WooCommerce does not know.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'show_standard_tabs' ) );
		// Priority 20: after WooCommerce enqueues the product script whose type-change handler this triggers.
		add_action( 'admin_enqueue_scripts', array( $this, 'show_standard_fields' ), 20 );

		add_filter( 'woocommerce_product_supports', array( $this, 'supports' ), 10, 3 );

		// Without these there is no Add to cart button at all. WooCommerce renders one by
		// firing woocommerce_{type}_add_to_cart, and it only registers listeners for the
		// types it ships with - so a custom type silently renders nothing where the button
		// should be. Ours behave like the products they extend, so they use the same
		// renderers.
		add_action( 'woocommerce_' . self::SIMPLE . '_add_to_cart', 'woocommerce_simple_add_to_cart' );
		add_action( 'woocommerce_' . self::VARIABLE . '_add_to_cart', 'woocommerce_variable_add_to_cart' );

		add_action( 'admin_notices', array( $this, 'warn_unsupported_variable' ) );
	}

	/**
	 * Whether a variation can actually carry its parent's schedule.
	 *
	 * Free registers the variable type but cannot bill it: a variation holds none of its
	 * parent's meta, so nothing reads a schedule off it and checkout sells it once. Pro
	 * supplies the resolver and answers yes here.
	 */
	public static function variable_supported(): bool {
		// The default covers a Pro older than the filter: its resolver is only loaded when
		// the module actually booted, so it answers the same question. Never autoloaded -
		// that would say yes merely because the file is on disk.
		$supported = class_exists( '\SubmexPro\Product\Variation_Subscription', false );

		/**
		 * Filter whether variable subscriptions can be billed.
		 *
		 * @param bool $supported
		 */
		return (bool) apply_filters( 'submex_variable_subscriptions_supported', $supported );
	}

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::SIMPLE, self::VARIABLE );
	}

	public static function is_subscription_type( $product ): bool {
		return $product instanceof \WC_Product && in_array( $product->get_type(), self::all(), true );
	}

	/**
	 * Point each type at the data store its parent class expects.
	 *
	 * WooCommerce resolves the store by type name, so an unregistered one silently falls
	 * back to the simple-product store - which has no concept of children. A variable
	 * subscription loaded that way reports no variations at all.
	 *
	 * @param array $stores
	 */
	public function map_data_stores( $stores ): array {
		$stores[ 'product-' . self::SIMPLE ]   = 'WC_Product_Data_Store_CPT';
		$stores[ 'product-' . self::VARIABLE ] = 'WC_Product_Variable_Data_Store_CPT';

		return (array) $stores;
	}

	/**
	 * WooCommerce stores the type as a term, so a new one has to exist before a product
	 * can be saved as it.
	 */
	public function register_terms(): void {
		foreach ( self::all() as $type ) {
			if ( ! get_term_by( 'slug', $type, 'product_type' ) ) {
				wp_insert_term( $type, 'product_type' );
			}
		}
	}

	/**
	 * @param array $types
	 */
	public function add_to_selector( $types ): array {
		$types[ self::SIMPLE ] = __( 'Subscription', 'submex' );

		// Offered only when something can bill it - but never taken away from a product
		// that is already one, or saving the product would silently change its type.
		if ( self::variable_supported() || self::VARIABLE === self::editing_type() ) {
			$types[ self::VARIABLE ] = __( 'Variable subscription', 'submex' );
		}

		return (array) $types;
	}

	/**
	 * Tell an admin editing a variable subscription that it is not billing.
	 */
	public function warn_unsupported_variable(): void {
		if ( self::variable_supported() || self::VARIABLE !== self::editing_type() ) {
			return;
		}

		echo '<div class="' . esc_attr( Notices::important( 'error', true ) ) . '"><p>' . esc_html__(
			'This is a variable subscription, but Submex Pro is not active, so nothing gives its variations a billing schedule. A customer buying one is charged once and never again. Activate Submex Pro, or change the product type to Subscription.',
			'submex'
		) . '</p></div>';
	}

	/**
	 * The product type of the product open in the editor, if one is.
	 */
	private static function editing_type(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id ) {
			return '';
		}

		global $product_object, $post;

		$product = $product_object instanceof \WC_Product ? $product_object : null;

		if ( ! $product && $post instanceof \WP_Post && 'product' === $post->post_type ) {
			$product = wc_get_product( $post );
		}

		return $product instanceof \WC_Product ? $product->get_type() : '';
	}

	/**
	 * @param string $classname
	 * @param string $type
	 */
	public function map_class( $classname, $type ): string {
		return match ( $type ) {
			self::SIMPLE   => Simple_Subscription::class,
			self::VARIABLE => Variable_Subscription::class,
			default        => (string) $classname,
		};
	}

	/**
	 * @param array $tabs
	 */
	public function show_standard_tabs( $tabs ): array {
		$classes = array_map( static fn( string $type ): string => 'show_if_' . $type, self::all() );

		foreach ( array( 'general', 'inventory', 'shipping', 'linked_product', 'attribute', 'advanced' ) as $tab ) {
			if ( isset( $tabs[ $tab ]['class'] ) ) {
				$tabs[ $tab ]['class'] = array_merge( (array) $tabs[ $tab ]['class'], $classes );
			}
		}

		if ( isset( $tabs['variations']['class'] ) ) {
			$tabs['variations']['class'][] = 'show_if_' . self::VARIABLE;
		}

		return (array) $tabs;
	}

	/**
	 * WooCommerce shows the price fields only for types it ships with, and the variations
	 * machinery only for its own variable type. Both are class-driven in the DOM, so the
	 * fix is to let our types answer to the same classes.
	 */
	public function show_standard_fields(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id || ! wp_script_is( 'wc-admin-product-meta-boxes', 'enqueued' ) ) {
			return;
		}

		$template = <<<'JS'
jQuery( function ( $ ) {
	var simple = %1$s, variable = %2$s;
	$( '.pricing' ).addClass( 'show_if_' + simple );
	$( '.show_if_simple' ).not( '.submex-product-options' ).addClass( 'show_if_' + simple );
	$( '.show_if_variable' ).addClass( 'show_if_' + variable );
	// WooCommerce's own handler, not just its event: its first pass ran before these classes and hid the General tab.
	$( 'select#product-type' ).trigger( 'change' );
} );
JS;

		wp_add_inline_script( 'wc-admin-product-meta-boxes', sprintf( $template, wp_json_encode( self::SIMPLE ), wp_json_encode( self::VARIABLE ) ) );
	}

	/**
	 * @param bool   $supports
	 * @param string $feature
	 */
	public function supports( $supports, $feature, $product ): bool {
		if ( 'ajax_add_to_cart' === $feature && self::is_subscription_type( $product ) ) {
			return false;
		}

		return (bool) $supports;
	}
}
