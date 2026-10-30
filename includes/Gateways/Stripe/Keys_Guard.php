<?php

namespace Submex\Gateways\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro before 0.54.0 owned Stripe, and deleting it with "delete data" on removes the keys this plugin now renews with.
 */
final class Keys_Guard {

	public const OPTIONS = array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'submex_stripe_secret', 'woocommerce_submex_stripe_settings' );

	private array $kept = array();

	public function register(): void {
		add_action( 'pre_uninstall_plugin', array( $this, 'keep' ), 10, 1 );
		add_action( 'deleted_plugin', array( $this, 'restore' ), 10, 1 );
	}

	/**
	 * @param string $plugin Plugin file being uninstalled.
	 */
	public function keep( $plugin ): void {
		if ( ! self::is_old_pro( (string) $plugin ) ) {
			return;
		}

		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name, null );

			if ( null !== $value ) {
				$this->kept[ $name ] = $value;
			}
		}
	}

	/**
	 * @param string $plugin Plugin file that was deleted.
	 */
	public function restore( $plugin ): void {
		if ( ! $this->kept ) {
			return;
		}

		foreach ( $this->kept as $name => $value ) {
			if ( null === get_option( $name, null ) ) {
				update_option( $name, $value );
			}
		}

		$this->kept = array();
	}

	private static function is_old_pro( string $plugin ): bool {
		if ( 'submex-pro.php' !== basename( $plugin ) ) {
			return false;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$version = (string) ( get_plugins()[ $plugin ]['Version'] ?? '' );

		return '' !== $version && version_compare( $version, '0.54.0', '<' );
	}
}
