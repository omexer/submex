<?php

namespace Submex\Admin;

use Submex\Gateways\Gateway_Switch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a gateway's Submex settings (renewal switch, keys, setup card) on that gateway's own
 * WooCommerce payment settings page, as a form of its own that Submex → Settings saves.
 *
 * The settings themselves are unchanged: the same section, the same options, saved through
 * Settings_Page as before. Only where they are shown moves, and every link to the old Settings
 * section follows them there (Settings_Page::section_url()).
 *
 * Extensions add panels through the `submex_gateway_settings_panels` filter, keyed by the
 * Submex settings section:
 *
 *     'square' => array(
 *         'gateways' => array( 'square_credit_card' ), // WooCommerce gateway ids whose page shows it.
 *         'tab'      => '',                            // Or a settings tab of its own, e.g. 'mollie_settings'.
 *         'url'      => '',                            // Where links to the section go; defaults to the first page above.
 *                                                    // A panel with only a url has no page yet: links go there.
 *     )
 */
final class Gateway_Panel {

	public const FILTER = 'submex_gateway_settings_panels';

	private const CHECKOUT = 'checkout';

	public function __construct( private readonly Settings_Page $settings ) {}

	public function register(): void {
		// Late enough that every extension's panels are in, early enough for WooCommerce's save on wp_loaded.
		add_action( 'wp_loaded', array( $this, 'hook_tabs' ), 1 );
	}

	/**
	 * @return array<string, array{gateways: string[], tab: string, url: string}>
	 */
	public static function panels(): array {
		/**
		 * Filter the Submex settings sections drawn on WooCommerce payment gateway pages.
		 *
		 * @param array<string, array{gateways?: string[], tab?: string, url?: string}> $panels Keyed by Submex settings section.
		 */
		$panels = (array) apply_filters( self::FILTER, array() );
		$clean  = array();

		foreach ( $panels as $section => $panel ) {
			$gateways = array_values( array_filter( array_map( 'strval', (array) ( $panel['gateways'] ?? array() ) ) ) );
			$tab      = sanitize_key( (string) ( $panel['tab'] ?? '' ) );

			$url = (string) ( $panel['url'] ?? '' );

			// Nothing to draw on and nowhere to send links: still a section of Submex → Settings.
			if ( ! $gateways && '' === $tab && '' === $url ) {
				continue;
			}

			$clean[ (string) $section ] = array(
				'gateways' => $gateways,
				'tab'      => $tab,
				'url'      => $url,
			);
		}

		return $clean;
	}

	/**
	 * Where a Submex settings section now lives, or null while it is still on Submex → Settings.
	 */
	public static function url_of( string $section ): ?string {
		$panel = self::panels()[ $section ] ?? null;

		if ( ! $panel ) {
			return null;
		}

		if ( '' !== $panel['url'] ) {
			return $panel['url'];
		}

		if ( '' !== $panel['tab'] ) {
			return admin_url( 'admin.php?page=wc-settings&tab=' . $panel['tab'] );
		}

		return $panel['gateways']
			? admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( $panel['gateways'][0] ) )
			: admin_url( 'admin.php?page=' . Integrations_Page::SLUG );
	}

	public function hook_tabs(): void {
		$tabs = array( self::CHECKOUT => true );

		foreach ( self::panels() as $panel ) {
			if ( '' !== $panel['tab'] ) {
				$tabs[ $panel['tab'] ] = true;
			}
		}

		$this->hide_mirrored_enable();

		foreach ( array_keys( $tabs ) as $tab ) {
			// After WooCommerce's form, as a form of its own: some providers (Square, WooPayments) draw
			// their settings in their own app, hide what follows it inside the form, and save without
			// WooCommerce's Save changes.
			add_action( 'woocommerce_after_settings_' . $tab, array( $this, 'render' ) );
		}
	}

	/**
	 * A gateway whose Enable Submex mirrors (Gateway_Switch) would show two switches for one
	 * thing on its page; WooCommerce's is left off, so the panel's is the one.
	 */
	private function hide_mirrored_enable(): void {
		$section = self::current();

		if ( '' === $section ) {
			return;
		}

		foreach ( self::panels()[ $section ]['gateways'] as $gateway ) {
			if ( Gateway_Switch::is_linked( $gateway ) ) {
				add_filter(
					'woocommerce_settings_api_form_fields_' . $gateway,
					static function ( $fields ) {
						unset( $fields['enabled'] );
						return $fields;
					}
				);
			}
		}
	}

	/**
	 * The section to draw on the page being shown or saved, if any.
	 */
	public static function current(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- routing only.
		$tab     = isset( $_REQUEST['tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['tab'] ) ) : '';
		$section = isset( $_REQUEST['section'] ) ? sanitize_key( wp_unslash( $_REQUEST['section'] ) ) : '';
		// phpcs:enable

		if ( '' === $tab ) {
			return '';
		}

		foreach ( self::panels() as $id => $panel ) {
			if ( '' !== $panel['tab'] ) {
				// A tab of its own shows the panel on its first page, not on every sub-page.
				if ( $tab === $panel['tab'] && '' === $section ) {
					return $id;
				}

				continue;
			}

			if ( self::CHECKOUT === $tab && '' !== $section && in_array( $section, array_map( 'strtolower', $panel['gateways'] ), true ) ) {
				return $id;
			}
		}

		return '';
	}

	public function render(): void {
		$section = self::current();

		if ( '' === $section || ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		echo '<div class="submex-gateway-panel" id="submex-gateway-panel">';
		printf( '<h2 class="submex-gateway-panel__title">%s</h2>', esc_html__( 'Submex subscriptions', 'submex' ) );
		echo '<p class="submex-gateway-panel__lede">' . esc_html__( 'How Submex renews subscriptions paid this way. These settings have their own Save button.', 'submex' ) . '</p>';
		$this->settings->render_panel_form( $section );
		echo '</div>';
	}
}
