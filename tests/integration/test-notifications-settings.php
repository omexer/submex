<?php
/**
 * The Email Notifications page: each row's switch is its email's own WooCommerce setting,
 * whichever screen saves it; no email has a second switch; the reminder moved here in hours;
 * and each email can be previewed through WooCommerce's own preview.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Scheduler;
use Submex\Emails\Notification_Settings;

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$woo = null;
foreach ( WC_Admin_Settings::get_settings_pages() as $candidate ) {
	if ( $candidate instanceof WC_Settings_Page && 'submex' === $candidate->get_id() ) {
		$woo = $candidate;
	}
}
if ( ! $woo ) {
	submex_test_abort( 'the settings tab is not registered' );
}

$emails = WC()->mailer()->get_emails();
$by_id  = array();
foreach ( $emails as $key => $email ) {
	$by_id[ $email->id ] = $email;
}

// Every option this test can write, as it was.
$absent  = new stdClass();
$touched = array( 'submex_renewal_reminder_days', Renewal_Scheduler::OPTION_REMINDER_HOURS );
foreach ( $by_id as $id => $email ) {
	if ( 0 === strpos( $id, 'submex_' ) ) {
		$touched[] = $email->get_option_key();
	}
}
$was = array();
foreach ( $touched as $name ) {
	$was[ $name ] = get_option( $name, $absent );
}

$section = static fn( string $id ): array => (array) $woo->get_settings_for_section( $id );
$rows    = static fn( array $fields ): array => array_values( array_filter( $fields, static fn( $f ) => ! empty( $f['id'] ) && ! in_array( $f['type'] ?? '', array( 'title', 'sectionend' ), true ) ) );

$post = static function ( string $path, array $values ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/submex/v1/settings/' . $path );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'values' => $values ) ) );
	return rest_do_request( $request );
};
$get = static fn( string $path ): WP_REST_Response => rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/settings/' . $path ) );

$enabled = static function ( string $email_id ) use ( $by_id ): string {
	$settings = get_option( $by_id[ $email_id ]->get_option_key(), array() );
	return (string) ( is_array( $settings ) ? ( $settings['enabled'] ?? '' ) : '' );
};

echo "\n1. The section\n";
$check( 'Email notifications is a section of the Subscriptions tab', 'Email notifications' === ( $woo->get_sections()['notifications'] ?? '' ), $woo->get_sections() );
$check( 'in the Email Notifications menu group', 'Email Notifications' === ( \Submex\Admin\Settings_Page::groups()['notifications']['label'] ?? '' ) );
$fields = $section( 'notifications' );
$ids    = array_column( $rows( $fields ), 'id' );

$sw   = static fn( string $email_id ): string => Notification_Settings::switch_id( $email_id );
$main = array(
	$sw( 'submex_renewal_reminder' ),
	Renewal_Scheduler::OPTION_REMINDER_HOURS,
	$sw( 'submex_payment_failed' ),
	$sw( 'submex_renewal_receipt' ),
	$sw( 'submex_subscription_cancelled' ),
	$sw( 'submex_subscription_reactivated' ),
);
$other = array( $sw( 'submex_subscription_started' ), $sw( 'submex_confirm_payment' ), $sw( 'submex_merchant_new_subscription' ), $sw( 'submex_merchant_subscription_cancelled' ), $sw( 'submex_merchant_subscription_ended' ) );
$in    = static fn( array $want ): array => array_values( array_intersect( $ids, $want ) );
$check( "free's rows come in the design's order", $main === $in( $main ), $in( $main ) );
$check( 'the other emails follow in their own card', $other === $in( $other ) && array_search( $other[0], $ids, true ) > array_search( end( $main ), $ids, true ), $ids );

$cards = array();
$card  = '';
foreach ( $fields as $f ) {
	if ( 'title' === ( $f['type'] ?? '' ) ) {
		$card = (string) $f['title'];
	} elseif ( ! empty( $f['submex_email'] ) ) {
		$cards[ $card ][] = $f['submex_email'];
	}
}
$check( 'two cards: Email notifications, then Other emails', array( 'Email notifications', 'Other emails' ) === array_keys( $cards ), array_keys( $cards ) );

$field = static function ( string $id ) use ( $fields ): array {
	foreach ( $fields as $f ) {
		if ( ( $f['id'] ?? '' ) === $id ) {
			return $f;
		}
	}
	return array();
};
$check( 'each switch is the email\'s own WooCommerce setting', 'woocommerce_submex_renewal_receipt_settings[enabled]' === $sw( 'submex_renewal_receipt' ) && 'checkbox' === ( $field( $sw( 'submex_renewal_receipt' ) )['type'] ?? '' ) );
$check( 'the renewal hours show only while the renewal reminder is on', $sw( 'submex_renewal_reminder' ) === ( $field( Renewal_Scheduler::OPTION_REMINDER_HOURS )['submex_show_if'] ?? '' ) );
$check( 'the unit is in the title, the help in desc_tip', 'Send renewal reminder before (Hours)' === ( $field( Renewal_Scheduler::OPTION_REMINDER_HOURS )['title'] ?? '' ) && is_string( $field( Renewal_Scheduler::OPTION_REMINDER_HOURS )['desc_tip'] ?? null ) );

echo "\n2. No second switch for any email\n";
$listed = array();
foreach ( array_keys( $woo->get_sections() ) as $id ) {
	foreach ( $section( (string) $id ) as $f ) {
		foreach ( $by_id as $email_id => $email ) {
			if ( 0 === strpos( (string) ( $f['id'] ?? '' ), $email->get_option_key() ) ) {
				$listed[ $email_id ][] = $id . ':' . $f['id'];
			}
		}
	}
}
$ours    = array_filter( array_keys( $by_id ), static fn( $id ) => 0 === strpos( $id, 'submex_' ) );
$missing = array_values( array_diff( $ours, array_keys( $listed ) ) );
$twice   = array_filter( $listed, static fn( $where ) => count( $where ) > 1 );
$check( 'every Submex email has a row', array() === $missing, $missing );
$check( 'and only one, on the Notifications page', array() === $twice && array() === array_filter( $listed, static fn( $where ) => 0 !== strpos( $where[0], 'notifications:' ) ), $twice );

echo "\n3. The switch saves WooCommerce's own setting, from either screen\n";
$reminder = $by_id['submex_renewal_receipt'];
update_option( $reminder->get_option_key(), array( 'enabled' => 'yes', 'subject' => 'Kept subject' ) );

$saved = $post( 'notifications', array( $sw( 'submex_renewal_receipt' ) => 'no' ) );
$check( 'the app\'s save turns it off in WooCommerce', 200 === $saved->get_status() && 'no' === $enabled( 'submex_renewal_receipt' ), array( $saved->get_status(), get_option( $reminder->get_option_key() ) ) );
$check( 'and leaves the email\'s other settings alone', 'Kept subject' === ( get_option( $reminder->get_option_key() )['subject'] ?? '' ) );
$check( 'the app reads it back as off', 'no' === ( $saved->get_data()['values'][ $sw( 'submex_renewal_receipt' ) ] ?? '' ), $saved->get_data()['values'] ?? null );
$check( 'and the email itself is off', ! ( new \Submex\Emails\Renewal_Receipt() )->is_enabled() );

$post( 'notifications', array( $sw( 'submex_renewal_receipt' ) => 'yes' ) );
$check( 'and on again', 'yes' === $enabled( 'submex_renewal_receipt' ) );

// WooCommerce's tab posts the form as the browser nests it.
WC_Admin_Settings::save_fields( array( $field( $sw( 'submex_renewal_receipt' ) ) ), array( $reminder->get_option_key() => array() ) );
$check( 'WooCommerce\'s own tab turns it off', 'no' === $enabled( 'submex_renewal_receipt' ), get_option( $reminder->get_option_key() ) );
WC_Admin_Settings::save_fields( array( $field( $sw( 'submex_renewal_receipt' ) ) ), array( $reminder->get_option_key() => array( 'enabled' => '1' ) ) );
$check( 'and on', 'yes' === $enabled( 'submex_renewal_receipt' ) && 'Kept subject' === ( get_option( $reminder->get_option_key() )['subject'] ?? '' ) );

update_option( $reminder->get_option_key(), array( 'enabled' => 'no' ) );
$read = $get( 'notifications' )->get_data();
$row  = array();
foreach ( $read['cards'] as $c ) {
	foreach ( $c['rows'] as $r ) {
		if ( ( $r['id'] ?? '' ) === $sw( 'submex_renewal_receipt' ) ) {
			$row = $r;
		}
	}
}
$check( 'a change made under WooCommerce → Emails shows in the app', 'no' === ( $row['value'] ?? '' ) && 'submex_renewal_receipt' === ( $row['submex_email'] ?? '' ), $row );

echo "\n4. The reminder moved here, in hours\n";
$check( 'Renewal & billing no longer has it', array() === array_intersect( array( 'submex_renewal_reminder_days', Renewal_Scheduler::OPTION_REMINDER_HOURS ), array_column( $section( 'renewal' ), 'id' ) ), array_column( $section( 'renewal' ), 'id' ) );
$check( 'Notifications does', in_array( Renewal_Scheduler::OPTION_REMINDER_HOURS, $ids, true ) );

$migrate = static function (): void {
	\Submex\Plugin::instance()->get( 'notification_settings' )->migrate();
};
$restore_reminder = get_option( $by_id['submex_renewal_reminder']->get_option_key(), $absent );

delete_option( Renewal_Scheduler::OPTION_REMINDER_HOURS );
update_option( 'submex_renewal_reminder_days', 5 );
$migrate();
$check( 'a store\'s days become hours', 120 === (int) get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ), get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );
$check( 'and the days are gone', false === get_option( 'submex_renewal_reminder_days', false ) );
$check( 'the same value the page shows', '120' === (string) ( $field( Renewal_Scheduler::OPTION_REMINDER_HOURS ) ? WC_Admin_Settings::get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) : '' ) );

update_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, 30 );
update_option( 'submex_renewal_reminder_days', 2 );
$migrate();
$migrate();
$check( 'it runs once: hours already set are never overwritten', 30 === (int) get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ), get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );
delete_option( 'submex_renewal_reminder_days' );

delete_option( Renewal_Scheduler::OPTION_REMINDER_HOURS );
update_option( $by_id['submex_renewal_reminder']->get_option_key(), array( 'enabled' => 'yes' ) );
update_option( 'submex_renewal_reminder_days', 0 );
$migrate();
$check( '0 days, which turned the reminder off, turns its email off', 'no' === $enabled( 'submex_renewal_reminder' ), get_option( $by_id['submex_renewal_reminder']->get_option_key() ) );
$check( 'and the hours start at the default', Renewal_Scheduler::DEFAULT_HOURS === (int) get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );

delete_option( Renewal_Scheduler::OPTION_REMINDER_HOURS );
$migrate();
$check( 'a store that never set it gets 24 hours', 24 === (int) get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );

foreach ( array( '0' => 1, '-3' => 1, 'abc' => 24, '48' => 48 ) as $posted => $kept ) {
	WC_Admin_Settings::save_fields( array( $field( Renewal_Scheduler::OPTION_REMINDER_HOURS ) ), array( Renewal_Scheduler::OPTION_REMINDER_HOURS => (string) $posted ) );
	$check( "hours saved as '$posted' are kept as $kept", $kept === (int) get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ), get_option( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );
}
update_option( Renewal_Scheduler::OPTION_REMINDER_HOURS, 'lots' );
$check( 'hours written around the screen are read as the default', 24 === Renewal_Scheduler::hours( Renewal_Scheduler::OPTION_REMINDER_HOURS ) );

if ( $absent === $restore_reminder ) {
	delete_option( $by_id['submex_renewal_reminder']->get_option_key() );
} else {
	update_option( $by_id['submex_renewal_reminder']->get_option_key(), $restore_reminder );
}

echo "\n5. Previewing an email\n";
update_option( $reminder->get_option_key(), array( 'enabled' => 'yes' ) );
$reminder->init_settings();

$rows = array();
foreach ( $get( 'notifications' )->get_data()['cards'] ?? array() as $c ) {
	foreach ( $c['rows'] as $r ) {
		if ( isset( $r['id'] ) ) {
			$rows[ $r['id'] ] = $r;
		}
	}
}
$url = (string) ( $rows[ $sw( 'submex_renewal_receipt' ) ]['preview_url'] ?? '' );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
$nonce = (string) ( $query['_wpnonce'] ?? '' );
$check( 'an email row links to WooCommerce\'s own preview of that email', str_starts_with( $url, admin_url() ) && 'true' === ( $query['preview_woocommerce_mail'] ?? '' ) && get_class( $reminder ) === ( $query['type'] ?? '' ), $url );
$check( 'with a nonce WooCommerce accepts for this store manager', false !== wp_verify_nonce( $nonce, 'preview-mail' ) );
$check( 'a row that is no email has no preview', '' === ( $rows[ Renewal_Scheduler::OPTION_REMINDER_HOURS ]['preview_url'] ?? null ) );
$check( 'every email row has one', array() === array_filter( $rows, static fn( $r ) => '' !== $r['submex_email'] && '' === $r['preview_url'] ) );

$preview = wc_get_container()->get( \Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview::class );
$preview->set_email_type( (string) $query['type'] );
$html = $preview->render();
$check( 'WooCommerce draws it, as the email with sample details', str_contains( $html, esc_html( $reminder->get_heading() ) ) && str_contains( $html, 'This is a preview.' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );

$_GET['section'] = 'notifications';
ob_start();
\Submex\Plugin::instance()->get( 'settings_page' )->render();
$screen = (string) ob_get_clean();
unset( $_GET['section'] );
$check( 'the page without JavaScript links the preview too, in a new tab', str_contains( $screen, 'preview_woocommerce_mail=true' ) && str_contains( $screen, 'target="_blank"' ) && str_contains( $screen, 'Renewal success email (opens in a new tab)' ) );

echo "\n6. What cannot be done\n";
$customer = wp_insert_user(
	array(
		'user_login' => 'sk_notify_' . strtolower( wp_generate_password( 8, false, false ) ),
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => 'sb-notify-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
		'role'       => 'customer',
	)
);
foreach ( array( 'a visitor' => array( 0, 401 ), 'a customer' => array( $customer, 403 ) ) as $who => list( $user, $status ) ) {
	wp_set_current_user( $user );
	$check( "$who cannot flip a switch", $status === $post( 'notifications', array( $sw( 'submex_renewal_receipt' ) => 'no' ) )->get_status() );
	$check( "$who cannot see the page, preview links and all", $status === $get( 'notifications' )->get_status() );
	$check( "$who holds no preview nonce of the store manager's", false === wp_verify_nonce( $nonce, 'preview-mail' ) );
}
wp_set_current_user( 1 );
$check( 'nor did they change anything', 'yes' === $enabled( 'submex_renewal_receipt' ) );
wp_delete_user( $customer );

foreach ( $was as $name => $value ) {
	if ( $absent === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}
foreach ( $by_id as $email ) {
	$email->init_settings();
}

submex_test_done( $fail );
