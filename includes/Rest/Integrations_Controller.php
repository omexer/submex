<?php

namespace Submex\Rest;

use Submex\Admin\Integration_Installer;
use Submex\Admin\Integrations_Page;
use Submex\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the Integrations screen draws. Installing stays with Integration_Installer.
 */
class Integrations_Controller {

	public const NAMESPACE = 'submex/v1';

	public function __construct( private readonly Integrations_Page $page ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/integrations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'integrations' ),
					'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
				),
			)
		);
	}

	public function integrations(): \WP_REST_Response {
		$can_install = current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		$items       = array();

		foreach ( $this->page->integrations() as $integration ) {
			$active   = ! empty( $integration['active'] );
			$slug     = sanitize_key( (string) ( $integration['slug'] ?? '' ) );
			$title    = (string) ( $integration['title'] ?? '' );
			$category = (string) ( $integration['category'] ?? '' );

			$items[] = array(
				'title'           => $title,
				'requires'        => (string) ( $integration['requires'] ?? $title ),
				'description'     => (string) ( $integration['description'] ?? '' ),
				'hint'            => (string) ( $integration['hint'] ?? '' ),
				'category'        => '' !== $category ? $category : __( 'Other', 'submex' ),
				'icon'            => esc_url_raw( (string) ( $integration['icon'] ?? '' ) ),
				'active'          => $active,
				'configure_url'   => esc_url_raw( (string) ( $integration['configure_url'] ?? '' ) ),
				'configure_label' => (string) ( $integration['configure_label'] ?? '' ),
				'install'         => ! $active && '' !== $slug && $can_install ? $slug : '',
				'get_url'         => $active ? '' : esc_url_raw( (string) ( $integration['url'] ?? '' ) ),
			);
		}

		return new \WP_REST_Response(
			array(
				'integrations' => $items,
				// The same admin-ajax action the server-rendered screen posts to, so there is one installer.
				'installer'    => $can_install
					? array(
						'url'    => admin_url( 'admin-ajax.php' ),
						'action' => Integration_Installer::ACTION,
						'nonce'  => wp_create_nonce( Integration_Installer::ACTION ),
					)
					: null,
			)
		);
	}
}
