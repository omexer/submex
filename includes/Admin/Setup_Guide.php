<?php

namespace Submex\Admin;

use Submex\Billing\Renewal_Processor;
use Submex\Billing\Renewal_Scheduler;
use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Test_Gateway;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The merchant's first ten minutes.
 *
 * A checklist and one activation notice, not a wizard. WordPress.org retention is decided
 * here: a plugin that activates and changes nothing gets uninstalled. UX Spec 5.
 */
class Setup_Guide {

	private const DISMISSED = 'submex_setup_notice_dismissed';

	public function __construct( private readonly Renewal_Scheduler $scheduler ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'admin_post_submex_dismiss_setup', array( $this, 'dismiss' ) );
		add_action( 'admin_post_submex_test_renewal', array( $this, 'run_test_renewal' ) );
		add_action( 'admin_post_submex_create_product', array( $this, 'create_first_product' ) );
	}

	/**
	 * One dismissible notice. No redirect on activation, no dashboard-wide banner.
	 */
	public function activation_notice(): void {
		if ( get_option( self::DISMISSED ) || ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'plugins' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-success"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'Submex is active.', 'submex' ),
			esc_html__( 'Turn any product into a subscription in about two minutes.', 'submex' ),
			esc_url( add_query_arg( array( 'page' => Menu::SLUG ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Set up subscriptions', 'submex' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=submex_dismiss_setup' ), 'submex_dismiss_setup' ) ),
			esc_html__( 'Dismiss', 'submex' )
		);
	}

	public function dismiss(): void {
		if ( current_user_can( Menu::CAPABILITY ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'submex_dismiss_setup' ) ) {
			update_option( self::DISMISSED, 1, false );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * @return array<int, array{done: bool, title: string, detail: string, action: array{label: string, url: string}|null}>
	 */
	public function steps(): array {
		$connected = $this->connected_gateways();
		$product   = $this->first_subscription_product();
		$queue_ok  = $this->scheduler->queue_is_healthy();

		return array(
			array(
				'done'   => (bool) $connected,
				'title'  => __( 'Connect a payment method', 'submex' ),
				'detail' => $connected
					/* translators: %s: comma-separated list of connected gateways */
					? sprintf( __( '%s can charge renewals automatically.', 'submex' ), implode( ', ', $connected ) )
					: __( 'Subscriptions renew by themselves once a gateway such as Stripe or PayPal is connected. Without one, renewals become invoices the customer pays by hand, which still works.', 'submex' ),
				'action' => array(
					'label' => $connected ? __( 'Change', 'submex' ) : __( 'Choose a gateway', 'submex' ),
					'url'   => Settings_Page::section_url(),
				),
			),
			array(
				'done'   => (bool) $product,
				'title'  => __( 'Create a subscription product', 'submex' ),
				'detail' => $product
					/* translators: %s: product name */
					? sprintf( __( '"%s" is ready to sell.', 'submex' ), $product->get_name() )
					: __( 'Edit any simple product and tick Subscription in the Product data panel.', 'submex' ),
				'action' => $product
					? array(
						'label' => __( 'Edit', 'submex' ),
						'url'   => get_edit_post_link( $product->get_id(), '' ),
					)
					: null,
				'form'   => $product ? null : 'create_product',
			),
			array(
				'done'   => $queue_ok,
				'title'  => __( 'Check automatic renewals can run', 'submex' ),
				'detail' => $queue_ok
					? __( 'Renewals are processing normally.', 'submex' )
					: __( 'Scheduled tasks are overdue. On a quiet site WordPress cron may not fire often enough — a real server cron fixes it.', 'submex' ),
				'action' => null,
			),
			array(
				'done'   => (bool) get_option( 'submex_test_renewal_passed' ),
				'title'  => __( 'Run a test renewal', 'submex' ),
				'detail' => __( 'See a renewal happen end to end, on a throwaway subscription. Nobody is charged and everything is deleted afterwards.', 'submex' ),
				'action' => array(
					'label' => __( 'Run test renewal', 'submex' ),
					'url'   => wp_nonce_url( admin_url( 'admin-post.php?action=submex_test_renewal' ), 'submex_test_renewal' ),
				),
			),
		);
	}

	/**
	 * Make the first subscription product from the guide, so the merchant never has to
	 * find the Product data panel to get started.
	 */
	public function create_first_product(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'submex_create_product' ) ) {
			wp_die( esc_html__( 'That request could not be verified.', 'submex' ) );
		}

		$name  = sanitize_text_field( wp_unslash( $_POST['submex_name'] ?? '' ) );
		$price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['submex_price'] ?? '' ) ) );

		$period   = sanitize_key( wp_unslash( $_POST['submex_period'] ?? 'month' ) );
		$period   = in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? $period : 'month';
		$interval = intval( wp_unslash( $_POST['submex_interval'] ?? 1 ) );
		$trial    = intval( wp_unslash( $_POST['submex_trial'] ?? 0 ) );

		// intval with a range check, not absint: absint would turn a posted -4 into 4.
		if ( '' === $name || '' === $price || (float) $price <= 0 || $interval < 1 || $interval > 365 || $trial < 0 || $trial > 365 ) {
			$this->redirect_back( 'invalid' );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->update_meta_data( Subscription_Product::META_ENABLED, 'yes' );
		$product->update_meta_data( Subscription_Product::META_PERIOD, $period );
		$product->update_meta_data( Subscription_Product::META_INTERVAL, $interval );
		$product->update_meta_data( Subscription_Product::META_TRIAL_DAYS, $trial );
		$product->save();

		$this->redirect_back( $product->get_id() ? 'created' : 'failed' );
	}

	private function redirect_back( string $result ): void {
		wp_safe_redirect( add_query_arg( 'submex_product', $result, admin_url( 'admin.php?page=' . Menu::SLUG ) ) );
		exit;
	}

	/**
	 * Create a throwaway subscription, renew it, then delete the lot.
	 *
	 * The most useful control in the plugin: a merchant who has watched a renewal happen
	 * will trust it with real customers, and one who hasn't opens a support ticket.
	 */
	public function run_test_renewal(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'submex_test_renewal' ) ) {
			wp_die( esc_html__( 'That request could not be verified.', 'submex' ) );
		}

		$result = $this->perform_test_renewal();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Menu::SLUG,
					'submex_test' => $result ? 'pass' : 'fail',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function perform_test_renewal(): bool {
		Test_Gateway::sanction();

		$processor = \Submex\Plugin::instance()->get( 'processor' );
		$registry  = \Submex\Plugin::instance()->get( 'gateways' );

		if ( ! $processor instanceof Renewal_Processor || ! $registry ) {
			return false;
		}

		$registry->add( new Test_Gateway() );

		$subscription = new Subscription();
		$subscription->set_currency( get_woocommerce_currency() );
		$subscription->set_billing_period( 'month' );
		$subscription->set_billing_interval( 1 );
		$subscription->set_payment_method( Test_Gateway::ID );
		$subscription->set_payment_method_title( __( 'Submex self-test', 'submex' ) );
		$subscription->set_next_payment( gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) );
		$subscription->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
		$subscription->update_meta_data( '_submex_is_test', 'yes' );
		$subscription->transition_to( Subscription_Status::Active );
		$subscription->save();

		$id       = $subscription->get_id();
		$scripted = wc_get_order( $id );

		if ( ! $scripted instanceof Subscription ) {
			return false;
		}

		Test_Gateway::script_subscription( $scripted, array( 'success' ) );

		$processor->process( $id );

		$renewed = ! empty(
			array_filter(
				$this->slots_for( $id ),
				static fn( $slot ): bool => 'paid' === $slot->state
			)
		);

		$this->cleanup_test( $id );

		update_option( 'submex_test_renewal_passed', $renewed ? 1 : 0, false );

		return $renewed;
	}

	/**
	 * @return object[]
	 */
	private function slots_for( int $id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}submex_charge_slot WHERE subscription_id = %d", $id ) );
	}

	private function cleanup_test( int $id ): void {
		global $wpdb;

		foreach ( wc_get_orders(
			array(
				'limit'      => -1,
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- renewal orders are linked to their subscription by meta.
				'meta_key'   => '_submex_subscription_id',
				'meta_value' => $id,
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $order ) {
			$order->delete( true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'submex_charge_slot', array( 'subscription_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'submex_activity', array( 'subscription_id' => $id ), array( '%d' ) );

		$subscription = wc_get_order( $id );
		if ( $subscription ) {
			$subscription->delete( true );
		}
	}

	/**
	 * Which gateways can actually charge a renewal right now.
	 *
	 * Asks the registry rather than naming one, so a gateway added by Pro counts and the
	 * checklist never tells a shop using another gateway to go and connect PayPal.
	 *
	 * @return string[]
	 */
	private function connected_gateways(): array {
		$registry = \Submex\Plugin::instance()->get( 'gateways' );

		if ( ! $registry instanceof \Submex\Gateways\Gateway_Registry ) {
			return array();
		}

		$names = array();

		foreach ( $registry->all() as $gateway ) {
			// Manual and the test gateway are always present and charge nobody by
			// themselves, so counting them would tick this step on a store with nothing set up.
			if ( in_array( $gateway->id(), array( 'submex_manual', Test_Gateway::ID ), true ) ) {
				continue;
			}

			$names[] = $gateway->title();
		}

		return $names;
	}

	private function first_subscription_product(): ?\WC_Product {
		$products = wc_get_products(
			array(
				'limit'      => 1,
				'status'     => 'publish',
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- limit 1, once while setting up.
				'meta_key'   => Subscription_Product::META_ENABLED,
				'meta_value' => 'yes',
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $products[0] ?? null;
	}

	public function is_complete(): bool {
		foreach ( $this->steps() as $step ) {
			if ( ! $step['done'] ) {
				return false;
			}
		}

		return true;
	}

	public function has_subscriptions(): bool {
		return ! empty( Subscription_Query::ids( array( 'limit' => 1 ) ) );
	}
}
