<?php
/**
 * Settings over REST: the menu and every section match what the PHP page draws, a save
 * writes only the posted fields of that page through WooCommerce's own sanitising, and
 * nothing else can be written.
 *
 * @package Submex
 */

use Submex\Admin\Settings_Page;
use Submex\Lifecycle\Cancellation_Policy;

require __DIR__ . '/bootstrap.php';

$woo = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$woo = $candidate;
	}
}
if ( ! $woo ) {
	submex_test_abort( 'the settings tab is not registered' );
}

// One section no group names, one field stacked into Renewal & Billing beside the free one, and a second gateway beside PayPal.
$extra = static function ( $sections ) {
	$sections                      = (array) $sections;
	$sections['submex_test_extra'] = 'Test extra';
	if ( ! isset( $sections['recovery'] ) ) {
		$sections['recovery'] = 'Test retries';
	}
	if ( ! isset( $sections['mollie'] ) ) {
		$sections['mollie'] = 'Test gateway';
	}
	return $sections;
};
$extra_fields = static function ( $settings, $section ) {
	if ( 'submex_test_extra' === $section ) {
		return array(
			array( 'title' => 'Extra', 'type' => 'title', 'id' => 'submex_test_extra_title' ),
			array( 'title' => 'Note', 'type' => 'textarea', 'id' => 'submex_test_extra_note', 'default' => '' ),
			array( 'title' => 'Path', 'type' => 'text', 'id' => 'submex_test_extra_path', 'default' => '' ),
			array( 'type' => 'sectionend', 'id' => 'submex_test_extra_title' ),
		);
	}
	if ( 'mollie' === $section && array() === (array) $settings ) {
		return array( array( 'title' => 'Listed', 'type' => 'text', 'id' => 'submex_test_listed_option', 'default' => '' ) );
	}
	if ( 'recovery' === $section ) {
		return array_merge( (array) $settings, array( array( 'title' => 'Stacked', 'type' => 'text', 'id' => 'submex_test_stacked_option', 'default' => '' ) ) );
	}
	return $settings;
};
add_filter( 'woocommerce_get_sections_submex', $extra, 99 );
add_filter( 'woocommerce_get_settings_submex', $extra_fields, 99, 2 );

// Every option any section can save, as it was, so every save below is undone.
$absent   = new stdClass();
$snapshot = array( 'siteurl' => get_option( 'siteurl' ) );
foreach ( array_keys( $woo->get_sections() ) as $section ) {
	foreach ( $woo->get_settings_for_section( (string) $section ) as $field ) {
		if ( ! empty( $field['id'] ) && ! in_array( $field['type'] ?? '', array( 'title', 'sectionend' ), true ) ) {
			$snapshot[ $field['id'] ] = get_option( $field['id'], $absent );
		}
	}
}

$get = static function ( string $path, array $query = array() ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/submex/v1/settings' . $path );
	$request->set_query_params( $query );
	return rest_do_request( $request );
};

$post = static function ( string $section, array $values ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/submex/v1/settings/' . $section );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'values' => $values ) ) );
	return rest_do_request( $request );
};

// Every field a page returns, by id: its rows and the fields drawn beside them.
$fields_of = static function ( array $page ): array {
	$fields = array();
	foreach ( $page['cards'] as $card ) {
		foreach ( $card['rows'] as $row ) {
			if ( isset( $row['id'] ) ) {
				$fields[ $row['id'] ] = $row;
				foreach ( $row['joined'] as $extra ) {
					$fields[ $extra['id'] ] = $extra;
				}
			}
		}
	}
	return $fields;
};

$slug = static fn( string $section ): string => '' === $section ? 'general' : $section;

echo "\n1. The menu is the PHP page's menu\n";
$menu = $get( '' );
$check( 'the menu answers the store manager', 200 === $menu->get_status(), $menu->get_status() );
$groups = $menu->get_data()['groups'] ?? array();
$listed = array();
foreach ( $groups as $group ) {
	foreach ( $group['sections'] as $section ) {
		$listed[ $section['id'] ][] = $group['id'];
	}
}
$sections = $woo->get_sections();
$missing  = array();
foreach ( $sections as $section => $label ) {
	$section = (string) $section;
	$group   = Settings_Page::group_of( $section );
	if ( array( $group ) !== ( $listed[ $slug( $section ) ] ?? null ) ) {
		$missing[] = $section;
	}
}
$check( 'every section is in the menu once, in the group the PHP page puts it in', array() === $missing && count( $listed ) === count( $sections ), $missing );
$check( 'groups keep their labels and menu order', array_values( array_intersect( array_keys( Settings_Page::groups() ), array_column( $groups, 'id' ) ) ) === array_column( $groups, 'id' ) && 'Customer Controls' === ( array_column( $groups, 'label', 'id' )['customers'] ?? '' ) );
$check( 'a section no group names lands in Integrations', array( 'integrations' ) === ( $listed['submex_test_extra'] ?? null ) );
$payments = array_column( $groups, null, 'id' )['payments'] ?? array();
$check( 'Payments lists its gateways', ! empty( $payments['list'] ) && in_array( 'paypal', array_column( $payments['sections'], 'id' ), true ) );
$notices = $get( '', array( 'submex_license_notice' => 'activated' ) )->get_data()['notices'] ?? array();
$check( 'a licence change that came back to the page is reported', array( array( 'type' => 'good', 'message' => 'Licence activated.' ) ) === $notices, $notices );

echo "\n2. Every section returns its fields and their saved values\n";
$wrong = array();
foreach ( $sections as $section => $label ) {
	$section  = (string) $section;
	$response = $get( '/' . $slug( $section ) );
	if ( 200 !== $response->get_status() ) {
		$wrong[] = "{$section}: status " . $response->get_status();
		continue;
	}
	$fields = $fields_of( $response->get_data() );
	foreach ( $woo->get_settings_for_section( $section ) as $field ) {
		$id = (string) ( $field['id'] ?? '' );
		if ( '' === $id || ! in_array( $field['type'] ?? '', array( 'checkbox', 'select', 'text', 'password', 'number', 'email', 'url', 'textarea' ), true ) ) {
			continue;
		}
		$expected = array_key_exists( 'value', $field ) ? (string) $field['value'] : (string) WC_Admin_Settings::get_option( $id, $field['default'] ?? '' );
		if ( ! isset( $fields[ $id ] ) ) {
			$wrong[] = "{$section}: {$id} missing";
		} elseif ( $expected !== $fields[ $id ]['value'] ) {
			$wrong[] = "{$section}: {$id} is " . wp_json_encode( $fields[ $id ]['value'] ) . ', saved ' . wp_json_encode( $expected );
		}
	}
}
$check( 'every field of every section is there, with the value saved for it', array() === $wrong, $wrong );

update_option( Cancellation_Policy::OPTION, 'yes' );
$controls = $fields_of( $get( '/customer_controls' )->get_data() );
$when     = $controls[ Cancellation_Policy::OPTION_WHEN ] ?? array();
$check( 'a row behind a switch names the switch', Cancellation_Policy::OPTION === ( $when['submex_show_if'] ?? '' ) );
$check( 'a select carries its options in order', array( Cancellation_Policy::AT_PERIOD_END, Cancellation_Policy::IMMEDIATELY ) === array_column( $when['options'] ?? array(), 'value' ) );
$check( 'a switch carries its help text', str_contains( $controls[ Cancellation_Policy::OPTION ]['help'] ?? '', 'My Account' ) );

$general = $get( '/general' )->get_data();
$html    = implode( '', array_column( array_merge( ...array_column( $general['cards'] ?? array(), 'rows' ) ), 'html' ) );
$check( 'rows only PHP can draw come drawn, by the same callbacks', str_contains( $html, 'submex-checks' ) && str_contains( $html, 'Double-charge protection' ) );
$check( 'General is addressed as general', 'general' === ( $general['section'] ?? '' ) && 'general' === ( $general['cards'][0]['anchor'] ?? '' ) );
$check( 'cards keep their titles, one per WooCommerce title', in_array( 'Access', array_column( $general['cards'] ?? array(), 'title' ), true ) );

$renewal = $get( '/renewal' )->get_data();
$check( 'a stacked group returns all its sections together', array( 'recovery', 'renewal' ) === ( $renewal['sections'] ?? null ) && isset( $fields_of( $renewal )['submex_test_stacked_option'], $fields_of( $renewal )['submex_catch_up_policy'] ), $renewal['sections'] ?? null );
$check( 'a section that does not exist is a 404', 404 === $get( '/submex_no_such_section' )->get_status() );

echo "\n3. A save writes only the posted fields of that page\n";
update_option( Cancellation_Policy::OPTION, 'yes' );
update_option( Cancellation_Policy::OPTION_WHEN, Cancellation_Policy::AT_PERIOD_END );
update_option( 'submex_allow_auto_renew_toggle', 'yes' );
update_option( 'submex_guest_checkout', 'create_account' );

$saved = $post( 'customer_controls', array( Cancellation_Policy::OPTION => 'no' ) );
$check( 'the save succeeds', 200 === $saved->get_status() && true === ( $saved->get_data()['saved'] ?? null ), $saved->get_data() );
$check( 'the posted field is written', 'no' === get_option( Cancellation_Policy::OPTION ) );
$check( 'a switch on the same page that was not posted stays on', 'yes' === get_option( 'submex_allow_auto_renew_toggle' ) );
$check( 'a select on the same page that was not posted is untouched', Cancellation_Policy::AT_PERIOD_END === get_option( Cancellation_Policy::OPTION_WHEN ) );
$check( 'the fresh values come back', 'no' === ( $saved->get_data()['values'][ Cancellation_Policy::OPTION ] ?? '' ) && 'yes' === ( $saved->get_data()['values']['submex_allow_auto_renew_toggle'] ?? '' ) );

$again = $post( 'customer_controls', array( Cancellation_Policy::OPTION => 'no' ) );
$check( 'saving the same again changes nothing further', 200 === $again->get_status() && 'no' === get_option( Cancellation_Policy::OPTION ) && 'yes' === get_option( 'submex_allow_auto_renew_toggle' ) );

echo "\n4. Nothing else can be written\n";
$siteurl = get_option( 'siteurl' );
$foreign = $post( 'customer_controls', array( 'siteurl' => 'https://attacker.example' ) );
$check( 'an option that is not a setting is refused', 400 === $foreign->get_status() && 'submex_unknown_setting' === ( $foreign->get_data()['code'] ?? '' ), $foreign->get_data() );
$check( '  and not written', $siteurl === get_option( 'siteurl' ) );

$mixed = $post( 'customer_controls', array( Cancellation_Policy::OPTION => 'yes', 'submex_guest_checkout' => 'require_login' ) );
$check( "another section's setting is refused, with the rest of the request", 400 === $mixed->get_status() && 'no' === get_option( Cancellation_Policy::OPTION ) && 'create_account' === get_option( 'submex_guest_checkout' ) );

$nested = $post( 'customer_controls', array( Cancellation_Policy::OPTION => array( 'yes' ) ) );
$check( 'a value that is not a single value is refused', 400 === $nested->get_status() && 'no' === get_option( Cancellation_Policy::OPTION ) );

$customer = wp_insert_user(
	array(
		'user_login' => 'sk_settings_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => 'sb-settings-' . wp_generate_password( 6, false, false ) . '@example.test',
		'role'       => 'customer',
	)
);
foreach ( array( 'a visitor' => array( 0, 401 ), 'a customer' => array( $customer, 403 ) ) as $who => list( $user, $status ) ) {
	wp_set_current_user( $user );
	$check( "{$who} cannot read the settings", $status === $get( '/customer_controls' )->get_status() && $status === $get( '' )->get_status() );
	$check( "{$who} cannot save them", $status === $post( 'customer_controls', array( Cancellation_Policy::OPTION => 'yes' ) )->get_status() && 'no' === get_option( Cancellation_Policy::OPTION ) );
}
wp_set_current_user( 1 );
wp_delete_user( $customer );

echo "\n5. WooCommerce's sanitising runs\n";
$bad = $post( 'customer_controls', array( Cancellation_Policy::OPTION_WHEN => 'whenever' ) );
$check( 'a select value that is not an option falls back to the default', Cancellation_Policy::AT_PERIOD_END === get_option( Cancellation_Policy::OPTION_WHEN ) && Cancellation_Policy::AT_PERIOD_END === ( $bad->get_data()['values'][ Cancellation_Policy::OPTION_WHEN ] ?? '' ), get_option( Cancellation_Policy::OPTION_WHEN ) );
$post( 'customer_controls', array( Cancellation_Policy::OPTION => 'maybe' ) );
$check( 'a switch sent anything but on is saved as off', 'no' === get_option( Cancellation_Policy::OPTION ) );

$post( 'submex_test_extra', array( 'submex_test_extra_note' => 'Hello <script>alert(1)</script><strong>there</strong>', 'submex_test_extra_path' => 'C:\\shop\\files' ) );
$check( 'a textarea is kept to safe HTML', 'Hello alert(1)<strong>there</strong>' === get_option( 'submex_test_extra_note' ), get_option( 'submex_test_extra_note' ) );
$check( 'a backslash survives the save', 'C:\\shop\\files' === get_option( 'submex_test_extra_path' ), get_option( 'submex_test_extra_path' ) );

$sanitised = static fn( $value ) => 'charge_all' === $value ? 'rebase' : $value;
update_option( 'submex_catch_up_policy', 'charge_all' );
add_filter( 'woocommerce_admin_settings_sanitize_option_submex_catch_up_policy', $sanitised );
$post( 'renewal', array( 'submex_catch_up_policy' => 'charge_all' ) );
remove_filter( 'woocommerce_admin_settings_sanitize_option_submex_catch_up_policy', $sanitised );
$check( "a field's own sanitising filter runs", 'rebase' === get_option( 'submex_catch_up_policy' ), get_option( 'submex_catch_up_policy' ) );

echo "\n6. A stacked group saves each of its sections\n";
$fired  = static fn( string $id ): int => did_action( 'woocommerce_update_options_submex_' . $id );
$before = array( 'renewal' => $fired( 'renewal' ), 'recovery' => $fired( 'recovery' ) );
$stack  = $post( 'renewal', array( 'submex_catch_up_policy' => 'charge_all', 'submex_test_stacked_option' => 'stacked' ) );
$check( 'both sections are written from one save', 200 === $stack->get_status() && 'charge_all' === get_option( 'submex_catch_up_policy' ) && 'stacked' === get_option( 'submex_test_stacked_option' ), $stack->get_data() );
$check( "each section's update action runs once, as on WooCommerce's own tab", $before['renewal'] + 1 === $fired( 'renewal' ) && $before['recovery'] + 1 === $fired( 'recovery' ) );
$only = $post( 'renewal', array( 'submex_catch_up_policy' => 'rebase' ) );
$check( 'a section with nothing posted is not saved or announced', 200 === $only->get_status() && 'stacked' === get_option( 'submex_test_stacked_option' ) && $before['recovery'] + 1 === $fired( 'recovery' ) );

echo "\n7. What WooCommerce is told during a save comes back\n";
$refuse = static function (): void {
	WC_Admin_Settings::add_error( 'Test refusal.' );
};
add_action( 'woocommerce_update_options_submex_submex_test_extra', $refuse );
$first  = $post( 'submex_test_extra', array( 'submex_test_extra_path' => 'one' ) );
$second = $post( 'submex_test_extra', array( 'submex_test_extra_path' => 'two' ) );
remove_action( 'woocommerce_update_options_submex_submex_test_extra', $refuse );
$check( 'an error raised on save is returned', array( 'Test refusal.' ) === ( $first->get_data()['errors'] ?? null ), $first->get_data()['errors'] ?? null );
$check( 'the next save returns only its own, not the last one too', array( 'Test refusal.' ) === ( $second->get_data()['errors'] ?? null ), $second->get_data()['errors'] ?? null );
$check( 'a save with nothing to say returns no errors', array() === ( $post( 'submex_test_extra', array( 'submex_test_extra_path' => 'three' ) )->get_data()['errors'] ?? null ) );

echo "\n8. The route's script loads with the app\n";
$page = \Submex\Plugin::instance()->get( 'settings_page' );
// The shell fires this on every app page; it is not merged into this branch.
do_action( 'submex_app_enqueue' );
$script = wp_scripts()->registered['submex-settings'] ?? null;
$check( 'the settings bundle is enqueued from the app hook', $page instanceof Settings_Page && wp_script_is( 'submex-settings', 'enqueued' ) );
$check( '  after the shell and the shared UI', $script && in_array( 'submex-shell', $script->deps, true ) && in_array( 'submex-ui', $script->deps, true ), $script ? $script->deps : null );
wp_dequeue_script( 'submex-settings' );
wp_deregister_script( 'submex-settings' );

remove_filter( 'woocommerce_get_sections_submex', $extra, 99 );
remove_filter( 'woocommerce_get_settings_submex', $extra_fields, 99 );
foreach ( array_merge( $snapshot, array( 'submex_test_extra_note' => $absent, 'submex_test_extra_path' => $absent, 'submex_test_stacked_option' => $absent ) ) as $name => $value ) {
	if ( $value === $absent ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

submex_test_done( $fail );
