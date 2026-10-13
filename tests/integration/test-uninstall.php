<?php
/**
 * Deleting the plugin removes its own data only when asked, and never touches subscriptions.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';
// uninstall_plugin() is defined here, so a real uninstall always has it loaded.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$tables = array( 'submex_schedule', 'submex_activity', 'submex_charge_slot' );
$exists = function ( string $t ) use ( $wpdb ): bool {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
};

// --- back up -------------------------------------------------------------------------
$backup = array();
foreach ( $tables as $t ) {
	$backup[ $t ] = $exists( $t ) ? $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$t}", ARRAY_A ) : null;
}
$options = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'submex\_%'", ARRAY_A );
$planted = new \Submex\Domain\Subscription();
$planted->set_customer_id( 1 );
$planted->transition_to( \Submex\Domain\Subscription_Status::Pending );
$planted->save();

printf( "backed up: %s rows across %d tables, %d options\n\n", implode( '/', array_map( fn( $r ) => null === $r ? 'x' : count( $r ), $backup ) ), count( $tables ), count( $options ) );

$delivery_rows = $exists( 'submex_delivery' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_delivery" ) : -1;

$run = function () { define( 'WP_UNINSTALL_PLUGIN', 'submex/submex.php' ); include SUBMEX_PATH . 'uninstall.php'; };

// Every action free queues, including the ones that settle a pending renewal; none may fire into a deleted plugin.
$queued_actions = array(
	\Submex\Billing\Renewal_Scheduler::ACTION_RENEWAL,
	\Submex\Billing\Renewal_Scheduler::ACTION_REMINDER,
	\Submex\Emails\Mailer::ACTION_REACTIVATED,
	\Submex\Billing\Renewal_Scheduler::ACTION_SWEEP,
	\Submex\Billing\Renewal_Processor::ACTION_SETTLE,
	\Submex\Billing\Renewal_Processor::ACTION_RESOLVE,
	\Submex\Data\Stats::ACTION_SNAPSHOT,
	\Submex\Gateways\PayPal\Webhook_Controller::ACTION_PROCESS,
);
foreach ( $queued_actions as $hook ) {
	as_schedule_single_action( time() + DAY_IN_SECONDS, $hook, array( 'order_id' => 999999001 ), \Submex\Billing\Renewal_Scheduler::GROUP );
}
$still_queued = static fn(): array => array_values( array_filter( $queued_actions, static fn( string $hook ): bool => (bool) as_next_scheduled_action( $hook, null, \Submex\Billing\Renewal_Scheduler::GROUP ) ) );

// --- 1. the guard --------------------------------------------------------------------
update_option( 'submex_delete_data_on_uninstall', 'no' );
$run();
$check( 'leaves everything alone when the store did not ask', $exists( 'submex_schedule' ) && $exists( 'submex_activity' ) && $exists( 'submex_charge_slot' ) );
$check( 'and keeps the settings', (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'submex\_%'" ) );
$check( 'and the queued work', $queued_actions === $still_queued(), $still_queued() );

// --- 2. the real thing ---------------------------------------------------------------
// Pro's and the add-on's settings share the prefix; free deleted first must leave them for their own uninstall.
$theirs = array( 'submex_pro_sbt_probe', 'submex_cr_sbt_probe', 'submex_submex_sbt_probe' );
foreach ( $theirs as $name ) {
	update_option( $name, 'kept' );
}
set_transient( 'submex_pro_sbt_probe', 'kept', HOUR_IN_SECONDS );
set_transient( 'submex_cr_sbt_probe', 'kept', HOUR_IN_SECONDS );
set_transient( 'submex_sbt_probe', 'gone', HOUR_IN_SECONDS );

// An add-on still installed: a plugin file by the add-on's name, which is all uninstall looks for.
$stand_in = WP_PLUGIN_DIR . '/submex-cr-harness-' . wp_generate_password( 6, false ) . '/submex-content-restriction.php';
wp_mkdir_p( dirname( $stand_in ) );
file_put_contents( $stand_in, "<?php\n/**\n * Plugin Name: Submex Content Restriction harness\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
wp_clean_plugins_cache( false );

update_option( 'submex_delete_data_on_uninstall', 'yes' );
// WP_UNINSTALL_PLUGIN is already defined, so run the file's body a second time directly.
include SUBMEX_PATH . 'uninstall.php';

foreach ( $tables as $t ) {
	$check( "drops {$t}", ! $exists( $t ) );
}
$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'submex\_%' AND option_name NOT LIKE 'submex\_pro\_%' AND option_name NOT LIKE 'submex\_cr\_%' AND option_name NOT LIKE 'submex\_submex\_%'" );
$check( 'removes every setting of its own, but the switch an extension still installed reads', array( 'submex_delete_data_on_uninstall' ) === $left, $left );
$check( "leaves Pro's and the add-on's settings for their own uninstall", array( 'kept', 'kept', 'kept' ) === array_map( static fn( $n ) => get_option( $n ), $theirs ) );
$check( 'and their transients', 'kept' === get_transient( 'submex_pro_sbt_probe' ) && 'kept' === get_transient( 'submex_cr_sbt_probe' ) && false === get_transient( 'submex_sbt_probe' ) );

unlink( $stand_in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
rmdir( dirname( $stand_in ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
wp_clean_plugins_cache( false );
$pro_installed = (bool) array_filter( array_keys( get_plugins() ), static fn( $f ) => 'submex-pro.php' === basename( $f ) );
include SUBMEX_PATH . 'uninstall.php';
$check( $pro_installed ? 'with Pro still installed, the switch stays' : 'with no extension left, the switch goes too', $pro_installed === ( 'yes' === get_option( 'submex_delete_data_on_uninstall' ) ) );

foreach ( $theirs as $name ) {
	delete_option( $name );
}
delete_transient( 'submex_pro_sbt_probe' );
delete_transient( 'submex_cr_sbt_probe' );
delete_option( 'submex_delete_data_on_uninstall' );
$check( "leaves Pro's delivery table alone", $delivery_rows === ( $exists( 'submex_delivery' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}submex_delivery" ) : -1 ) );
$check( 'leaves subscriptions alone', wc_get_order( $planted->get_id() ) instanceof \Submex\Domain\Subscription );
$check( 'unschedules everything it queued, pending-renewal settlement included', array() === $still_queued(), $still_queued() );

// --- 3. put it back ------------------------------------------------------------------
delete_option( 'submex_db_version' );
( new \Submex\Data\Migrator() )->install();

foreach ( $backup as $t => $rows ) {
	if ( null === $rows ) { continue; }
	foreach ( $rows as $row ) { $wpdb->insert( $wpdb->prefix . $t, $row ); }
}
foreach ( $options as $o ) {
	$wpdb->insert( $wpdb->options, array( 'option_name' => $o['option_name'], 'option_value' => $o['option_value'], 'autoload' => $o['autoload'] ) );
}
wp_cache_flush();

$restored = array();
foreach ( $tables as $t ) { $restored[ $t ] = $exists( $t ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$t}" ) : -1; }
foreach ( $backup as $t => $rows ) {
	$check( "restored {$t}", $restored[ $t ] === ( null === $rows ? -1 : count( $rows ) ), array( 'was' => null === $rows ? 'absent' : count( $rows ), 'now' => $restored[ $t ] ) );
}
$check( 'restored the settings', count( $options ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'submex\_%'" ) );

$planted->delete( true );
foreach ( $queued_actions as $hook ) {
	as_unschedule_all_actions( $hook, array( 'order_id' => 999999001 ), \Submex\Billing\Renewal_Scheduler::GROUP );
}
submex_test_done( $fail );
