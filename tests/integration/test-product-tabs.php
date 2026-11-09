<?php
/**
 * WooCommerce's product data tabs stay as WooCommerce shows them for its own types, and also show for Submex's.
 *
 * @package Submex
 */

use Submex\Product\Product_Types;

require __DIR__ . '/bootstrap.php';
require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-product-data.php';

$tabs_of = new ReflectionMethod( WC_Meta_Box_Product_Data::class, 'get_product_data_tabs' );
$tabs_of->setAccessible( true );

remove_all_filters( 'woocommerce_product_data_tabs' );
$woo = $tabs_of->invoke( null );
( new Product_Types() )->register();
$ours = $tabs_of->invoke( null );

$restricted = static fn( array $classes ): bool => (bool) array_filter( $classes, static fn( $c ) => str_starts_with( (string) $c, 'show_if_' ) );

foreach ( $woo as $key => $tab ) {
	$before = (array) ( $tab['class'] ?? array() );
	$after  = (array) ( $ours[ $key ]['class'] ?? array() );

	if ( $restricted( $before ) ) {
		$check( "{$key}: restricted by WooCommerce, so it also shows for Submex's types", (bool) array_filter( $after, static fn( $c ) => str_starts_with( (string) $c, 'show_if_' . Product_Types::SIMPLE ) || str_starts_with( (string) $c, 'show_if_' . Product_Types::VARIABLE ) ), $after );
	} else {
		$check( "{$key}: shown for every type, so it gains no show_if class that would hide it from a simple product", ! $restricted( $after ), $after );
	}
}

$check( 'General stays on a WooCommerce simple product', ! $restricted( (array) ( $ours['general']['class'] ?? array() ) ), $ours['general'] ?? null );

submex_test_done( $fail );
