<?php

namespace Submex\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin PayPal REST client: OAuth token caching and JSON requests.
 *
 * Deliberately not a full SDK. Everything Submex needs is four endpoints, and a vendored
 * SDK would be the only production dependency in the whole free plugin.
 */
class PayPal_Client {

	private const LIVE    = 'https://api-m.paypal.com';
	private const SANDBOX = 'https://api-m.sandbox.paypal.com';

	private const TOKEN_TRANSIENT = 'submex_paypal_token';

	public function __construct(
		private readonly string $client_id,
		private readonly string $secret,
		private readonly bool $sandbox = true
	) {}

	public static function from_settings(): self {
		return new self(
			(string) get_option( 'submex_paypal_client_id', '' ),
			(string) get_option( 'submex_paypal_secret', '' ),
			'yes' !== get_option( 'submex_paypal_live', 'no' )
		);
	}

	public function is_configured(): bool {
		return '' !== $this->client_id && '' !== $this->secret;
	}

	public function is_enabled(): bool {
		return 'yes' === get_option( 'submex_paypal_enabled', 'no' ) && $this->is_configured();
	}

	public function base(): string {
		return $this->sandbox ? self::SANDBOX : self::LIVE;
	}

	/**
	 * @return array{ok: bool, status: int, body: array, error: string}
	 */
	public function post( string $path, array $payload, array $headers = array() ): array {
		return $this->request( 'POST', $path, $payload, $headers );
	}

	public function get( string $path ): array {
		return $this->request( 'GET', $path, null );
	}

	private function request( string $method, string $path, ?array $payload, array $headers = array() ): array {
		$token = $this->access_token();

		if ( '' === $token ) {
			return $this->fail( 0, 'Could not authenticate with PayPal. Check the API credentials.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				$headers
			),
		);

		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $this->base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $this->fail( 0, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : array();

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'body'   => $body,
				'error'  => '',
			);
		}

		return array(
			'ok'     => false,
			'status' => $status,
			'body'   => $body,
			'error'  => $this->error_message( $body ),
		);
	}

	/**
	 * PayPal buries the useful part of an error in details[0].
	 */
	private function error_message( array $body ): string {
		if ( isset( $body['details'][0]['description'] ) ) {
			return (string) $body['details'][0]['description'];
		}
		if ( isset( $body['message'] ) ) {
			return (string) $body['message'];
		}

		return __( 'Unexpected response from PayPal.', 'submex' );
	}

	private function fail( int $status, string $error ): array {
		return array(
			'ok'     => false,
			'status' => $status,
			'body'   => array(),
			'error'  => $error,
		);
	}

	/**
	 * Cached OAuth token. PayPal tokens last ~9 hours; we re-fetch a minute early.
	 */
	private function access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT . '_' . md5( $this->client_id . $this->base() ) );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return '';
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 300 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT . '_' . md5( $this->client_id . $this->base() ), $body['access_token'], $ttl );

		return (string) $body['access_token'];
	}
}
