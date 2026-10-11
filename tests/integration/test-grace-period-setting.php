<?php
/**
 * The grace period is only offered where something reads it: Submex Pro's payment retries, which add it.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';

$page = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$page = $candidate;
	}
}
if ( ! $page ) {
	submex_test_abort( 'the Subscriptions settings tab is not registered' );
}

$own   = array_column( $page->get_settings_for_renewal_section(), 'id' );
$shown = array_column( (array) $page->get_settings_for_section( 'renewal' ), 'id' );

$check( 'Submex itself never offers the grace period', ! array_intersect( array( 'submex_grace_period_days', 'submex_grace_title' ), $own ), $own );
$check( 'while the settings around it still are', in_array( 'submex_catch_up_policy', $own, true ) );
$check(
	did_action( 'submex_pro_loaded' ) ? 'with Submex Pro it is on the page' : 'nor is it on the page without Submex Pro',
	(bool) did_action( 'submex_pro_loaded' ) === in_array( 'submex_grace_period_days', $shown, true ),
	$shown
);

submex_test_done( $fail );
