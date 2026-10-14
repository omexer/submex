<?php

namespace Submex\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cancellation reasons the store offers, in its order.
 *
 * An id is never reused, and every id ever offered keeps its label, so a reason stored on a
 * subscription still reads after it is renamed, switched off or deleted.
 */
final class Retention_Reasons {

	public const OPTION        = 'submex_retention_reasons';
	public const LABELS_OPTION = 'submex_retention_reason_labels';
	public const MAX_LABEL     = 80;

	/**
	 * @return array<int, array{id: string, label: string, enabled: bool}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return self::seed();
		}

		$reasons = array();

		foreach ( $stored as $reason ) {
			if ( is_array( $reason ) && isset( $reason['id'], $reason['label'] ) ) {
				$reasons[] = array(
					'id'      => (string) $reason['id'],
					'label'   => (string) $reason['label'],
					'enabled' => ! empty( $reason['enabled'] ),
				);
			}
		}

		return $reasons;
	}

	/**
	 * @return array<string, string> id => label, in order.
	 */
	public static function enabled(): array {
		$enabled = array();

		foreach ( self::all() as $reason ) {
			if ( $reason['enabled'] ) {
				$enabled[ $reason['id'] ] = $reason['label'];
			}
		}

		return $enabled;
	}

	public static function find( string $id ): ?array {
		foreach ( self::all() as $reason ) {
			if ( $reason['id'] === $id ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Any id this store has ever offered, including ones since switched off or deleted.
	 */
	public static function label( string $id ): string {
		$reason = self::find( $id );

		if ( $reason ) {
			return $reason['label'];
		}

		return (string) ( self::labels()[ $id ] ?? '' );
	}

	/**
	 * @return array<string, string>
	 */
	public static function labels(): array {
		self::all();

		return array_map( 'strval', (array) get_option( self::LABELS_OPTION, array() ) );
	}

	/**
	 * @return array{id: string, label: string, enabled: bool}|\WP_Error
	 */
	public static function create( string $label ) {
		$label = self::clean_label( $label );

		if ( $label instanceof \WP_Error ) {
			return $label;
		}

		$reasons = self::all();
		$reason  = array(
			'id'      => self::new_id(),
			'label'   => $label,
			'enabled' => true,
		);

		$reasons[] = $reason;
		self::save( $reasons );

		return $reason;
	}

	/**
	 * @return array{id: string, label: string, enabled: bool}|\WP_Error|null Null when there is no such reason.
	 */
	public static function update( string $id, ?string $label = null, ?bool $enabled = null ) {
		if ( null !== $label ) {
			$label = self::clean_label( $label );

			if ( $label instanceof \WP_Error ) {
				return $label;
			}
		}

		$reasons = self::all();

		foreach ( $reasons as $index => $reason ) {
			if ( $reason['id'] !== $id ) {
				continue;
			}

			$reasons[ $index ] = array(
				'id'      => $id,
				'label'   => $label ?? $reason['label'],
				'enabled' => $enabled ?? $reason['enabled'],
			);
			self::save( $reasons );

			return $reasons[ $index ];
		}

		return null;
	}

	public static function delete( string $id ): bool {
		$reasons = self::all();
		$kept    = array_values( array_filter( $reasons, static fn( array $reason ): bool => $reason['id'] !== $id ) );

		if ( count( $kept ) === count( $reasons ) ) {
			return false;
		}

		self::save( $kept );

		/**
		 * Fires after a cancellation reason is deleted. Its label stays readable for history.
		 *
		 * @param string $reason_id
		 */
		do_action( 'submex_retention_reason_deleted', $id );

		return true;
	}

	/**
	 * @param string[] $ids Every reason's id, each once, in the new order.
	 * @return true|\WP_Error
	 */
	public static function reorder( array $ids ) {
		$reasons = array_column( self::all(), null, 'id' );
		$ids     = array_map( 'strval', $ids );

		if ( count( $ids ) !== count( $reasons ) || count( array_unique( $ids ) ) !== count( $ids ) || array_diff( $ids, array_keys( $reasons ) ) ) {
			return new \WP_Error( 'submex_reason_order', __( 'The new order must list every reason exactly once.', 'submex' ), array( 'status' => 400 ) );
		}

		self::save( array_map( static fn( string $id ): array => $reasons[ $id ], $ids ) );

		return true;
	}

	/**
	 * How many of the store's offers use a reason, as the extension that owns offers counts them.
	 */
	public static function usage( string $id ): int {
		/**
		 * Filter how many retention offers use a cancellation reason, so deleting it can warn first.
		 *
		 * @param int    $count
		 * @param string $reason_id
		 */
		return max( 0, (int) apply_filters( 'submex_retention_reason_usage', 0, $id ) );
	}

	/**
	 * The six reasons the store asked before reasons could be edited, under the keys subscriptions already store.
	 *
	 * @return array<int, array{id: string, label: string, enabled: bool}>
	 */
	private static function seed(): array {
		// Stored once for every customer, so in the store's language rather than this visitor's.
		$switched = switch_to_locale( get_locale() );

		$defaults = array(
			'too_expensive' => __( 'Too expensive', 'submex' ),
			'not_using'     => __( "I wasn't using it", 'submex' ),
			'found_better'  => __( 'I found something better', 'submex' ),
			'quality'       => __( "It wasn't what I expected", 'submex' ),
			'temporary'     => __( 'Only pausing for now', 'submex' ),
			'other'         => __( 'Something else', 'submex' ),
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		$reasons = array();

		foreach ( $defaults as $id => $label ) {
			$reasons[] = array(
				'id'      => $id,
				'label'   => $label,
				'enabled' => true,
			);
		}

		self::save( $reasons );

		return $reasons;
	}

	/**
	 * @param array<int, array{id: string, label: string, enabled: bool}> $reasons
	 */
	private static function save( array $reasons ): void {
		$labels = (array) get_option( self::LABELS_OPTION, array() );

		foreach ( $reasons as $reason ) {
			$labels[ $reason['id'] ] = $reason['label'];
		}

		update_option( self::LABELS_OPTION, $labels, false );
		update_option( self::OPTION, array_values( $reasons ), false );
	}

	private static function new_id(): string {
		$taken = (array) get_option( self::LABELS_OPTION, array() );

		do {
			$id = 'r_' . strtolower( wp_generate_password( 8, false, false ) );
		} while ( isset( $taken[ $id ] ) );

		return $id;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function clean_label( string $label ) {
		$label  = trim( sanitize_text_field( $label ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $label ) : strlen( $label );

		if ( $length < 1 || $length > self::MAX_LABEL ) {
			return new \WP_Error(
				'submex_reason_label',
				/* translators: %d: the longest a reason may be, in characters */
				sprintf( __( 'A reason needs a label of 1 to %d characters.', 'submex' ), self::MAX_LABEL ),
				array( 'status' => 400 )
			);
		}

		return $label;
	}
}
