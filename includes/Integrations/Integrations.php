<?php

namespace Submex\Integrations;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes subscription state to the integrations whose plugin is active.
 *
 * The lifecycle rule lives here once rather than in every integration: access is granted
 * while a subscription is Active or Trialling and withdrawn on every other state, so a
 * failed payment stops the course and the newsletter the same way a cancellation does, once
 * its grace period is over.
 */
class Integrations {

	/** @var array<string, Integration> */
	private array $integrations = array();

	/**
	 * Every integration, available or not: Submex's own and those an extension adds.
	 *
	 * @return Integration[]
	 */
	public static function integrations(): array {
		/**
		 * Filter the integrations a subscription's state is routed to.
		 *
		 * @param Integration[] $integrations
		 */
		$integrations = (array) apply_filters( 'submex_integrations', array( new FluentCRM() ) );

		return array_values( array_filter( $integrations, static fn( $integration ): bool => $integration instanceof Integration ) );
	}

	/**
	 * Slugs of the integrations whose plugin is actually present.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		$slugs = array();

		foreach ( self::integrations() as $integration ) {
			if ( $integration->is_available() ) {
				$slugs[] = $integration->slug();
			}
		}

		return $slugs;
	}

	public function register(): void {
		foreach ( self::integrations() as $integration ) {
			if ( $integration->is_available() ) {
				$this->integrations[ $integration->slug() ] = $integration;
			}
		}

		if ( ! $this->integrations ) {
			return;
		}

		add_action( 'submex_subscription_activated', array( $this, 'on_activated' ), 10, 1 );
		add_action( 'submex_subscription_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'submex_subscription_cancelled', array( $this, 'on_cancelled' ), 10, 1 );
		add_action( 'submex_subscription_grace_ended', array( $this, 'on_cancelled' ), 10, 1 );

		add_action( 'submex_product_subscription_fields', array( $this, 'render_fields' ), 30 );
		add_action( 'submex_save_product_subscription_fields', array( $this, 'save_fields' ), 30 );

		foreach ( $this->integrations as $integration ) {
			if ( method_exists( $integration, 'hooks' ) ) {
				$integration->hooks();
			}
		}
	}

	/**
	 * @return array<string, Integration>
	 */
	public function all(): array {
		return $this->integrations;
	}

	public function on_activated( Subscription $subscription ): void {
		$this->apply( $subscription, true );
	}

	public function on_status_changed( Subscription $subscription, string $from, string $to ): void {
		$status = Subscription_Status::tryFrom( $to );

		if ( ! $status ) {
			return;
		}

		// Still in its grace period after a failed renewal: nothing changes until it ends.
		if ( $subscription->in_grace() ) {
			return;
		}

		$this->apply( $subscription, Subscription_Status::Active === $status || Subscription_Status::Trialling === $status );
	}

	public function on_cancelled( Subscription $subscription ): void {
		$this->apply( $subscription, false );
	}

	public function render_fields( $product ): void {
		foreach ( $this->integrations as $integration ) {
			$integration->render_fields( $product instanceof \WC_Product ? $product : null );
		}
	}

	public function save_fields( \WC_Product $product ): void {
		foreach ( $this->integrations as $integration ) {
			$integration->save_fields( $product );
		}
	}

	/**
	 * Course and list ids configured on the products this subscription bills for.
	 *
	 * @return int[]
	 */
	public static function product_ids( Subscription $subscription, string $meta_key ): array {
		$ids = array();

		foreach ( $subscription->get_items() as $item ) {
			$product = self::settings_product( $item );

			if ( ! $product ) {
				continue;
			}

			$ids = array_merge( $ids, array_map( 'absint', (array) $product->get_meta( $meta_key ) ) );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** The product an item's settings are saved on: a variation's parent, since variations do not inherit its meta. */
	public static function settings_product( \WC_Order_Item $item ): ?\WC_Product {
		$product = $item instanceof \WC_Order_Item_Product ? wc_get_product( $item->get_product_id() ) : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The ids this subscription may safely withdraw: its own, minus anything another live
	 * subscription of the same customer still grants.
	 *
	 * @return int[]
	 */
	public static function releasable_ids( Subscription $subscription, string $meta_key ): array {
		$ids         = self::product_ids( $subscription, $meta_key );
		$customer_id = $subscription->get_customer_id();

		if ( ! $ids || ! $customer_id ) {
			return $ids;
		}

		$held = array();

		foreach ( Subscription_Query::get(
			array(
				'limit'       => -1,
				'status'      => null,
				'customer_id' => $customer_id,
			)
		) as $other ) {
			if ( $other->get_id() === $subscription->get_id() ) {
				continue;
			}

			if ( self::grants_access( $other ) ) {
				$held = array_merge( $held, self::product_ids( $other, $meta_key ) );
			}
		}

		return array_values( array_diff( $ids, $held ) );
	}

	/**
	 * Active or trialling, or on hold for a failed renewal that is still in its grace period.
	 */
	public static function grants_access( Subscription $subscription ): bool {
		$status = $subscription->get_status_enum();

		return Subscription_Status::Active === $status || Subscription_Status::Trialling === $status || $subscription->in_grace();
	}

	/**
	 * @return array<int, string> post id => title
	 */
	public static function post_options( string $post_type ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return array();
		}

		$options = array();

		foreach ( get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		) as $post ) {
			$options[ (int) $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * @param array{id: string, label: string, options: array, value: array, description?: string, empty?: string} $args
	 */
	public static function render_multiselect( array $args ): void {
		$options  = (array) ( $args['options'] ?? array() );
		$selected = array_map( 'absint', (array) ( $args['value'] ?? array() ) );

		echo '<p class="form-field ' . esc_attr( $args['id'] ) . '_field">';
		echo '<label for="' . esc_attr( $args['id'] ) . '">' . esc_html( $args['label'] ) . '</label>';

		if ( ! $options ) {
			echo '<span class="description">' . esc_html( $args['empty'] ?? __( 'Nothing to choose yet.', 'submex' ) ) . '</span></p>';
			return;
		}

		echo '<select id="' . esc_attr( $args['id'] ) . '" name="' . esc_attr( $args['id'] ) . '[]" class="wc-enhanced-select" multiple="multiple" style="width:50%;">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( in_array( (int) $value, $selected, true ), true, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			echo '<span class="description">' . esc_html( $args['description'] ) . '</span>';
		}

		echo '</p>';
	}

	/**
	 * @return int[]
	 */
	public static function posted_ids( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this hook.
		$raw = isset( $_POST[ $field ] ) ? array_map( 'intval', (array) wp_unslash( $_POST[ $field ] ) ) : array();

		// Not absint(): it would launder a posted -4 into course 4 rather than discarding it.
		$ids = array_filter( array_map( 'intval', $raw ), static fn( int $id ): bool => $id > 0 );

		return array_values( array_unique( $ids ) );
	}

	private function apply( Subscription $subscription, bool $grant ): void {
		foreach ( $this->integrations as $integration ) {
			try {
				if ( $grant ) {
					$integration->grant( $subscription );
				} else {
					$integration->revoke( $subscription );
				}
			} catch ( \Throwable $e ) {
				// A third-party API failing must not abort the subscription transition itself.
				do_action( 'submex_integration_failed', $integration->slug(), $e->getMessage(), $subscription );
			}
		}
	}
}
