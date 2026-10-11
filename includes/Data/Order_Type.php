<?php

namespace Submex\Data;

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the submex_sub order type, its statuses, and the data store that backs it.
 */
class Order_Type {

	private ?object $hpos_store = null;

	public function register(): void {
		add_action( 'init', array( $this, 'register_statuses' ), 5 );
		add_action( 'init', array( $this, 'register_order_type' ), 6 );
		add_filter( 'woocommerce_data_stores', array( $this, 'register_data_store' ) );
	}

	/**
	 * Statuses are registered as post statuses so the legacy CPT path keeps working;
	 * HPOS stores the slug directly in the orders table.
	 */
	public function register_statuses(): void {
		foreach ( Subscription_Status::cases() as $status ) {
			register_post_status(
				'wc-' . $status->value,
				array(
					'label'                     => $status->label(),
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of subscriptions */
					'label_count'               => _n_noop(
						'Subscriptions <span class="count">(%s)</span>',
						'Subscriptions <span class="count">(%s)</span>',
						'submex'
					),
				)
			);
		}
	}

	public function register_order_type(): void {
		wc_register_order_type(
			Subscription::TYPE,
			array(
				'labels'                           => array(
					'name'          => __( 'Subscriptions', 'submex' ),
					'singular_name' => __( 'Subscription', 'submex' ),
				),
				'public'                           => false,
				'show_ui'                          => false,
				'show_in_menu'                     => false,
				'capability_type'                  => 'shop_order',
				'map_meta_cap'                     => true,
				'hierarchical'                     => false,
				'supports'                         => array( 'title', 'comments', 'custom-fields' ),
				'rewrite'                          => false,
				'query_var'                        => false,
				'class_name'                       => Subscription::class,
				// Subscriptions are not sales; keep them out of Woo's own counts and reports.
				'exclude_from_orders_screen'       => true,
				'exclude_from_order_count'         => true,
				'exclude_from_order_views'         => true,
				'exclude_from_order_reports'       => true,
				'exclude_from_order_sales_reports' => true,
				'exclude_from_order_webhooks'      => true,
				'add_order_meta_boxes'             => false,
			)
		);
	}

	/**
	 * Point the submex_sub data store at whichever order storage the site is using.
	 *
	 * WooCommerce does not wire custom order types to the HPOS data store automatically,
	 * so without this WC_Data_Store::load( 'submex_sub' ) throws.
	 *
	 * The HPOS store MUST be handed over as an object resolved from WooCommerce's
	 * container. WC_Data_Store::__construct() does a bare `new $store()` when given a
	 * class name, which skips dependency injection and leaves OrdersTableDataStore's
	 * $database_util null — the first save then fatals inside get_db_row_from_order().
	 */
	public function register_data_store( array $stores ): array {
		$stores[ Subscription::TYPE ] = $this->order_data_store();

		return $stores;
	}

	private function order_data_store(): object|string {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( ! $hpos ) {
			return \WC_Order_Data_Store_CPT::class;
		}

		if ( null === $this->hpos_store && function_exists( 'wc_get_container' ) ) {
			$this->hpos_store = wc_get_container()->get(
				\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class
			);
		}

		return $this->hpos_store ?? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class;
	}
}
