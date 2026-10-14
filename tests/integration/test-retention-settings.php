<?php
/**
 * The Retention Flow screen's data: reasons with ids that never move or come back, the
 * reasons screen's text and the confirmation messages, the endpoints that edit them, the
 * extension points Pro fills, and where the screen sits in the menu.
 *
 * @package Submex
 */

use Submex\Admin\Menu;
use Submex\Admin\Page_Shell;
use Submex\Admin\Retention_Page;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Lifecycle\Cancellation_Survey;
use Submex\Lifecycle\Retention_Flow;
use Submex\Lifecycle\Retention_Reasons;

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = \Submex\Plugin::instance();
$survey = $plugin->get( 'survey' );

if ( ! $survey instanceof Cancellation_Survey ) {
	submex_test_abort( 'the cancellation survey is not registered' );
}

$options = array( Retention_Reasons::OPTION, Retention_Reasons::LABELS_OPTION, Retention_Flow::OPTION, Retention_Flow::DISPLAY_OPTION, Retention_Flow::MESSAGES_OPTION );
$was     = array();
foreach ( $options as $name ) {
	$was[ $name ] = get_option( $name, null );
	delete_option( $name );
}

$users = array();
foreach ( array( 'customer', 'shop_manager' ) as $role ) {
	$users[ $role ] = wp_insert_user(
		array(
			'user_login' => 'sk_ret_' . $role . '_' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'sb-ret-' . wp_generate_password( 6, false, false ) . '@example.test',
			'role'       => $role,
		)
	);
}

$rest = static function ( string $method, string $path, array $body = array(), int $as = 1 ): WP_REST_Response {
	wp_set_current_user( $as );
	$request = new WP_REST_Request( $method, $path );
	if ( $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	$response = rest_do_request( $request );
	wp_set_current_user( 1 );
	return $response;
};

$ids = static fn(): array => array_column( Retention_Reasons::all(), 'id' );

// ---------------------------------------------------------------- seeding and stable ids

echo "\n1. Reasons\n";
$seeded = array( 'too_expensive', 'not_using', 'found_better', 'quality', 'temporary', 'other' );
$check( 'first read seeds the six reasons subscriptions already store, in order', $seeded === $ids(), $ids() );
$check( '  enabled, with their old labels', 'Too expensive' === $survey->reasons()['too_expensive'] && 'Something else' === $survey->reasons()['other'] && 6 === count( $survey->reasons() ) );
$check( '  and once: a second read writes nothing new', $seeded === $ids() && 6 === count( Retention_Reasons::labels() ) );

$add_reason = static fn( array $reasons ): array => $reasons + array( 'sk_filtered' => 'Added by a filter' );
add_filter( 'submex_cancellation_reasons', $add_reason );
$check( 'the old submex_cancellation_reasons filter still adds to the list', 'Added by a filter' === ( $survey->reasons()['sk_filtered'] ?? '' ) );
remove_filter( 'submex_cancellation_reasons', $add_reason );

$first  = Retention_Reasons::create( '  Moving abroad  ' );
$second = Retention_Reasons::create( 'Too slow' );
$check( 'a new reason gets r_ and eight random characters', is_array( $first ) && 1 === preg_match( '/^r_[a-z0-9]{8}$/', $first['id'] ), $first );
$check( '  a different one each time', is_array( $second ) && $first['id'] !== $second['id'] );
$check( '  goes last, trimmed and switched on', array( $first['id'], $second['id'] ) === array_slice( $ids(), -2 ) && 'Moving abroad' === $first['label'] && true === $first['enabled'] );
$check( 'a label must be 1 to 80 characters', Retention_Reasons::create( '   ' ) instanceof WP_Error && Retention_Reasons::create( str_repeat( 'x', 81 ) ) instanceof WP_Error && is_array( Retention_Reasons::create( str_repeat( 'y', 80 ) ) ) );

$subscription = new Subscription();
$subscription->set_customer_id( 1 );
$subscription->update_meta_data( Cancellation_Survey::META_REASON, $first['id'] );
$subscription->transition_to( Subscription_Status::Pending );
$subscription->save();

Retention_Reasons::update( $first['id'], 'Moving abroad for good' );
$check( 'renamed, a stored reason reads the new label', 'Moving abroad for good' === $survey->reason_for( $subscription ) );
Retention_Reasons::update( $first['id'], null, false );
$check( 'switched off, it leaves the list customers see', ! isset( $survey->reasons()[ $first['id'] ] ) && in_array( $first['id'], $ids(), true ) );
$check( '  but a subscription that gave it still reads it', 'Moving abroad for good' === $survey->reason_for( $subscription ) );

$deleted = array();
$on_delete = static function ( ...$args ) use ( &$deleted ): void {
	$deleted[] = $args;
};
add_action( 'submex_retention_reason_deleted', $on_delete, 10, 9 );
$check( 'deleting a reason removes it', Retention_Reasons::delete( $first['id'] ) && ! in_array( $first['id'], $ids(), true ) );
$check( '  tells extensions once, with only its id', array( array( $first['id'] ) ) === $deleted, $deleted );
$check( '  and a subscription that gave it still reads it', 'Moving abroad for good' === $survey->reason_for( $subscription ) );
$check( '  as does the count of reasons given', 1 <= ( $survey->tally()[ $first['id'] ] ?? 0 ), $survey->tally() );
$check( 'deleting it again does nothing and tells nobody', false === Retention_Reasons::delete( $first['id'] ) && 1 === count( $deleted ) );
remove_action( 'submex_retention_reason_deleted', $on_delete, 10 );
$check( 'a deleted id is never handed out again', ! in_array( $first['id'], array_map( static fn() => Retention_Reasons::create( 'Probe' )['id'], range( 1, 5 ) ), true ) );

$order = $ids();
$check( 'reordering must name every reason once', Retention_Reasons::reorder( array_slice( $order, 1 ) ) instanceof WP_Error && Retention_Reasons::reorder( array_merge( $order, array( $order[0] ) ) ) instanceof WP_Error && Retention_Reasons::reorder( array_merge( array_slice( $order, 1 ), array( 'r_unknown1' ) ) ) instanceof WP_Error && $order === $ids() );
$check( '  and then saves that order', true === Retention_Reasons::reorder( array_reverse( $order ) ) && array_reverse( $order ) === $ids() );

$subscription->delete( true );

// ---------------------------------------------------------------- the endpoints

echo "\n2. Endpoints\n";
$paths = array(
	array( 'GET', '/submex/v1/retention', array() ),
	array( 'POST', '/submex/v1/retention', array( 'enabled' => true ) ),
	array( 'POST', '/submex/v1/retention/messages/cancel', array( 'enabled' => false ) ),
	array( 'GET', '/submex/v1/retention/reasons', array() ),
	array( 'POST', '/submex/v1/retention/reasons', array( 'label' => 'From a customer' ) ),
	array( 'PUT', '/submex/v1/retention/reasons', array( 'order' => $ids() ) ),
	array( 'POST', '/submex/v1/retention/reasons/too_expensive', array( 'label' => 'Hijacked' ) ),
	array( 'DELETE', '/submex/v1/retention/reasons/too_expensive', array() ),
);
$before = array( Retention_Reasons::all(), Retention_Flow::is_on(), Retention_Flow::message( 'cancel' ) );
foreach ( $paths as list( $method, $path, $body ) ) {
	$check( "{$method} {$path} refuses a customer", 403 === $rest( $method, $path, $body, $users['customer'] )->get_status() );
	$check( "  and a visitor", 401 === $rest( $method, $path, $body, 0 )->get_status() );
}
$check( '  and changed nothing', $before === array( Retention_Reasons::all(), Retention_Flow::is_on(), Retention_Flow::message( 'cancel' ) ) );
$check( 'a shop manager can read the screen', 200 === $rest( 'GET', '/submex/v1/retention', array(), $users['shop_manager'] )->get_status() );

$usage_args = array();
$usage      = static function ( ...$args ) use ( &$usage_args ) {
	$usage_args[] = $args;
	return 'too_expensive' === $args[1] ? 3 : $args[0];
};
add_filter( 'submex_retention_reason_usage', $usage, 10, 9 );
$screen = $rest( 'GET', '/submex/v1/retention' )->get_data();
$listed = array_column( $screen['reasons'], null, 'id' );
$check( 'the screen lists every reason, off ones too, in order', $ids() === array_column( $screen['reasons'], 'id' ) );
$check( '  with how many offers use each, as the usage filter counts them', 3 === $listed['too_expensive']['usage'] && 0 === $listed['other']['usage'] );
$check( '  the filter asked with a count of 0 and the reason id', in_array( array( 0, 'too_expensive' ), $usage_args, true ), $usage_args );
remove_filter( 'submex_retention_reason_usage', $usage, 10 );
$check( 'the screen starts with the flow off and the default text', false === $screen['enabled'] && "Before you go, what's the main reason?" === $screen['display']['title'] && 'Please tell us why you are cancelling. Your feedback helps us improve.' === $screen['display']['description'] && 18 === $screen['display']['title_size'] && 14 === $screen['display']['description_size'] && 'center' === $screen['display']['align'], $screen['display'] );
$check( '  and only the cancel message, with its defaults', array( 'cancel' ) === array_keys( (array) $screen['messages'] ) && 'Your subscription has been cancelled' === $screen['messages']->cancel['title'] && "You won't be charged again." === $screen['messages']->cancel['description'] && 'Close' === $screen['messages']->cancel['button_text'], $screen['messages'] );

$created = $rest( 'POST', '/submex/v1/retention/reasons', array( 'label' => 'Over REST' ) );
$check( 'a reason is created over REST', 201 === $created->get_status() && 'Over REST' === $created->get_data()['label'] && 0 === $created->get_data()['usage'] );
$id = (string) $created->get_data()['id'];
$check( 'a label that is empty or over 80 characters is refused', 400 === $rest( 'POST', '/submex/v1/retention/reasons', array( 'label' => '' ) )->get_status() && 400 === $rest( 'POST', '/submex/v1/retention/reasons', array( 'label' => str_repeat( 'z', 81 ) ) )->get_status() && 400 === $rest( 'POST', '/submex/v1/retention/reasons', array() )->get_status() );
$updated = $rest( 'POST', "/submex/v1/retention/reasons/{$id}", array( 'label' => 'Renamed over REST', 'enabled' => false ) );
$check( 'one is renamed and switched off over REST', 200 === $updated->get_status() && 'Renamed over REST' === Retention_Reasons::label( $id ) && false === Retention_Reasons::find( $id )['enabled'] );
$check( '  a rename to nothing is refused', 400 === $rest( 'POST', "/submex/v1/retention/reasons/{$id}", array( 'label' => '' ) )->get_status() && 'Renamed over REST' === Retention_Reasons::label( $id ) );
$check( '  and one that does not exist is not found', 404 === $rest( 'POST', '/submex/v1/retention/reasons/r_nothere1', array( 'label' => 'x' ) )->get_status() && 404 === $rest( 'DELETE', '/submex/v1/retention/reasons/r_nothere1' )->get_status() );
$order = array_reverse( $ids() );
$check( 'reordered over REST', 200 === $rest( 'PUT', '/submex/v1/retention/reasons', array( 'order' => $order ) )->get_status() && $order === $ids() );
$check( '  unless the order leaves one out', 400 === $rest( 'PUT', '/submex/v1/retention/reasons', array( 'order' => array_slice( $order, 1 ) ) )->get_status() && $order === $ids() );
$check( 'deleted over REST', 200 === $rest( 'DELETE', "/submex/v1/retention/reasons/{$id}" )->get_status() && null === Retention_Reasons::find( $id ) && 'Renamed over REST' === Retention_Reasons::label( $id ) );

$check( 'the flow is switched on and off over REST', true === $rest( 'POST', '/submex/v1/retention', array( 'enabled' => true ) )->get_data()['enabled'] && Retention_Flow::is_on() && 'yes' === get_option( Retention_Flow::OPTION ) );
$rest( 'POST', '/submex/v1/retention', array( 'enabled' => false ) );
$check( '  and off', ! Retention_Flow::is_on() );

$bad = array(
	'a title size over 32'        => array( 'title_size' => 40 ),
	'a description size under 12' => array( 'description_size' => 11 ),
	'an alignment of middle'      => array( 'align' => 'middle' ),
	'a 41-character button'       => array( 'keep_text' => str_repeat( 'k', 41 ) ),
	'an empty Continue button'    => array( 'continue_text' => '' ),
	'a setting that is not one'   => array( 'colour' => 'red' ),
);
foreach ( $bad as $what => $display ) {
	$check( "display settings with {$what} are refused", 400 === $rest( 'POST', '/submex/v1/retention', array( 'display' => $display ) )->get_status() && false === get_option( Retention_Flow::DISPLAY_OPTION ) );
}
$check( '  as they are when saved without REST', Retention_Flow::save_display( array( 'title_size' => 33 ) ) instanceof WP_Error && Retention_Flow::save_display( array( 'align' => 'justify' ) ) instanceof WP_Error );

$saved = $rest(
	'POST',
	'/submex/v1/retention',
	array(
		'display' => array(
			'title'         => 'Wait <b>a moment</b>',
			'description'   => '<p onclick="x()">Hi <strong>there</strong>, <u>really</u>.<script>alert(1)</script><img src="x" onerror="y()"><a href="javascript:alert(2)">bad</a> <a href="https://example.test/help" target="_blank" rel="noopener">help</a></p><ul><li>one</li></ul><h1>big</h1>',
			'title_size'    => 24,
			'align'         => 'left',
			'continue_text' => 'Cancel anyway',
		),
	)
);
$stored = (array) get_option( Retention_Flow::DISPLAY_OPTION );
$check( 'display settings save', 200 === $saved->get_status() && 24 === $stored['title_size'] && 'left' === $stored['align'] && 'Cancel anyway' === $stored['continue_text'] );
$check( '  keeping what they did not name', 14 === $stored['description_size'] && 'Keep Subscription' === $stored['keep_text'] );
$check( '  the title as plain text', 'Wait a moment' === $stored['title'], $stored['title'] );
$check( '  the description through the allowlist before it is stored', ! str_contains( $stored['description'], 'script' ) && ! str_contains( $stored['description'], 'onclick' ) && ! str_contains( $stored['description'], '<img' ) && ! str_contains( $stored['description'], 'javascript:' ) && ! str_contains( $stored['description'], '<h1' ), $stored['description'] );
$check( '  keeping p, strong, u, lists and a safe link', str_contains( $stored['description'], '<strong>there</strong>' ) && str_contains( $stored['description'], '<u>really</u>' ) && str_contains( $stored['description'], '<ul><li>one</li></ul>' ) && str_contains( $stored['description'], '<a href="https://example.test/help" target="_blank" rel="noopener">help</a>' ), $stored['description'] );

$message = $rest( 'POST', '/submex/v1/retention/messages/cancel', array( 'title' => '<i>Done</i>', 'description' => 'Bye <em>now</em><script>x()</script>', 'button_text' => 'Back' ) );
$cancel  = Retention_Flow::message( 'cancel' );
$check( 'the cancel message saves, its title as text and its description through the allowlist', 200 === $message->get_status() && 'Done' === $cancel['title'] && 'Bye <em>now</em>' === $cancel['description'] && 'Back' === $cancel['button_text'] && true === $cancel['enabled'], $cancel );
$check( '  a button with no text, or over 40 characters, is refused', 400 === $rest( 'POST', '/submex/v1/retention/messages/cancel', array( 'button_text' => '' ) )->get_status() && 400 === $rest( 'POST', '/submex/v1/retention/messages/cancel', array( 'button_text' => str_repeat( 'b', 41 ) ) )->get_status() );
$check( '  switched off on its own', 200 === $rest( 'POST', '/submex/v1/retention/messages/cancel', array( 'enabled' => false ) )->get_status() && false === Retention_Flow::message( 'cancel' )['enabled'] && 'Done' === Retention_Flow::message( 'cancel' )['title'] );
$check( 'pause and offer accept are not free\'s to edit', 404 === $rest( 'POST', '/submex/v1/retention/messages/pause', array( 'enabled' => false ) )->get_status() && 404 === $rest( 'POST', '/submex/v1/retention/messages/nonsense', array( 'enabled' => false ) )->get_status() );

// ---------------------------------------------------------------- the message types Pro adds

echo "\n3. Message types\n";
$types_args = array();
$more       = static function ( ...$args ) use ( &$types_args ): array {
	$types_args[] = $args;
	return array_merge( (array) $args[0], array( 'pause', 'offer_accept', 'made_up' ) );
};
add_filter( 'submex_retention_message_types', $more, 10, 9 );
$types = Retention_Flow::message_types();
$check( 'the types filter is asked with the cancel type alone', array( array( 'cancel' ) ) === ( $types_args[0] ?? null ), $types_args );
$check( '  and what it adds is kept to the three known types', array( 'cancel', 'pause', 'offer_accept' ) === $types, $types );
$screen = $rest( 'GET', '/submex/v1/retention' )->get_data();
$check( '  the screen then lists all three, with their defaults', array( 'cancel', 'pause', 'offer_accept' ) === array_keys( (array) $screen['messages'] ) && 'It resumes on {resume_date}.' === $screen['messages']->pause['description'] && "You're all set" === $screen['messages']->offer_accept['title'] );
$check( '  and they can be saved', 200 === $rest( 'POST', '/submex/v1/retention/messages/pause', array( 'title' => 'Paused' ) )->get_status() && 'Paused' === Retention_Flow::message( 'pause' )['title'] );
remove_filter( 'submex_retention_message_types', $more, 10 );

// ---------------------------------------------------------------- the menu

echo "\n4. The menu\n";
global $submenu, $menu, $_wp_submenu_nopriv, $_registered_pages, $_parent_pages;
$saved_menu = array( $submenu, $menu );
$submenu    = array();
$menu       = array();
wp_set_current_user( 1 );
do_action( 'admin_menu', '' );
$items  = array_values( (array) ( $submenu[ Menu::SLUG ] ?? array() ) );
$labels = array_column( $items, 0 );
$slugs  = array_column( $items, 2 );
$pro    = (bool) did_action( 'submex_pro_loaded' );

$check( 'the first item is Overview, not Home', 'Overview' === ( $labels[0] ?? '' ) && ! in_array( 'Home', $labels, true ), $labels );
$after = $pro && in_array( 'submex-reports', $slugs, true ) ? 'submex-reports' : Menu::LIST_SLUG;
$check( 'Retention Flow comes straight after ' . ( Menu::LIST_SLUG === $after ? 'All subscriptions' : 'Reports' ), array_search( Retention_Page::SLUG, $slugs, true ) === array_search( $after, $slugs, true ) + 1 && 'Retention Flow' === $labels[ array_search( Retention_Page::SLUG, $slugs, true ) ], $slugs );
if ( $pro ) {
	$check( 'with Pro active there is no Upgrade to Pro', ! in_array( Page_Shell::UPGRADE_URL, $slugs, true ) && ! in_array( 'Upgrade to Pro', $labels, true ), $labels );
} else {
	$check( 'without Pro, Upgrade to Pro links straight out', in_array( Page_Shell::UPGRADE_URL, $slugs, true ) && 'Upgrade to Pro' === $labels[ array_search( Page_Shell::UPGRADE_URL, $slugs, true ) ], $labels );
	$check( '  as a link, with no screen behind it', ! has_action( get_plugin_page_hookname( Page_Shell::UPGRADE_URL, Menu::SLUG ) ) );
	$check( '  and before Help and Settings', array_search( Page_Shell::UPGRADE_URL, $slugs, true ) < array_search( Menu::SLUG . '-help', $slugs, true ) );
}
$submenu = $saved_menu[0];
$menu    = $saved_menu[1];

$offers = $rest( 'GET', '/submex/v1/retention' )->get_data()['upgrade_url'];
$check( $pro ? 'and the Offers card has no upgrade link' : 'and the Offers card links to the same place', ( $pro ? '' : Page_Shell::UPGRADE_URL ) === $offers, $offers );

// ---------------------------------------------------------------- clean up

foreach ( $users as $user ) {
	wp_delete_user( $user );
}
wp_set_current_user( 1 );
foreach ( $was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
