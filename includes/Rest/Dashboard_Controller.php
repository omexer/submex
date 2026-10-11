<?php

namespace Submex\Rest;

use Submex\Admin\Menu;
use Submex\Admin\Setup_Guide;
use Submex\Data\Stats;
use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the Submex home screen draws, in one request.
 *
 * Read-only. Pro adds what only it knows - subscriptions at risk, revenue at risk -
 * through submex_dashboard_data rather than a second request.
 */
class Dashboard_Controller {

	public const NAMESPACE = 'submex/v1';

	public function __construct(
		private readonly Stats $stats,
		private readonly Setup_Guide $setup
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'dashboard' ),
					'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
				),
			)
		);
	}

	public function dashboard(): \WP_REST_Response {
		$counts = $this->stats->counts_by_status();
		$mrr    = $this->stats->mrr();

		$data = array(
			'greeting'  => $this->greeting(),
			'setup'     => $this->setup_steps(),
			'stats'     => array(
				'mrr'       => $this->plain( $mrr->format() ),
				'active'    => $this->stats->active_count(),
				'trialling' => (int) ( $counts[ Subscription_Status::Trialling->value ] ?? 0 ),
				'on_hold'   => (int) ( $counts[ Subscription_Status::OnHold->value ] ?? 0 ),
			),
			'attention' => $this->attention( $counts ),
			'recent'    => $this->recent(),
			'links'     => array(
				'new_product'  => admin_url( 'post-new.php?post_type=product' ),
				'list'         => admin_url( 'admin.php?page=' . Menu::LIST_SLUG ),
				'integrations' => admin_url( 'admin.php?page=' . Menu::SLUG . '-integrations' ),
				'settings'     => admin_url( 'admin.php?page=wc-settings&tab=submex' ),
				'help'         => admin_url( 'admin.php?page=' . Menu::SLUG . '-help' ),
			),
			'create'    => array(
				'url'   => admin_url( 'admin-post.php' ),
				'nonce' => wp_create_nonce( 'submex_create_product' ),
			),
		);

		/**
		 * Filter what the Submex home screen draws.
		 *
		 * @param array<string, mixed> $data
		 */
		return new \WP_REST_Response( (array) apply_filters( 'submex_dashboard_data', $data ) );
	}

	/**
	 * @return array{steps: array<int, array<string, mixed>>, done: int, total: int}
	 */
	private function setup_steps(): array {
		$steps = array();

		foreach ( $this->setup->steps() as $step ) {
			$steps[] = array(
				'done'   => ! empty( $step['done'] ),
				'title'  => (string) $step['title'],
				'detail' => (string) $step['detail'],
				'action' => $step['action']
					? array(
						'label' => (string) $step['action']['label'],
						'url'   => html_entity_decode( (string) $step['action']['url'] ),
					)
					: null,
				'form'   => (string) ( $step['form'] ?? '' ),
			);
		}

		$done = count( array_filter( $steps, static fn( array $step ): bool => $step['done'] ) );

		return array(
			'steps' => $steps,
			'done'  => $done,
			'total' => count( $steps ),
		);
	}

	/**
	 * What a merchant should look at today. Free knows about payment trouble by status;
	 * Pro's health scoring adds to this through the filter.
	 *
	 * @param array<string, int> $counts
	 * @return array<int, array{key: string, label: string, count: int, url: string, tone: string}>
	 */
	private function attention( array $counts ): array {
		$items = array();

		$on_hold = (int) ( $counts[ Subscription_Status::OnHold->value ] ?? 0 );

		if ( $on_hold > 0 ) {
			$items[] = array(
				'key'   => 'on_hold',
				/* translators: %d: number of subscriptions */
				'label' => sprintf( _n( '%d subscription on hold after a failed payment', '%d subscriptions on hold after a failed payment', $on_hold, 'submex' ), $on_hold ),
				'count' => $on_hold,
				'url'   => admin_url( 'admin.php?page=' . Menu::LIST_SLUG . '&status=' . Subscription_Status::OnHold->value ),
				'tone'  => 'bad',
			);
		}

		$cancelling = (int) ( $counts[ Subscription_Status::PendingCancel->value ] ?? 0 );

		if ( $cancelling > 0 ) {
			$items[] = array(
				'key'   => 'pending_cancel',
				/* translators: %d: number of subscriptions */
				'label' => sprintf( _n( '%d subscription ends when its period runs out', '%d subscriptions end when their period runs out', $cancelling, 'submex' ), $cancelling ),
				'count' => $cancelling,
				'url'   => admin_url( 'admin.php?page=' . Menu::LIST_SLUG . '&status=' . Subscription_Status::PendingCancel->value ),
				'tone'  => 'warn',
			);
		}

		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function recent(): array {
		$out = array();

		$subscriptions = Subscription_Query::get(
			array(
				'limit'   => 6,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof Subscription ) {
				continue;
			}

			$status = $subscription->get_status_enum();
			$items  = $subscription->get_items();
			$first  = $items ? reset( $items ) : null;

			$out[] = array(
				'id'           => $subscription->get_id(),
				'customer'     => trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() ) ?: (string) $subscription->get_billing_email(),
				'product'      => $first ? (string) $first->get_name() : '',
				'status'       => (string) $subscription->get_status(),
				'status_label' => $status ? $status->label() : '',
				'total'        => $this->plain( $subscription->get_formatted_order_total() ),
				'created'      => $subscription->get_date_created() ? $subscription->get_date_created()->date_i18n( (string) get_option( 'date_format' ) ) : '',
				'url'          => admin_url( 'admin.php?page=' . Menu::LIST_SLUG . '&subscription=' . $subscription->get_id() ),
			);
		}

		return $out;
	}

	private function greeting(): string {
		$hour = (int) current_time( 'G' );
		$name = wp_get_current_user()->first_name ?: wp_get_current_user()->display_name;

		$greeting = match ( true ) {
			$hour < 12 => __( 'Good morning', 'submex' ),
			$hour < 18 => __( 'Good afternoon', 'submex' ),
			default    => __( 'Good evening', 'submex' ),
		};

		return '' !== (string) $name ? $greeting . ', ' . $name : $greeting;
	}

	private function plain( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}
}
