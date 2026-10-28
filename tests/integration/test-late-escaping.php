<?php
/**
 * Markup built in one place and printed in another is escaped with wp_kses() as it is printed.
 * These checks prove the tag lists drop nothing real: the product panel (with Pro's rows when Pro is
 * active), WooCommerce's own settings fields, the header icons, and the product-type script.
 *
 * @package Submex
 */

use Submex\Admin\Allowed_Html;
use Submex\Admin\Settings_Page;
use Submex\Product\Product_Meta_Fields;

require __DIR__ . '/bootstrap.php';
require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

$pro   = (bool) did_action( 'submex_pro_loaded' );
$label = $pro ? 'with Pro' : 'without Pro';

/**
 * Every element as "tag[attr=value ...]", values decoded, so entity spelling cannot hide a loss.
 *
 * @return string[]
 */
$inventory = static function ( string $html ): array {
	if ( '' === trim( $html ) ) {
		return array();
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$out = array();
	foreach ( ( new DOMXPath( $doc ) )->query( '//*' ) as $el ) {
		$attrs = array();
		foreach ( $el->attributes as $attr ) {
			// wp_kses drops an empty style attribute, which never did anything.
			if ( 'style' === strtolower( $attr->name ) && '' === trim( $attr->value ) ) {
				continue;
			}
			$attrs[] = strtolower( $attr->name ) . '=' . html_entity_decode( $attr->value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		sort( $attrs );
		$out[] = strtolower( $el->nodeName ) . '[' . implode( ' ', $attrs ) . ']';
	}

	return $out;
};

$lost = static function ( string $raw, array $allowed ) use ( $inventory ): array {
	return array_values( array_diff( $inventory( $raw ), $inventory( wp_kses( $raw, $allowed ) ) ) );
};

// ---------------------------------------------------------------- product panel

echo "\n1. The product panel ({$label})\n";

wp_set_current_user( 1 );

foreach ( array( 'submex_subscription', 'submex_variable_subscription' ) as $type ) {
	$classname = WC_Product_Factory::get_product_classname( 0, $type );
	$product   = new $classname();
	$product->set_name( 'ES escaping ' . $type );
	$product->set_regular_price( '10' );
	$product->save();
	$GLOBALS['post']           = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['product_object'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	foreach ( array( Product_Meta_Fields::ACTION_PRICING, Product_Meta_Fields::ACTION_RENEWAL, Product_Meta_Fields::ACTION_BILLING, Product_Meta_Fields::ACTION_SHIPPING, Product_Meta_Fields::ACTION_MORE ) as $action ) {
		ob_start();
		do_action( $action, $product );
		$raw = (string) ob_get_clean();

		$gone = $lost( $raw, Allowed_Html::form() );
		$check( "{$type}: {$action} keeps every tag and attribute", array() === $gone, $gone );
		$check( "{$type}: {$action} carries no script tag for wp_kses to strip", ! str_contains( $raw, '<script' ), $raw );
	}

	$product->delete( true );
}

// ---------------------------------------------------------------- settings

echo "\n2. WooCommerce's own settings fields\n";

$screen = \Submex\Plugin::instance()->get( 'settings_page' );
$woo    = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$woo = $candidate;
	}
}

if ( ! $woo || ! $screen instanceof Settings_Page ) {
	submex_test_abort( 'the settings tab or screen is not registered' );
}

$ours    = array( 'checkbox', 'select', 'textarea', 'text', 'password', 'number', 'email', 'url', 'title', 'sectionend' );
$checked = 0;

foreach ( array_keys( $screen->sections() ) as $section ) {
	foreach ( (array) $woo->get_settings_for_section( (string) $section ) as $field ) {
		if ( in_array( (string) ( $field['type'] ?? 'text' ), $ours, true ) ) {
			continue;
		}

		++$checked;
		$gone = $lost( $screen->woo_markup( $field ), Allowed_Html::form() );
		$check( 'section "' . $section . '", ' . ( $field['type'] ?? '' ) . ' field ' . ( $field['id'] ?? '' ) . ' keeps every tag and attribute', array() === $gone, $gone );
	}
}

$check( 'at least one field is drawn by WooCommerce itself, so the check above ran', $checked > 0, $checked );

// ---------------------------------------------------------------- icons and the product-type script

echo "\n3. Icons and scripts\n";

ob_start();
\Submex\Admin\Page_Shell::open( 'Harness' );
\Submex\Admin\Page_Shell::close();
$shell  = (string) ob_get_clean();
$shapes = array_values( preg_grep( '/^(svg|rect|circle|path)\[/', $inventory( $shell ) ) );
$check( 'the header icons keep their shapes', in_array( 'rect[height=17 rx=3 width=17 x=3.5 y=3.5]', $shapes, true ) && in_array( 'circle[cx=12 cy=12 r=3]', $shapes, true ) && (bool) preg_grep( '/^svg\[.*viewbox=0 0 24 24/', $shapes ), $shapes );

set_current_screen( 'product' );
wp_enqueue_script( 'wc-admin-product-meta-boxes', WC()->plugin_url() . '/assets/js/admin/meta-boxes-product.js', array(), WC_VERSION, false );
// Ours only: other admin_enqueue_scripts callbacks need wp-admin functions WP-CLI does not load.
\Submex\Plugin::instance()->get( 'product_types' )->show_standard_fields();
$inline = implode( "\n", (array) wp_scripts()->get_data( 'wc-admin-product-meta-boxes', 'after' ) );
$check( 'the product-type script is added after WooCommerce\'s product script, not printed', str_contains( $inline, '"submex_subscription"' ), $inline );
$check( '  and reruns WooCommerce\'s own type handler, so an existing subscription opens with its General tab', str_contains( $inline, "$( 'select#product-type' ).trigger( 'change' )" ), $inline );
set_current_screen( 'dashboard' );


submex_test_done( $fail );
