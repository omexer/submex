<?php
/**
 * Each Integrations tile says where that integration is set up.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';

$tiles = static fn(): array => array(
	array(
		'key'             => 'sb-harness-section',
		'title'           => 'Zz Harness Section',
		'requires'        => 'Harness',
		'active'          => true,
		'configure_url'   => 'https://example.test/wp-admin/admin.php?page=submex-settings&section=harness',
		'configure_label' => 'Open harness settings',
	),
	array(
		'key'           => 'sb-harness-default',
		'title'         => 'Zz Harness Default',
		'requires'      => 'Harness',
		'active'        => true,
		'configure_url' => 'https://example.test/wp-admin/admin.php?page=submex-settings&section=default',
	),
	array(
		'key'      => 'sb-harness-product',
		'title'    => 'Zz Harness Product',
		'requires' => 'Harness',
		'active'   => false,
		'url'      => 'https://example.test/get-harness',
		'hint'     => 'Set per product in the <em>harness</em>.',
	),
);
$add   = static fn( $integrations ): array => array_merge( (array) $integrations, $tiles() );
add_filter( 'submex_admin_integrations', $add, 99 );

$tile_html = static function ( string $title ): string {
	wp_set_current_user( 1 );
	ob_start();
	( new \Submex\Admin\Integrations_Page() )->render();
	$html = (string) ob_get_clean();
	$at   = strpos( $html, '>' . $title . '<' );
	if ( false === $at ) {
		return '';
	}
	$start = strrpos( substr( $html, 0, $at ), '<div class="submex-tile">' );
	$end   = strpos( $html, '<div class="submex-tile">', $at );
	return substr( $html, (int) $start, false === $end ? null : $end - (int) $start );
};

$section = $tile_html( 'Zz Harness Section' );
$check( 'a tile with a settings page links to it', str_contains( $section, 'href="https://example.test/wp-admin/admin.php?page=submex-settings&#038;section=harness"' ), $section );
$check( 'under the label it was given', str_contains( $section, '>Open harness settings</a>' ), $section );
$default = $tile_html( 'Zz Harness Default' );
$check( 'without a label the link says Settings', str_contains( $default, '>Settings</a>' ), $default );
$product = $tile_html( 'Zz Harness Product' );
$check( 'a tile set up per product says so, as text', str_contains( $product, 'Set per product in the &lt;em&gt;harness&lt;/em&gt;.' ), $product );
$check( 'and has no settings link', ! str_contains( $product, 'page=submex-settings' ), $product );
$check( 'while its get-it link is still there', str_contains( $product, 'https://example.test/get-harness' ), $product );

remove_filter( 'submex_admin_integrations', $add, 99 );

// Install, Activate now, or nothing, from where the tile's plugin is on this site.
$folder = WP_PLUGIN_DIR . '/sb-harness-plugin';
$file   = 'sb-harness-plugin/sb-harness-plugin.php';
wp_mkdir_p( $folder );
file_put_contents( WP_PLUGIN_DIR . '/' . $file, "<?php\n/**\n * Plugin Name: Submex Harness Plugin\n */\n" );
wp_clean_plugins_cache( false );

$states = static fn( $integrations ): array => array_merge(
	(array) $integrations,
	array(
		array(
			'key'      => 'sb-harness-inactive',
			'title'    => 'Zz Harness Inactive',
			'requires' => 'Harness',
			'slug'     => 'sb-harness-plugin',
			'url'      => 'https://example.test/get-inactive',
			'active'   => false,
			'category' => 'Zz Harness',
		),
		array(
			'key'      => 'sb-harness-missing',
			'title'    => 'Zz Harness Missing',
			'requires' => 'Harness',
			'slug'     => 'sb-harness-not-here',
			'active'   => false,
			'category' => 'Zz Harness',
		),
		array(
			'key'            => 'sb-harness-first',
			'title'          => 'Zz Harness First',
			'requires'       => 'Harness',
			'active'         => true,
			'category'       => 'Zz First',
			'category_order' => 0,
		),
	)
);
add_filter( 'submex_admin_integrations', $states, 99 );

$rest = static function (): array {
	wp_set_current_user( 1 );
	$data = ( new \Submex\Rest\Integrations_Controller( new \Submex\Admin\Integrations_Page() ) )->integrations()->get_data();
	return array_column( $data['integrations'], null, 'title' );
};

$check( 'the installed plugin is seen as inactive', \Submex\Admin\Integration_Installer::STATE_INACTIVE === \Submex\Admin\Integration_Installer::state( 'sb-harness-plugin' ) );
$inactive = $tile_html( 'Zz Harness Inactive' );
$check( 'an installed, inactive plugin offers Activate now', str_contains( $inactive, 'data-activate="1">Activate now</button>' ), $inactive );
$check( 'and not a link to buy what is already here', ! str_contains( $inactive, 'get-inactive' ), $inactive );
$missing = $tile_html( 'Zz Harness Missing' );
$check( 'a missing plugin offers Install', str_contains( $missing, 'data-slug="sb-harness-not-here">Install</button>' ), $missing );

$items = $rest();
$check( 'the app is told to activate', true === $items['Zz Harness Inactive']['activate'] && 'sb-harness-plugin' === $items['Zz Harness Inactive']['install'] && '' === $items['Zz Harness Inactive']['get_url'], $items['Zz Harness Inactive'] );
$check( 'and to install what is missing', false === $items['Zz Harness Missing']['activate'] && 'sb-harness-not-here' === $items['Zz Harness Missing']['install'], $items['Zz Harness Missing'] );

$order = array_values( array_unique( array_column( ( new \Submex\Admin\Integrations_Page() )->integrations(), 'category' ) ) );
$check( 'a group with a lower category_order comes first', 'Zz First' === ( $order[0] ?? '' ), $order );

activate_plugin( $file );
$items = $rest();
$check( 'an active plugin whose integration is not connected offers nothing to click', '' === $items['Zz Harness Inactive']['install'] && false === $items['Zz Harness Inactive']['activate'], $items['Zz Harness Inactive'] );
deactivate_plugins( $file, true );

remove_filter( 'submex_admin_integrations', $states, 99 );
wp_delete_file( WP_PLUGIN_DIR . '/' . $file );
rmdir( $folder );
wp_clean_plugins_cache( false );
wp_set_current_user( 0 );

submex_test_done( $fail );
