<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a payment provider last said when it refused this store.
 *
 * A customer is only told the payment could not start, so without this the reason sits in a note on one failed order.
 */
final class Gateway_Refusals {

	private const OPTION = 'submex_gateway_refusals';

	public static function record( string $gateway_id, string $title, string $reason, string $section ): void {
		$all                = self::all();
		$all[ $gateway_id ] = compact( 'title', 'reason', 'section' );

		update_option( self::OPTION, $all, false );
	}

	public static function clear( string $gateway_id ): void {
		$all = self::all();

		if ( isset( $all[ $gateway_id ] ) ) {
			unset( $all[ $gateway_id ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * @return array<string, array{title: string, reason: string, section: string}>
	 */
	public static function all(): array {
		return (array) get_option( self::OPTION, array() );
	}
}
