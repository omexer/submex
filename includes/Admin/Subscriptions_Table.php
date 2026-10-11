<?php

namespace Submex\Admin;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Status_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The subscriptions list. Uses WP_List_Table so it inherits sorting, pagination and
 * screen options, and looks like the rest of wp-admin rather than like a plugin.
 */
class Subscriptions_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'subscription' => __( 'Subscription', 'submex' ),
			'customer'     => __( 'Customer', 'submex' ),
			'status'       => __( 'Status', 'submex' ),
			'next_payment' => __( 'Next payment', 'submex' ),
			'total'        => __( 'Recurring total', 'submex' ),
			'gateway'      => __( 'Payment method', 'submex' ),
		);
	}

	/**
	 * @param Subscription $item
	 */
	public function column_cb( $item ): string {
		return $item instanceof Subscription
			? sprintf( '<input type="checkbox" name="subscription[]" value="%d" />', (int) $item->get_id() )
			: '';
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'subscription' => array( 'ID', false ),
			'next_payment' => array( 'next_payment', false ),
			'total'        => array( 'total', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'cancel' => __( 'Cancel', 'submex' ),
			'hold'   => __( 'Put on hold', 'submex' ),
			'resume' => __( 'Reactivate', 'submex' ),
		);
	}

	public function prepare_items(): void {
		$this->process_bulk_action();

		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$paged  = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$order = 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ?? 'desc' ) ) ) ? 'ASC' : 'DESC';

		$args = array(
			'limit'   => $per_page,
			'paged'   => $paged,
			'orderby' => $this->orderby_for( $orderby ),
			'order'   => $order,
		);

		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		if ( '' !== $search ) {
			// Matches the customer's name, email and the subscription id, which is what
			// somebody typing into this box is holding.
			$args['s'] = $search;
		}

		$this->items           = array_filter( Subscription_Query::get( $args ), static fn( $s ) => $s instanceof Subscription );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'subscription' );

		$total = count(
			Subscription_Query::ids(
				array_merge(
					$args,
					array(
						'limit' => -1,
						'paged' => 1,
					)
				)
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @param Subscription $item
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'subscription' => $this->subscription_cell( $item ) . $this->row_actions_for( $item ),
			'customer'     => esc_html( trim( $item->get_billing_first_name() . ' ' . $item->get_billing_last_name() ) ?: $item->get_billing_email() ?: '—' ),
			// The pill carries colour, but the label is always readable text inside it:
			// a merchant scanning for problems must not have to decode a swatch.
			'status'       => sprintf(
				'<span class="submex-pill submex-pill--%s">%s</span>%s',
				esc_attr( (string) $item->get_status() ),
				esc_html( Status_Presenter::for( $item )['label'] ),
				$this->pending_line( $item )
			),
			'next_payment' => $item->get_next_payment()
				? esc_html( date_i18n( (string) get_option( 'date_format' ), strtotime( $item->get_next_payment() . ' UTC' ) ) )
				: '—',
			'total'        => wp_kses_post( $item->get_formatted_order_total() ),
			'gateway'      => esc_html( $item->get_payment_method_title() ?: $item->get_payment_method() ?: '—' ),
			default        => '',
		};
	}

	private function row_actions_for( Subscription $item ): string {
		$view = add_query_arg(
			array(
				'page'         => Menu::LIST_SLUG,
				'subscription' => $item->get_id(),
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'submex' ) ),
		);

		$status = $item->get_status_enum();

		// Renewing would only resume the charge already waiting on the payment provider.
		if ( $status && $status->is_billable() && ! Status_Presenter::pending_charge( $item ) ) {
			$actions['renew'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'       => 'submex_process_renewal',
								'subscription' => $item->get_id(),
							),
							admin_url( 'admin-post.php' )
						),
						'submex_process_renewal_' . $item->get_id()
					)
				),
				esc_html__( 'Renew now', 'submex' )
			);
		}

		if ( $item->get_parent_order_id() ) {
			$actions['order'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( (string) $this->get_edit_order_url( $item->get_parent_order_id() ) ),
				esc_html__( 'Parent order', 'submex' )
			);
		}

		return $this->row_actions( $actions );
	}

	private function pending_line( Subscription $item ): string {
		$pending = Status_Presenter::pending_charge( $item );

		if ( ! $pending ) {
			return '';
		}

		return sprintf(
			'<br><span class="description">%s</span>',
			sprintf(
				/* translators: 1: renewal order number, linked, 2: how long ago it was submitted, such as "3 days" */
				esc_html__( 'Renewal order %1$s submitted %2$s ago', 'submex' ),
				sprintf( '<a href="%s">#%s</a>', esc_url( $pending['order']->get_edit_order_url() ), esc_html( $pending['order']->get_order_number() ) ),
				esc_html( human_time_diff( $pending['since'] ) )
			)
		);
	}

	/**
	 * wc_get_orders understands date and ID; anything of ours is order meta.
	 */
	private function orderby_for( string $orderby ): string {
		return match ( $orderby ) {
			'ID'           => 'ID',
			'next_payment' => 'meta_value',
			'total'        => 'total',
			default        => 'date',
		};
	}

	private function process_bulk_action(): void {
		$action = $this->current_action();

		if ( ! $action || ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// intval with a floor, not absint: a posted -4 must be discarded, not turned into 4.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$ids = array_filter( array_map( 'intval', (array) ( $_REQUEST['subscription'] ?? array() ) ), static fn( int $id ): bool => $id > 0 );

		if ( ! $ids ) {
			return;
		}

		$target = match ( $action ) {
			'cancel' => Subscription_Status::Cancelled,
			'hold'   => Subscription_Status::OnHold,
			'resume' => Subscription_Status::Active,
			default  => null,
		};

		if ( ! $target ) {
			return;
		}

		$changed = 0;

		foreach ( $ids as $id ) {
			$subscription = wc_get_order( $id );

			if ( ! $subscription instanceof Subscription ) {
				continue;
			}

			$from = $subscription->get_status_enum();

			// Skipped rather than forced: the state machine decides what is legal, and a
			// bulk action must not be a way around it.
			if ( ! $from || ! $from->can_transition_to( $target ) ) {
				continue;
			}

			$subscription->transition_to( $target, __( 'Changed in bulk from the subscriptions list.', 'submex' ) );
			$subscription->save();
			++$changed;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => Menu::LIST_SLUG,
					'submex_changed' => $changed,
					'submex_asked'   => count( $ids ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * A parent order lives on the WooCommerce orders screen, wherever HPOS puts it.
	 */
	private function get_edit_order_url( int $order_id ): string {
		$order = wc_get_order( $order_id );

		return $order ? (string) $order->get_edit_order_url() : '';
	}

	private function subscription_cell( Subscription $item ): string {
		$url = add_query_arg(
			array(
				'page'         => Menu::LIST_SLUG,
				'subscription' => $item->get_id(),
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<strong><a href="%s">#%d</a></strong><br><span class="description">%s</span>',
			esc_url( $url ),
			$item->get_id(),
			esc_html( Status_Presenter::title( $item ) )
		);
	}

	public function no_items(): void {
		esc_html_e( 'No subscriptions yet. Tick "Subscription" on a product to start selling one.', 'submex' );
	}

	/**
	 * Status filter links across the top, with counts.
	 */
	protected function get_views(): array {
		$current = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = add_query_arg( array( 'page' => Menu::LIST_SLUG ), admin_url( 'admin.php' ) );

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'submex' )
			),
		);

		foreach ( Subscription_Status::cases() as $status ) {
			$count = count(
				Subscription_Query::ids(
					array(
						'status' => $status->value,
						'limit'  => -1,
					)
				)
			);

			if ( 0 === $count ) {
				continue;
			}

			$views[ $status->value ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status->value, $base ) ),
				$current === $status->value ? ' class="current"' : '',
				esc_html( $status->label() ),
				$count
			);
		}

		return $views;
	}
}
