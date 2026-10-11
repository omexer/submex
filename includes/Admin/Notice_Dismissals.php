<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user dismissal of a notice, which comes back once the count grows past what was dismissed, or the problem is a different one.
 */
final class Notice_Dismissals {

	public const ACTION = 'submex_dismiss_notice';

	private const META = 'submex_dismissed_notices';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether the current user should see this notice at this count. Call it even when the count is 0.
	 */
	public static function shows( string $notice, int $count ): bool {
		$user_id   = get_current_user_id();
		$dismissed = self::dismissed( $user_id );
		$at        = (int) ( $dismissed[ $notice ] ?? 0 );

		// Follow the count down, so new rows after some were fixed bring the notice back.
		if ( $user_id && $at > $count ) {
			$dismissed[ $notice ] = $count;
			update_user_meta( $user_id, self::META, $dismissed );
			$at = $count;
		}

		return $count > $at;
	}

	/**
	 * Whether the current user should see a notice about this problem. '' means none; call it then too, so a recurrence shows.
	 */
	public static function shows_problem( string $notice, string $problem ): bool {
		$user_id   = get_current_user_id();
		$dismissed = self::dismissed( $user_id );
		$at        = (string) ( $dismissed[ $notice ] ?? '' );

		if ( $user_id && '' === $problem && '' !== $at ) {
			unset( $dismissed[ $notice ] );
			update_user_meta( $user_id, self::META, $dismissed );
		}

		return '' !== $problem && $problem !== $at;
	}

	/**
	 * A key for a problem made of parts, the same whatever order they are listed in.
	 *
	 * @param string[] $parts
	 */
	public static function problem_key( array $parts ): string {
		if ( ! $parts ) {
			return '';
		}

		sort( $parts );

		return substr( md5( implode( '|', $parts ) ), 0, 12 );
	}

	public static function problem_url( string $notice, string $problem ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'notice'  => $notice,
					'problem' => $problem,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $notice
		);
	}

	public static function url( string $notice, int $count ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'notice' => $notice,
					'count'  => $count,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $notice
		);
	}

	public function handle(): void {
		$notice = sanitize_key( wp_unslash( $_GET['notice'] ?? '' ) );

		if ( '' === $notice || ! current_user_can( Menu::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::ACTION . '_' . $notice ) ) {
			wp_die( esc_html__( 'That request could not be verified.', 'submex' ) );
		}

		$user_id              = get_current_user_id();
		$dismissed            = self::dismissed( $user_id );
		$dismissed[ $notice ] = isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( $_GET['problem'] ) ) : absint( $_GET['count'] ?? 0 );

		update_user_meta( $user_id, self::META, $dismissed );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/**
	 * @return array<string, int|string>
	 */
	private static function dismissed( int $user_id ): array {
		$stored = $user_id ? get_user_meta( $user_id, self::META, true ) : array();

		return is_array( $stored ) ? $stored : array();
	}
}
