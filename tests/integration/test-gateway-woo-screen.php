<?php
/**
 * Stripe and PayPal are set up on their own WooCommerce → Payments screen: the setup card, the environment and the keys
 * are drawn and saved there, and Submex → Settings sends old links to it.
 *
 * @package Submex
 */

use Submex\Admin\Gateway_Settings;
use Submex\Admin\Settings_Page;
use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	final class Submex_Test_Redirected extends Exception {}
}
add_filter(
	'wp_redirect',
	static function ( $to ) {
		throw new Submex_Test_Redirected( (string) $to );
	},
	1
);

$gateways = WC()->payment_gateways()->payment_gateways();
$stripe   = $gateways['submex_stripe'] ?? null;
$paypal   = $gateways['submex_paypal'] ?? null;
$screen   = \Submex\Plugin::instance()->get( 'settings_page' );

if ( ! $stripe instanceof WC_Payment_Gateway || ! $paypal instanceof WC_Payment_Gateway || ! $screen instanceof Settings_Page ) {
	submex_test_abort( 'Stripe, PayPal or the settings screen is not registered' );
}

$names = array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'submex_stripe_secret', 'submex_paypal_live' );
$kept  = array();
foreach ( $names as $name ) {
	$kept[ $name ] = get_option( $name, null );
}

$draw = static function ( WC_Payment_Gateway $gateway ): string {
	ob_start();
	$gateway->admin_options();
	return (string) ob_get_clean();
};

echo "\nWhere Manage goes\n";
$check( 'Stripe\'s Manage opens its own WooCommerce screen', str_contains( $stripe->get_settings_url(), 'tab=checkout' ) && str_contains( $stripe->get_settings_url(), 'section=submex_stripe' ), $stripe->get_settings_url() );
$check( 'and so does PayPal\'s', str_contains( $paypal->get_settings_url(), 'section=submex_paypal' ), $paypal->get_settings_url() );
$check( 'a gateway section links to that screen', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=submex_stripe' ) === Gateway_Settings::url( 'stripe' ) );
$check( 'a section with no screen of its own stays in Submex → Settings', Settings_Page::section_url( 'checkout' ) === Gateway_Settings::url( 'checkout' ) );
$check( 'Submex → Settings no longer lists Stripe or PayPal', ! isset( $screen->sections()['stripe'] ) && ! isset( $screen->sections()['paypal'] ), array_keys( $screen->sections() ) );
$moved = static fn( $sections ) => (array) $sections + array( 'submex_test_moved' => 'notifications' );
add_filter( 'submex_gateway_settings_sections', $moved );
$check( 'any section an extension moves to its gateway\'s screen leaves Submex → Settings', ! isset( $screen->sections()['notifications'] ) );
remove_filter( 'submex_gateway_settings_sections', $moved );
$check( '  and comes back when it no longer is', isset( $screen->sections()['notifications'] ) );

$_GET = array( 'section' => 'paypal' );
try {
	$screen->redirect_gateway_section();
	$to = '';
} catch ( Submex_Test_Redirected $redirect ) {
	$to = $redirect->getMessage();
}
$check( 'an old link to the PayPal section opens PayPal\'s WooCommerce screen', str_contains( $to, 'section=submex_paypal' ), $to );
$_GET = array( 'section' => 'checkout' );
try {
	$screen->redirect_gateway_section();
	$to = '';
} catch ( Submex_Test_Redirected $redirect ) {
	$to = $redirect->getMessage();
}
$check( 'other sections stay put', '' === $to, $to );
$_GET = array();

echo "\nThe screen\n";
update_option( 'submex_stripe_live', 'no' );
$html  = $draw( $stripe );
$card  = strpos( $html, 'submex-setup' );
$table = strpos( $html, 'woocommerce_submex_stripe_enabled' );
$check( 'the setup card comes before WooCommerce\'s Enable', false !== $card && false !== $table && $card < $table );
$check( 'the environment and both keys are on it', str_contains( $html, 'id="submex_stripe_live"' ) && str_contains( $html, 'id="submex_stripe_test_secret"' ) && str_contains( $html, 'id="submex_stripe_secret"' ) );
$check( 'Submex\'s own switch is not, as WooCommerce\'s Enable sets it', ! str_contains( $html, 'id="submex_stripe_enabled"' ) );
$check( 'in test mode the live key\'s row starts hidden', 1 === preg_match( '/<tr[^>]*wc-settings-row-submex-hidden[^>]*>(?:(?!<\/tr>).)*id="submex_stripe_secret"/s', $html ) );
$check( 'and the test key\'s does not', 0 === preg_match( '/<tr[^>]*wc-settings-row-submex-hidden[^>]*>(?:(?!<\/tr>).)*id="submex_stripe_test_secret"/s', $html ) );
$check( 'each environment has its card, the other one hidden', 1 === preg_match( '/<div class="submex-gateway-card" data-submex-show-when="[^"]*&quot;no&quot;[^"]*">/', $html ) && 1 === preg_match( '/<div class="submex-gateway-card" hidden data-submex-show-when="[^"]*&quot;yes&quot;/', $html ), $html );
$check( 'the script reads which rows depend on the select', str_contains( $html, 'id="submex-gateway-when"' ) );

$html = $draw( $paypal );
$check( 'PayPal\'s screen shows its webhook URL to copy', str_contains( $html, rest_url( 'submex/v1/webhook/paypal' ) ) && str_contains( $html, 'data-submex-copy' ) );
preg_match_all( '/\sid="(submex-[^"]+)"/', $html, $ids );
$twice = array_keys( array_filter( array_count_values( $ids[1] ), static fn( $n ) => $n > 1 ) );
$check( 'each environment\'s card has its own ids, so Copy copies from the card on screen', array() === $twice, $twice );

update_option( 'submex_stripe_live', 'live' );
$html = $draw( $stripe );
$check( 'an environment saved outside the select\'s choices reads as its default, so the test key still shows', 0 === preg_match( '/<tr[^>]*wc-settings-row-submex-hidden[^>]*>(?:(?!<\/tr>).)*id="submex_stripe_test_secret"/s', $html ) && 1 === preg_match( '/<div class="submex-gateway-card" data-submex-show-when="[^"]*&quot;no&quot;/', $html ) );
update_option( 'submex_stripe_live', 'no' );

echo "\nSaving\n";
$_POST = array(
	'woocommerce_submex_stripe_enabled'     => '1',
	'woocommerce_submex_stripe_title'       => 'Card via Stripe',
	'woocommerce_submex_stripe_description' => 'Pay by card.',
	'submex_stripe_live'                    => 'yes',
	'submex_stripe_test_secret'             => 'sk_test_kept_by_the_screen',
	'submex_stripe_secret'                  => '',
);
$_REQUEST = $_POST;
$stripe->process_admin_options();
$saved = (array) get_option( 'woocommerce_submex_stripe_settings', array() );
$check( 'WooCommerce\'s own fields save as before', 'Card via Stripe' === ( $saved['title'] ?? '' ) && 'yes' === ( $saved['enabled'] ?? '' ), $saved );
$check( 'the environment saves to Submex\'s option', 'yes' === get_option( 'submex_stripe_live' ) );
$check( 'the hidden test key keeps what was posted', 'sk_test_kept_by_the_screen' === get_option( 'submex_stripe_test_secret' ) );
$check( 'turning it on here turns on Submex\'s switch too', 'yes' === get_option( 'submex_stripe_enabled' ) );

$_POST    = array(
	'woocommerce_submex_stripe_title' => 'Card via Stripe',
	'submex_stripe_live'              => 'yes',
	'submex_stripe_test_secret'       => 'sk_test_kept_by_the_screen',
);
$_REQUEST = $_POST;
$stripe->process_admin_options();
$billing = (bool) Subscription_Query::ids( array( 'payment_method' => 'submex_stripe', 'status' => array( Subscription_Status::Active->value, Subscription_Status::OnHold->value, Subscription_Status::Trialling->value, Subscription_Status::PendingCancel->value, Subscription_Status::Pending->value ), 'limit' => 1 ) );
$check( 'turning it off stops checkout', 'no' === ( get_option( 'woocommerce_submex_stripe_settings' )['enabled'] ?? '' ) );
$check( $billing ? 'with subscriptions already paying by Stripe on this site, Submex keeps them renewing' : 'with nothing billing through Stripe, Submex turns it off too, so its warnings stop', ( $billing ? 'yes' : 'no' ) === get_option( 'submex_stripe_enabled' ) );

update_option( 'submex_stripe_enabled', 'yes' );
$sub = new Subscription();
$sub->set_customer_id( 1 );
$sub->set_currency( 'USD' );
$sub->set_billing_period( 'month' );
$sub->set_billing_interval( 1 );
$sub->set_payment_method( 'submex_stripe' );
$sub->set_billing_email( 'sb-woo-screen@example.test' );
$sub->transition_to( Subscription_Status::Pending );
$sub->save();
$sub->transition_to( Subscription_Status::Active );
$sub->save();
$stripe->process_admin_options();
$check( 'with a subscription still paying by Stripe, turning checkout off leaves it renewing', 'yes' === get_option( 'submex_stripe_enabled' ) );
$sub->delete( true );

if ( ! $billing ) {
	$pending = new Subscription();
	$pending->set_customer_id( 1 );
	$pending->set_currency( 'USD' );
	$pending->set_payment_method( 'submex_stripe' );
	$pending->transition_to( Subscription_Status::Pending );
	$pending->save();
	update_option( 'submex_stripe_enabled', 'yes' );
	$stripe->process_admin_options();
	$check( 'a checkout still being paid for keeps it on', 'yes' === get_option( 'submex_stripe_enabled' ) );
	$pending->set_date_created( time() - 3 * DAY_IN_SECONDS );
	$pending->save();
	$stripe->process_admin_options();
	$check( 'one left at the provider for days does not', 'no' === get_option( 'submex_stripe_enabled' ) );
	$pending->delete( true );
}
$_POST    = array();
$_REQUEST = array();

foreach ( $kept as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
