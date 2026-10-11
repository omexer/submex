<?php

namespace Submex\Rest;

use Submex\Admin\Menu;
use Submex\Data\Stats;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the overview screen needs, in one request.
 *
 * One endpoint rather than several: the screen is useless with a subset, so three
 * round trips would only buy three ways to be half-drawn.
 */
class Overview_Controller {

	public const NAMESPACE = 'submex/v1';

	private Stats $stats;

	public function __construct( Stats $stats ) {
		$this->stats = $stats;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'may_read' ),
			)
		);
	}

	public function may_read(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	public function overview(): \WP_REST_Response {
		$mrr     = $this->stats->mrr();
		$history = $this->stats->history( 31 );

		return rest_ensure_response(
			array(
				'mrr'      => array(
					// Decoded, not just stripped: React prints text, so &nbsp; and a currency
					// entity would reach the screen as their own source.
					'formatted' => html_entity_decode( wp_strip_all_tags( $mrr->format() ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
					'minor'     => $mrr->minor(),
					'change'    => $this->change( $history, $mrr->minor() ),
				),
				'active'   => $this->stats->active_count(),
				'excluded' => $this->stats->excluded_currency_count(),
				'statuses' => $this->statuses(),
				'history'  => $this->history( $history ),
			)
		);
	}

	/**
	 * @param array<string, array{mrr: int, active: int}> $history
	 */
	private function change( array $history, int $now ): ?float {
		$oldest = $history ? reset( $history ) : null;

		if ( ! $oldest || empty( $oldest['mrr'] ) ) {
			return null;
		}

		return round( ( ( $now - (int) $oldest['mrr'] ) / (int) $oldest['mrr'] ) * 100, 1 );
	}

	/**
	 * @param array<string, array{mrr: int, active: int}> $history
	 * @return array<int, array{date: string, mrr: int, active: int}>
	 */
	private function history( array $history ): array {
		$out = array();

		foreach ( $history as $date => $row ) {
			$out[] = array(
				'date'   => (string) $date,
				'mrr'    => (int) ( $row['mrr'] ?? 0 ),
				'active' => (int) ( $row['active'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array{key: string, label: string, count: int, colour: string}>
	 */
	private function statuses(): array {
		$counts = $this->stats->counts_by_status();
		$out    = array();

		foreach ( Subscription_Status::cases() as $status ) {
			$out[] = array(
				'key'    => $status->value,
				'label'  => $status->label(),
				'count'  => (int) ( $counts[ $status->value ] ?? 0 ),
				'colour' => self::COLOURS[ $status->value ] ?? '#8c8f94',
			);
		}

		return $out;
	}

	/** Status colours, shared with the list screen's pills. */
	private const COLOURS = array(
		'submex-pending'        => '#dba617',
		'submex-trialling'      => '#72aee6',
		'submex-active'         => '#00a32a',
		'submex-on-hold'        => '#d63638',
		'submex-cancelling' => '#b26200',
		'submex-cancelled'      => '#8c8f94',
		'submex-expired'        => '#646970',
		'submex-switched'       => '#a7aaad',
	);
}
