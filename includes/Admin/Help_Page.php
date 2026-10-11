<?php

namespace Submex\Admin;

use Submex\Billing\Renewal_Scheduler;
use Submex\Data\Migrator;
use Submex\Data\Subscription_Query;
use Submex\Gateways\Gateway_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where to get help, and the facts to bring with you.
 *
 * The report is the point. Most support threads open with a question that cannot be
 * answered until somebody asks for versions, whether HPOS is on and whether the queue is
 * running, so this hands all of it over in one paste. It deliberately reports whether a
 * gateway is configured, never any part of its credentials.
 */
class Help_Page {

	public const SLUG = Menu::SLUG . '-help';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 90 );
	}

	public function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Help', 'submex' ),
			__( 'Help', 'submex' ),
			Menu::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function report(): array {
		global $wpdb;

		$scheduler = \Submex\Plugin::instance()->get( 'scheduler' );
		$gateways  = \Submex\Plugin::instance()->get( 'gateways' );
		$migrator  = new Migrator();

		$rows = array(
			'Submex'     => defined( 'SUBMEX_VERSION' ) ? SUBMEX_VERSION : '?',
			'Submex Pro' => defined( 'SUBMEX_PRO_VERSION' ) ? SUBMEX_PRO_VERSION : __( 'not installed', 'submex' ),
			'WordPress'            => get_bloginfo( 'version' ),
			'WooCommerce'          => defined( 'WC_VERSION' ) ? WC_VERSION : '?',
			'PHP'                  => PHP_VERSION,
			'MySQL'                => $wpdb->db_version(),
			'Theme'                => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
			'Locale'               => get_locale(),
			'Currency'             => get_woocommerce_currency(),
			'Timezone'             => wp_timezone_string(),
			'HPOS'                 => $this->hpos() ? 'yes' : 'no',
			'Site URL'             => (string) get_option( 'siteurl' ),
		);

		$rows['Renewal queue'] = $scheduler instanceof Renewal_Scheduler && $scheduler->queue_is_healthy() ? 'healthy' : 'overdue tasks';
		$rows['Charge ledger'] = $migrator->charge_slot_guard_intact() ? 'unique index present' : 'MISSING';
		$rows['Subscriptions'] = (string) count(
			Subscription_Query::ids(
				array(
					'limit'  => -1,
					'status' => null,
				)
			)
		);

		if ( $gateways instanceof Gateway_Registry ) {
			$ids = array();

			foreach ( $gateways->all() as $gateway ) {
				$ids[] = $gateway->id();
			}

			$rows['Recurring gateways'] = $ids ? implode( ', ', $ids ) : 'none registered';
		}

		/**
		 * Filter the rows of the support report.
		 *
		 * Never add anything secret: merchants paste this into public forums.
		 *
		 * @param array $rows
		 */
		return (array) apply_filters( 'submex_support_report', $rows );
	}

	/**
	 * Every tile lands somewhere inside this site: documentation links would 404 until the docs are public.
	 *
	 * @return array<int, array{icon: string, title: string, body: string, label: string, url: string}>
	 */
	public function tiles(): array {
		return array(
			array(
				'icon'  => '1',
				'title' => __( 'Run the setup checks', 'submex' ),
				'body'  => __( 'Home runs a real renewal end to end without charging anyone. If renewals are the problem, that test usually says why.', 'submex' ),
				'label' => __( 'Open Home', 'submex' ),
				'url'   => admin_url( 'admin.php?page=' . Menu::SLUG ),
			),
			array(
				'icon'  => '2',
				'title' => __( 'Read the activity log', 'submex' ),
				'body'  => __( 'Every subscription records each charge attempt and status change, with the reason. Open one and scroll to Activity.', 'submex' ),
				'label' => __( 'All subscriptions', 'submex' ),
				'url'   => admin_url( 'admin.php?page=' . Menu::LIST_SLUG ),
			),
			array(
				'icon'  => '3',
				'title' => __( 'Check renewal health', 'submex' ),
				'body'  => __( 'Settings shows whether the renewal queue is running, any charge whose outcome is unknown, and the double-charge safeguard.', 'submex' ),
				'label' => __( 'Open settings', 'submex' ),
				'url'   => admin_url( 'admin.php?page=wc-settings&tab=submex' ),
			),
		);
	}

	/**
	 * The report as the text a merchant pastes.
	 *
	 * @param array<string, string> $report
	 */
	public static function report_text( array $report ): string {
		$text = '';

		foreach ( $report as $label => $value ) {
			$text .= $label . ': ' . $value . "\n";
		}

		return $text;
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
		$report = $this->report();
		$text   = self::report_text( $report );

		Page_Shell::open(
			__( 'Help', 'submex' ),
			__( 'Where to look first, and the report to send if you still need a hand.', 'submex' )
		);

		echo '<h2 class="submex-section-title">' . esc_html__( 'Check these first', 'submex' ) . '</h2>';
		echo '<div class="submex-grid">';

		foreach ( $this->tiles() as $tile ) {
			printf(
				'<div class="submex-tile"><div class="submex-tile__top"><span class="submex-tile__icon" aria-hidden="true">%s</span><h3 class="submex-tile__title">%s</h3></div><p class="submex-tile__body">%s</p><div class="submex-tile__foot"><a class="submex-btn submex-btn--sm" href="%s">%s</a></div></div>',
				esc_html( $tile['icon'] ),
				esc_html( $tile['title'] ),
				esc_html( $tile['body'] ),
				esc_url( $tile['url'] ),
				esc_html( $tile['label'] )
			);
		}

		echo '</div>';

		echo '<h2 class="submex-section-title">' . esc_html__( 'System report', 'submex' ) . '</h2>';
		echo '<div class="submex-card submex-report-card">';
		echo '<div class="submex-report-card__head"><p class="submex-lede">' . esc_html__( 'Paste this into your support request. It contains no keys and no customer data.', 'submex' ) . '</p>';
		printf(
			'<button type="button" class="submex-btn submex-btn--primary submex-btn--sm" data-submex-copy="submex-report" data-submex-copied="%s">%s</button></div>',
			esc_attr__( 'Copied', 'submex' ),
			esc_html__( 'Copy report', 'submex' )
		);

		printf(
			'<textarea id="submex-report" readonly rows="%d" class="submex-report">%s</textarea>',
			(int) min( 24, count( $report ) + 1 ),
			esc_textarea( $text )
		);

		echo '</div>';

		Page_Shell::close();
	}

	private function hpos(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
