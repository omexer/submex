<?php
/**
 * Every Submex admin page hosts the app around its server render, the shell and the
 * routes load on those pages only, and the Integrations, Help and detail-panel endpoints answer
 * managers only.
 *
 * @package Submex
 */

use Submex\Admin\App_Host;
use Submex\Admin\Help_Page;
use Submex\Admin\Integration_Installer;
use Submex\Admin\Integrations_Page;
use Submex\Admin\Menu;
use Submex\Admin\Page_Shell;
use Submex\Admin\Retention_Page;
use Submex\Admin\Settings_Page;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/user.php';

$plugin = \Submex\Plugin::instance();
$host   = $plugin->get( 'app_host' );

if ( ! $host instanceof App_Host ) {
	submex_test_abort( 'the app host is not registered' );
}

$users = array();
foreach ( array( 'customer', 'shop_manager' ) as $role ) {
	$users[ $role ] = wp_insert_user(
		array(
			'user_login' => 'sk_shell_' . $role . '_' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'sb-shell-' . wp_generate_password( 6, false, false ) . '@example.test',
			'role'       => $role,
		)
	);
}

$sub = new Subscription();
$sub->set_customer_id( 1 );
$sub->transition_to( Subscription_Status::Pending );
$sub->save();
$id = $sub->get_id();

$free_pages = array( Menu::SLUG, Menu::LIST_SLUG, Retention_Page::SLUG, Integrations_Page::SLUG, Help_Page::SLUG, Settings_Page::SLUG );

// ---------------------------------------------------------------- the pages the app owns

$check( 'every free page is an app page', array() === array_diff( $free_pages, App_Host::pages() ), App_Host::pages() );

$add_page = static fn( $pages ): array => array_merge( (array) $pages, array( 'submex-harness', Menu::SLUG ) );
add_filter( 'submex_app_pages', $add_page );
$pages = App_Host::pages();
remove_filter( 'submex_app_pages', $add_page );
$check( 'another plugin adds its page through the filter, without repeating ours', in_array( 'submex-harness', $pages, true ) && 1 === count( array_keys( $pages, Menu::SLUG, true ) ), $pages );

// ---------------------------------------------------------------- each page prints the host

$hosted = static function ( callable $render, array $get = array() ): string {
	$host_header = $_SERVER['HTTP_HOST'] ?? null;
	// The list table builds its paging links from the request's host, which WP-CLI has none of.
	$_SERVER['HTTP_HOST'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	wp_set_current_user( 1 );
	$_GET = $get;
	ob_start();
	$render();
	$_GET = array();
	if ( null === $host_header ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $host_header;
	}
	return (string) ob_get_clean();
};

$screens = array(
	'Overview'         => array( Menu::SLUG, array( $plugin->get( 'admin_menu' ), 'render' ), array(), '>Overview</h1>' ),
	'All subscriptions' => array( Menu::LIST_SLUG, array( $plugin->get( 'admin_menu' ), 'render_list' ), array(), 'All subscriptions' ),
	'one subscription' => array( Menu::LIST_SLUG, array( $plugin->get( 'admin_menu' ), 'render_list' ), array( 'subscription' => (string) $id ), 'Subscription #' . $id ),
	'Retention Flow'   => array( Retention_Page::SLUG, array( $plugin->get( 'retention_page' ), 'render' ), array(), 'Ask before cancelling' ),
	'Integrations'     => array( Integrations_Page::SLUG, array( $plugin->get( 'integrations_page' ), 'render' ), array(), 'Integrations' ),
	'Help'             => array( Help_Page::SLUG, array( $plugin->get( 'help_page' ), 'render' ), array(), 'Check these first' ),
	'Settings'         => array( Settings_Page::SLUG, array( $plugin->get( 'settings_page' ), 'render' ), array(), 'Settings' ),
);

foreach ( $screens as $label => list( $slug, $render, $get, $inside ) ) {
	$html   = $hosted( $render, $get );
	$prefix = '<div id="submex-app" class="submex-ui" data-page="' . $slug . '"></div><div id="submex-fallback">';
	$body   = substr( $html, strlen( $prefix ), -strlen( '</div>' ) );

	$check( "{$label} opens with the app host for its own page", str_starts_with( $html, $prefix ), substr( $html, 0, 160 ) );
	$check( "  and closes the fallback it opened", str_ends_with( rtrim( $html ), '</div>' ) && substr_count( $html, 'id="submex-app"' ) === 1, substr( $html, -80 ) );
	$check( '  with its server render inside the fallback', str_contains( $body, 'class="submex-shell"' ) && str_contains( $body, $inside ), wp_strip_all_tags( substr( $body, 0, 400 ) ) );
}

// ---------------------------------------------------------------- what loads, and where

$saved_scripts = $GLOBALS['wp_scripts'] ?? null;
$saved_styles  = $GLOBALS['wp_styles'] ?? null;
$saved_page    = $GLOBALS['plugin_page'] ?? null;

// Our two admin_enqueue_scripts callbacks, in their order; WooCommerce's own need a real screen.
$enqueue_on = static function ( ?string $page, string $hook ) use ( $plugin, $host ): array {
	global $plugin_page;

	$GLOBALS['wp_scripts'] = null;
	$GLOBALS['wp_styles']  = null;
	$plugin_page           = $page;
	$before                = did_action( 'submex_app_enqueue' );

	$plugin->get( 'admin_assets' )->enqueue( $hook );
	$host->enqueue();

	return array(
		'fired'   => did_action( 'submex_app_enqueue' ) - $before,
		'scripts' => wp_scripts(),
	);
};

foreach ( $free_pages as $slug ) {
	$result  = $enqueue_on( $slug, Menu::SLUG === $slug ? 'toplevel_page_' . $slug : 'submex_page_' . $slug );
	$scripts = $result['scripts'];

	$check( "{$slug}: the app hook fires once", 1 === $result['fired'], $result['fired'] );
	$check( '  the shell is enqueued', wp_script_is( App_Host::HANDLE, 'enqueued' ) );

	$routes_ok = true;
	foreach ( array( 'dashboard', 'subscriptions', 'integrations', 'help', 'retention' ) as $bundle ) {
		$registered = $scripts->registered[ 'submex-' . $bundle ] ?? null;
		$routes_ok  = $routes_ok && wp_script_is( 'submex-' . $bundle, 'enqueued' ) && $registered && in_array( App_Host::HANDLE, $registered->deps, true );
	}
	$check( '  every free route loads after the shell', $routes_ok );
}

$data = (string) wp_scripts()->get_data( App_Host::HANDLE, 'before' )[1];
$check(
	'the shell is told the header links, with no Upgrade',
	str_contains( $data, wp_json_encode( Page_Shell::header_links()['help'] ) ) && str_contains( $data, '"upgrade":""' ),
	$data
);

$others = array(
	'our WooCommerce settings tab'           => array( 'wc-settings', 'woocommerce_page_wc-settings', array( 'tab' => 'submex' ), true ),
	'an Submex page with no route' => array( 'submex-harness', 'submex_page_submex-harness', array(), true ),
	'another plugin\'s page'                 => array( 'some-other-plugin', 'toplevel_page_some-other-plugin', array(), false ),
	'a screen with no page'                  => array( null, 'edit.php', array(), false ),
);
foreach ( $others as $label => list( $page, $hook, $query, $loads_ui ) ) {
	$_GET   = $query;
	$result = $enqueue_on( $page, $hook );
	$_GET   = array();
	$check( "{$label}: no app hook and no shell", 0 === $result['fired'] && ! wp_script_is( App_Host::HANDLE, 'enqueued' ) && ! wp_script_is( 'submex-dashboard', 'enqueued' ) );

	// These load the shared UI, so only the page check keeps the app off them.
	if ( $loads_ui ) {
		$check( '  though the shared UI was registered there', wp_script_is( 'submex-ui', 'registered' ) );
	}
}

$GLOBALS['wp_scripts']  = $saved_scripts;
$GLOBALS['wp_styles']   = $saved_styles;
$GLOBALS['plugin_page'] = $saved_page;

// ---------------------------------------------------------------- the endpoints behind the routes

$tiles = static fn( $integrations ): array => array_merge(
	(array) $integrations,
	array(
		array( 'title' => 'Zz Shell Active', 'requires' => 'Harness', 'active' => true, 'configure_url' => 'https://example.test/configure', 'configure_label' => 'Open it', 'category' => 'Zz Harness' ),
		array( 'title' => 'Zz Shell Sold', 'active' => false, 'url' => 'https://example.test/buy' ),
		array( 'title' => 'Zz Shell Hosted', 'active' => false, 'slug' => 'zz-shell-harness', 'url' => 'https://example.test/hosted' ),
	)
);
add_filter( 'submex_admin_integrations', $tiles, 99 );

$get = static function ( string $path, int $user ): WP_REST_Response {
	wp_set_current_user( $user );
	return rest_do_request( new WP_REST_Request( 'GET', $path ) );
};

$response = $get( '/submex/v1/integrations', 1 );
$data     = $response->get_data();
$by_title = array_column( (array) ( $data['integrations'] ?? array() ), null, 'title' );

$check( 'integrations answer an administrator', 200 === $response->get_status(), $response->get_status() );
$check( '  a live one says so, with where it is set up', true === ( $by_title['Zz Shell Active']['active'] ?? null ) && 'https://example.test/configure' === $by_title['Zz Shell Active']['configure_url'] && 'Open it' === $by_title['Zz Shell Active']['configure_label'] && 'Zz Harness' === $by_title['Zz Shell Active']['category'], $by_title['Zz Shell Active'] ?? null );
$check( '  one with no category is filed under Other, and with no plugin named needs itself, as the page says', 'Other' === ( $by_title['Zz Shell Sold']['category'] ?? '' ) && 'Zz Shell Sold' === ( $by_title['Zz Shell Sold']['requires'] ?? '' ) );
$check( '  one sold elsewhere links out, with nothing to install', '' === ( $by_title['Zz Shell Sold']['install'] ?? null ) && 'https://example.test/buy' === $by_title['Zz Shell Sold']['get_url'] );
$check( '  one on WordPress.org can be installed by someone allowed to', 'zz-shell-harness' === ( $by_title['Zz Shell Hosted']['install'] ?? '' ) );
$check(
	'  through the existing installer action, with a nonce it accepts',
	Integration_Installer::ACTION === ( $data['installer']['action'] ?? '' ) && admin_url( 'admin-ajax.php' ) === $data['installer']['url'] && false !== wp_verify_nonce( (string) $data['installer']['nonce'], Integration_Installer::ACTION ),
	$data['installer'] ?? null
);
$check( '  and sorted as the page sorts them', array_values( array_map( 'strval', array_column( $data['integrations'], 'title' ) ) ) === array_values( array_map( static fn( $i ) => (string) $i['title'], $plugin->get( 'integrations_page' )->integrations() ) ) );
$check( '  a second request answers the same', $data === $get( '/submex/v1/integrations', 1 )->get_data() );

$manager      = $get( '/submex/v1/integrations', $users['shop_manager'] );
$manager_data = $manager->get_data();
$manager_tile = array_column( (array) ( $manager_data['integrations'] ?? array() ), null, 'title' )['Zz Shell Hosted'] ?? array();
$check( 'a shop manager sees them, but is offered no install and no installer nonce', 200 === $manager->get_status() && '' === ( $manager_tile['install'] ?? null ) && null === $manager_data['installer'] && 'https://example.test/hosted' === $manager_tile['get_url'], $manager_data['installer'] ?? null );

remove_filter( 'submex_admin_integrations', $tiles, 99 );

$help = $get( '/submex/v1/help', 1 );
$page = $plugin->get( 'help_page' );
$check( 'help answers an administrator with the page\'s own tiles', 200 === $help->get_status() && array_column( $page->tiles(), 'title' ) === array_column( $help->get_data()['tiles'], 'title' ) && array_column( $page->tiles(), 'url' ) === array_column( $help->get_data()['tiles'], 'url' ), $help->get_data()['tiles'] ?? null );
$check( '  and the report the page shows', Help_Page::report_text( $page->report() ) === $help->get_data()['report'] && str_contains( $help->get_data()['report'], 'Submex: ' ), $help->get_data()['report'] ?? null );

$panel = static function ( $subscription ): void {
	echo '<div class="sb-shell-harness">panel for #' . (int) $subscription->get_id() . '</div>';
};
add_action( 'submex_admin_subscription_detail', $panel );
$panels = $get( "/submex/v1/subscriptions/{$id}/panels", 1 );
$check( 'the detail panels endpoint returns what extensions draw under that subscription', 200 === $panels->get_status() && str_contains( (string) $panels->get_data()['html'], "panel for #{$id}" ), $panels->get_data() );
$check( '  and says so when there is no such subscription', 404 === $get( '/submex/v1/subscriptions/999999999/panels', 1 )->get_status() );
$check( '  and answers a shop manager, who can open that screen', 200 === $get( "/submex/v1/subscriptions/{$id}/panels", $users['shop_manager'] )->get_status() );
remove_action( 'submex_admin_subscription_detail', $panel );

foreach ( array( '/submex/v1/integrations', '/submex/v1/help', "/submex/v1/subscriptions/{$id}/panels" ) as $path ) {
	$check( "{$path} refuses a customer", 403 === $get( $path, $users['customer'] )->get_status() );
	$check( "{$path} refuses a visitor", 401 === $get( $path, 0 )->get_status() );
}

$sub->delete( true );
foreach ( $users as $user ) {
	wp_delete_user( $user );
}
wp_set_current_user( 0 );

submex_test_done( $fail );
