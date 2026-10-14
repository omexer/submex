<?php

namespace Submex\Lifecycle;

use Submex\Admin\Allowed_Html;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Ask before cancelling": whether the reasons screen runs, what it says, and what customers see afterwards.
 */
final class Retention_Flow {

	public const OPTION          = 'submex_retention_flow';
	public const DISPLAY_OPTION  = 'submex_retention_display';
	public const MESSAGES_OPTION = 'submex_retention_messages';

	public const MAX_TITLE  = 120;
	public const MAX_BUTTON = 40;
	public const ALIGNS     = array( 'left', 'center', 'right' );
	public const SIZES      = array(
		'title_size'       => array( 14, 32 ),
		'description_size' => array( 12, 20 ),
	);

	public const MESSAGE_CANCEL       = 'cancel';
	public const MESSAGE_PAUSE        = 'pause';
	public const MESSAGE_OFFER_ACCEPT = 'offer_accept';

	public static function is_on(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	public static function set( bool $on ): void {
		update_option( self::OPTION, $on ? 'yes' : 'no' );
	}

	/**
	 * @return array{title: string, description: string, keep_text: string, continue_text: string, title_size: int, description_size: int, align: string}
	 */
	public static function display_defaults(): array {
		return array(
			'title'            => __( "Before you go, what's the main reason?", 'submex' ),
			'description'      => __( 'Please tell us why you are cancelling. Your feedback helps us improve.', 'submex' ),
			'keep_text'        => __( 'Keep Subscription', 'submex' ),
			'continue_text'    => __( 'Continue canceling', 'submex' ),
			'title_size'       => 18,
			'description_size' => 14,
			'align'            => 'center',
		);
	}

	/**
	 * @return array{title: string, description: string, keep_text: string, continue_text: string, title_size: int, description_size: int, align: string}
	 */
	public static function display(): array {
		$stored  = (array) get_option( self::DISPLAY_OPTION, array() );
		$cleaned = self::clean_display( array_intersect_key( $stored, self::display_defaults() ) );

		return array_merge( self::display_defaults(), $cleaned instanceof \WP_Error ? array() : $cleaned );
	}

	/**
	 * Saves the keys given; the rest keep their current value.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>|\WP_Error The whole display, as saved.
	 */
	public static function save_display( array $values ) {
		$cleaned = self::clean_display( array_intersect_key( $values, self::display_defaults() ) );

		if ( $cleaned instanceof \WP_Error ) {
			return $cleaned;
		}

		$display = array_merge( self::display(), $cleaned );
		update_option( self::DISPLAY_OPTION, $display, false );

		return $display;
	}

	/**
	 * The confirmation messages in use: the cancel one always, others only once an extension uses them.
	 *
	 * @return string[]
	 */
	public static function message_types(): array {
		/**
		 * Filter which confirmation messages the store can edit. Free uses only cancel.
		 *
		 * @param string[] $types Any of cancel, pause, offer_accept.
		 */
		$types = (array) apply_filters( 'submex_retention_message_types', array( self::MESSAGE_CANCEL ) );

		return array_values( array_intersect( array_keys( self::message_defaults() ), array_map( 'strval', $types ) ) );
	}

	/**
	 * @return array<string, array{enabled: bool, title: string, description: string, button_text: string}>
	 */
	public static function message_defaults(): array {
		return array(
			self::MESSAGE_CANCEL       => array(
				'enabled'     => true,
				'title'       => __( 'Your subscription has been cancelled', 'submex' ),
				'description' => __( "You won't be charged again.", 'submex' ),
				'button_text' => __( 'Close', 'submex' ),
			),
			self::MESSAGE_PAUSE        => array(
				'enabled'     => true,
				'title'       => __( 'Your subscription is paused', 'submex' ),
				/* translators: {resume_date} is replaced with the date; keep it as it is. */
				'description' => __( 'It resumes on {resume_date}.', 'submex' ),
				'button_text' => __( 'Close', 'submex' ),
			),
			self::MESSAGE_OFFER_ACCEPT => array(
				'enabled'     => true,
				'title'       => __( "You're all set", 'submex' ),
				'description' => __( 'Your offer has been applied to your subscription.', 'submex' ),
				'button_text' => __( 'Close', 'submex' ),
			),
		);
	}

	/**
	 * @return array{enabled: bool, title: string, description: string, button_text: string}|null
	 */
	public static function message( string $type ): ?array {
		$defaults = self::message_defaults();

		if ( ! isset( $defaults[ $type ] ) ) {
			return null;
		}

		$stored  = (array) get_option( self::MESSAGES_OPTION, array() );
		$cleaned = self::clean_message( array_intersect_key( (array) ( $stored[ $type ] ?? array() ), $defaults[ $type ] ) );

		return array_merge( $defaults[ $type ], $cleaned instanceof \WP_Error ? array() : $cleaned );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>|\WP_Error The whole message, as saved.
	 */
	public static function save_message( string $type, array $values ) {
		$current = self::message( $type );

		if ( null === $current || ! in_array( $type, self::message_types(), true ) ) {
			return new \WP_Error( 'submex_message_type', __( 'There is no such confirmation message.', 'submex' ), array( 'status' => 404 ) );
		}

		$cleaned = self::clean_message( array_intersect_key( $values, $current ) );

		if ( $cleaned instanceof \WP_Error ) {
			return $cleaned;
		}

		$stored          = (array) get_option( self::MESSAGES_OPTION, array() );
		$stored[ $type ] = array_merge( $current, $cleaned );
		update_option( self::MESSAGES_OPTION, $stored, false );

		return $stored[ $type ];
	}

	/**
	 * Run on save and again on output, so markup written straight into the option never reaches a page.
	 */
	public static function rich_text( string $html ): string {
		// wp_kses() keeps a script's text once the tags go; a script's text is never copy.
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );

		return wp_kses( $html, Allowed_Html::rich_text() );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function clean_display( array $values ) {
		$clean = array();

		foreach ( $values as $key => $value ) {
			if ( 'description' === $key ) {
				$clean[ $key ] = self::rich_text( (string) $value );
				continue;
			}

			if ( isset( self::SIZES[ $key ] ) ) {
				list( $min, $max ) = self::SIZES[ $key ];

				$size = is_bool( $value ) ? false : filter_var( $value, FILTER_VALIDATE_INT );

				if ( false === $size || $size < $min || $size > $max ) {
					/* translators: 1: setting, 2: smallest, 3: largest */
					return self::invalid( sprintf( __( '%1$s must be a whole number from %2$d to %3$d.', 'submex' ), $key, $min, $max ) );
				}

				$clean[ $key ] = $size;
				continue;
			}

			if ( 'align' === $key ) {
				if ( ! in_array( $value, self::ALIGNS, true ) ) {
					return self::invalid( __( 'Alignment must be left, center or right.', 'submex' ) );
				}

				$clean[ $key ] = (string) $value;
				continue;
			}

			$text = self::plain( $value, 'title' === $key ? self::MAX_TITLE : self::MAX_BUTTON, 'title' !== $key );

			if ( $text instanceof \WP_Error ) {
				return $text;
			}

			$clean[ $key ] = $text;
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function clean_message( array $values ) {
		$clean = array();

		foreach ( $values as $key => $value ) {
			if ( 'enabled' === $key ) {
				$clean[ $key ] = (bool) $value;
				continue;
			}

			if ( 'description' === $key ) {
				$clean[ $key ] = self::rich_text( (string) $value );
				continue;
			}

			$text = self::plain( $value, 'title' === $key ? self::MAX_TITLE : self::MAX_BUTTON, 'button_text' === $key );

			if ( $text instanceof \WP_Error ) {
				return $text;
			}

			$clean[ $key ] = $text;
		}

		return $clean;
	}

	/**
	 * @param mixed $value
	 * @return string|\WP_Error
	 */
	private static function plain( $value, int $max, bool $required ) {
		if ( ! is_scalar( $value ) ) {
			return self::invalid( __( 'Text fields take text.', 'submex' ) );
		}

		$text   = trim( sanitize_text_field( (string) $value ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( $length > $max || ( $required && 0 === $length ) ) {
			/* translators: %d: the most characters allowed */
			return self::invalid( sprintf( __( 'Keep each text to %d characters or fewer, and give every button a label.', 'submex' ), $max ) );
		}

		return $text;
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'submex_retention_invalid', $message, array( 'status' => 400 ) );
	}
}
