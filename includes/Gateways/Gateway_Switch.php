<?php

namespace Submex\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One on/off switch for a gateway that has two: WooCommerce's, under Payments, which the
 * checkout reads, and Submex's own, which renewals read. Turning either one changes the other.
 */
final class Gateway_Switch {

	private const RECONCILED = 'submex_gateway_switches_reconciled';

	/**
	 * @var array<string, string> WooCommerce gateway ID => Submex option.
	 */
	private static array $links = array();

	private static bool $syncing = false;

	public static function link( string $gateway_id, string $option ): void {
		if ( isset( self::$links[ $gateway_id ] ) ) {
			return;
		}

		self::$links[ $gateway_id ] = $option;
		$settings                   = self::settings_name( $gateway_id );

		add_action( 'add_option_' . $settings, static fn( $name, $value ) => self::to_option( $option, $value ), 10, 2 );
		add_action( 'update_option_' . $settings, static fn( $old, $value ) => self::to_option( $option, $value ), 10, 2 );
		add_action( 'add_option_' . $option, static fn( $name, $value ) => self::to_settings( $settings, $value ), 10, 2 );
		add_action( 'update_option_' . $option, static fn( $old, $value ) => self::to_settings( $settings, $value ), 10, 2 );

		self::reconcile_once( $gateway_id, $option );
	}

	/**
	 * Before the switches were joined they could disagree; a gateway on in either place stays on.
	 */
	public static function reconcile( string $gateway_id, string $option ): void {
		$settings = (array) get_option( self::settings_name( $gateway_id ), array() );

		if ( 'yes' === ( $settings['enabled'] ?? 'no' ) || 'yes' === get_option( $option, 'no' ) ) {
			self::to_option( $option, array( 'enabled' => 'yes' ) );
			self::to_settings( self::settings_name( $gateway_id ), 'yes' );
		}
	}

	public static function settings_name( string $gateway_id ): string {
		return 'woocommerce_' . $gateway_id . '_settings';
	}

	private static function reconcile_once( string $gateway_id, string $option ): void {
		$done = (array) get_option( self::RECONCILED, array() );

		if ( in_array( $gateway_id, $done, true ) ) {
			return;
		}

		self::reconcile( $gateway_id, $option );

		$done[] = $gateway_id;
		update_option( self::RECONCILED, $done, true );
	}

	/**
	 * @param mixed $settings The gateway's WooCommerce settings.
	 */
	private static function to_option( string $option, $settings ): void {
		$on = is_array( $settings ) && 'yes' === ( $settings['enabled'] ?? 'no' ) ? 'yes' : 'no';

		if ( self::$syncing || get_option( $option, 'no' ) === $on ) {
			return;
		}

		self::$syncing = true;
		update_option( $option, $on );
		self::$syncing = false;
	}

	/**
	 * @param mixed $value The Submex option.
	 */
	private static function to_settings( string $settings_name, $value ): void {
		$on       = 'yes' === $value ? 'yes' : 'no';
		$settings = (array) get_option( $settings_name, array() );

		if ( self::$syncing || ( $settings['enabled'] ?? 'no' ) === $on ) {
			return;
		}

		$settings['enabled'] = $on;

		self::$syncing = true;
		update_option( $settings_name, $settings );
		self::$syncing = false;
	}
}
