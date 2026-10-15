<?php
/**
 * Plugin Name: Submex Subscription Billing for WooCommerce
 * Plugin URI:  https://github.com/omexer/submex
 * Description: Turn any WooCommerce product into a subscription and let it bill itself.
 * Version:     1.1.1
 * Author:      Omexer
 * Author URI:  https://omexer.com
 * Text Domain: submex
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 *
 * This file must stay parseable on old PHP so the version guard can run instead of
 * a white screen. No typed properties, no enums, no match, above the bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SUBMEX_VERSION', '1.1.1' );
define( 'SUBMEX_FILE', __FILE__ );
define( 'SUBMEX_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUBMEX_URL', plugin_dir_url( __FILE__ ) );
define( 'SUBMEX_MIN_PHP', '8.1' );
define( 'SUBMEX_MIN_WP', '6.5' );
define( 'SUBMEX_MIN_WC', '8.0' );

/**
 * Collect unmet requirements. Empty array means we are good to boot.
 */
function submex_unmet_requirements() {
	$unmet = array();

	if ( version_compare( PHP_VERSION, SUBMEX_MIN_PHP, '<' ) ) {
		$unmet[] = sprintf( 'PHP %s or newer (this site runs %s)', SUBMEX_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( get_bloginfo( 'version' ), SUBMEX_MIN_WP, '<' ) ) {
		$unmet[] = sprintf( 'WordPress %s or newer', SUBMEX_MIN_WP );
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		$unmet[] = 'WooCommerce to be installed and active';
	} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, SUBMEX_MIN_WC, '<' ) ) {
		$unmet[] = sprintf( 'WooCommerce %s or newer', SUBMEX_MIN_WC );
	}

	return $unmet;
}

/**
 * Tell WooCommerce we speak HPOS and Cart/Checkout blocks.
 *
 * Declared before the requirement check so the flags are correct even when we refuse to boot.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SUBMEX_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SUBMEX_FILE, true );
	}
);

/**
 * Flush rewrites once so the My Account subscriptions endpoint resolves.
 */
register_activation_hook(
	__FILE__,
	function () {
		update_option( 'submex_flush_rewrites', 1, false );
	}
);

add_action(
	'init',
	function () {
		if ( get_option( 'submex_flush_rewrites' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'submex_flush_rewrites' );
		}
	},
	99
);

add_action(
	'plugins_loaded',
	function () {
		$unmet = submex_unmet_requirements();

		if ( ! empty( $unmet ) ) {
			add_action(
				'admin_notices',
				function () use ( $unmet ) {
					echo '<div class="notice notice-error submex-notice--important"><p><strong>Submex</strong> needs ' . esc_html( implode( ', and ', $unmet ) ) . '. It has not been loaded.</p></div>';
				}
			);
			return;
		}

		require_once SUBMEX_PATH . 'includes/autoload.php';

		\Submex\Plugin::instance()->boot();
	},
	10
);
