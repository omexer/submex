<?php
/**
 * Without WooCommerce, the notice offers to install or activate it in one click, to those allowed to.
 *
 * @package Submex
 */

use Submex\Admin\WooCommerce_Installer;

require __DIR__ . '/bootstrap.php';
require_once SUBMEX_PATH . 'includes/Admin/WooCommerce_Installer.php';

wp_set_current_user( 1 );

$file = WooCommerce_Installer::installed_file();
$check( 'finds the installed WooCommerce by its main file, whatever its folder', null !== $file && 'woocommerce.php' === basename( (string) $file ) && is_plugin_active( (string) $file ), $file );

$button = WooCommerce_Installer::button();
$check( 'installed, it offers to activate it', str_contains( $button, 'Activate WooCommerce' ) && ! str_contains( $button, 'Install' ), $button );
$check( 'through Submex\'s own handler, with a nonce', str_contains( $button, 'action=' . WooCommerce_Installer::ACTION ) && str_contains( $button, '_wpnonce=' ), $button );

$manager = wp_insert_user(
	array(
		'user_login' => 'sk_wc_installer_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'shop_manager',
	)
);
wp_set_current_user( $manager );
$check( 'someone who cannot activate plugins gets no button', '' === WooCommerce_Installer::button() );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $manager );

$hide = static fn( $plugins ) => array_filter( $plugins, static fn( $f ) => 'woocommerce.php' !== basename( $f ), ARRAY_FILTER_USE_KEY );
wp_cache_set( 'plugins', array( '' => $hide( get_plugins() ) ), 'plugins' );
$button = WooCommerce_Installer::button();
$check( 'not installed, it offers to install and activate it', null === WooCommerce_Installer::installed_file() && str_contains( $button, 'Install and activate WooCommerce' ), $button );
wp_cache_delete( 'plugins', 'plugins' );

$real     = get_plugins()[ (string) $file ];
$impostor = array(
	'Name'       => 'Aaa woocommerce.php impostor',
	'TextDomain' => 'aaa',
);
wp_cache_set( 'plugins', array( '' => array( 'aaa-copy/woocommerce.php' => $impostor ) + $hide( get_plugins() ) ), 'plugins' );
$check( 'another plugin whose main file is woocommerce.php is never taken for WooCommerce', null === WooCommerce_Installer::installed_file() );
wp_cache_set( 'plugins', array( '' => array( 'aaa-copy/woocommerce.php' => $impostor, 'renamed-wc/woocommerce.php' => $real ) + $hide( get_plugins() ) ), 'plugins' );
$check( 'a renamed WooCommerce is still found by its name', 'renamed-wc/woocommerce.php' === WooCommerce_Installer::installed_file(), WooCommerce_Installer::installed_file() );
wp_cache_set( 'plugins', array( '' => array( 'aaa-copy/woocommerce.php' => $real, 'woocommerce/woocommerce.php' => $real ) + $hide( get_plugins() ) ), 'plugins' );
$check( 'and its usual folder wins over any copy', 'woocommerce/woocommerce.php' === WooCommerce_Installer::installed_file(), WooCommerce_Installer::installed_file() );
wp_cache_delete( 'plugins', 'plugins' );

set_transient( 'submex_woocommerce_install_error', 'Plugin file does not exist.', MINUTE_IN_SECONDS );
$check( 'a failed activation is told once', 'Plugin file does not exist.' === WooCommerce_Installer::last_error() && '' === WooCommerce_Installer::last_error() );

submex_test_done( $fail );
