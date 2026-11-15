<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and activates WooCommerce in one click when Submex has nothing to run on.
 */
final class WooCommerce_Installer {

	public const ACTION = 'submex_install_woocommerce';

	private const ERROR = 'submex_woocommerce_install_error';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The installed WooCommerce: its usual folder first, else a renamed one that is WooCommerce by name, never just any woocommerce.php.
	 */
	public static function installed_file(): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		if ( isset( $plugins['woocommerce/woocommerce.php'] ) ) {
			return 'woocommerce/woocommerce.php';
		}

		foreach ( $plugins as $file => $data ) {
			if ( 'woocommerce.php' === basename( $file ) && 'WooCommerce' === ( $data['Name'] ?? '' ) && 'woocommerce' === ( $data['TextDomain'] ?? '' ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * The button for the notice, or nothing for someone who may not use it.
	 */
	public static function button(): string {
		$installed = null !== self::installed_file();

		if ( ! current_user_can( $installed ? 'activate_plugins' : 'install_plugins' ) ) {
			return '';
		}

		return sprintf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ),
			$installed ? esc_html__( 'Activate WooCommerce', 'submex' ) : esc_html__( 'Install and activate WooCommerce', 'submex' )
		);
	}

	/**
	 * Why the last attempt failed, shown once.
	 */
	public static function last_error(): string {
		$error = (string) get_transient( self::ERROR );

		if ( '' !== $error ) {
			delete_transient( self::ERROR );
		}

		return $error;
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );

		$file = self::installed_file();

		if ( null === $file ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_die( esc_html__( 'You are not allowed to install plugins.', 'submex' ), '', array( 'response' => 403 ) );
			}

			$file = self::install();

			// Without direct file access WordPress has to ask for credentials, which only its own install screen can do.
			if ( null === $file ) {
				wp_safe_redirect( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' ) );
				exit;
			}
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to activate plugins.', 'submex' ), '', array( 'response' => 403 ) );
		}

		$result = activate_plugin( $file );

		if ( is_wp_error( $result ) ) {
			set_transient( self::ERROR, $result->get_error_message(), MINUTE_IN_SECONDS );
			wp_safe_redirect( admin_url( 'plugins.php' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-admin' ) );
		exit;
	}

	private static function install(): ?string {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return null;
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => 'woocommerce',
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return null;
		}

		$result = ( new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() ) )->install( $api->download_link );

		if ( true !== $result ) {
			return null;
		}

		wp_clean_plugins_cache();

		return self::installed_file();
	}
}
