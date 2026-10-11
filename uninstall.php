<?php
/**
 * Removes Submex's own data when the plugin is deleted, and only when the store asked for
 * that under WooCommerce → Settings → Subscriptions.
 *
 * Subscriptions and their orders are deliberately left alone either way. They are
 * WooCommerce orders and part of the store's financial record; deleting a plugin is not
 * consent to destroy them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( 'yes' !== get_option( 'submex_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'submex_schedule', 'submex_activity', 'submex_charge_slot' ) as $submex_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own tables is what uninstall is for.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $submex_table ) );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API deletes options by prefix.
foreach ( array( '', '_transient_', '_transient_timeout_' ) as $submex_prefix ) {
	// Pro (submex_pro_, its gateways' submex_submex_) and add-ons (submex_cr_) share the prefix; their own uninstall removes what is theirs.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s",
			$wpdb->esc_like( $submex_prefix . 'submex_' ) . '%',
			$wpdb->esc_like( $submex_prefix . 'submex_pro_' ) . '%',
			$wpdb->esc_like( $submex_prefix . 'submex_cr_' ) . '%',
			$wpdb->esc_like( $submex_prefix . 'submex_submex_' ) . '%',
			'submex_delete_data_on_uninstall'
		)
	);
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

foreach ( array( 'submex_dismissed_notices', '_submex_fluentcrm_added_tags', '_submex_fluentcrm_added_lists' ) as $submex_user_meta ) {
	delete_metadata( 'user', 0, $submex_user_meta, '', true );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'submex_scheduled_renewal', 'submex_renewal_reminder', 'submex_reactivated_email', 'submex_sweep_overdue', 'submex_settle_paid_renewal', 'submex_resolve_pending_renewal', 'submex_daily_snapshot', 'submex_paypal_process_webhook' ) as $submex_action ) {
		// Hook alone: given a group as well, Action Scheduler only matches actions queued with no arguments.
		as_unschedule_all_actions( $submex_action );
	}
}

// Pro and the add-ons read this when they are deleted, so it goes only with the last of them.
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$submex_extensions = array_filter(
	array_keys( get_plugins() ),
	static fn( string $file ): bool => in_array( basename( $file ), array( 'submex-pro.php', 'submex-content-restriction.php' ), true )
);
if ( ! $submex_extensions ) {
	delete_option( 'submex_delete_data_on_uninstall' );
}

wp_cache_flush();
