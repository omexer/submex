<?php
/**
 * Renewal outcomes at the edges: paid late, a listener failing after the money is taken,
 * paying early declined, a customer who never confirms, and reactivating while a renewal
 * waits for the customer.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Processor;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Charge_Result;
use Submex\Gateways\Test_Gateway;
use Submex\Lifecycle\Early_Renewal;

require __DIR__ . '/bootstrap.php';

$answer  = 'success';
$charges = 0;
\Submex\Plugin::instance()->get( 'gateways' )->add(
	new class( $answer, $charges ) implements \Submex\Gateways\Recurring_Gateway {
		public function __construct( public string &$answer, public int &$charges ) {}
		public function id(): string { return Test_Gateway::ID; }
		public function title(): string { return 'Harness'; }
		public function model(): \Submex\Gateways\Gateway_Model { return \Submex\Gateways\Gateway_Model::Tokenized; }
		public function supports( string $f ): bool { return true; }
		public function create_mandate( Subscription $s, \WC_Order $o ): Charge_Result { return Charge_Result::success( 'm' ); }
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): Charge_Result {
			++$this->charges;
			return match ( $this->answer ) {
				'action'  => Charge_Result::requires_action( $r->get_checkout_payment_url(), 'act' . $r->get_id() ),
				'decline' => Charge_Result::hard_decline( 'card_declined', 'Declined by the harness.' ),
				default   => Charge_Result::success( 'c' . $r->get_id() . '-' . $this->charges ),
			};
		}
		public function reconcile( Subscription $s, string $k ): ?Charge_Result { return null; }
		public function cancel_mandate( Subscription $s ): bool { return true; }
		public function update_payment_method( Subscription $s, string $t ): bool { return true; }
	}
);

$plugin    = \Submex\Plugin::instance();
$processor = $plugin->get( 'processor' );
$slots     = $plugin->get( 'charge_slots' );
$product   = submex_test_product();
$made      = array();

$make = static function ( string $period, int $due_in ) use ( $product, &$made ): Subscription {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( $period );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Test_Gateway::ID );
	$s->set_address( array( 'first_name' => 'Edge', 'email' => 'edges@example.test', 'country' => 'US' ), 'billing' );
	$s->update_meta_data( '_submex_site_url', get_option( 'siteurl' ) );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Harness', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + $due_in ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s;

	return $s;
};

$fresh   = static fn( Subscription $s ): Subscription => wc_get_order( $s->get_id() );
$renewal = static function ( Subscription $s ): ?WC_Order {
	$slot = \Submex\Plugin::instance()->get( 'charge_slots' )->latest_unsettled( $s->get_id() );

	return $slot && $slot->renewal_order_id ? wc_get_order( (int) $slot->renewal_order_id ) : null;
};

echo "\n1. A renewal the customer confirms long after it was due\n";
$answer = 'action';
$s      = $make( 'day', -HOUR_IN_SECONDS );
$processor->process( $s->get_id() );
$order = $renewal( $s );
global $wpdb;
$wpdb->update( $wpdb->prefix . 'submex_charge_slot', array( 'covers_to_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'renewal_order_id' => $order->get_id() ) );
$order->payment_complete( 'paid-late' );
$s = $fresh( $s );
$check( 'reactivates it', Subscription_Status::Active === $s->get_status_enum(), $s->get_status() );
$check( 'with the next payment still ahead, not due at once', strtotime( $s->get_next_payment() . ' UTC' ) > time(), $s->get_next_payment() );

echo "\n2. Something breaks after the money is taken\n";
$answer  = 'success';
$charges = 0;
$s       = $make( 'month', -HOUR_IN_SECONDS );
$breaks  = static function () {
	throw new TypeError( 'a listener broke' );
};
add_action( 'woocommerce_payment_complete', $breaks );
$processor->process( $s->get_id() );
remove_action( 'woocommerce_payment_complete', $breaks );
$processor->process( $s->get_id() );
$check( 'the card is charged once', 1 === $charges, $charges );
$check( 'and the next payment moves on', strtotime( $fresh( $s )->get_next_payment() . ' UTC' ) > time() + 20 * DAY_IN_SECONDS, $fresh( $s )->get_next_payment() );

echo "\n3. Paying early, declined\n";
update_option( Early_Renewal::OPTION, 'yes' );
$answer = 'decline';
$s      = $make( 'month', 20 * DAY_IN_SECONDS );
$due    = $s->get_next_payment();
$result = Early_Renewal::charge( $s );
$s      = $fresh( $s );
$check( 'says it was declined', false === $result['ok'] && str_contains( $result['message'], 'declined' ), $result );
$check( 'and leaves the paid-up subscription active', Subscription_Status::Active === $s->get_status_enum(), $s->get_status() );
$check( 'with its renewal date unchanged', $due === $s->get_next_payment() );
$check( 'and nothing left open to charge', null === $slots->latest_unsettled( $s->get_id() ) );
delete_option( Early_Renewal::OPTION );

echo "\n4. A customer who never confirms\n";
$answer = 'action';
$s      = $make( 'month', -HOUR_IN_SECONDS );
$processor->process( $s->get_id() );
$order = $renewal( $s );
$check( 'is given a week', (bool) as_next_scheduled_action( Renewal_Processor::ACTION_LAPSE, array( 'order_id' => $order->get_id() ) ) );
$failed = 0;
$count  = static function () use ( &$failed ) {
	++$failed;
};
add_action( 'submex_renewal_failed', $count );
$processor->lapse_customer_action( $order->get_id() );
remove_action( 'submex_renewal_failed', $count );
$check( 'after which the renewal is a failed payment', 1 === $failed && wc_get_order( $order->get_id() )->has_status( 'failed' ), array( $failed, wc_get_order( $order->get_id() )->get_status() ) );
as_unschedule_all_actions( Renewal_Processor::ACTION_LAPSE );

echo "\n5. Reactivating while a renewal waits for the customer\n";
$s = $make( 'month', -HOUR_IN_SECONDS );
$processor->process( $s->get_id() );
$request = new WP_REST_Request( 'POST', '/submex/v1/subscriptions/' . $s->get_id() . '/actions' );
$request->set_param( 'id', $s->get_id() );
$request->set_param( 'action', 'reactivate' );
$response = ( new \Submex\Rest\Subscriptions_Controller( $plugin->get( 'activity' ), $processor ) )->run_action( $request );
$check( 'is refused, with the reason', $response instanceof WP_Error && 'submex_awaiting_customer' === $response->get_error_code(), $response instanceof WP_Error ? $response->get_error_code() : 'allowed' );
$check( 'and the subscription stays on hold', Subscription_Status::OnHold === $fresh( $s )->get_status_enum() );
as_unschedule_all_actions( Renewal_Processor::ACTION_LAPSE );

foreach ( $made as $s ) {
	foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'created_via' => 'submex_renewal' ) ) as $o ) {
		if ( (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id() ) {
			$o->delete( true );
		}
	}
	$s->delete( true );
}

submex_test_done( $fail );
