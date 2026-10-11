<?php

namespace Submex\Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A subscription sold in variations, each free to bill on its own schedule.
 */
class Variable_Subscription extends \WC_Product_Variable {

	public function get_type() {
		return Product_Types::VARIABLE;
	}
}
