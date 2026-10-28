<?php
/**
 * The setup notice names the consequence that actually follows.
 *
 * A missing PayPal webhook ID was once reported as "PayPal is not offered at checkout",
 * which is not what happens: PayPal is offered and charges the customer, and every
 * webhook is then rejected, so no renewal is ever recorded.
 *
 * @package Submex
 */

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$keys   = array( 'submex_paypal_enabled', 'submex_paypal_client_id', 'submex_paypal_secret', 'submex_paypal_webhook_id', 'submex_stripe_enabled' );
$before = array();
foreach ( $keys as $key ) {
	$before[ $key ] = get_option( $key, null );
}
// Stripe has its own notice, covered by test-stripe-in-free.
update_option( 'submex_stripe_enabled', 'no' );

$saved_screen   = $GLOBALS['current_screen'] ?? null;
$dismissed_meta = get_user_meta( 1, 'submex_dismissed_notices', true );
delete_user_meta( 1, 'submex_dismissed_notices' );
set_current_screen( 'toplevel_page_submex' );

$render = static function (): string {
	ob_start();
	( new \Submex\Admin\Gateway_Notice() )->render();

	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
};

update_option( 'submex_paypal_enabled', 'yes' );

update_option( 'submex_paypal_client_id', 'AX' );
update_option( 'submex_paypal_secret', 'SX' );
update_option( 'submex_paypal_webhook_id', '' );
$text = $render();
$check( 'a missing webhook ID says renewals go unrecorded', str_contains( $text, 'renewals will not be recorded' ), $text );
$check( 'and does not claim PayPal is hidden at checkout', ! str_contains( $text, 'not offered at checkout' ), $text );

update_option( 'submex_paypal_client_id', '' );
update_option( 'submex_paypal_secret', '' );
$text = $render();
$check( 'missing credentials say PayPal is not offered', str_contains( $text, 'not offered at checkout' ), $text );
$check( 'and do not also claim customers can pay', ! str_contains( $text, 'Customers can pay' ), $text );

update_option( 'submex_paypal_client_id', 'AX' );
update_option( 'submex_paypal_secret', 'SX' );
update_option( 'submex_paypal_webhook_id', 'WH-1' );
$check( 'a complete setup shows nothing', '' === $render(), $render() );

$GLOBALS['current_screen'] = $saved_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
'' === $dismissed_meta ? delete_user_meta( 1, 'submex_dismissed_notices' ) : update_user_meta( 1, 'submex_dismissed_notices', $dismissed_meta );
foreach ( $before as $key => $value ) {
	null === $value ? delete_option( $key ) : update_option( $key, $value );
}

submex_test_done( $fail );
