<?php

namespace Submex\Gateways\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Stripe REST client.
 *
 * Form-encoded, not JSON — Stripe's API takes application/x-www-form-urlencoded with
 * bracket notation for nested values.
 */
class Stripe_Client {

	private const BASE = 'https://api.stripe.com';

	public function __construct( private readonly string $secret_key ) {}

	public static function from_settings(): self {
		$test = 'yes' !== get_option( 'submex_stripe_live', 'no' );

		return new self( (string) get_option( $test ? 'submex_stripe_test_secret' : 'submex_stripe_secret', '' ) );
	}

	public function is_configured(): bool {
		return '' !== $this->secret_key;
	}

	public function is_enabled(): bool {
		return 'yes' === get_option( 'submex_stripe_enabled', 'no' ) && $this->is_configured();
	}

	/**
	 * @return array{ok: bool, status: int, body: array, error: string, code: string}
	 */
	public function post( string $path, array $params, string $idempotency_key = '' ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->secret_key,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);

		// Stripe deduplicates on this for 24h, which is the second net behind our slot ledger.
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		return $this->request( 'POST', $path, $headers, $this->encode( $params ) );
	}

	public function get( string $path ): array {
		return $this->request( 'GET', $path, array( 'Authorization' => 'Bearer ' . $this->secret_key ), null );
	}

	private function request( string $method, string $path, array $headers, ?string $body ): array {
		if ( ! $this->is_configured() ) {
			return $this->fail( 'Stripe is not configured.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
		$parsed = is_array( $parsed ) ? $parsed : array();

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'body'   => $parsed,
				'error'  => '',
				'code'   => '',
			);
		}

		return array(
			'ok'     => false,
			'status' => $status,
			'body'   => $parsed,
			'error'  => (string) ( $parsed['error']['message'] ?? 'Unexpected response from Stripe.' ),
			'code'   => (string) ( $parsed['error']['decline_code'] ?? $parsed['error']['code'] ?? '' ),
		);
	}

	/**
	 * Stripe expects nested params as foo[bar]=baz, which http_build_query produces.
	 */
	private function encode( array $params ): string {
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	private function fail( string $error ): array {
		return array(
			'ok'     => false,
			'status' => 0,
			'body'   => array(),
			'error'  => $error,
			'code'   => '',
		);
	}
}
