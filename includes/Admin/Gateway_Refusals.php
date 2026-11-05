<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a payment provider last said when it refused this store's credentials.
 *
 * A customer is only told the payment could not start, so without this the reason sits in a note on one failed order.
 */
final class Gateway_Refusals {

	private const OPTION = 'submex_gateway_refusals';

	private const SHOWN_FOR = 7 * DAY_IN_SECONDS;

	/**
	 * Keep the reason only when the provider refused the credentials; a declined card or bad address is no fault of the store's.
	 *
	 * @param array{status: int, error: string} $response
	 */
	public static function note( string $gateway_id, string $title, array $response, string $section ): void {
		if ( in_array( (int) ( $response['status'] ?? 0 ), array( 401, 403 ), true ) ) {
			self::record( $gateway_id, $title, (string) $response['error'], $section );
		}
	}

	public static function record( string $gateway_id, string $title, string $reason, string $section ): void {
		$all   = self::all();
		$since = isset( $all[ $gateway_id ] ) && $all[ $gateway_id ]['reason'] === $reason ? (int) $all[ $gateway_id ]['since'] : time();

		$all[ $gateway_id ] = array(
			'title'   => $title,
			'reason'  => $reason,
			'section' => $section,
			'since'   => $since,
			'last'    => time(),
		);

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
	 * @return array<string, array{title: string, reason: string, section: string, since: int, last: int}>
	 */
	public static function all(): array {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * The ones still worth saying: a gateway switched off, removed with its plugin or not failing lately has nothing left to fix.
	 *
	 * @return array<string, array{title: string, reason: string, section: string, since: int, last: int}>
	 */
	public static function current(): array {
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		return array_filter(
			self::all(),
			static fn( array $refusal, string $id ): bool => isset( $gateways[ $id ] )
				&& 'yes' === $gateways[ $id ]->enabled
				&& time() - (int) ( $refusal['last'] ?? 0 ) < self::SHOWN_FOR,
			ARRAY_FILTER_USE_BOTH
		);
	}
}
