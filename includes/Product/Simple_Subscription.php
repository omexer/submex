<?php

namespace Submex\Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A subscription product.
 *
 * Extends WC_Product_Simple deliberately: everything WooCommerce does for a simple
 * product - pricing, tax, stock, shipping, the add-to-cart flow - is correct here, and
 * the only difference is that buying it starts a schedule.
 */
class Simple_Subscription extends \WC_Product_Simple {

	public function get_type() {
		return Product_Types::SIMPLE;
	}

	public function is_purchasable() {
		return parent::is_purchasable();
	}
}
