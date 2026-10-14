<?php

namespace Submex\Admin;

use Submex\Billing\Renewal_Processor;
use Submex\Data\Activity_Repository;
use Submex\Domain\Subscription;
use Submex\Frontend\MyAccount\Status_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Submex menu: Overview, and the subscriptions list with its detail screen.
 */
class Menu {

	public const SLUG       = 'submex';
	public const LIST_SLUG  = 'submex-list';
	public const CAPABILITY = 'manage_woocommerce';

	public function __construct(
		private readonly Activity_Repository $activity,
		private readonly Renewal_Processor $processor,
		private readonly Setup_Guide $setup
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'add_upgrade' ), 89 );
		add_action( 'admin_post_submex_process_renewal', array( $this, 'process_renewal_now' ) );
	}

	/**
	 * Extensions add their screens under this, not under WooCommerce.
	 */
	public const PARENT = self::SLUG;

	/**
	 * The brand glyph alone, as a data URI, so WordPress recolours it like its own menu icons.
	 */
	private static function menu_icon(): string {
		$svg = (string) file_get_contents( SUBMEX_PATH . 'assets/images/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled file.

		return 'data:image/svg+xml;base64,' . base64_encode( trim( $svg ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress reads menu icons this way.
	}

	public function add_menu(): void {
		// Menu label is the product; the page title stays what the page actually shows.
		add_menu_page(
			__( 'Submex', 'submex' ),
			__( 'Submex', 'submex' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			// Directly below WooCommerce, which sits at 55.6.
			56
		);

		// Without this the top-level entry repeats itself as its own first child.
		add_submenu_page(
			self::SLUG,
			__( 'Submex overview', 'submex' ),
			__( 'Overview', 'submex' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'All subscriptions', 'submex' ),
			__( 'All subscriptions', 'submex' ),
			self::CAPABILITY,
			self::LIST_SLUG,
			array( $this, 'render_list' )
		);

		add_action( 'load-toplevel_page_' . self::SLUG, array( $this, 'redirect_legacy_links' ) );
	}

	/**
	 * A plain link out, never a screen of locked features. Gone once Pro is active.
	 */
	public function add_upgrade(): void {
		if ( did_action( 'submex_pro_loaded' ) ) {
			return;
		}

		// No callback: WordPress then prints the slug itself as the link.
		add_submenu_page( self::SLUG, __( 'Upgrade to Pro', 'submex' ), __( 'Upgrade to Pro', 'submex' ), self::CAPABILITY, Page_Shell::UPGRADE_URL );
	}

	/**
	 * The list used to live at the top-level address, so bookmarks, emails and other
	 * plugins still link there with a subscription id or a filter. Those go to where the
	 * list is now, rather than to a home screen that would ignore what they asked for.
	 */
	public function redirect_legacy_links(): void {
		$carry = array( 'subscription', 'status', 's', 'paged', 'orderby', 'order', 'submex_changed', 'submex_asked', 'processed' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect carrying read-only view state.
		$args = array_intersect_key( wp_unslash( $_GET ), array_flip( $carry ) );

		if ( ! $args ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array_map( 'sanitize_text_field', (array) $args ) + array( 'page' => self::LIST_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Overview: setup until it is done, then how the business is doing.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscriptions.', 'submex' ) );
		}

		App_Host::start( self::SLUG );

		Page_Shell::open( __( 'Overview', 'submex' ), '', array(), '', false );

		$this->render_test_result();
		$this->render_product_notice();

		if ( ! $this->setup->is_complete() ) {
			$this->render_checklist();
		}

		if ( $this->setup->has_subscriptions() ) {
			$this->render_summary();
		}

		Page_Shell::close();

		App_Host::end();
	}

	public function render_list(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscriptions.', 'submex' ) );
		}

		App_Host::start( self::LIST_SLUG );
		$this->render_list_screen();
		App_Host::end();
	}

	private function render_list_screen(): void {
		$id = absint( $_GET['subscription'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id ) {
			$this->render_detail( $id );
			return;
		}

		$table = new Subscriptions_Table();
		$table->prepare_items();

		Page_Shell::open(
			__( 'All subscriptions', 'submex' ),
			__( 'Everyone who pays you on a schedule.', 'submex' ),
			array(),
			sprintf(
				'<a class="submex-btn submex-btn--primary" href="%s">%s</a>',
				esc_url( admin_url( 'post-new.php?post_type=product' ) ),
				esc_html__( 'New subscription product', 'submex' )
			)
		);

		$this->render_bulk_notice();

		if ( ! $this->setup->has_subscriptions() ) {
			printf(
				'<div class="submex-card"><div class="submex-empty"><p class="submex-empty__title">%s</p><p>%s</p><p><a class="submex-btn submex-btn--primary" href="%s">%s</a></p></div></div>',
				esc_html__( 'No subscriptions yet', 'submex' ),
				esc_html__( 'They will be listed here the moment someone buys a subscription product.', 'submex' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				esc_html__( 'Finish setting up', 'submex' )
			);

			Page_Shell::close();
			return;
		}

		$table->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::LIST_SLUG ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only list filter; nothing is changed.
		if ( isset( $_GET['status'] ) ) {
			printf( '<input type="hidden" name="status" value="%s" />', esc_attr( sanitize_text_field( wp_unslash( $_GET['status'] ) ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$table->search_box( __( 'Search subscriptions', 'submex' ), 'submex-search' );
		$table->display();

		echo '</form>';

		Page_Shell::close();
	}

	private function render_test_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$result = isset( $_GET['submex_test'] ) ? sanitize_key( wp_unslash( $_GET['submex_test'] ) ) : '';

		if ( '' === $result ) {
			return;
		}

		printf(
			'<div class="%s"><p>%s</p></div>',
			esc_attr( Notices::feedback( 'pass' === $result ? 'success' : 'error', true ) ),
			'pass' === $result
				? esc_html__( 'Test renewal succeeded. A subscription was created, renewed and cleaned up — automatic billing works on this site.', 'submex' )
				: esc_html__( 'The test renewal did not complete. Check that scheduled tasks are running, then try again.', 'submex' )
		);
	}

	private function render_checklist(): void {

		echo '<div class="submex-card"><h2>'
			. esc_html__( 'Get your first subscription running', 'submex' )
			. '</h2><ol class="submex-steps">';

		$number = 0;

		foreach ( $this->setup->steps() as $step ) {
			++$number;
			$done = ! empty( $step['done'] );

			printf(
				'<li class="submex-step%s"><span class="submex-step__mark">%s</span><div class="submex-step__body">'
					. '<span class="submex-step__title">%s</span><span class="submex-step__detail">%s</span>%s',
				$done ? ' submex-step--done' : '',
				$done ? '&#10003;' : esc_html( (string) $number ),
				esc_html( (string) $step['title'] ),
				esc_html( (string) $step['detail'] ),
				$step['action']
					? sprintf(
						'<div class="submex-step__action"><a class="button button-small" href="%s">%s</a></div>',
						esc_url( (string) $step['action']['url'] ),
						esc_html( (string) $step['action']['label'] )
					)
					: ''
			);

			if ( 'create_product' === ( $step['form'] ?? '' ) ) {
				$this->render_product_form();
			}

			echo '</div></li>';
		}

		echo '</ol></div>';
	}

	private function render_bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['submex_changed'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$changed = max( 0, (int) sanitize_text_field( wp_unslash( $_GET['submex_changed'] ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$asked = isset( $_GET['submex_asked'] ) ? max( 0, (int) sanitize_text_field( wp_unslash( $_GET['submex_asked'] ) ) ) : 0;
		$held  = $asked - $changed;

		printf(
			'<div class="%s"><p>%s%s</p></div>',
			esc_attr( Notices::feedback( $changed ? 'success' : 'warning', true ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of subscriptions */
					_n( '%d subscription updated.', '%d subscriptions updated.', $changed, 'submex' ),
					$changed
				)
			),
			$held > 0
				? ' ' . esc_html(
					sprintf(
						/* translators: %d: number of subscriptions left alone */
						_n(
							'%d was left alone because that change is not allowed from its current status.',
							'%d were left alone because that change is not allowed from their current status.',
							$held,
							'submex'
						),
						$held
					)
				)
				: ''
		);
	}

	private function render_product_notice(): void {
		$result = isset( $_GET['submex_product'] ) ? sanitize_key( wp_unslash( $_GET['submex_product'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = match ( $result ) {
			'created' => __( 'Your subscription product is published and ready to sell.', 'submex' ),
			'invalid' => __( 'Check the name, the price and the interval: the price must be above zero and the interval a whole number of periods.', 'submex' ),
			'failed'  => __( 'The product could not be created.', 'submex' ),
			default   => '',
		};

		if ( '' !== $message ) {
			printf(
				'<div class="%s"><p>%s</p></div>',
				esc_attr( Notices::feedback( 'created' === $result ? 'success' : 'error', true ) ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Creating the first product here rather than sending the merchant to find the
	 * Product data panel, which is the step people get stuck on.
	 */
	private function render_product_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="submex-inline-form">
			<?php wp_nonce_field( 'submex_create_product' ); ?>
			<input type="hidden" name="action" value="submex_create_product" />

			<label class="submex-field">
				<span><?php esc_html_e( 'Name', 'submex' ); ?></span>
				<input type="text" name="submex_name" required style="width:14rem" />
			</label>

			<label class="submex-field">
				<span><?php esc_html_e( 'Price', 'submex' ); ?></span>
				<input type="text" name="submex_price" required style="width:6rem" />
			</label>

			<label class="submex-field">
				<span><?php esc_html_e( 'Every', 'submex' ); ?></span>
				<input type="number" name="submex_interval" value="1" min="1" max="365" style="width:4.5rem" />
			</label>

			<label class="submex-field">
				<span class="screen-reader-text"><?php esc_html_e( 'Billing period', 'submex' ); ?></span>
				<select name="submex_period">
					<option value="day"><?php esc_html_e( 'Days', 'submex' ); ?></option>
					<option value="week"><?php esc_html_e( 'Weeks', 'submex' ); ?></option>
					<option value="month" selected><?php esc_html_e( 'Months', 'submex' ); ?></option>
					<option value="year"><?php esc_html_e( 'Years', 'submex' ); ?></option>
				</select>
			</label>

			<label class="submex-field">
				<span><?php esc_html_e( 'Free trial (days)', 'submex' ); ?></span>
				<input type="number" name="submex_trial" value="0" min="0" max="365" style="width:5.5rem" />
			</label>

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create it', 'submex' ); ?></button>
		</form>
		<?php
	}

	private function render_detail( int $id ): void {
		$subscription = wc_get_order( $id );

		if ( ! $subscription instanceof Subscription ) {
			Page_Shell::open( __( 'Subscription not found', 'submex' ) );
			Page_Shell::close();
			return;
		}

		$state = Status_Presenter::for( $subscription );
		$back  = add_query_arg( array( 'page' => self::LIST_SLUG ), admin_url( 'admin.php' ) );

		/* translators: %d: subscription ID */
		$heading = sprintf( __( 'Subscription #%d', 'submex' ), $id );

		// The heading below carries the status pill, so the shell's is for screen readers.
		Page_Shell::open(
			$heading,
			'',
			array(
				array(
					'label' => __( 'All subscriptions', 'submex' ),
					'url'   => $back,
				),
				array( 'label' => '#' . $id ),
			),
			'',
			false
		);

		printf(
			'<h2 class="submex-detail-heading">%s <span class="submex-pill submex-pill--%s">%s</span></h2><p class="submex-lede"><a href="%s">&larr; %s</a></p>',
			esc_html( $heading ),
			esc_attr( (string) $subscription->get_status() ),
			esc_html( $state['label'] ),
			esc_url( $back ),
			esc_html__( 'All subscriptions', 'submex' )
		);

		echo '<table class="widefat striped submex-table submex-facts"><tbody>';
		$this->row( __( 'Customer', 'submex' ), trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() ) ?: (string) $subscription->get_billing_email() );
		$this->row( __( 'Recurring total', 'submex' ), wp_strip_all_tags( $subscription->get_formatted_order_total() ) );
		$this->row( __( 'Billing', 'submex' ), sprintf( '%d / %s', $subscription->get_billing_interval(), $subscription->get_billing_period() ) );
		$this->row( __( 'Next payment', 'submex' ), (string) $subscription->get_next_payment() ?: '-' );
		$this->pending_row( $subscription );
		$this->row( __( 'Trial ends', 'submex' ), (string) $subscription->get_trial_end() ?: '-' );
		$this->row( __( 'Payment method', 'submex' ), $subscription->get_payment_method_title() ?: (string) $subscription->get_payment_method() );
		$this->row( __( 'Parent order', 'submex' ), $subscription->get_parent_order_id() ? '#' . $subscription->get_parent_order_id() : '-' );
		echo '</tbody></table>';

		$this->render_process_button( $subscription );

		$this->render_activity( $id );

		/**
		 * Renders on the subscription detail screen, below its facts.
		 *
		 * @param Subscription $subscription
		 */
		do_action( 'submex_admin_subscription_detail', $subscription );

		Page_Shell::close();
	}

	/**
	 * The most useful support tool in the plugin, and the most dangerous button - it
	 * charges a real customer, so it names the amount and asks first.
	 */
	private function render_process_button( Subscription $subscription ): void {
		// Processing would only resume the charge already waiting on the payment provider.
		if ( Status_Presenter::pending_charge( $subscription ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'submex_process_renewal',
					'subscription' => $subscription->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'submex_process_renewal_' . $subscription->get_id()
		);

		$confirm = sprintf(
			/* translators: %s: recurring total */
			__( 'This charges the customer %s right now. Continue?', 'submex' ),
			wp_strip_all_tags( $subscription->get_formatted_order_total() )
		);

		printf(
			'<p><a href="%s" class="button" onclick="return confirm(%s)">%s</a></p>',
			esc_url( $url ),
			esc_attr( wp_json_encode( $confirm ) ),
			esc_html__( 'Process renewal now', 'submex' )
		);
	}

	/**
	 * Recurring revenue at a glance, above the list.
	 */
	private function render_summary(): void {
		$stats = \Submex\Plugin::instance()->get( 'stats' );

		if ( ! $stats instanceof \Submex\Data\Stats ) {
			return;
		}

		$mrr     = $stats->mrr();
		$history = $stats->history( 31 );
		$oldest  = $history ? reset( $history ) : null;
		$change  = $oldest && isset( $oldest['mrr'] ) && $oldest['mrr'] > 0
			? round( ( ( $mrr->minor() - $oldest['mrr'] ) / $oldest['mrr'] ) * 100, 1 )
			: null;

		echo '<div id="submex-overview-fallback"><div class="submex-stats">';

		printf(
			'<div class="submex-stat"><span class="submex-stat__label">%s</span><span class="submex-stat__value">%s</span>%s</div>',
			esc_html__( 'Monthly recurring revenue', 'submex' ),
			wp_kses_post( $mrr->format() ),
			null === $change
				? '<span class="submex-stat__meta">' . esc_html__( 'Tracking starts today', 'submex' ) . '</span>'
				: sprintf(
					'<span class="submex-stat__meta submex-delta--%s">%s%s%% %s</span>',
					$change < 0 ? 'down' : 'up',
					$change < 0 ? '' : '+',
					esc_html( (string) $change ),
					esc_html__( 'over 30 days', 'submex' )
				)
		);

		printf(
			'<div class="submex-stat"><span class="submex-stat__label">%s</span><span class="submex-stat__value">%s</span></div>',
			esc_html__( 'Live subscriptions', 'submex' ),
			esc_html( number_format_i18n( $stats->active_count() ) )
		);

		$excluded = $stats->excluded_currency_count();

		if ( $excluded ) {
			printf(
				'<div class="submex-stat"><span class="submex-stat__label">%s</span><span class="submex-stat__value">%s</span><span class="submex-stat__meta">%s</span></div>',
				esc_html__( 'Not counted', 'submex' ),
				esc_html( number_format_i18n( $excluded ) ),
				esc_html__( 'in another currency', 'submex' )
			);
		}

		echo '</div></div>';
	}

	private function render_activity( int $id ): void {
		$entries = $this->activity->for_subscription( $id, 30 );

		echo '<h2>' . esc_html__( 'Activity', 'submex' ) . '</h2>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet.', 'submex' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped submex-table submex-facts"><tbody>';
		foreach ( $entries as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( $entry->created_gmt ),
				esc_html( $entry->message ),
				esc_html( $entry->actor )
			);
		}
		echo '</tbody></table>';
	}

	public function process_renewal_now(): void {
		$id = absint( $_GET['subscription'] ?? 0 );

		if ( ! current_user_can( self::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'submex_process_renewal_' . $id ) ) {
			wp_die( esc_html__( 'That request could not be verified.', 'submex' ) );
		}

		$this->processor->process( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::LIST_SLUG,
					'subscription' => $id,
					'processed'    => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function pending_row( Subscription $subscription ): void {
		$pending = Status_Presenter::pending_charge( $subscription );

		if ( ! $pending ) {
			return;
		}

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Payment processing', 'submex' ),
			sprintf(
				/* translators: 1: renewal order number, linked, 2: how long ago it was submitted, such as "3 days" */
				esc_html__( 'Renewal order %1$s was submitted %2$s ago and is waiting for the payment provider to confirm it.', 'submex' ),
				sprintf( '<a href="%s">#%s</a>', esc_url( $pending['order']->get_edit_order_url() ), esc_html( $pending['order']->get_order_number() ) ),
				esc_html( human_time_diff( $pending['since'] ) )
			)
		);
	}

	private function row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
}
