<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and activates an integration's plugin from the Integrations screen.
 *
 * Saves the round trip to Plugins, search, install, activate, come back - which is four
 * screens to answer a question the Integrations screen has already asked.
 *
 * Every guard WordPress puts around installing code applies here and is checked
 * explicitly: the capability, the nonce, and that the slug is one we actually offer.
 * Nothing arbitrary can be installed through this.
 */
class Integration_Installer {

	public const ACTION = 'submex_install_integration';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		check_ajax_referer( self::ACTION );

		// install_plugins and activate_plugins are separate capabilities on purpose, and a
		// site can grant one without the other.
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to install plugins.', 'submex' ) ), 403 );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

		if ( '' === $slug || ! in_array( $slug, $this->allowed(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not an integration Submex offers.', 'submex' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$file = $this->installed_file( $slug );

		if ( ! $file ) {
			$file = $this->install( $slug );
		}

		if ( is_wp_error( $file ) ) {
			wp_send_json_error( array( 'message' => $file->get_error_message() ), 500 );
		}

		$activated = activate_plugin( $file );

		if ( is_wp_error( $activated ) ) {
			wp_send_json_error( array( 'message' => $activated->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Installed and activated.', 'submex' ) ) );
	}

	/**
	 * @return string[]
	 */
	private function allowed(): array {
		$slugs = array();

		foreach ( (array) apply_filters( 'submex_admin_integrations', array() ) as $integration ) {
			$slug = (string) ( $integration['slug'] ?? '' );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * @return string|\WP_Error
	 */
	private function install( string $slug ) {
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		// plugins_api returns an object or an array depending on the filters a site has on
		// it; both shapes carry the same key.
		$fields   = (array) $api;
		$download = isset( $fields['download_link'] ) ? (string) $fields['download_link'] : '';

		if ( '' === $download ) {
			return new \WP_Error( 'submex_no_download', __( 'WordPress.org did not offer a download for that plugin.', 'submex' ) );
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $download );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new \WP_Error( 'submex_install_failed', __( 'WordPress could not install that plugin.', 'submex' ) );
		}

		$file = $this->installed_file( $slug );

		return $file ?: new \WP_Error( 'submex_install_missing', __( 'The plugin installed but could not be found afterwards.', 'submex' ) );
	}

	private function installed_file( string $slug ): string {
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( (string) $file ) === $slug ) {
				return (string) $file;
			}
		}

		return '';
	}
}
