<?php

namespace Submex\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A currency's ISO 4217 minor units: what payment providers count in, whatever the store displays.
 */
final class Currency {

	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}

		return in_array( $currency, self::THREE_DECIMAL, true ) ? 3 : 2;
	}

	/**
	 * @param string|float|int $amount A decimal amount, e.g. 29.99.
	 */
	public static function minor_units( $amount, string $currency ): int {
		return Money::from_decimal( $amount, $currency, self::decimals( $currency ) )->minor();
	}
}
