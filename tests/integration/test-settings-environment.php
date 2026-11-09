<?php
/**
 * A gateway's environment select shows only that environment's credentials, a setup card ticks what is done,
 * and saving in one environment never touches the other's keys.
 *
 * @package Submex
 */

use Submex\Admin\Allowed_Html;
use Submex\Admin\Settings_Page;
use Submex\Admin\Setup_Card;

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

$screen = \Submex\Plugin::instance()->get( 'settings_page' );
if ( ! $screen instanceof Settings_Page ) {
	submex_test_abort( 'the Submex settings screen is not registered' );
}

$p = 'submex_test_env_';

$card = array(
	'type'  => Setup_Card::TYPE,
	'title' => 'Set up Testpay',
	'done'  => 'Testpay is set up',
	'desc'  => 'Testpay renews <strong>cards</strong>.',
	'steps' => array(
		array(
			'label'            => 'Approve your domain',
			'detail'           => 'Testpay refuses payments from a domain it has not approved.',
			'action'           => array(
				'url'      => 'https://example.com/domains',
				'text'     => 'Open domains',
				'external' => true,
			),
			'submex_show_when' => array( $p . 'live' => 'yes' ),
		),
		array(
			'label'  => 'Add the webhook',
			'detail' => 'Paste this address into Testpay.',
			'copy'   => array(
				'label' => 'Webhook URL',
				'value' => 'https://shop.test/wp-json/testpay',
			),
			'list'   => array(
				'summary' => 'Events to send (2)',
				'items'   => array( 'payment.done', 'payment.failed' ),
			),
			'action' => array(
				'url'      => 'https://example.com/webhooks',
				'text'     => 'Open webhooks',
				'external' => true,
			),
		),
		array(
			'label'            => 'Paste your sandbox keys below',
			'filled'           => array( $p . 'sandbox_id', $p . 'sandbox_secret' ),
			'action'           => array(
				'url'      => 'https://sandbox.example.com/keys',
				'text'     => 'Open sandbox keys',
				'external' => true,
			),
			'submex_show_when' => array( $p . 'live' => 'no' ),
		),
		array(
			'label'            => 'Paste your live keys below',
			'filled'           => array( $p . 'live_id', $p . 'live_secret' ),
			'action'           => array(
				'url'      => 'https://example.com/keys',
				'text'     => 'Open live keys',
				'external' => true,
			),
			'submex_show_when' => array( $p . 'live' => 'yes' ),
		),
		array(
			'label'  => 'Turn on Testpay below',
			'filled' => array( $p . 'on' ),
		),
	),
);

$fields = array(
	array( 'title' => 'Testpay', 'type' => 'title', 'desc' => 'Testpay renews cards.', 'id' => $p . 'title' ),
	$card,
	array( 'title' => 'Enable Testpay', 'type' => 'checkbox', 'id' => $p . 'on', 'default' => 'no' ),
	array(
		'title'   => 'Environment',
		'type'    => 'select',
		'id'      => $p . 'live',
		'default' => 'no',
		'options' => array(
			'no'  => 'Sandbox',
			'yes' => 'Live',
		),
	),
	array( 'title' => 'Sandbox ID', 'type' => 'text', 'id' => $p . 'sandbox_id', 'submex_show_when' => array( $p . 'live' => 'no' ) ),
	array( 'title' => 'Sandbox secret', 'type' => 'password', 'id' => $p . 'sandbox_secret', 'submex_show_when' => array( $p . 'live' => 'no' ) ),
	array( 'title' => 'Live ID', 'type' => 'text', 'id' => $p . 'live_id', 'submex_show_when' => array( $p . 'live' => array( 'yes' ) ) ),
	array( 'title' => 'Live secret', 'type' => 'password', 'id' => $p . 'live_secret', 'submex_show_when' => array( $p . 'live' => 'yes' ) ),
	array( 'title' => 'Account', 'type' => 'text', 'id' => $p . 'account' ),
	array( 'type' => 'sectionend', 'id' => $p . 'title' ),
);

$add_section = static function ( $sections ) {
	$sections                      = (array) $sections;
	$sections['submex_test_env'] = 'Testpay';
	return $sections;
};
$add_fields  = static function ( $settings, $section ) use ( $fields ) {
	return 'submex_test_env' === $section ? $fields : $settings;
};
add_filter( 'woocommerce_get_sections_submex', $add_section, 99 );
add_filter( 'woocommerce_get_settings_submex', $add_fields, 99, 2 );

$names = array( 'on', 'live', 'sandbox_id', 'sandbox_secret', 'live_id', 'live_secret', 'account' );
$stripe_names = array( 'submex_stripe_enabled', 'submex_stripe_live', 'submex_stripe_test_secret', 'submex_stripe_secret' );
$paypal_names = array( 'submex_paypal_enabled', 'submex_paypal_live', 'submex_paypal_client_id', 'submex_paypal_secret', 'submex_paypal_webhook_id' );
$was    = array();
foreach ( array_merge( array_map( static fn( $n ) => $p . $n, $names ), $stripe_names, $paypal_names ) as $name ) {
	$was[ $name ] = get_option( $name, null );
}
foreach ( array_map( static fn( $n ) => $p . $n, $names ) as $name ) {
	delete_option( $name );
}
$site = submex_test_site_snapshot();

$render = static function ( string $section ) use ( $screen ): string {
	$_GET = array( 'section' => $section );
	ob_start();
	$screen->render();
	$_GET = array();
	return (string) ob_get_clean();
};
$xpath  = static function ( string $html ): DOMXPath {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	return new DOMXPath( $doc );
};
// What a browser sends from the page as drawn, hidden rows included: they are hidden, not removed or disabled.
$form_of = static function ( string $html ) use ( $xpath ): array {
	$x    = $xpath( $html );
	$post = array();
	foreach ( $x->query( '//form[contains(@class,"submex-settings__main")]//*[self::input or self::select or self::textarea][@name][not(@disabled)]' ) as $el ) {
		$name = $el->getAttribute( 'name' );
		if ( 'select' === $el->nodeName ) {
			$chosen        = $x->query( './/option[@selected]', $el )->item( 0 ) ?? $x->query( './/option', $el )->item( 0 );
			$post[ $name ] = $chosen ? $chosen->getAttribute( 'value' ) : '';
		} elseif ( in_array( $el->getAttribute( 'type' ), array( 'checkbox', 'radio' ), true ) ) {
			if ( $el->hasAttribute( 'checked' ) ) {
				$post[ $name ] = $el->getAttribute( 'value' );
			}
		} elseif ( ! in_array( $el->getAttribute( 'type' ), array( 'submit', 'button' ), true ) ) {
			$post[ $name ] = $el->getAttribute( 'value' );
		}
	}
	return $post;
};
$save    = static function ( array $post ) use ( $screen ): void {
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		$screen->maybe_save();
	} catch ( Submex_Test_Redirected $e ) {
		unset( $e );
	}
	$_POST    = array();
	$_REQUEST = array();
};
$row_of  = static function ( string $html, string $id ) use ( $xpath ): ?DOMElement {
	$found = $xpath( $html )->query( '//*[@id="' . $id . '"]/ancestor::div[contains(concat(" ",@class," ")," submex-settings__row ")][1]' )->item( 0 );
	return $found instanceof DOMElement ? $found : null;
};
$hidden  = static fn( ?DOMElement $row ): bool => $row && str_contains( ' ' . $row->getAttribute( 'class' ) . ' ', ' is-hidden ' );

echo "1. Drawn for the saved environment\n";
update_option( $p . 'sandbox_id', 'sandbox-id-1' );
update_option( $p . 'live_id', 'live-id-1' );
$html = $render( 'submex_test_env' );
$check( 'with sandbox chosen, the sandbox rows show', ! $hidden( $row_of( $html, $p . 'sandbox_id' ) ) && ! $hidden( $row_of( $html, $p . 'sandbox_secret' ) ) );
$check( 'and the live rows start hidden, so nothing flashes before the app draws', $hidden( $row_of( $html, $p . 'live_id' ) ) && $hidden( $row_of( $html, $p . 'live_secret' ) ) );
$check( 'a field for both environments shows either way', ! $hidden( $row_of( $html, $p . 'account' ) ) );
$when = json_decode( (string) $row_of( $html, $p . 'live_id' )?->getAttribute( 'data-submex-show-when' ), true );
$check( 'a row names the select and the values it shows for', array( $p . 'live' => array( 'yes' ) ) === $when, $when );
$live_input = $xpath( $html )->query( '//input[@id="' . $p . 'live_id"]' )->item( 0 );
$check( 'a hidden input is neither disabled nor emptied', $live_input && ! $live_input->hasAttribute( 'disabled' ) && 'live-id-1' === $live_input->getAttribute( 'value' ) );

update_option( $p . 'live', 'yes' );
$html = $render( 'submex_test_env' );
$check( 'with live saved, it is the other way round', $hidden( $row_of( $html, $p . 'sandbox_id' ) ) && ! $hidden( $row_of( $html, $p . 'live_id' ) ) );
delete_option( $p . 'live' );

echo "\n2. Saving in one environment keeps the other's keys\n";
$post                          = $form_of( $render( 'submex_test_env' ) );
$post[ $p . 'sandbox_secret' ] = 'sandbox-secret-1';
$save( $post );
$check( 'sandbox keys saved', 'sandbox-id-1' === get_option( $p . 'sandbox_id' ) && 'sandbox-secret-1' === get_option( $p . 'sandbox_secret' ) );

$post                       = $form_of( $render( 'submex_test_env' ) );
$post[ $p . 'live' ]        = 'yes';
$post[ $p . 'live_id' ]     = 'live-id-2';
$post[ $p . 'live_secret' ] = 'live-secret-2';
$save( $post );
$check( 'switching to live and saving its keys', 'yes' === get_option( $p . 'live' ) && 'live-id-2' === get_option( $p . 'live_id' ) && 'live-secret-2' === get_option( $p . 'live_secret' ) );
$check( 'leaves the sandbox keys as they were', 'sandbox-id-1' === get_option( $p . 'sandbox_id' ) && 'sandbox-secret-1' === get_option( $p . 'sandbox_secret' ), array( get_option( $p . 'sandbox_id' ), get_option( $p . 'sandbox_secret' ) ) );

$post               = $form_of( $render( 'submex_test_env' ) );
$post[ $p . 'live' ] = 'no';
$save( $post );
$check( 'and switching back keeps the live keys', 'no' === get_option( $p . 'live' ) && 'live-id-2' === get_option( $p . 'live_id' ) && 'live-secret-2' === get_option( $p . 'live_secret' ) );
$check( 'with the sandbox keys still there', 'sandbox-id-1' === get_option( $p . 'sandbox_id' ) && 'sandbox-secret-1' === get_option( $p . 'sandbox_secret' ) );

$rest = static function ( string $method, string $section, ?array $values = null ): array {
	$request = new WP_REST_Request( $method, '/submex/v1/settings/' . $section );
	if ( null !== $values ) {
		$request->set_param( 'values', $values );
	}
	return (array) rest_do_request( $request )->get_data();
};
$rest( 'POST', 'submex_test_env', array( $p . 'live' => 'yes', $p . 'live_id' => 'live-id-3' ) );
$check( 'over REST, a save naming only live fields keeps the sandbox keys', 'live-id-3' === get_option( $p . 'live_id' ) && 'sandbox-id-1' === get_option( $p . 'sandbox_id' ) && 'sandbox-secret-1' === get_option( $p . 'sandbox_secret' ) );
$check( 'and the live secret it did not name', 'live-secret-2' === get_option( $p . 'live_secret' ) );

echo "\n3. Over REST\n";
$page = $rest( 'GET', 'submex_test_env' );
$rows = array_merge( ...array_column( (array) ( $page['cards'] ?? array() ), 'rows' ) );
$byid = array_column( array_filter( $rows, static fn( $r ) => isset( $r['id'] ) ), null, 'id' );
$check( 'a field carries its condition', array( $p . 'live' => array( 'no' ) ) === (array) ( $byid[ $p . 'sandbox_id' ]['submex_show_when'] ?? array() ), $byid[ $p . 'sandbox_id' ] ?? null );
$check( 'and one for both environments carries none', array() === (array) ( $byid[ $p . 'account' ]['submex_show_when'] ?? array() ) );
$setup = array_values( array_filter( $rows, static fn( $r ) => Setup_Card::TYPE === ( $r['type'] ?? '' ) ) )[0] ?? array();
$check( 'the setup card comes as steps the app can tick as fields fill in', 5 === count( $setup['setup']['steps'] ?? array() ) && array( $p . 'live_id', $p . 'live_secret' ) === ( $setup['setup']['steps'][3]['filled'] ?? null ), $setup );
$check( 'its text kept to safe markup', str_contains( (string) ( $setup['setup']['desc'] ?? '' ), '<strong>cards</strong>' ) );

echo "\n4. The setup card as PHP draws it\n";
update_option( $p . 'live', 'yes' );
update_option( $p . 'on', 'no' );
$html  = $render( 'submex_test_env' );
$x     = $xpath( $html );
$steps = $x->query( '//ol[contains(@class,"submex-setup__steps")]/li' );
$check( 'only the chosen environment\'s steps are drawn', 4 === $steps->length && str_contains( $html, 'Paste your live keys below' ) && ! str_contains( $html, 'Paste your sandbox keys below' ), $steps->length );
$check( 'the heading names the gateway and counts what can be checked', str_contains( $html, 'Set up Testpay' ) && str_contains( $html, '1 of 2 done' ) );
$check( 'filled keys tick their step', str_contains( (string) $steps->item( 2 )?->getAttribute( 'class' ), 'is-done' ) );
$check( 'the switch still off is the step to do now', str_contains( (string) $steps->item( 3 )?->getAttribute( 'class' ), 'is-current' ) );
$check( 'steps done in the provider are numbered, never ticked', str_contains( (string) $steps->item( 0 )?->getAttribute( 'class' ), 'is-manual' ) && str_contains( (string) $steps->item( 1 )?->getAttribute( 'class' ), 'is-manual' ) );
$copy = $x->query( '//input[contains(@class,"submex-setup__copy-input")]' )->item( 0 );
$check( 'the webhook address is a labelled, read-only box with no name to post', $copy && $copy->hasAttribute( 'readonly' ) && ! $copy->hasAttribute( 'name' ) && 'https://shop.test/wp-json/testpay' === $copy->getAttribute( 'value' ) && 1 === $x->query( '//label[@for="' . $copy->getAttribute( 'id' ) . '"]' )->length );
$check( 'with a copy button', 1 === $x->query( '//button[@type="button"][@data-submex-copy="' . $copy?->getAttribute( 'id' ) . '"]' )->length );
$check( 'the events fold away under a count', 1 === $x->query( '//details[contains(@class,"submex-setup__list")]/summary[.="Events to send (2)"]' )->length && 2 === $x->query( '//details//li/code' )->length );
$external = $x->query( '//a[.//text()[contains(.,"Open domains")]]' )->item( 0 );
$check( 'a link to the provider opens a new tab and says so', $external && '_blank' === $external->getAttribute( 'target' ) && str_contains( $external->getAttribute( 'rel' ), 'noopener' ) && str_contains( $external->textContent, '(opens in a new tab)' ) );
$check( 'steps done in the provider keep their link, as a plain button', 2 === $x->query( '//li[contains(@class,"is-manual")]//a[contains(@class,"submex-btn")][not(contains(@class,"submex-btn--primary"))]' )->length );
$check( 'a done step has no button, and a step with nothing to open has none either', ! str_contains( $html, 'Open live keys' ) && 0 === $x->query( '//a[contains(@class,"submex-btn--primary")]' )->length );
update_option( $p . 'live_secret', '' );
$x = $xpath( $render( 'submex_test_env' ) );
$check( 'with a key missing, that step is current and its button is the one primary button', 1 === $x->query( '//a[contains(@class,"submex-btn--primary")]' )->length && 1 === $x->query( '//li[contains(@class,"is-current")]//a[contains(@class,"submex-btn--primary")][contains(@href,"example.com/keys")]' )->length );
update_option( $p . 'live_secret', 'live-secret-2' );

$field = $fields[1];
$raw   = Setup_Card::markup( $field, array( $p . 'live' => 'yes', $p . 'live_id' => 'a', $p . 'live_secret' => 'b', $p . 'on' => 'yes' ) );
$check( 'everything checkable done, but steps only the provider can confirm remain, so it does not claim to be set up', ! str_contains( $raw, 'Testpay is set up' ) && str_contains( $raw, 'Set up Testpay' ) && str_contains( $raw, '2 of 2 done' ) && ! str_contains( $raw, 'is-current' ) );
$no_manual = $field;
$no_manual['steps'] = array_values( array_filter( $field['steps'], static fn( $s ) => ! empty( $s['filled'] ) || isset( $s['done'] ) ) );
$check( 'with every step checkable and done, it says it is set up', str_contains( Setup_Card::markup( $no_manual, array( $p . 'live' => 'yes', $p . 'live_id' => 'a', $p . 'live_secret' => 'b', $p . 'on' => 'yes' ) ), 'Testpay is set up' ) );
$check( 'and the screen\'s HTML filter keeps all of it', wp_kses( $raw, Allowed_Html::form() ) === $raw );

echo "\n5. Stripe and PayPal\n";
$fields_of = static fn( string $section ): array => array_column( array_filter( $screen->settings_for( $section ), static fn( $f ) => ! empty( $f['id'] ) ), null, 'id' );
$stripe    = $fields_of( 'stripe' );
$check( 'Stripe shows the test key in test mode and the live key live', array( 'submex_stripe_live' => 'no' ) === ( $stripe['submex_stripe_test_secret']['submex_show_when'] ?? null ) && array( 'submex_stripe_live' => 'yes' ) === ( $stripe['submex_stripe_secret']['submex_show_when'] ?? null ) );
$check( 'each key says where it is, linking Stripe\'s API keys page in a new tab', str_contains( $screen->help_text( $stripe['submex_stripe_test_secret'] ), 'href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer"' ) && str_contains( $screen->help_text( $stripe['submex_stripe_secret'] ), '(opens in a new tab)' ) );
$check( 'Stripe opens with a setup card', Setup_Card::TYPE === ( $screen->settings_for( 'stripe' )[1]['type'] ?? '' ) );

update_option( 'submex_stripe_live', 'no' );
update_option( 'submex_stripe_test_secret', 'sk_test_keep' );
update_option( 'submex_stripe_secret', '' );
$post                         = $form_of( $render( 'stripe' ) );
$post['submex_stripe_live']   = 'yes';
$post['submex_stripe_secret'] = 'sk_live_new';
$save( $post );
$check( 'saving Stripe live keeps its test key', 'sk_test_keep' === get_option( 'submex_stripe_test_secret' ) && 'sk_live_new' === get_option( 'submex_stripe_secret' ) );

$paypal = $fields_of( 'paypal' );
$check( 'PayPal has its own credentials for each environment, each shown only while it is chosen', array( 'submex_paypal_live' => 'no' ) === ( $paypal['submex_paypal_sandbox_client_id']['submex_show_when'] ?? null ) && array( 'submex_paypal_live' => 'yes' ) === ( $paypal['submex_paypal_live_client_id']['submex_show_when'] ?? null ) && isset( $paypal['submex_paypal_sandbox_webhook_id'], $paypal['submex_paypal_live_webhook_id'], $paypal['submex_paypal_live_secret'] ) );
$check( 'and each hint links that environment\'s app page', str_contains( $screen->help_text( $paypal['submex_paypal_sandbox_client_id'] ), 'developer.paypal.com/dashboard/applications/sandbox' ) && str_contains( $screen->help_text( $paypal['submex_paypal_live_client_id'] ), 'developer.paypal.com/dashboard/applications/live' ) );

$paypal_was = array();
foreach ( array( 'live', 'sandbox_client_id', 'sandbox_secret', 'sandbox_webhook_id', 'live_client_id', 'live_secret', 'live_webhook_id', 'client_id', 'secret', 'webhook_id', 'enabled' ) as $key ) {
	$paypal_was[ 'submex_paypal_' . $key ] = get_option( 'submex_paypal_' . $key, null );
	delete_option( 'submex_paypal_' . $key );
}
update_option( 'submex_paypal_sandbox_client_id', 'sb-id' );
update_option( 'submex_paypal_sandbox_secret', 'sb-secret' );
update_option( 'submex_paypal_sandbox_webhook_id', 'sb-hook' );
update_option( 'submex_paypal_enabled', 'yes' );
update_option( 'submex_paypal_live', 'yes' );
$paypal_card = (string) $screen->wide_markup( $screen->settings_for( 'paypal' )[1], array( 'submex_paypal_live' => 'yes', 'submex_paypal_sandbox_client_id' => 'sb-id', 'submex_paypal_sandbox_secret' => 'sb-secret', 'submex_paypal_sandbox_webhook_id' => 'sb-hook', 'submex_paypal_enabled' => 'yes' ) );
$check( 'switched to Live with only sandbox credentials, PayPal is not set up and asks for the live app', ! str_contains( $paypal_card, 'PayPal is set up' ) && str_contains( $paypal_card, 'is-current' ), $paypal_card );
$check( 'and live calls never use the sandbox credentials', '' === \Submex\Gateways\PayPal\PayPal_Client::credential( 'client_id' ) && ! \Submex\Gateways\PayPal\PayPal_Client::from_settings()->is_configured() );
update_option( 'submex_paypal_client_id', 'old-shared' );
$check( 'credentials saved before the split still work until replaced', 'old-shared' === \Submex\Gateways\PayPal\PayPal_Client::credential( 'client_id' ) );
\Submex\Gateways\PayPal\PayPal_Client::adopt_shared_credentials();
$check( 'and move to the environment chosen, leaving the other untouched', 'old-shared' === get_option( 'submex_paypal_live_client_id' ) && false === get_option( 'submex_paypal_client_id' ) && 'sb-id' === get_option( 'submex_paypal_sandbox_client_id' ) );
foreach ( $paypal_was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

update_option( 'submex_stripe_live', 'live' );
$stripe_now = $fields_of( 'stripe' );
$check( 'a stored environment outside the select\'s options reads as its default, so a key field still shows', 'no' === $screen->field_value( $stripe_now['submex_stripe_live'] ) );
update_option( 'submex_stripe_live', 'no' );
$card_html = (string) $screen->wide_markup( $screen->settings_for( 'paypal' )[1], array( 'submex_paypal_live' => 'no' ) );
$check( 'the webhook address moved into the setup card, with its six events', str_contains( $card_html, esc_attr( rest_url( 'submex/v1/webhook/paypal' ) ) ) && str_contains( $card_html, 'Events to send (6)' ) && str_contains( $card_html, 'PAYMENT.SALE.COMPLETED' ) );

remove_filter( 'woocommerce_get_sections_submex', $add_section, 99 );
remove_filter( 'woocommerce_get_settings_submex', $add_fields, 99 );
foreach ( $was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
submex_test_site_restore( $site );

submex_test_done( $fail );
