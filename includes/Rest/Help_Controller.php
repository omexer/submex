<?php

namespace Submex\Rest;

use Submex\Admin\Help_Page;
use Submex\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the Help screen draws: where to look first, and the system report.
 */
class Help_Controller {

	public const NAMESPACE = 'submex/v1';

	public function __construct( private readonly Help_Page $page ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/help',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'help' ),
					'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
				),
			)
		);
	}

	public function help(): \WP_REST_Response {
		$tiles = array();

		foreach ( $this->page->tiles() as $tile ) {
			$tiles[] = array(
				'title' => $tile['title'],
				'body'  => $tile['body'],
				'label' => $tile['label'],
				'url'   => esc_url_raw( $tile['url'] ),
			);
		}

		return new \WP_REST_Response(
			array(
				'tiles'  => $tiles,
				'report' => Help_Page::report_text( $this->page->report() ),
			)
		);
	}
}
