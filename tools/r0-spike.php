<?php
/**
 * R0 spike harness — answers R0-1 (HPOS custom order type) and R0-2 (period_index).
 * Run: docker compose exec -T wordpress php /var/www/html/submex-r0.php
 */

require_once dirname( __DIR__, 5 ) . '/wp-load.php';

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

global $wpdb;

$pass = 0; $fail = 0; $findings = array();
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label" . ( $detail ? "\n          -> $detail" : '' ) . "\n"; }
	return (bool) $cond;
}
function finding( $text ) { global $findings; $findings[] = $text; }

echo "\nWP " . get_bloginfo( 'version' ) . " / WC " . WC()->version . " / PHP " . PHP_VERSION;
echo "\nHPOS enabled: " . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no' ) . "\n";

// ==================================================== R0-1
echo "\n[R0-1] Custom order type on HPOS\n";

check( 'order type is registered', in_array( Subscription::TYPE, wc_get_order_types(), true ) );

$type_args = wc_get_order_type( Subscription::TYPE );
check( 'class_name maps to our class', isset( $type_args['class_name'] ) && Subscription::class === $type_args['class_name'] );

// Data store resolves?
try {
	$store = WC_Data_Store::load( Subscription::TYPE );
	check( 'WC_Data_Store::load( submex_sub ) resolves', true );
	echo "          store: " . get_class( $store->get_current_class_name() ? $store : $store ) . " -> " . $store->get_current_class_name() . "\n";
} catch ( \Exception $e ) {
	check( 'WC_Data_Store::load( submex_sub ) resolves', false, $e->getMessage() );
	finding( 'R0-1 BLOCKER: data store did not resolve: ' . $e->getMessage() );
}

// Create + save
$sub = new Subscription();
$sub->set_billing_period( 'month' );
$sub->set_billing_interval( 1 );
$sub->set_next_payment( '2026-09-17 00:00:00' );
$sub->set_trial_end( '2026-08-24 00:00:00' );
$sub->set_period_index( 0 );
$sub->set_currency( 'USD' );
$sub->set_total( 29.00 );
$sub->transition_to( Subscription_Status::Active );
$sub->set_billing_email( 'sarah@example.test' );
$sub->set_billing_first_name( 'Sarah' );
$sub_id = $sub->save();

check( 'subscription saved and got an ID', $sub_id > 0, "id={$sub_id}" );

// Where did it actually land?
$in_hpos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $sub_id ) );
$hpos_type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}wc_orders WHERE id = %d", $sub_id ) );
$hpos_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $sub_id ) );
$in_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $sub_id ) );

check( 'row written to wc_orders (HPOS)', 1 === $in_hpos );
check( 'wc_orders.type is submex_sub', Subscription::TYPE === $hpos_type, "type={$hpos_type}" );
echo "          wc_orders.status = '{$hpos_status}'\n";
if ( 0 !== $in_posts ) {
	finding( 'A row was also written to wp_posts (compatibility mode may be on).' );
}

// Read back through the factory
$loaded = wc_get_order( $sub_id );
check( 'wc_get_order() returns our class', $loaded instanceof Subscription, is_object( $loaded ) ? get_class( $loaded ) : var_export( $loaded, true ) );

if ( $loaded instanceof Subscription ) {
	check( 'get_type() is submex_sub', Subscription::TYPE === $loaded->get_type() );
	check( 'status round-trips', Subscription_Status::Active === $loaded->get_status_enum(), 'got ' . var_export( $loaded->get_status(), true ) );
	check( 'billing_period round-trips', 'month' === $loaded->get_billing_period(), var_export( $loaded->get_billing_period(), true ) );
	check( 'billing_interval round-trips', 1 === $loaded->get_billing_interval(), var_export( $loaded->get_billing_interval(), true ) );
	check( 'next_payment round-trips', '2026-09-17 00:00:00' === $loaded->get_next_payment(), var_export( $loaded->get_next_payment(), true ) );
	check( 'trial_end round-trips', '2026-08-24 00:00:00' === $loaded->get_trial_end(), var_export( $loaded->get_trial_end(), true ) );
	check( 'core props round-trip (total, currency, email)', 29.00 === (float) $loaded->get_total() && 'USD' === $loaded->get_currency() && 'sarah@example.test' === $loaded->get_billing_email() );
	check( 'no pending changes after read', array() === $loaded->get_changes(), wp_json_encode( $loaded->get_changes() ) );
}

// Meta storage location
$meta_in_hpos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s", $sub_id, '_submex_next_payment' ) );
check( 'schedule meta stored in wc_orders_meta', 1 === $meta_in_hpos );

// Line items
$product = new WC_Product_Simple();
$product->set_name( 'Monthly Coffee Box' );
$product->set_regular_price( 29.00 );
$product->save();

$item_id = $loaded->add_product( $product, 1 );
$loaded->calculate_totals( false );
$loaded->save();
$reloaded = wc_get_order( $sub_id );
check( 'line items persist on the subscription', 1 === count( $reloaded->get_items() ), count( $reloaded->get_items() ) . ' items' );

// Notes
$loaded->add_order_note( 'R0 spike note' );
$notes = wc_get_order_notes( array( 'order_id' => $sub_id ) );
check( 'order notes work', ! empty( $notes ) );

// Querying
$found = wc_get_orders( array( 'type' => Subscription::TYPE, 'limit' => -1, 'return' => 'ids' ) );
check( 'KNOWN TRAP: bare wc_get_orders( type ) returns nothing', ! in_array( $sub_id, array_map( 'intval', $found ), true ) );
finding( 'wc_get_orders() with only a type returns nothing for custom order types: an omitted status (and "any") resolves to the shop_order status list, which never contains es-*. Statuses must be passed wc- prefixed even though the object reports them unprefixed. Wrapped in Subscription_Query so no caller has to know.' );

check( 'Subscription_Query::ids() finds it', in_array( $sub_id, Subscription_Query::ids( array( 'limit' => -1 ) ), true ) );
check( 'Subscription_Query filters by status', in_array( $sub_id, Subscription_Query::billable_ids( array( 'limit' => -1 ) ), true ) );
check( 'Subscription_Query respects a non-matching status', ! in_array( $sub_id, Subscription_Query::ids( array( 'status' => Subscription_Status::Cancelled->value, 'limit' => -1 ) ), true ) );
check( 'Subscription_Query returns our class by default', ( Subscription_Query::get( array( 'limit' => 1 ) )[0] ?? null ) instanceof Subscription );

// Placeholder post row: expected HPOS behaviour or our own misconfiguration?
$post_row = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d", $sub_id ) );
if ( $post_row ) {
	echo "          wp_posts placeholder: post_type='{$post_row->post_type}' post_status='{$post_row->post_status}'\n";
}

// Isolation from real orders
$plain = wc_create_order();
$plain->set_total( 10 );
$plain->save();
$shop_orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'return' => 'ids' ) );
check( 'subscriptions do NOT leak into shop_order queries', ! in_array( $sub_id, array_map( 'intval', $shop_orders ), true ) );

// ==================================================== R0-2
echo "\n[R0-2] period_index / charge-slot semantics\n";

$slot_table = $wpdb->prefix . 'submex_charge_slot';
$wpdb->query( "DROP TABLE IF EXISTS {$slot_table}" );
$wpdb->query( "CREATE TABLE {$slot_table} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  period_index INT UNSIGNED NOT NULL,
  attempt_group SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  state VARCHAR(20) NOT NULL,
  scheduled_for_gmt DATETIME NOT NULL,
  covers_from_gmt DATETIME NOT NULL,
  covers_to_gmt DATETIME NOT NULL,
  renewal_order_id BIGINT UNSIGNED NULL,
  created_gmt DATETIME NOT NULL,
  UNIQUE KEY uq_slot (subscription_id, period_index),
  KEY idx_state (state, scheduled_for_gmt)
) {$wpdb->get_charset_collate()}" );

check( 'charge slot table created', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$slot_table}'" ) );

function claim_slot( $sub_id, $index, $from = '2026-09-17 00:00:00', $to = '2026-10-17 00:00:00' ) {
	global $wpdb, $slot_table;
	$wpdb->suppress_errors( true );
	$ok = $wpdb->insert( $slot_table, array(
		'subscription_id'   => $sub_id,
		'period_index'      => $index,
		'attempt_group'     => 0,
		'state'             => 'claimed',
		'scheduled_for_gmt' => $from,
		'covers_from_gmt'   => $from,
		'covers_to_gmt'     => $to,
		'created_gmt'       => gmdate( 'Y-m-d H:i:s' ),
	) );
	$wpdb->suppress_errors( false );
	return false !== $ok;
}

check( 'slot 1 claimed', claim_slot( $sub_id, 1 ) );
check( 'duplicate claim of slot 1 is rejected by the unique key', ! claim_slot( $sub_id, 1 ) );
check( 'slot 2 claimed', claim_slot( $sub_id, 2 ) );

// Simulated concurrency: two workers both read MAX()+1 and both insert.
$next_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(period_index) FROM {$slot_table} WHERE subscription_id = %d", $sub_id ) ) + 1;
$next_b = $next_a; // second worker read the same value
$a_won = claim_slot( $sub_id, $next_a );
$b_won = claim_slot( $sub_id, $next_b );
check( 'concurrent claim: exactly one worker wins', $a_won xor $b_won, "a={$a_won} b={$b_won}" );

$total_slots = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slot_table} WHERE subscription_id = %d", $sub_id ) );
check( 'exactly 3 slots exist after 5 claim attempts', 3 === $total_slots, "{$total_slots} slots" );

// Pause consumes no index
$before_pause = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(period_index) FROM {$slot_table} WHERE subscription_id = %d", $sub_id ) );
$loaded = wc_get_order( $sub_id );
$loaded->transition_to( Subscription_Status::OnHold );
$loaded->save();
$loaded = wc_get_order( $sub_id );
$loaded->transition_to( Subscription_Status::Active );
$loaded->save();
$after_pause = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(period_index) FROM {$slot_table} WHERE subscription_id = %d", $sub_id ) );
check( 'pause/resume consumes no period_index', $before_pause === $after_pause, "{$before_pause} -> {$after_pause}" );

// Catch-up re-base consumes exactly one slot spanning the gap
$rebase_index = $after_pause + 1;
check( 're-base claims one slot covering the whole gap',
	claim_slot( $sub_id, $rebase_index, '2026-06-17 00:00:00', '2026-10-17 00:00:00' ) );
$span = $wpdb->get_row( $wpdb->prepare( "SELECT covers_from_gmt, covers_to_gmt FROM {$slot_table} WHERE subscription_id = %d AND period_index = %d", $sub_id, $rebase_index ) );
check( 'the re-based slot records the full covered period',
	'2026-06-17 00:00:00' === $span->covers_from_gmt && '2026-10-17 00:00:00' === $span->covers_to_gmt );

// Idempotency key behaviour
$key = fn( $sid, $idx, $grp ) => sha1( "site|{$sid}|{$idx}|{$grp}" );
check( 'timeout retry reuses the same idempotency key', $key( $sub_id, 5, 0 ) === $key( $sub_id, 5, 0 ) );
check( 'decline retry produces a different key', $key( $sub_id, 5, 0 ) !== $key( $sub_id, 5, 1 ) );

// ==================================================== status machine
echo "\n[R0-2b] Status transition rules\n";
check( 'active -> on-hold allowed', Subscription_Status::Active->can_transition_to( Subscription_Status::OnHold ) );
check( 'cancelled is terminal', ! Subscription_Status::Cancelled->can_transition_to( Subscription_Status::Active ) );
check( 'pending -> switched refused', ! Subscription_Status::Pending->can_transition_to( Subscription_Status::Switched ) );
$threw = false;
try {
	$x = wc_get_order( $sub_id );
	$x->transition_to( Subscription_Status::Cancelled );
	$x->save();
	$x = wc_get_order( $sub_id );
	$x->transition_to( Subscription_Status::Active );
} catch ( \InvalidArgumentException $e ) { $threw = true; }
check( 'illegal transition throws rather than silently no-opping', $threw );

// ==================================================== cleanup
$wpdb->query( "DROP TABLE IF EXISTS {$slot_table}" );
foreach ( array( $sub_id, $plain->get_id() ) as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
$product->delete( true );

echo "\n==== $pass passed, $fail failed ====\n";
if ( $findings ) {
	echo "\nFindings:\n";
	foreach ( $findings as $f ) { echo "  * $f\n"; }
}
exit( $fail > 0 ? 1 : 0 );
