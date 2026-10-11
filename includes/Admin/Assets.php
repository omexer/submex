<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin stylesheet, on Submex screens only.
 *
 * Pro's screens are Submex screens, so they inherit it without importing anything - which
 * is why every class here is shared rather than namespaced per plugin.
 */
class Assets {

	/**
	 * The file's own timestamp, so an edited asset is never served from cache between releases.
	 */
	public static function version( string $relative ): string {
		$mtime = @filemtime( SUBMEX_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just falls back to the version.

		return $mtime ? SUBMEX_VERSION . '.' . $mtime : SUBMEX_VERSION;
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'submex_app_enqueue', array( $this, 'enqueue_routes' ) );
	}

	/**
	 * Marks Submex's own screens so the frame can take over the page edge. Not the
	 * WooCommerce settings tab: that screen is WooCommerce's, and has no frame.
	 *
	 * @param string $classes
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && str_contains( (string) $screen->id, Menu::SLUG )
			? trim( (string) $classes . ' submex-admin' )
			: (string) $classes;
	}

	/**
	 * @param string $hook
	 */
	public function enqueue( $hook ): void {
		if ( ! $this->is_submex_screen( (string) $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'submex-admin',
			SUBMEX_URL . 'assets/css/admin.css',
			array(),
			self::version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'submex-admin',
			SUBMEX_URL . 'assets/js/admin.js',
			array(),
			self::version( 'assets/js/admin.js' ),
			true
		);

		/**
		 * Filter the data handed to the admin script.
		 *
		 * Pro adds its own action names and nonces here rather than enqueueing a second
		 * script for a few hundred bytes.
		 *
		 * @param array $data
		 */
		$data = (array) apply_filters(
			'submex_admin_script_data',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'installAction' => current_user_can( 'install_plugins' ) ? Integration_Installer::ACTION : '',
				'installNonce'  => wp_create_nonce( Integration_Installer::ACTION ),
				'i18n'          => array(
					'install'    => __( 'Install', 'submex' ),
					'installing' => __( 'Installing…', 'submex' ),
					'done'       => __( 'Done.', 'submex' ),
					'failed'     => __( 'That did not work.', 'submex' ),
				),
			)
		);

		wp_localize_script( 'submex-admin', 'submexAdmin', $data );

		$this->register_ui();
	}

	/**
	 * Register the shared UI on every Submex screen, enqueue it on none.
	 *
	 * Registered rather than enqueued because Submex Pro's screens depend on the handle:
	 * a screen that uses it asks for it, and a screen that does not costs nothing.
	 */
	private function register_ui(): void {
		$asset = SUBMEX_PATH . 'build/ui.asset.php';

		if ( ! is_readable( $asset ) ) {
			return;
		}

		$asset = require $asset;

		wp_register_style( 'submex-ui', SUBMEX_URL . 'build/ui.css', array(), $asset['version'] );
		wp_style_add_data( 'submex-ui', 'rtl', 'replace' );

		wp_register_script( 'submex-ui', SUBMEX_URL . 'build/ui.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'submex-ui', 'submex', SUBMEX_PATH . 'languages' );
	}

	/**
	 * Every free route on every app page, so moving between them needs no page load.
	 */
	public function enqueue_routes(): void {
		foreach ( array( 'dashboard', 'subscriptions', 'integrations', 'help' ) as $bundle ) {
			$this->enqueue_bundle( $bundle );
		}
	}

	private function enqueue_bundle( string $bundle ): void {
		$asset = SUBMEX_PATH . 'build/' . $bundle . '.asset.php';

		if ( ! is_readable( $asset ) ) {
			return;
		}

		$asset  = require $asset;
		$handle = 'submex-' . $bundle;

		wp_enqueue_script(
			$handle,
			SUBMEX_URL . 'build/' . $bundle . '.js',
			array_merge( $asset['dependencies'], array( 'submex-ui', App_Host::HANDLE ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( $handle, 'submex', SUBMEX_PATH . 'languages' );
	}

	private function is_submex_screen( string $hook ): bool {
		if ( str_contains( $hook, Menu::SLUG ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		// Our own tab of the WooCommerce settings screen, and nobody else's.
		return 'woocommerce_page_wc-settings' === $hook && 'submex' === $tab;
	}
}
