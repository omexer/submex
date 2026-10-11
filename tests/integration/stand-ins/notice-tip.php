<?php
/**
 * A notice callback of our own for the notices test: it has to live in a file inside the plugin, as ours do.
 *
 * @package Submex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function submex_harness_tip(): void {
	echo '<div class="notice notice-info"><p>Submex Pro is in test licence mode.</p></div>';
}
