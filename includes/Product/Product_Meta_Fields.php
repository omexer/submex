<?php

namespace Submex\Product;

use Submex\Admin\Assets;
use Submex\Domain\Billing_Schedule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The subscription panel on the product edit screen.
 *
 * Each section is an action, and every field - free's and Pro's - is a callback on one at a
 * fixed priority, so the order on screen is the order of the PRIORITY_ constants below.
 */
class Product_Meta_Fields {

	public const ACTION_PRICING  = 'submex_product_fields_pricing';
	public const ACTION_RENEWAL  = 'submex_product_fields_renewal_pricing';
	public const ACTION_BILLING  = 'submex_product_fields_billing';
	public const ACTION_SHIPPING = 'submex_product_fields_shipping';
	// The original single hook; it now fills "More settings".
	public const ACTION_MORE = 'submex_product_subscription_fields';

	public const PRIORITY_PAYMENT_TYPE = 10;
	public const PRIORITY_SCHEDULE     = 20;
	public const PRIORITY_LENGTH       = 30;
	public const PRIORITY_COMMITMENT   = 40;
	public const PRIORITY_TRIAL        = 50;
	public const PRIORITY_LIMIT        = 60;
	public const PRIORITY_ACCESS       = 70;

	public const PRIORITY_SHIPS           = 10;
	public const PRIORITY_DELIVERY        = 20;
	public const PRIORITY_SHIPPING_CHARGE = 40;

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( self::ACTION_PRICING, array( $this, 'signup_fee_field' ), 20 );
		add_action( self::ACTION_BILLING, array( $this, 'schedule_field' ), self::PRIORITY_SCHEDULE );
		add_action( self::ACTION_BILLING, array( $this, 'trial_field' ), self::PRIORITY_TRIAL );
	}

	public function render(): void {
		global $product_object;

		printf(
			'<div class="submex-product-options show_if_%s show_if_%s">',
			esc_attr( Product_Types::SIMPLE ),
			esc_attr( Product_Types::VARIABLE )
		);

		// A legacy product says yes through this; a new one says yes by being the type.
		if ( $product_object && 'yes' === $product_object->get_meta( Subscription_Product::META_ENABLED ) && ! Product_Types::is_subscription_type( $product_object ) ) {
			printf(
				'<p class="form-field"><span class="description">%s</span></p>',
				esc_html__( 'This product bills recurringly but is still a simple product. Change its type to Subscription when convenient; it keeps working either way.', 'submex' )
			);
		}

		Product_Field_Layout::section( 'pricing', __( 'Pricing', 'submex' ), self::ACTION_PRICING, $product_object );
		Product_Field_Layout::section( 'renewal-pricing', __( 'Custom renewal pricing', 'submex' ), self::ACTION_RENEWAL, $product_object );
		Product_Field_Layout::section( 'billing', __( 'Billing settings', 'submex' ), self::ACTION_BILLING, $product_object );
		Product_Field_Layout::section( 'shipping', __( 'Shipping settings', 'submex' ), self::ACTION_SHIPPING, $product_object );
		Product_Field_Layout::section( 'more', __( 'More settings', 'submex' ), self::ACTION_MORE, $product_object, '', true );

		echo '</div>';
	}

	/**
	 * @param \WC_Product|null $product
	 */
	public function signup_fee_field( $product ): void {
		woocommerce_wp_text_input(
			array(
				'id'          => Subscription_Product::META_SIGNUP_FEE,
				/* translators: %s: store currency symbol */
				'label'       => sprintf( __( 'Sign-up fee (%s)', 'submex' ), get_woocommerce_currency_symbol() ),
				'data_type'   => 'price',
				'value'       => $product ? ( $product->get_meta( Subscription_Product::META_SIGNUP_FEE ) ?: '' ) : '',
				'desc_tip'    => true,
				'description' => __( 'A one-off charge taken with the first payment.', 'submex' ),
			)
		);
	}

	/**
	 * @param \WC_Product|null $product
	 */
	public function schedule_field( $product ): void {
		Product_Field_Layout::duration(
			array(
				'label'        => __( 'Bill every', 'submex' ),
				'number_id'    => Subscription_Product::META_INTERVAL,
				'number_value' => $product ? ( $product->get_meta( Subscription_Product::META_INTERVAL ) ?: 1 ) : 1,
				'min'          => 1,
				'unit_id'      => Subscription_Product::META_PERIOD,
				'unit_value'   => $product ? ( $product->get_meta( Subscription_Product::META_PERIOD ) ?: 'month' ) : 'month',
				'tip'          => __( 'How often the customer is charged. 2 with "Month(s)" charges every two months.', 'submex' ),
			)
		);
	}

	/**
	 * @param \WC_Product|null $product
	 */
	public function trial_field( $product ): void {
		Product_Field_Layout::duration(
			array(
				'label'        => __( 'Free trial', 'submex' ),
				'number_id'    => Subscription_Product::META_TRIAL_DAYS,
				'number_value' => $product ? ( $product->get_meta( Subscription_Product::META_TRIAL_DAYS ) ?: '' ) : '',
				'placeholder'  => '0',
				'unit_id'      => Subscription_Product::META_TRIAL_PERIOD,
				'unit_value'   => $product ? ( $product->get_meta( Subscription_Product::META_TRIAL_PERIOD ) ?: 'day' ) : 'day',
				'tip'          => __( 'Nothing is charged until the trial ends, apart from any sign-up fee. Leave empty for no trial.', 'submex' ),
				'note'         => __( 'Offer customers a free trial before their first payment.', 'submex' ),
			)
		);
	}

	/**
	 * @param string $hook
	 */
	public function enqueue( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'submex-product-fields', SUBMEX_URL . 'assets/css/product-fields.css', array( 'woocommerce_admin_styles' ), Assets::version( 'assets/css/product-fields.css' ) );
		wp_enqueue_script( 'submex-product-fields', SUBMEX_URL . 'assets/js/product-fields.js', array( 'jquery' ), Assets::version( 'assets/js/product-fields.js' ), true );
		wp_localize_script(
			'submex-product-fields',
			'submexProductFields',
			array(
				'simpleType'       => Product_Types::SIMPLE,
				'paymentTypeClass' => Product_Field_Layout::PAYMENT_TYPE_CLASS,
				'defaultType'      => 'recurring',
				'pricingTitle'     => __( 'Pricing', 'submex' ),
			)
		);
	}

	public function save( \WC_Product $product ): void {
		// Nonce is verified by WooCommerce before this hook fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// The type is the switch now. The meta is still written so anything reading it
		// directly - an older extension, a report - keeps seeing the truth.
		$is_subscription = Product_Types::is_subscription_type( $product );

		$product->update_meta_data( Subscription_Product::META_ENABLED, $is_subscription ? 'yes' : 'no' );

		if ( ! $is_subscription ) {
			return;
		}

		$product->update_meta_data( Subscription_Product::META_PERIOD, $this->posted_period( Subscription_Product::META_PERIOD, 'month' ) );

		$product->update_meta_data(
			Subscription_Product::META_INTERVAL,
			max( 1, isset( $_POST[ Subscription_Product::META_INTERVAL ] ) ? absint( wp_unslash( $_POST[ Subscription_Product::META_INTERVAL ] ) ) : 1 )
		);

		$product->update_meta_data(
			Subscription_Product::META_TRIAL_DAYS,
			isset( $_POST[ Subscription_Product::META_TRIAL_DAYS ] ) ? absint( wp_unslash( $_POST[ Subscription_Product::META_TRIAL_DAYS ] ) ) : 0
		);
		$product->update_meta_data( Subscription_Product::META_TRIAL_PERIOD, $this->posted_period( Subscription_Product::META_TRIAL_PERIOD, 'day' ) );

		$product->update_meta_data(
			Subscription_Product::META_SIGNUP_FEE,
			isset( $_POST[ Subscription_Product::META_SIGNUP_FEE ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ Subscription_Product::META_SIGNUP_FEE ] ) ) ) : ''
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/**
		 * Fires while saving the subscription panel, for extension fields.
		 *
		 * @param \WC_Product $product
		 */
		do_action( 'submex_save_product_subscription_fields', $product );
	}

	private function posted_period( string $key, string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before save().
		$period = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;

		return in_array( $period, Billing_Schedule::PERIODS, true ) ? $period : $fallback;
	}
}
