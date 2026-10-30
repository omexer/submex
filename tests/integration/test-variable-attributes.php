<?php
/**
 * A new attribute on a variable subscription is used for variations, as on a variable product.
 *
 * @package Submex
 */

use Submex\Product\Product_Types;

require __DIR__ . '/bootstrap.php';

$check( 'on a variable subscription', 1 === (int) apply_filters( 'woocommerce_attribute_default_is_variation', 0, Product_Types::VARIABLE ) );
$check( 'but not on a simple subscription', 0 === (int) apply_filters( 'woocommerce_attribute_default_is_variation', 0, Product_Types::SIMPLE ) );

submex_test_done( $fail );
