<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Submex can connect to, and whether each connection is live.
 *
 * FluentCRM is built in and extensions add the rest. This screen exists so a merchant can
 * see what is possible and what is missing, rather than discovering that an integration
 * silently did nothing because its plugin was never installed.
 */
class Integrations_Page {

	public const SLUG = Menu::SLUG . '-integrations';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 30 );
	}

	public function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Integrations', 'submex' ),
			__( 'Integrations', 'submex' ),
			Menu::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Each entry is title, the plugin it needs, whether that plugin is here, and where it is set up.
	 *
	 * @return array<int, array{title: string, requires: string, active: bool, url: string, configure_url?: string, configure_label?: string, hint?: string}>
	 */
	public function integrations(): array {
		/**
		 * Filter the integrations shown on the Integrations screen.
		 *
		 * @param array $integrations
		 */
		$integrations = (array) apply_filters( 'submex_admin_integrations', array() );

		usort(
			$integrations,
			static fn( array $a, array $b ): int => strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) )
		);

		return $integrations;
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscriptions.', 'submex' ) );
		}

		App_Host::start( self::SLUG );
		$this->render_screen();
		App_Host::end();
	}

	private function render_screen(): void {
		$integrations = $this->integrations();

		Page_Shell::open(
			__( 'Integrations', 'submex' ),
			__( 'Hand a subscription to the plugin that delivers what it pays for, and take it back when it ends.', 'submex' )
		);

		if ( ! $integrations ) {
			printf(
				'<div class="submex-card"><div class="submex-empty"><p class="submex-empty__title">%s</p><p>%s</p></div></div>',
				esc_html__( 'Nothing to connect yet', 'submex' ),
				esc_html__( 'Integrations hand a subscription to the plugin that grants what it pays for — a course, a mailing list, a licence key. Submex Pro adds them.', 'submex' )
			);

			Page_Shell::close();
			return;
		}

		$groups = array();

		foreach ( $integrations as $integration ) {
			$category              = (string) ( $integration['category'] ?? '' );
			$category              = '' !== $category ? $category : __( 'Other', 'submex' );
			$groups[ $category ][] = $integration;
		}

		foreach ( $groups as $category => $items ) {
			echo '<h2 class="submex-section-title">' . esc_html( $category ) . '</h2><div class="submex-grid">';

			foreach ( $items as $integration ) {
				$this->render_tile( $integration );
			}

			echo '</div>';
		}

		echo '<p class="submex-lede" style="margin-top:24px">' . esc_html__( 'An integration only does anything while the plugin it connects to is active, and only for products you have configured it on.', 'submex' ) . '</p>';

		Page_Shell::close();
	}

	/**
	 * @param array<string, mixed> $integration
	 */
	private function render_tile( array $integration ): void {
		$active      = ! empty( $integration['active'] );
		$title       = (string) ( $integration['title'] ?? '' );
		$description = (string) ( $integration['description'] ?? '' );

		echo '<div class="submex-tile"><div class="submex-tile__top">';

		$this->render_icon( $integration );

		printf(
			'<div><h3 class="submex-tile__title">%s</h3><p class="submex-tile__meta">%s</p></div></div>',
			esc_html( $title ),
			esc_html(
				sprintf(
					/* translators: %s: the plugin an integration needs */
					__( 'Needs %s', 'submex' ),
					(string) ( $integration['requires'] ?? $title )
				)
			)
		);

		if ( '' !== $description ) {
			echo '<p class="submex-tile__body">' . esc_html( $description ) . '</p>';
		}

		$hint = (string) ( $integration['hint'] ?? '' );

		if ( '' !== $hint ) {
			echo '<p class="submex-tile__meta">' . esc_html( $hint ) . '</p>';
		}

		printf(
			'<div class="submex-tile__foot"><span class="submex-badge %s">%s</span><span class="submex-tile__action">',
			$active ? 'submex-badge--good' : '',
			esc_html( $active ? __( 'Connected', 'submex' ) : __( 'Not active', 'submex' ) )
		);

		$this->render_configure( $integration );
		$this->render_action( $integration, $active );

		echo '</span></div></div>';
	}

	/**
	 * The plugin's WordPress.org icon when it has one, otherwise its initial: a broken
	 * image in a grid of logos looks like the integration itself is broken.
	 *
	 * @param array<string, mixed> $integration
	 */
	private function render_icon( array $integration ): void {
		$title = (string) ( $integration['title'] ?? '?' );
		$icon  = (string) ( $integration['icon'] ?? '' );
		$first = strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 1 ) : substr( $title, 0, 1 ) );

		if ( '' === $icon ) {
			printf( '<span class="submex-tile__icon" aria-hidden="true">%s</span>', esc_html( $first ) );
			return;
		}

		printf(
			'<span class="submex-tile__icon" aria-hidden="true" data-initial="%s"><img src="%s" alt="" width="40" height="40" loading="lazy" onerror="this.parentNode.textContent=this.parentNode.dataset.initial"></span>',
			esc_attr( $first ),
			esc_url( $icon )
		);
	}

	/**
	 * Install where we can, link out where the plugin is sold rather than hosted.
	 */
	private function render_action( array $integration, bool $active ): void {
		if ( $active ) {
			return;
		}

		$slug = (string) ( $integration['slug'] ?? '' );

		if ( '' !== $slug && current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ) ) {
			printf(
				'<button type="button" class="submex-btn submex-btn--primary submex-btn--sm submex-install" data-slug="%s">%s</button>',
				esc_attr( $slug ),
				esc_html__( 'Install', 'submex' )
			);

			return;
		}

		$url = (string) ( $integration['url'] ?? '' );

		if ( '' === $url ) {
			return;
		}

		printf(
			'<a class="submex-btn submex-btn--sm" href="%s" target="_blank" rel="noopener noreferrer">%s <span aria-hidden="true">&#8599;</span></a>',
			esc_url( $url ),
			esc_html__( 'Get it', 'submex' )
		);
	}

	/**
	 * @param array<string, mixed> $integration
	 */
	private function render_configure( array $integration ): void {
		$url = (string) ( $integration['configure_url'] ?? '' );

		if ( '' === $url ) {
			return;
		}

		$label = (string) ( $integration['configure_label'] ?? '' );

		printf(
			'<a class="submex-btn submex-btn--sm" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( '' !== $label ? $label : __( 'Settings', 'submex' ) )
		);
	}
}
