<?php
/**
 * Paying a period early charges now and leaves the schedule where it was.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Test_Gateway;
use Submex\Lifecycle\Early_Renewal;

require __DIR__ . '/bootstrap.php';

$approve = true;
\Submex\Plugin::instance()->get( 'gateways' )->add(
	new class( $approve ) implements \Submex\Gateways\Recurring_Gateway {
		public function __construct( public bool &$approve ) {}
		public function id(): string { return Test_Gateway::ID; }
		public function title(): string { return 'Harness'; }
		public function model(): \Submex\Gateways\Gateway_Model { return \Submex\Gateways\Gateway_Model::Tokenized; }
		public function supports( string $f ): bool { return true; }
		public function create_mandate( Subscription $s, \WC_Order $o ): \Submex\Gateways\Charge_Result { return \Submex\Gateways\Charge_Result::success( 'm' ); }
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): \Submex\Gateways\Charge_Result {
			return $this->approve ? \Submex\Gateways\Charge_Result::success( 'c' . $r->get_id() ) : \Submex\Gateways\Charge_Result::hard_decline( 'card_declined', 'Declined by the harness.' );
		}
		public function reconcile( Subscription $s, string $k ): ?\Submex\Gateways\Charge_Result { return null; }
		public function cancel_mandate( Subscription $s ): bool { return true; }
		public function update_payment_method( Subscription $s, string $t ): bool { return true; }
	}
);

$product = submex_test_product();

$make = function () use ( $product ) {
	$s = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( Test_Gateway::ID );
	$s->set_address( array( 'first_name' => 'Early', 'email' => 'early@example.test', 'country' => 'US' ), 'billing' );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Harness', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	return $s;
};

update_option( Early_Renewal::OPTION, 'no' );
$s = $make();
$check( 'not offered until the store turns it on', ! Early_Renewal::is_available_for( $s ) );

update_option( Early_Renewal::OPTION, 'yes' );
$check( 'offered once it is on', Early_Renewal::is_available_for( $s ) );

$due_before = $s->get_next_payment();
$result     = Early_Renewal::charge( $s );
$check( 'the charge succeeded', $result['ok'], $result );

$s = wc_get_order( $s->get_id() );
$check( 'the renewal date did not move forward from the period paid for', substr( (string) $s->get_next_payment(), 0, 10 ) === gmdate( 'Y-m-d', strtotime( $due_before . ' UTC +1 month' ) ), array( $due_before, $s->get_next_payment() ) );

global $wpdb;
$orders = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_submex_subscription_id' AND meta_value=%d", $s->get_id() ) );
$check( 'exactly one renewal order was raised', 1 === count( $orders ), $orders );
if ( $orders ) {
	$check( 'and it was paid', in_array( wc_get_order( (int) $orders[0] )->get_status(), array( 'processing', 'completed' ), true ), wc_get_order( (int) $orders[0] )->get_status() );
}

$decline = $make();
$approve = false;
$r       = Early_Renewal::charge( $decline );
$check( 'a decline is reported honestly', ! $r['ok'] && str_contains( $r['message'], 'declined' ), $r );

$paypal = $make();
$paypal->set_payment_method( 'submex_paypal' );
$paypal->save();
$check( 'never offered for a gateway that bills on its own plan', ! Early_Renewal::is_available_for( $paypal ) );

delete_option( Early_Renewal::OPTION );
foreach ( array( $s, $decline, $paypal ) as $x ) { $x->delete( true ); }
submex_test_done( $fail );
