<?php
/**
 * Deleting a Pro from before 0.54.0, whose uninstall removed the Stripe settings it then
 * owned, leaves the keys this plugin now renews with.
 *
 * @package Submex
 */

use Submex\Gateways\Stripe\Keys_Guard;

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$was = array();
foreach ( Keys_Guard::OPTIONS as $name ) {
	$was[ $name ] = get_option( $name, null );
}
update_option( 'submex_stripe_test_secret', 'sk_test_kept' );
update_option( 'submex_stripe_enabled', 'yes' );

$dir  = WP_PLUGIN_DIR . '/submex-pro-old-' . wp_generate_password( 6, false, false );
$file = basename( $dir ) . '/submex-pro.php';
wp_mkdir_p( $dir );
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( "{$dir}/submex-pro.php", "<?php\n/**\n * Plugin Name: Submex Pro old harness\n * Version: 0.53.3\n */\n" );
file_put_contents( "{$dir}/uninstall.php", "<?php\nforeach ( array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'submex_stripe_secret', 'woocommerce_submex_stripe_settings' ) as \$o ) { delete_option( \$o ); }\n" );
// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
wp_clean_plugins_cache( false );

uninstall_plugin( $file );
do_action( 'deleted_plugin', $file, true );

$check( 'the Stripe key survives the old Pro\'s uninstall', 'sk_test_kept' === get_option( 'submex_stripe_test_secret' ), get_option( 'submex_stripe_test_secret' ) );
$check( 'and so does the switch', 'yes' === get_option( 'submex_stripe_enabled' ) );

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
unlink( "{$dir}/submex-pro.php" );
unlink( "{$dir}/uninstall.php" );
rmdir( $dir );
// phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
wp_clean_plugins_cache( false );

foreach ( $was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
