<?php
/**
 * "Ask before cancelling" in My Account: every state a subscription can be in when a screen
 * loads or a form arrives, every actor, and every replay — and off, exactly the old cancel.
 *
 * @package Submex
 */

use Submex\Data\Retention_Event_Repository;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Cancel_Flow;
use Submex\Lifecycle\Cancellation_Survey;
use Submex\Lifecycle\Retention_Flow;

require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'Submex_Test_Redirected' ) ) {
	// Handlers redirect and exit once they act; stop at the redirect and read the state.
	final class Submex_Test_Redirected extends Exception {}
}
add_filter(
	'wp_redirect',
	static function ( $to ) {
		throw new Submex_Test_Redirected( (string) $to );
	},
	1
);

if ( ! WC()->session ) {
	WC()->initialize_session();
}

global $wpdb;

$plugin  = \Submex\Plugin::instance();
$account = $plugin->get( 'account' );
$events  = $plugin->get( 'retention_events' );

if ( ! $events instanceof Retention_Event_Repository ) {
	submex_test_abort( 'the retention event log is not registered' );
}

$options = array( Retention_Flow::OPTION, Retention_Flow::MESSAGES_OPTION, Retention_Flow::DISPLAY_OPTION, 'submex_cancellation_effective', 'submex_allow_cancellation' );
$was     = array();
foreach ( $options as $name ) {
	$was[ $name ] = get_option( $name, null );
	delete_option( $name );
}
update_option( 'submex_cancellation_effective', 'immediate' );

$users = array();
$user  = static function ( string $name ) use ( &$users ): int {
	$id = wp_insert_user(
		array(
			'user_login' => 'sk_flow_' . $name . '_' . wp_generate_password( 5, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'sb-flow-' . $name . '-' . wp_generate_password( 5, false, false ) . '@example.test',
			'role'       => 'customer',
		)
	);
	$users[] = $id;
	return $id;
};

$alice = $user( 'alice' );
$bob   = $user( 'bob' );
$made  = array();

$make = static function ( Subscription_Status $status = Subscription_Status::Active ) use ( $alice, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( $alice );
	$s->set_currency( 'USD' );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_address( array( 'first_name' => 'Flow', 'email' => 'flow-alice@example.test' ), 'billing' );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();

	if ( Subscription_Status::Active !== $status ) {
		$s->transition_to( $status );
		$s->save();
	}

	$made[] = $s->get_id();

	return wc_get_order( $s->get_id() );
};

$fresh = static fn( Subscription $s ): Subscription => wc_get_order( $s->get_id() );

$post = static function ( array $fields ) use ( $account ): string {
	$_POST    = $fields;
	$_REQUEST = $fields;
	$to       = '';

	try {
		$account->handle_actions();
	} catch ( Submex_Test_Redirected $e ) {
		$to = $e->getMessage();
	}

	$_POST    = array();
	$_REQUEST = array();

	return $to;
};

$submit = static function ( Subscription $s, string $choice, string $reason = '', string $detail = '', ?string $nonce = null ) use ( $post ): string {
	return $post(
		array(
			'submex_action'       => Cancel_Flow::ACTION,
			'submex_subscription' => (string) $s->get_id(),
			'_wpnonce'            => $nonce ?? wp_create_nonce( Cancel_Flow::nonce_action( $s->get_id() ) ),
			'submex_choice'       => $choice,
			'submex_reason'       => $reason,
			'submex_detail'       => $detail,
		)
	);
};

$page = static function ( Subscription $s, array $get = array() ) use ( $account ): string {
	$_GET = $get;
	ob_start();
	$account->render( (string) $s->get_id() );
	$_GET = array();

	return (string) ob_get_clean();
};

$asking = static fn( Subscription $s, string $view = Cancel_Flow::VIEW_REASONS ): array => array(
	Cancel_Flow::VIEW => $view,
	'_wpnonce'        => wp_create_nonce( Cancel_Flow::nonce_action( $s->get_id() ) ),
);

$query_of = static function ( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	return $query;
};

$logged = static fn( Subscription $s ): array => array_map(
	static fn( $row ): string => $row->event . ( null === $row->reason_id ? '' : ':' . $row->reason_id ),
	$events->for_subscription( $s->get_id() )
);

$notices = static function (): string {
	$all = wc_get_notices();
	wc_clear_notices();

	return implode( ' | ', array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, array_merge( ...array_values( $all ?: array( array() ) ) ) ) );
};

$is = static fn( Subscription $s, Subscription_Status $status ): bool => $status === wc_get_order( $s->get_id() )->get_status_enum();

wp_set_current_user( $alice );

// ---------------------------------------------------------------- flow off: the old cancel, untouched

echo "\n1. Off\n";
$s      = $make();
$detail = $page( $s );
$check( 'the details page keeps the plain cancel form', str_contains( $detail, 'name="submex_action" value="cancel"' ) && ! str_contains( $detail, Cancel_Flow::VIEW . '=' ), substr( wp_strip_all_tags( $detail ), -300 ) );
$asked = $page( $s, $asking( $s ) );
$check( 'asking for the reasons screen draws the details page instead', str_contains( $asked, 'name="submex_action" value="cancel"' ) && ! str_contains( $asked, 'submex_choice' ) );
$check( '  and logs nothing', array() === $logged( $s ), $logged( $s ) );
$notices();
$to = $post(
	array(
		'submex_action'       => 'cancel',
		'submex_subscription' => (string) $s->get_id(),
		'_wpnonce'            => wp_create_nonce( 'submex_cancel_' . $s->get_id() ),
	)
);
$check( 'cancelling cancels, with no question asked', $is( $s, Subscription_Status::Cancelled ) );
$check( '  says so in the one-line notice it always has', 'Your subscription has been cancelled.' === $notices() );
$check( '  goes back to the subscription with no message panel', ! isset( $query_of( $to )[ Cancel_Flow::MESSAGE ] ) && '1' === ( $query_of( $to )['cancelled'] ?? '' ), $to );
$check( '  and logs nothing', array() === $logged( $s ), $logged( $s ) );
$announced = did_action( 'submex_subscription_cancelled' );
$post(
	array(
		'submex_action'       => 'cancel',
		'submex_subscription' => (string) $s->get_id(),
		'_wpnonce'            => wp_create_nonce( 'submex_cancel_' . $s->get_id() ),
	)
);
$check( 'resubmitting the cancel announces nothing a second time, so no second emails', $announced === did_action( 'submex_subscription_cancelled' ) );
$check( '  and says it is already cancelled', 'This subscription has already been cancelled.' === $notices() );

// ---------------------------------------------------------------- on: the reasons screen

echo "\n2. On: the reasons screen\n";
Retention_Flow::set( true );
$s      = $make();
$detail = $page( $s );
$check( 'Cancel subscription becomes a link to the reasons screen, with no form to post', str_contains( $detail, Cancel_Flow::VIEW . '=' . Cancel_Flow::VIEW_REASONS ) && ! str_contains( $detail, 'name="submex_action" value="cancel"' ) );
$check( '  and still says what cancelling does', str_contains( $detail, 'You will not be charged again' ) || str_contains( $detail, 'There is no refund' ) );

\Submex\Lifecycle\Retention_Reasons::update( 'found_better', null, false );
$view = $page( $s, $asking( $s ) );
$check( 'the screen asks the question it is set to', str_contains( $view, "Before you go, what&#039;s the main reason?" ) || str_contains( $view, 'Before you go, what’s the main reason?' ) || str_contains( $view, "Before you go, what's the main reason?" ), substr( $view, 0, 600 ) );
$check( '  as a plain form posting the flow, with its own nonce', str_contains( $view, 'value="' . Cancel_Flow::ACTION . '"' ) && str_contains( $view, 'name="_wpnonce"' ) && false !== wp_verify_nonce( (string) ( preg_match( '/name="_wpnonce" value="([^"]+)"/', $view, $m ) ? $m[1] : '' ), Cancel_Flow::nonce_action( $s->get_id() ) ) );
$check( '  offering the enabled reasons as radios, none picked', 5 === substr_count( $view, 'name="submex_reason"' ) && ! str_contains( $view, 'checked' ), substr_count( $view, 'name="submex_reason"' ) );
$check( '  not one that is switched off', ! str_contains( $view, 'value="found_better"' ) );
$check( '  with one detail box, under Other only', 1 === substr_count( $view, '<textarea' ) && (int) strpos( $view, '<textarea' ) > (int) strpos( $view, 'value="other"' ) );
$check( '  and both buttons, Keep and Continue canceling, each one submit', str_contains( $view, 'value="keep"' ) && str_contains( $view, 'Keep Subscription' ) && str_contains( $view, 'value="continue"' ) && str_contains( $view, 'Continue canceling' ) );
$check( 'opening it starts one attempt', array( 'started' ) === $logged( $s ), $logged( $s ) );
$page( $s, $asking( $s ) );
$check( '  and opening it again within 30 minutes is still one', array( 'started' ) === $logged( $s ), $logged( $s ) );
$wpdb->update( Retention_Event_Repository::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS ) ), array( 'subscription_id' => $s->get_id() ) );
$page( $s, $asking( $s ) );
$check( '  but after 30 minutes it is a new one', array( 'started', 'started' ) === $logged( $s ), $logged( $s ) );
\Submex\Lifecycle\Retention_Reasons::update( 'found_better', null, true );

echo "\n3. Keep\n";
$to = $submit( $s, Cancel_Flow::KEEP, 'too_expensive' );
$check( 'Keep Subscription keeps it', $is( $s, Subscription_Status::Active ) );
$check( '  goes back to the subscription', Cancel_Flow::details_url( $s ) === $to, $to );
$check( '  and logs that it was kept, for the reason picked', array( 'started', 'started', 'kept:too_expensive' ) === $logged( $s ), $logged( $s ) );

echo "\n4. Continue canceling, with no reason\n";
$notices();
$to = $submit( $s, Cancel_Flow::CONTINUE );
$check( 'cancels in one submit', $is( $s, Subscription_Status::Cancelled ) );
$check( '  logs the cancel with no reason', array( 'started', 'started', 'kept:too_expensive', 'cancelled' ) === $logged( $s ), $logged( $s ) );
$check( '  stores no reason', '' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) );
$check( '  and goes to the subscription to show the confirmation, not a notice', '1' === ( $query_of( $to )[ Cancel_Flow::MESSAGE ] ?? '' ) && '' === $notices(), $to );
$shown = $page( $s, $query_of( $to ) );
$check( 'the confirmation shows above the subscription', str_contains( $shown, 'submex-message' ) && str_contains( $shown, 'Your subscription has been cancelled' ) && str_contains( $shown, 'Close' ) );
$check( '  once: a reload shows the subscription alone', ! str_contains( $page( $s, $query_of( $to ) ), 'submex-message' ) );

echo "\n5. Double submit, back button, replay\n";
$notices();
$submit( $s, Cancel_Flow::CONTINUE );
$check( 'a second submit changes nothing', $is( $s, Subscription_Status::Cancelled ) && 1 === count( array_keys( $logged( $s ), 'cancelled', true ) ), $logged( $s ) );
$check( '  and says it is already cancelled', str_contains( $notices(), 'already been cancelled' ) );
$submit( $s, Cancel_Flow::KEEP, 'too_expensive' );
$check( 'a stale Keep logs nothing either', 1 === count( array_keys( $logged( $s ), 'kept:too_expensive', true ) ), $logged( $s ) );
$notices();
$view = $page( $s, $asking( $s ) );
$check( 'reopening the reasons screen says it is already cancelled', str_contains( $view, 'already been cancelled' ) && ! str_contains( $view, 'submex_choice' ) );
$check( '  and starts nothing', 2 === count( array_keys( $logged( $s ), 'started', true ) ), $logged( $s ) );

echo "\n6. Continue canceling, with a reason\n";
$mail = array();
add_filter(
	'wp_mail',
	static function ( $a ) use ( &$mail ) {
		$mail[] = $a;
		return $a;
	},
	999
);
$recorded = array();
$on_recorded = static function ( $subscription, $reason, $detail ) use ( &$recorded ): void {
	$recorded[] = array( $subscription->get_id(), $reason, $detail );
};
add_action( 'submex_cancellation_reason_recorded', $on_recorded, 10, 3 );

$s = $make();
$submit( $s, Cancel_Flow::CONTINUE, 'other', "  The app kept crashing.\n<b>twice</b>  " );
$check( 'Other with a detail cancels', $is( $s, Subscription_Status::Cancelled ) );
$check( '  storing the reason and the detail, as text', 'other' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) && "The app kept crashing.\ntwice" === $fresh( $s )->get_meta( Cancellation_Survey::META_DETAIL ), array( $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ), $fresh( $s )->get_meta( Cancellation_Survey::META_DETAIL ) ) );
$check( '  logs the reason, then the cancel with it', array( 'reason:other', 'cancelled:other' ) === $logged( $s ), $logged( $s ) );
$check( '  tells listeners, as the survey did', array( array( $s->get_id(), 'other', "The app kept crashing.\ntwice" ) ) === $recorded, $recorded );
$merchant = array_values( array_filter( $mail, static fn( $m ) => (string) get_option( 'admin_email' ) === ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'] ) ) );
$check( "  and the store's cancellation email gives the reason and what they said", $merchant && str_contains( (string) $merchant[0]['message'], 'Something else' ) && str_contains( (string) $merchant[0]['message'], 'The app kept crashing.' ), $merchant ? wp_strip_all_tags( (string) $merchant[0]['message'] ) : $mail );
$activity = $plugin->get( 'activity' )->for_subscription( $s->get_id() );
$check( '  and the history says why', (bool) array_filter( $activity, static fn( $a ) => 'Cancellation reason: Something else' === $a->message ) );

$s = $make();
$submit( $s, Cancel_Flow::CONTINUE, 'too_expensive', 'should not be kept' );
$check( 'a detail sent with any reason but Other is dropped', 'too_expensive' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) && '' === $fresh( $s )->get_meta( Cancellation_Survey::META_DETAIL ) );

$s = $make();
$submit( $s, Cancel_Flow::CONTINUE, 'not_a_reason' );
$check( 'a reason that is not offered is dropped, and the cancel still goes through', $is( $s, Subscription_Status::Cancelled ) && '' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) && array( 'cancelled' ) === $logged( $s ), $logged( $s ) );

remove_action( 'submex_cancellation_reason_recorded', $on_recorded, 10 );

echo "\n7. The confirmation message switched off\n";
Retention_Flow::save_message( Retention_Flow::MESSAGE_CANCEL, array( 'enabled' => false ) );
$s = $make();
$notices();
$to = $submit( $s, Cancel_Flow::CONTINUE );
$check( 'the cancel says so in the plain notice', 'Your subscription has been cancelled.' === $notices() && ! isset( $query_of( $to )[ Cancel_Flow::MESSAGE ] ), $to );
$check( '  and no panel shows', ! str_contains( $page( $s, array( Cancel_Flow::MESSAGE => '1' ) ), 'submex-message' ) );
Retention_Flow::save_message( Retention_Flow::MESSAGE_CANCEL, array( 'enabled' => true ) );

echo "\n8. At the end of the period\n";
update_option( 'submex_cancellation_effective', 'period_end' );
$s = $make();
$submit( $s, Cancel_Flow::CONTINUE, 'temporary' );
$check( 'cancels as the store set it: at the end of the period', $is( $s, Subscription_Status::PendingCancel ) );
$submit( $s, Cancel_Flow::CONTINUE, 'temporary' );
$check( '  and once: a replay neither ends it now nor logs a second cancel', $is( $s, Subscription_Status::PendingCancel ) && array( 'reason:temporary', 'cancelled:temporary' ) === $logged( $s ), $logged( $s ) );
$check( '  the reasons screen calls it cancelled', str_contains( $page( $s, $asking( $s ) ), 'already been cancelled' ) );
update_option( 'submex_cancellation_effective', 'immediate' );

echo "\n9. On hold\n";
$s = $make( Subscription_Status::OnHold );
$check( 'the reasons screen opens for a subscription on hold', str_contains( $page( $s, $asking( $s ) ), 'submex_choice' ) );
$submit( $s, Cancel_Flow::CONTINUE );
$check( '  and Continue canceling cancels it', $is( $s, Subscription_Status::Cancelled ) );

echo "\n10. A cancel the subscription's terms refuse\n";
$refuse = static fn() => false;
add_filter( 'submex_can_cancel', $refuse );
$s    = $make();
$view = $page( $s, $asking( $s ) );
$check( 'the reasons screen gives the refusal and no flow', str_contains( $view, 'cannot be cancelled online' ) && ! str_contains( $view, 'submex_choice' ) );
foreach ( array( Cancel_Flow::CONTINUE, Cancel_Flow::KEEP, Cancel_Flow::DECLINE ) as $choice ) {
	$notices();
	$submit( $s, $choice, 'too_expensive' );
	$check( "  {$choice} is refused", $is( $s, Subscription_Status::Active ) && str_contains( $notices(), 'cannot be cancelled online' ) );
}
$check( '  and nothing is logged', array() === $logged( $s ), $logged( $s ) );
remove_filter( 'submex_can_cancel', $refuse );

echo "\n11. Somebody else, nobody, or a bad nonce\n";
$s = $make();
foreach ( array( 'another customer' => $bob, 'a logged-out visitor' => 0 ) as $who => $as ) {
	wp_set_current_user( $as );
	$view = $page( $s, $asking( $s ) );
	$check( "{$who} cannot open the reasons screen", ! str_contains( $view, 'submex_choice' ) && str_contains( $view, 'could not be found' ) );
	foreach ( array( Cancel_Flow::CONTINUE, Cancel_Flow::KEEP, Cancel_Flow::DECLINE ) as $choice ) {
		$submit( $s, $choice, 'too_expensive' );
	}
	$check( "  nor keep, continue or decline", $is( $s, Subscription_Status::Active ) && '' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) );
}
wp_set_current_user( $alice );
$view = $page( $s, array( Cancel_Flow::VIEW => Cancel_Flow::VIEW_REASONS, '_wpnonce' => 'not-a-nonce' ) );
$check( 'the owner with a bad nonce gets no reasons screen', ! str_contains( $view, 'submex_choice' ) && str_contains( $view, 'expired' ) );
foreach ( array( Cancel_Flow::CONTINUE, Cancel_Flow::KEEP ) as $choice ) {
	$submit( $s, $choice, 'too_expensive', '', 'not-a-nonce' );
}
$check( '  and cannot cancel or keep with it', $is( $s, Subscription_Status::Active ) );
$check( 'none of that logged anything', array() === $logged( $s ), $logged( $s ) );

// ---------------------------------------------------------------- an offer, as an extension supplies one

echo "\n12. An offer\n";
$asked_for = array();
$offer_for = static function ( $offer, $subscription, $reason ) use ( &$asked_for ) {
	$asked_for[] = array( $offer, $subscription instanceof Subscription ? $subscription->get_id() : null, $reason );
	return 'too_expensive' === $reason ? array( 'id' => 7, 'title' => 'Stay for half price' ) : $offer;
};
$drawn = array();
$draw  = static function ( $offer, $subscription, $reason ) use ( &$drawn ): void {
	$drawn[] = array( $offer, $subscription instanceof Subscription ? $subscription->get_id() : null, $reason );
	echo '<div class="sk-harness-offer">' . esc_html( $offer['title'] ) . '</div>';
};
add_filter( 'submex_retention_offer_for', $offer_for, 10, 3 );
add_action( 'submex_retention_render_offer', $draw, 10, 3 );

$s  = $make();
$to = $submit( $s, Cancel_Flow::CONTINUE, 'too_expensive' );
$check( 'with an offer for the reason, Continue canceling does not cancel yet', $is( $s, Subscription_Status::Active ) );
$check( '  the offer is asked for with no offer yet, the subscription and the reason', array( array( null, $s->get_id(), 'too_expensive' ) ) === $asked_for, $asked_for );
$check( '  the customer is sent to the offer screen', Cancel_Flow::VIEW_OFFER === ( $query_of( $to )[ Cancel_Flow::VIEW ] ?? '' ), $to );
$check( '  and the reason is logged', array( 'reason:too_expensive' ) === $logged( $s ), $logged( $s ) );

$view = $page( $s, $query_of( $to ) );
$check( 'the offer screen draws the offer through the action, with the offer, subscription and reason', str_contains( $view, 'sk-harness-offer' ) && array( array( array( 'id' => 7, 'title' => 'Stay for half price' ), $s->get_id(), 'too_expensive' ) ) === $drawn, $drawn );
$check( '  under it, one form that declines and cancels', 1 === substr_count( $view, 'value="' . Cancel_Flow::DECLINE . '"' ) && str_contains( $view, 'No thanks, continue cancelling' ) && str_contains( $view, 'name="submex_reason" value="too_expensive"' ) );
$relabel = static fn( $text, $offer ) => 7 === ( $offer['id'] ?? 0 ) ? 'No, cancel it' : $text;
add_filter( 'submex_retention_decline_text', $relabel, 10, 2 );
$check( '  whose label the offer can set', str_contains( $page( $s, $query_of( $to ) ), 'No, cancel it' ) );
remove_filter( 'submex_retention_decline_text', $relabel, 10 );

$asked_for = array();
$submit( $s, Cancel_Flow::DECLINE, 'too_expensive' );
$check( 'No thanks cancels in one submit', $is( $s, Subscription_Status::Cancelled ) );
$check( '  without asking for another offer', array() === $asked_for, $asked_for );
$check( '  keeping the reason, logged once', 'too_expensive' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) && array( 'reason:too_expensive', 'started', 'cancelled:too_expensive' ) === $logged( $s ), $logged( $s ) );
$submit( $s, Cancel_Flow::DECLINE, 'too_expensive' );
$check( '  and a replay changes nothing', 1 === count( array_keys( $logged( $s ), 'cancelled:too_expensive', true ) ), $logged( $s ) );
$drawn = array();
$view  = $page( $s, $query_of( $to ) );
$check( '  nor does reopening the offer screen, which says it is already cancelled', str_contains( $view, 'already been cancelled' ) && array() === $drawn );

$s         = $make();
$asked_for = array();
$submit( $s, Cancel_Flow::CONTINUE );
$check( 'with no reason picked, the offer is asked for with an empty reason, and none means cancel now', array( array( null, $s->get_id(), '' ) ) === $asked_for && $is( $s, Subscription_Status::Cancelled ), $asked_for );

echo "\n13. Two tabs, and the store\n";
// Tab A takes the offer, which never changes the status; tab B then turns it down.
$s  = $make();
$to = $submit( $s, Cancel_Flow::CONTINUE, 'too_expensive' );
$events->log( $s->get_id(), $alice, Retention_Event_Repository::OFFER_ACCEPTED, 'too_expensive', 7 );
$submit( $s, Cancel_Flow::DECLINE, 'too_expensive' );
$check( 'an offer taken in one tab does not stop Continue canceling in another', $is( $s, Subscription_Status::Cancelled ) && 1 === count( array_keys( $logged( $s ), 'cancelled:too_expensive', true ) ), $logged( $s ) );
$drawn = array();
$check( '  and the first tab, reloaded, says it is already cancelled and shows no offer', str_contains( $page( $s, $query_of( $to ) ), 'already been cancelled' ) && array() === $drawn );

// The store cancels while the customer looks at the offer.
$s  = $make();
$to = $submit( $s, Cancel_Flow::CONTINUE, 'too_expensive' );
$store = $fresh( $s );
$store->transition_to( Subscription_Status::Cancelled, 'Cancelled by the store.' );
$store->save();
$notices();
$submit( $s, Cancel_Flow::DECLINE, 'too_expensive' );
$check( 'the store cancelling first leaves No thanks nothing to do', $is( $s, Subscription_Status::Cancelled ) && ! in_array( 'cancelled:too_expensive', $logged( $s ), true ) && str_contains( $notices(), 'already been cancelled' ), $logged( $s ) );
$check( '  and stores no reason the customer never confirmed', '' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) );

remove_filter( 'submex_retention_offer_for', $offer_for, 10 );
remove_action( 'submex_retention_render_offer', $draw, 10 );

echo "\n14. Switched off half way\n";
$s = $make();
Retention_Flow::set( false );
$submit( $s, Cancel_Flow::CONTINUE, 'quality' );
$check( 'a reasons form sent after the store turned the flow off still cancels', $is( $s, Subscription_Status::Cancelled ) && 'quality' === $fresh( $s )->get_meta( Cancellation_Survey::META_REASON ) );

// ---------------------------------------------------------------- the stored text is escaped on the way out

echo "\n15. Escaping\n";
Retention_Flow::set( true );
update_option(
	Retention_Flow::DISPLAY_OPTION,
	array(
		'title'       => 'Wait <script>alert(1)</script>',
		'description' => '<p onclick="x()">Hi <strong>there</strong><script>alert(2)</script><img src=x onerror=alert(3)></p>',
		'keep_text'   => '<em>Stay</em>',
	)
);
$s    = $make();
$view = $page( $s, $asking( $s ) );
$check( 'markup written straight into the option never reaches the reasons screen', ! str_contains( $view, '<script' ) && ! str_contains( $view, 'onerror' ) && ! str_contains( $view, 'onclick' ) && ! str_contains( $view, '<img' ), substr( $view, 0, 900 ) );
$check( '  the allowed rich text does', str_contains( $view, '<strong>there</strong>' ) );
$check( '  and button text stays text', ! str_contains( $view, '<em>Stay' ) );
delete_option( Retention_Flow::DISPLAY_OPTION );

$messages = (array) get_option( Retention_Flow::MESSAGES_OPTION, array() );
update_option( Retention_Flow::MESSAGES_OPTION, array( 'cancel' => array( 'enabled' => true, 'title' => '<b>Bye</b>', 'description' => 'Gone <a href="javascript:alert(1)" onclick="y()">now</a><script>z()</script>', 'button_text' => 'OK' ) ) );
$to    = $submit( $s, Cancel_Flow::CONTINUE );
$shown = $page( $s, $query_of( $to ) );
$check( 'and never reaches the confirmation message', str_contains( $shown, 'submex-message' ) && ! str_contains( $shown, '<script' ) && ! str_contains( $shown, 'javascript:' ) && ! str_contains( $shown, 'onclick' ) && ! str_contains( $shown, '<b>Bye' ), substr( $shown, 0, 600 ) );
update_option( Retention_Flow::MESSAGES_OPTION, $messages );

// ---------------------------------------------------------------- the log, as extensions read it

echo "\n16. The event log\n";
$s = $make();
$check( 'an event that is not one of the six is refused', false === $events->log( $s->get_id(), $alice, 'bogus' ) && array() === $logged( $s ) );
$check( 'a start is written', true === $events->log( $s->get_id(), $alice, Retention_Event_Repository::STARTED ) );
$check( '  and a second inside 30 minutes is not', false === $events->log( $s->get_id(), $alice, Retention_Event_Repository::STARTED ) );
$check( 'other events are never folded', $events->log( $s->get_id(), $alice, Retention_Event_Repository::REASON, 'quality' ) && $events->log( $s->get_id(), $alice, Retention_Event_Repository::REASON, 'quality' ) );
$events->log( $s->get_id(), $alice, Retention_Event_Repository::OFFER_SHOWN, 'quality', 42 );
$from = time() - MINUTE_IN_SECONDS;
$to_t = time() + MINUTE_IN_SECONDS;
$rows = array_values( array_filter( $events->between( $from, $to_t, array( Retention_Event_Repository::OFFER_SHOWN ) ), static fn( $r ) => (int) $r->subscription_id === $s->get_id() ) );
$check( 'a range read returns the rows with their reason, offer and customer', 1 === count( $rows ) && 42 === (int) $rows[0]->offer_id && 'quality' === $rows[0]->reason_id && $alice === (int) $rows[0]->customer_id, $rows );
$check( '  and counts them, in total and by reason', $events->count( Retention_Event_Repository::REASON, $from, $to_t ) >= 2 && ( $events->count_by_reason( Retention_Event_Repository::REASON, $from, $to_t )['quality'] ?? 0 ) >= 2 );
$mine = static fn( array $rows ): array => array_values( array_map( static fn( $r ) => $r->event, array_filter( $rows, static fn( $r ) => (int) $r->subscription_id === $s->get_id() ) ) );
$check( '  of several events at once, oldest first', array( 'started', 'offer_shown' ) === $mine( $events->between( $from, $to_t, array( Retention_Event_Repository::STARTED, Retention_Event_Repository::OFFER_SHOWN ) ) ), $mine( $events->between( $from, $to_t, array( 'started', 'offer_shown' ) ) ) );
$check( '  every event when none is named', array( 'started', 'reason', 'reason', 'offer_shown' ) === $mine( $events->between( $from, $to_t ) ) );
$check( '  and none for an event that does not exist', array() === $events->between( $from, $to_t, array( 'bogus' ) ) );
$check( '  but not outside the range', 0 === count( array_filter( $events->between( time() + HOUR_IN_SECONDS, time() + 2 * HOUR_IN_SECONDS ), static fn( $r ) => (int) $r->subscription_id === $s->get_id() ) ) );

// ---------------------------------------------------------------- clean up

wp_set_current_user( 1 );
foreach ( $made as $id ) {
	$wpdb->delete( Retention_Event_Repository::table(), array( 'subscription_id' => $id ) );
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}
foreach ( $users as $id ) {
	wp_delete_user( $id );
}
foreach ( $was as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

submex_test_done( $fail );
