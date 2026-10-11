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
$add = static fn( $integrations ): array => array_merge( (array) $integrations, $tiles() );
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
wp_set_current_user( 0 );

submex_test_done( $fail );
