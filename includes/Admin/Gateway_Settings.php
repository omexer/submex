<?php

namespace Submex\Admin;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Gateway_Switch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Submex gateway's settings section, drawn and saved on its own WooCommerce → Payments screen.
 */
final class Gateway_Settings {

	// WooCommerce prefixes a row_class with wc-settings-row-; the script and stylesheet use the prefixed name.
	private const HIDDEN = 'submex-hidden';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * WooCommerce gateway id => the Submex settings section holding its fields.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		$sections = array( 'submex_paypal' => 'paypal' );

		// A Submex Pro older than 0.54 still registers its own Stripe, set up in its own section.
		if ( \Submex\Plugin::owns_stripe() ) {
			$sections = array( 'submex_stripe' => 'stripe' ) + $sections;
		}

		/**
		 * Filter the gateways whose settings live on their WooCommerce → Payments screen.
		 *
		 * @param array<string, string> $sections WooCommerce gateway id => Submex settings section.
		 */
		return (array) apply_filters( 'submex_gateway_settings_sections', $sections );
	}

	public static function gateway_of( string $section ): string {
		return (string) array_search( $section, self::sections(), true );
	}

	/**
	 * Where a section is set up: its gateway's WooCommerce screen when it has one.
	 */
	public static function url( string $section ): string {
		$gateway = self::gateway_of( $section );

		return '' === $gateway
			? Settings_Page::section_url( $section )
			: admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gateway );
	}

	/**
	 * The section's fields, less its headings and the Submex switch the gateway's own Enable already sets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( string $gateway_id ): array {
		$section = self::sections()[ $gateway_id ] ?? '';
		$page    = \Submex\Plugin::instance()->get( 'settings_page' );

		if ( '' === $section || ! $page instanceof Settings_Page ) {
			return array();
		}

		$switch = Gateway_Switch::option( $gateway_id );

		return array_values(
			array_filter(
				$page->settings_for( $section ),
				static fn( array $field ): bool => ! in_array( $field['type'] ?? '', array( 'title', 'sectionend' ), true ) && ( '' === $switch || ( $field['id'] ?? '' ) !== $switch )
			)
		);
	}

	/**
	 * @param callable(): void $woo Prints WooCommerce's own screen: heading, description, then the Enable, Title and Description table.
	 */
	public static function render( \WC_Payment_Gateway $gateway, callable $woo ): void {
		$fields = self::fields( $gateway->id );
		$values = self::values( $gateway, $fields );
		$cards  = '';
		$rows   = array();
		$when   = array();

		foreach ( $fields as $field ) {
			if ( Setup_Card::TYPE === ( $field['type'] ?? '' ) ) {
				$cards .= self::cards( $field, $values );
				continue;
			}

			$shown = Settings_Page::when_of( $field );

			if ( $shown && ! empty( $field['id'] ) ) {
				$when[ (string) $field['id'] ] = $shown;
				$field['row_class']            = Settings_Page::shown_when( $shown, $values ) ? '' : self::HIDDEN;
			}

			$rows[] = $field;
		}

		ob_start();
		$woo();
		$screen = (string) ob_get_clean();
		$table  = strpos( $screen, '<table' );

		// The card goes between WooCommerce's heading and its table, so the steps come before the fields they name.
		if ( false === $table ) {
			echo $screen . $cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Setup_Card's own escaped markup.
		} else {
			echo substr( $screen, 0, $table ) . $cards . substr( $screen, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Setup_Card's own escaped markup.
		}

		echo '<table class="form-table submex-gateway-fields">';
		\WC_Admin_Settings::output_fields( $rows );
		echo '</table>';

		if ( $when ) {
			printf( '<script type="application/json" id="submex-gateway-when">%s</script>', wp_json_encode( $when, JSON_HEX_TAG | JSON_HEX_AMP ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with tags and ampersands escaped.
		}
	}

	public static function save( \WC_Payment_Gateway $gateway ): void {
		$fields = array_values( array_filter( self::fields( $gateway->id ), static fn( array $field ): bool => ! empty( $field['id'] ) ) );

		if ( $fields ) {
			\WC_Admin_Settings::save_fields( $fields );
		}

		$switch = Gateway_Switch::option( $gateway->id );

		// Checkout off turns the gateway off too, unless subscriptions still bill through it and must keep renewing.
		if ( '' !== $switch && 'yes' !== $gateway->get_option( 'enabled', 'no' ) && ! self::still_billing( $gateway->id ) ) {
			update_option( $switch, 'no' );
		}

		$section = self::sections()[ $gateway->id ] ?? '';

		if ( '' !== $section ) {
			do_action( 'woocommerce_update_options_submex_' . $section ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own settings hook, as Submex → Settings fires it.
		}
	}

	private static function still_billing( string $gateway_id ): bool {
		$running = array( Subscription_Status::Trialling, Subscription_Status::Active, Subscription_Status::OnHold, Subscription_Status::PendingCancel );
		$query   = array(
			'payment_method' => $gateway_id,
			'status'         => array_map( static fn( Subscription_Status $status ): string => $status->value, $running ),
			'limit'          => 1,
		);

		if ( Subscription_Query::ids( $query ) ) {
			return true;
		}

		// A checkout left at the provider stays pending for good; only one still being paid for counts.
		return (bool) Subscription_Query::ids(
			array(
				'status'       => Subscription_Status::Pending->value,
				'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
			) + $query
		);
	}

	/**
	 * One card per value of the select its steps switch on, so changing the select shows the right one before saving.
	 *
	 * @param array<string, mixed>  $field
	 * @param array<string, string> $values
	 */
	private static function cards( array $field, array $values ): string {
		$keys = array();

		foreach ( (array) ( $field['steps'] ?? array() ) as $step ) {
			$keys = array_merge( $keys, array_keys( Settings_Page::when_of( (array) $step ) ) );
		}

		$keys = array_values( array_unique( $keys ) );

		if ( 1 !== count( $keys ) ) {
			return Setup_Card::markup( $field, $values );
		}

		$key     = $keys[0];
		$choices = array();

		foreach ( (array) ( $field['steps'] ?? array() ) as $step ) {
			$choices = array_merge( $choices, Settings_Page::when_of( (array) $step )[ $key ] ?? array() );
		}

		$html = '';

		foreach ( array_values( array_unique( array_map( 'strval', $choices ) ) ) as $choice ) {
			$html .= sprintf(
				'<div class="submex-gateway-card"%s data-submex-show-when="%s">%s</div>',
				( $values[ $key ] ?? '' ) === $choice ? '' : ' hidden',
				esc_attr( (string) wp_json_encode( array( $key => array( $choice ) ) ) ),
				Setup_Card::markup( array( 'id' => (string) ( $field['title'] ?? '' ) . '-' . $choice ) + $field, array( $key => $choice ) + $values )
			);
		}

		return $html;
	}

	/**
	 * Saved values by option id; the Submex switch reads as the gateway's own Enable, the one on this screen.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<string, string>
	 */
	private static function values( \WC_Payment_Gateway $gateway, array $fields ): array {
		$page   = \Submex\Plugin::instance()->get( 'settings_page' );
		$values = array();

		foreach ( $fields as $field ) {
			$id = (string) ( $field['id'] ?? '' );

			if ( '' !== $id && $page instanceof Settings_Page ) {
				$value         = $page->field_value( $field );
				$values[ $id ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		$switch = Gateway_Switch::option( $gateway->id );

		// The saved setting, not $gateway->enabled, which WooCommerce's ?toggle_enabled leaves stale.
		if ( '' !== $switch ) {
			$values[ $switch ] = 'yes' === $gateway->get_option( 'enabled', 'no' ) ? 'yes' : 'no';
		}

		return $values;
	}

	public function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which screen this is, nothing more.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'wc-settings' !== $page || 'checkout' !== $tab || ! isset( self::sections()[ $section ] ) ) {
			return;
		}

		wp_enqueue_style( 'submex-admin', SUBMEX_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'submex-admin', SUBMEX_URL . 'assets/js/admin.js', array(), Assets::version( 'assets/js/admin.js' ), true );
		wp_enqueue_script( 'submex-gateway-settings', SUBMEX_URL . 'assets/js/gateway-settings.js', array(), Assets::version( 'assets/js/gateway-settings.js' ), true );
	}
}
