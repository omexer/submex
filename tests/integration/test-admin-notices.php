<?php
/**
 * On Submex screens, notices where money or renewals are at risk (and feedback on what the merchant just did)
 * stay under the header; every other notice, ours included, goes in the header's bell.
 *
 * @package Submex
 */

use Submex\Admin\App_Host;
use Submex\Admin\Notices;
use Submex\Admin\Page_Shell;
use Submex\Admin\Settings_Page;
use Submex\Billing\Renewal_Tax_Repair;
use Submex\Checkout\Guest_Checkout;
use Submex\Product\Product_Types;
use Submex\Product\Variable_Subscription;

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

global $wp_filter;

$plugin = \Submex\Plugin::instance();
$own    = $plugin->get( 'admin_notices' );

if ( ! $own instanceof Notices ) {
	submex_test_abort( 'the notices service is not registered' );
}

$options = array(
	'submex_paypal_enabled'                            => 'yes',
	'submex_paypal_client_id'                          => 'client-harness',
	'submex_paypal_secret'                             => 'secret-harness',
	'submex_paypal_webhook_id'                         => '',
	'submex_guest_checkout'                            => Guest_Checkout::REQUIRE,
	'woocommerce_enable_signup_and_login_from_checkout' => 'no',
	'woocommerce_enable_checkout_login_reminder'       => 'no',
	'submex_setup_notice_dismissed'                    => null,
);
$saved = array();
foreach ( $options as $name => $value ) {
	$saved[ $name ] = get_option( $name, null );
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

$tax_cache      = get_transient( Renewal_Tax_Repair::CACHE );
$dismissed_meta = get_user_meta( 1, 'submex_dismissed_notices', true );
$saved_screen   = $GLOBALS['current_screen'] ?? null;
$saved_product  = $GLOBALS['product_object'] ?? null;
$saved_hooks    = array();
foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' ) as $hook ) {
	$saved_hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
}

$printed = static function ( callable $callback ): string {
	ob_start();
	$callback();
	return (string) ob_get_clean();
};
$marked  = static fn( string $html ): bool => 1 === preg_match( '/class="[^"]*\b' . Notices::IMPORTANT . '\b/', $html );

// ---------------------------------------------------------------- which notices are important

echo "\n1. Important notices carry the marker\n";

// The setup notices show only on our screens, and only to someone who has not dismissed them.
delete_user_meta( 1, 'submex_dismissed_notices' );
set_current_screen( 'toplevel_page_submex' );

$check( 'the shared helper keeps WordPress\'s classes and adds the marker', 'notice notice-warning is-dismissible ' . Notices::IMPORTANT === Notices::important( 'warning', true ) );
$check( 'feedback is important, and marked as feedback', str_contains( Notices::feedback( 'success' ), Notices::IMPORTANT ) && str_contains( Notices::feedback( 'success' ), Notices::FEEDBACK ) && ! str_contains( Notices::important( 'error' ), Notices::FEEDBACK ) );

$gateway = $printed( array( $plugin->get( 'gateway_notice' ), 'render' ) );
$check( 'PayPal renewals not recorded', $marked( $gateway ) && str_contains( $gateway, 'PayPal renewals will not be recorded.' ), $gateway );

update_option( 'submex_paypal_secret', '' );
$gateway = $printed( array( $plugin->get( 'gateway_notice' ), 'render' ) );
$check( 'PayPal not offered at checkout', $marked( $gateway ) && str_contains( $gateway, 'not offering a payment method at checkout' ), $gateway );
update_option( 'submex_paypal_secret', 'secret-harness' );

delete_user_meta( 1, 'submex_dismissed_notices' );
set_transient( Renewal_Tax_Repair::CACHE, array( PHP_INT_MAX ), HOUR_IN_SECONDS );
$tax = $printed( array( $plugin->get( 'tax_repair' ), 'notice' ) );
$check( 'tax added twice', $marked( $tax ) && str_contains( $tax, 'tax added twice' ) && ! str_contains( $tax, Notices::FEEDBACK ), $tax );

$guest = $printed( array( $plugin->get( 'guest_checkout' ), 'warn_if_unreachable' ) );
$check( 'customers turned away at checkout', $marked( $guest ) && str_contains( $guest, 'turned away' ), $guest );

set_current_screen( 'product' );
$GLOBALS['product_object'] = new Variable_Subscription();
$unsupported               = static fn(): bool => false;
add_filter( 'submex_variable_subscriptions_supported', $unsupported );
$variable = $printed( array( $plugin->get( 'product_types' ), 'warn_unsupported_variable' ) );
remove_filter( 'submex_variable_subscriptions_supported', $unsupported );
$check( 'a variable subscription that will never renew', $marked( $variable ) && str_contains( $variable, 'charged once and never again' ), $variable );

$_GET   = array( 'submex_test' => 'pass' );
$result = new ReflectionMethod( $plugin->get( 'admin_menu' ), 'render_test_result' );
$tested = $printed( static fn() => $result->invoke( $plugin->get( 'admin_menu' ) ) );
$_GET   = array();
$check( 'the test renewal result is feedback', $marked( $tested ) && str_contains( $tested, Notices::FEEDBACK ), $tested );

set_current_screen( 'plugins' );
$setup = $printed( array( $plugin->get( 'setup' ), 'activation_notice' ) );
$check( 'our own "Submex is active" tip is not important', '' !== $setup && ! $marked( $setup ), $setup );

// ---------------------------------------------------------------- sorting them on our screens

echo "\n2. On an Submex screen\n";

foreach ( array_keys( $saved_hooks ) as $hook ) {
	remove_all_actions( $hook );
}

$runs = array();
$note = static function ( string $who, string $html ) use ( &$runs ): callable {
	return static function () use ( $who, $html, &$runs ): void {
		$runs[] = $who;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	};
};

// Test code runs through eval(), so it reads as another plugin's; our own callback comes from a file in the plugin.
require_once __DIR__ . '/stand-ins/notice-tip.php';

// Another plugin's callbacks live outside our plugin folders; a temp file stands in for one.
$foreign_file = trailingslashit( get_temp_dir() ) . 'sb-foreign-notices-' . wp_generate_password( 8, false ) . '.php';
file_put_contents( $foreign_file, '<?php function submex_test_foreign_note( $who, $html ) { return static function () use ( $who, $html ) { $GLOBALS["submex_test_runs"][] = $who; echo $html; }; }' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
require $foreign_file;
unlink( $foreign_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
$GLOBALS['submex_test_runs'] = &$runs;

add_action( 'admin_notices', submex_test_foreign_note( 'other', '<div class="notice notice-warning"><p>Milo Subscriptions: Staging Site Detected</p></div><div class="updated"><p>A second one.</p></div>' ) );
add_action( 'admin_notices', 'submex_harness_tip', 5 );
add_action( 'admin_notices', array( $plugin->get( 'gateway_notice' ), 'render' ), 20 );
add_action( 'admin_notices', $note( 'silent', '  ' ) );
add_action( 'all_admin_notices', submex_test_foreign_note( 'core', '<div class="notice notice-error"><p>WordPress core says hello.</p></div>' ) );

$notices = new Notices();
$notices->register();
remove_action( 'in_admin_header', array( $notices, 'collect' ), 1 );
remove_action( 'admin_footer', array( $notices, 'render_leftovers' ), 99 );

set_current_screen( 'dashboard' );
$notices->collect();
$check( 'elsewhere in the admin, nothing is taken off the notice hooks', false !== has_action( 'admin_notices', 'submex_harness_tip' ) );

set_current_screen( 'toplevel_page_submex' );
$notices->collect();
$check( 'on ours, our own callbacks are taken off', false === has_action( 'admin_notices', 'submex_harness_tip' ) && false === has_action( 'admin_notices', array( $plugin->get( 'gateway_notice' ), 'render' ) ) );

$top = $printed( static fn() => do_action( 'admin_notices' ) ) . $printed( static fn() => do_action( 'all_admin_notices' ) );
$check( 'they print where WordPress puts them, untouched', str_contains( $top, '<div class="notice notice-warning"><p>Milo Subscriptions: Staging Site Detected</p></div><div class="updated"><p>A second one.</p></div>' ) && str_contains( $top, 'WordPress core says hello.' ), $top );
$check( 'and none of ours is printed there', ! str_contains( $top, 'test licence mode' ) && ! str_contains( $top, 'PayPal renewals' ), $top );
$check( 'each callback ran once, in its order', array( 'other', 'silent', 'core' ) === $runs, $runs );

$shell = $printed(
	static function () {
		Page_Shell::open( 'Harness', '', array(), '', true );
		Page_Shell::close();
	}
);
preg_match( '#<header class="submex-shell__bar">(.*?)</header>#s', $shell, $header );
$header = $header[1] ?? '';
$after  = (string) strstr( $shell, '<hr class="wp-header-end">' );

$check( 'the header has the bell, before Help', str_contains( $header, '<details class="submex-notify"' ) && strpos( $header, 'submex-notify' ) < strpos( $header, Page_Shell::header_links()['help'] ), $header );
$check( 'counting the notices put in it', str_contains( $header, 'aria-label="1 notification"' ) && str_contains( $header, '<span class="submex-notify__count" aria-hidden="true">1</span>' ), $header );
$check( 'which holds our own tip, and nothing of another plugin\'s or WordPress\'s', str_contains( $header, 'test licence mode' ) && ! str_contains( $header, 'Milo Subscriptions' ) && ! str_contains( $header, 'WordPress core says hello.' ), $header );
$check( 'but not the important one', ! str_contains( $header, 'PayPal renewals' ) );
$check( 'which stays in view, under the page heading', $marked( $after ) && str_contains( $after, 'PayPal renewals will not be recorded.' ) && 1 === substr_count( $shell, 'PayPal renewals will not be recorded.' ), $after );

$again = $printed( array( $notices, 'render_leftovers' ) ) . $printed( array( Notices::class, 'render_important' ) );
$check( 'and nothing prints a second time at the foot of the page', '' === $again, $again );

$empty = new Notices();
$empty->register();
remove_action( 'in_admin_header', array( $empty, 'collect' ), 1 );
remove_action( 'admin_footer', array( $empty, 'render_leftovers' ), 99 );
$empty->collect();
$quiet = $printed(
	static function () {
		do_action( 'admin_notices' );
		Page_Shell::open( 'Harness' );
		Page_Shell::close();
	}
);
$check( 'with nothing to tuck, there is no bell', ! str_contains( $quiet, 'submex-notify' ) && ! str_contains( $quiet, 'notification' ), $quiet );

// ---------------------------------------------------------------- the body class the stylesheet keys on

echo "\n3. The page the shell draws is marked\n";

$host         = $plugin->get( 'app_host' );
$saved_page   = $GLOBALS['plugin_page'] ?? null;
$saved_script = $GLOBALS['wp_scripts'] ?? null;
$saved_style  = $GLOBALS['wp_styles'] ?? null;
$body_on      = static function ( ?string $page ) use ( $plugin, $host ): string {
	$GLOBALS['wp_scripts']  = null;
	$GLOBALS['wp_styles']   = null;
	$GLOBALS['plugin_page'] = $page;
	$plugin->get( 'admin_assets' )->enqueue( 'submex_page_' . $page );
	$host->enqueue();
	return (string) apply_filters( 'admin_body_class', '' );
};

$check( 'an app page gets the class', str_contains( $body_on( Settings_Page::SLUG ), App_Host::BODY_CLASS ) );
$check( 'another plugin\'s page does not', ! str_contains( $body_on( 'some-other-plugin' ), App_Host::BODY_CLASS ) );

// ---------------------------------------------------------------- clean up

$GLOBALS['wp_scripts']  = $saved_script;
$GLOBALS['wp_styles']   = $saved_style;
$GLOBALS['plugin_page'] = $saved_page;
$own->register();
foreach ( $saved_hooks as $hook => $object ) {
	if ( null === $object ) {
		unset( $wp_filter[ $hook ] );
	} else {
		$wp_filter[ $hook ] = $object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
$GLOBALS['current_screen'] = $saved_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$GLOBALS['product_object'] = $saved_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
false === $tax_cache ? delete_transient( Renewal_Tax_Repair::CACHE ) : set_transient( Renewal_Tax_Repair::CACHE, $tax_cache, HOUR_IN_SECONDS );
'' === $dismissed_meta ? delete_user_meta( 1, 'submex_dismissed_notices' ) : update_user_meta( 1, 'submex_dismissed_notices', $dismissed_meta );
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
