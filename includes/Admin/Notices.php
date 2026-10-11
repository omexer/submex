<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On our screens, our own notices move into the header's notification centre, IMPORTANT ones under it; others stay put.
 */
class Notices {

	public const IMPORTANT = 'submex-notice--important';

	public const FEEDBACK = 'submex-notice--feedback';

	private const HOOKS = array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' );

	/**
	 * @var array<string, array<int, callable>>
	 */
	private array $tucked = array();

	private string $html = '';

	private string $important = '';

	private int $count = 0;

	private bool $shown = false;

	private bool $placed = false;

	private static ?self $current = null;

	/**
	 * Classes for a notice that stays in view because money or renewals are at risk now.
	 */
	public static function important( string $type, bool $dismissible = false ): string {
		return 'notice notice-' . $type . ( $dismissible ? ' is-dismissible' : '' ) . ' ' . self::IMPORTANT;
	}

	/**
	 * Classes for the result of what the merchant just did: in view, and only on the page it was done on.
	 */
	public static function feedback( string $type, bool $dismissible = false ): string {
		return self::important( $type, $dismissible ) . ' ' . self::FEEDBACK;
	}

	/**
	 * Submex's own screens and WooCommerce → Settings, plus the orders screens when asked: where a notice is about the page in front of the merchant.
	 */
	public static function in_context( bool $with_orders = false ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		if ( str_contains( (string) $screen->id, Menu::SLUG ) || 'woocommerce_page_wc-settings' === $screen->id ) {
			return true;
		}

		// The orders list and an order's screen, with and without HPOS.
		return $with_orders && in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true );
	}

	public function register(): void {
		self::$current = $this;

		add_action( 'in_admin_header', array( $this, 'collect' ), 1 );
		// Last resort: a Submex screen that draws no shell would otherwise swallow them.
		add_action( 'admin_footer', array( $this, 'render_leftovers' ), 99 );
	}

	public function collect(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! str_contains( (string) $screen->id, Menu::SLUG ) ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			$this->tucked[ $hook ] = $this->take( $hook );
			add_action( $hook, array( $this, 'run' ) );
		}
	}

	/**
	 * Runs the callbacks that were on the notice hook now firing, sorting what each one printed.
	 */
	public function run(): void {
		$hook = current_action();

		foreach ( $this->tucked[ $hook ] ?? array() as $callback ) {
			ob_start();
			call_user_func( $callback );
			$output = (string) ob_get_clean();

			if ( '' === trim( $output ) ) {
				continue;
			}

			if ( self::is_important( $output ) ) {
				$this->important .= $output;
				continue;
			}

			$this->html  .= $output;
			$this->count += self::count( $output );
		}

		unset( $this->tucked[ $hook ] );
	}

	/**
	 * The bell that opens them, for the header bar. A details element, so it opens without JavaScript.
	 */
	public static function render_toggle(): void {
		$notices = self::$current;

		if ( ! $notices || '' === $notices->html ) {
			return;
		}

		$notices->shown = true;

		$label = sprintf(
			/* translators: %d: number of notifications */
			_n( '%d notification', '%d notifications', $notices->count, 'submex' ),
			$notices->count
		);

		printf(
			'<details class="submex-notify" data-submex-notify><summary class="submex-notify__bell" aria-label="%s" title="%s">%s<span class="submex-notify__count" aria-hidden="true">%s</span></summary>'
				. '<div class="submex-notify__panel" id="submex-other-notices" role="region" aria-label="%s"><p class="submex-notify__head">%s</p><div class="submex-notify__list">',
			esc_attr( $label ),
			esc_attr( $label ),
			wp_kses( self::bell(), Allowed_Html::svg() ),
			esc_html( number_format_i18n( $notices->count ) ),
			esc_attr__( 'Notifications', 'submex' ),
			esc_html__( 'Notifications', 'submex' )
		);

		echo wp_kses( $notices->html, Allowed_Html::form() );

		echo '</div></div></details>';
	}

	/**
	 * The important ones, under the header where the page's own content starts.
	 */
	public static function render_important(): void {
		$notices = self::$current;

		if ( ! $notices || $notices->placed ) {
			return;
		}

		$notices->placed = true;

		echo wp_kses( $notices->important, Allowed_Html::form() );
	}

	public function render_leftovers(): void {
		self::render_important();

		if ( $this->shown || '' === $this->html ) {
			return;
		}

		$this->shown = true;

		echo '<div class="submex-other-notices">' . wp_kses( $this->html, Allowed_Html::form() ) . '</div>';
	}

	public static function bell(): string {
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>';
	}

	/**
	 * Whether a notice callback belongs to Submex or an Submex extension.
	 *
	 * @param callable $callback
	 */
	private static function is_ours( $callback ): bool {
		try {
			if ( is_array( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( $callback );
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} else {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			}
		} catch ( \ReflectionException $e ) {
			return false;
		}

		$class = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass()->getName() : '';

		if ( str_starts_with( $class, 'Submex' ) ) {
			return true;
		}

		$file = wp_normalize_path( (string) $reflection->getFileName() );

		// A plugin folder named for us: the free plugin, Pro, and add-ons, however their zip unpacked.
		return str_starts_with( $file, wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) ) . 'submex' );
	}

	private static function is_important( string $html ): bool {
		return 1 === preg_match( '/\bclass\s*=\s*(["\'])[^"\']*\b' . preg_quote( self::IMPORTANT, '/' ) . '\b/', $html );
	}

	/**
	 * How many notices one callback printed; anything it printed counts as at least one.
	 */
	private static function count( string $html ): int {
		preg_match_all( '/<div\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches );

		$found = 0;

		foreach ( $matches[2] as $classes ) {
			if ( array_intersect( (array) preg_split( '/\s+/', trim( $classes ) ), array( 'notice', 'error', 'updated' ) ) ) {
				++$found;
			}
		}

		return max( 1, $found );
	}

	/**
	 * @return array<int, callable>
	 */
	private function take( string $hook ): array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return array();
		}

		$taken = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_callable( $callback['function'] ) || ! self::is_ours( $callback['function'] ) ) {
					continue;
				}

				$taken[] = $callback['function'];
				remove_action( $hook, $callback['function'], $priority );
			}
		}

		return $taken;
	}
}
