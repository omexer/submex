<?php
/**
 * What a merchant does in the admin takes effect: a product stays a subscription when saved,
 * a moved date moves the charge, Renew now tells the truth, and pay-by-link customers are not
 * told a card will be charged.
 *
 * @package Submex
 */

use Submex\Billing\Renewal_Scheduler;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Gateways\Test_Gateway;
use Submex\Product\Product_Types;
use Submex\Product\Subscription_Product;

require __DIR__ . '/bootstrap.php';

$plugin  = \Submex\Plugin::instance();
$fields  = new \Submex\Product\Product_Meta_Fields();
$made    = array();
$approve = true;

$plugin->get( 'gateways' )->add(
	new class( $approve ) implements \Submex\Gateways\Recurring_Gateway {
		public int $charges = 0;
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

$subscription = static function ( string $gateway, int $days ) use ( &$made ): Subscription {
	$product = submex_test_product();
	$s       = new Subscription();
	$s->set_customer_id( 1 );
	$s->set_currency( get_woocommerce_currency() );
	$s->set_billing_period( 'month' );
	$s->set_billing_interval( 1 );
	$s->set_payment_method( $gateway );
	$s->set_address( array( 'first_name' => 'Merchant', 'email' => 'sb-merchant-actions@example.test', 'country' => 'US' ), 'billing' );
	$item = new WC_Order_Item_Product();
	$item->set_props( array( 'name' => 'Harness', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
	$s->add_item( $item );
	$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) );
	$s->transition_to( Subscription_Status::Pending );
	$s->calculate_totals( false );
	$s->save();
	$s->transition_to( Subscription_Status::Active );
	$s->save();
	$made[] = $s;

	return $s;
};

$renewals = static function ( Subscription $s ): array {
	return array_values(
		array_filter(
			wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'created_via' => 'submex_renewal' ) ),
			static fn( $o ) => (int) $o->get_meta( '_submex_subscription_id' ) === $s->get_id()
		)
	);
};

$act = static function ( Subscription $s, string $action ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/submex/v1/subscriptions/' . $s->get_id() . '/actions' );
	$request->set_param( 'action', $action );

	return rest_do_request( $request );
};

echo "\n1. A variable subscription goes in the cart as the chosen variation\n";
$variable = new \Submex\Product\Variable_Subscription();
$check( 'WooCommerce is told to treat it as variable', 'variable' === apply_filters( 'woocommerce_add_to_cart_handler', Product_Types::VARIABLE, $variable ) );

echo "\n2. A product made from the setup checklist, saved in the editor\n";
$nonce_user = wp_create_nonce( 'submex_create_product' );
$escape     = static function () {
	throw new RuntimeException( 'redirected' );
};
add_filter( 'wp_redirect', $escape, 1 );
$_POST = array(
	'_wpnonce'        => $nonce_user,
	'submex_name'     => 'SK checklist product',
	'submex_price'    => '12',
	'submex_period'   => 'month',
	'submex_interval' => 1,
);
$guide = new \Submex\Admin\Setup_Guide( $plugin->get( 'scheduler' ) );
$known = wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) );
try {
	$guide->create_first_product();
} catch ( RuntimeException $e ) {
	unset( $e );
}
remove_filter( 'wp_redirect', $escape, 1 );
$_POST   = array();
$created = array_values( array_diff( wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) ), $known ) );
$new     = $created ? wc_get_product( $created[0] ) : null;
$check( 'the checklist made a product', $new instanceof WC_Product, $created );
if ( $new ) {
	$made[] = $new;
	$check( 'of the Subscription type', Product_Types::SIMPLE === $new->get_type(), $new->get_type() );
	$fields->save( $new );
	$new->save();
	$check( 'and saving it keeps it a subscription', 'yes' === wc_get_product( $new->get_id() )->get_meta( Subscription_Product::META_ENABLED ) );
}

$legacy = new WC_Product_Simple();
$legacy->set_name( 'SK legacy simple plan' );
$legacy->set_regular_price( '12' );
$legacy->update_meta_data( Subscription_Product::META_ENABLED, 'yes' );
$legacy->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$legacy->save();
$made[] = $legacy;
$legacy = wc_get_product( $legacy->get_id() );
$fields->save( $legacy );
$legacy->save();
$check( 'a simple product already billing keeps billing when saved', 'yes' === wc_get_product( $legacy->get_id() )->get_meta( Subscription_Product::META_ENABLED ) );

$retype = new \Submex\Product\Simple_Subscription();
$retype->set_name( 'SK retyped to simple' );
$retype->set_regular_price( '12' );
$retype->update_meta_data( Subscription_Product::META_ENABLED, 'yes' );
$retype->save();
$made[] = $retype;
// As WooCommerce saves a type change: the new class, while the stored type is still the old one.
$now_simple = new WC_Product_Simple( $retype->get_id() );
$fields->save( $now_simple );
$check( 'one changed from Subscription to Simple stops being a subscription', 'no' === $now_simple->get_meta( Subscription_Product::META_ENABLED ) );

$plain = new WC_Product_Simple();
$plain->set_name( 'SK plain' );
$plain->set_regular_price( '12' );
$plain->save();
$made[] = $plain;
$plain  = wc_get_product( $plain->get_id() );
$fields->save( $plain );
$check( 'a plain simple product stays plain', 'yes' !== $plain->get_meta( Subscription_Product::META_ENABLED ) );

echo "\n3. Moving the next payment\n";
$s         = $subscription( Test_Gateway::ID, 20 );
$scheduler = $plugin->get( 'scheduler' );
$scheduler->schedule_next( $s );
$args = array( 'subscription_id' => $s->get_id() );
$move = time() + 40 * DAY_IN_SECONDS;

$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $s->get_id() );
$request->set_param( 'next_payment', gmdate( 'Y-m-d H:i:s', $move ) );
$response = rest_do_request( $request );
$charge   = as_next_scheduled_action( Renewal_Scheduler::ACTION_RENEWAL, $args, Renewal_Scheduler::GROUP );
$remind   = as_next_scheduled_action( Renewal_Scheduler::ACTION_REMINDER, $args, Renewal_Scheduler::GROUP );
$check( 'the request is accepted', 200 === $response->get_status(), $response->get_data() );
$check( 'the charge moves with it', $charge && abs( $charge - $move ) < 120, array( $charge, $move ) );
$check( 'and so does the reminder', $remind && $remind > time() + 30 * DAY_IN_SECONDS, $remind );

$on_hold = $subscription( Test_Gateway::ID, 20 );
$on_hold->transition_to( Subscription_Status::OnHold );
$on_hold->save();
$as_args = array( 'subscription_id' => $on_hold->get_id() );
as_unschedule_all_actions( Renewal_Scheduler::ACTION_RENEWAL, $as_args, Renewal_Scheduler::GROUP );
$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $on_hold->get_id() );
$request->set_param( 'next_payment', gmdate( 'Y-m-d H:i:s', $move ) );
rest_do_request( $request );
$check( 'a subscription on hold is not queued to charge by moving its date', ! as_next_scheduled_action( Renewal_Scheduler::ACTION_RENEWAL, $as_args, Renewal_Scheduler::GROUP ) );

echo "\n4. Renew now\n";
$s      = $subscription( Test_Gateway::ID, 20 );
$first  = $act( $s, 'renew_now' );
$second = $act( wc_get_order( $s->get_id() ), 'renew_now' );
$check( 'the first press succeeds', 200 === $first->get_status(), $first->get_data() );
$check( 'a second press straight after is refused', 409 === $second->get_status(), $second->get_status() );
$check( 'and only one renewal is raised', 1 === count( $renewals( $s ) ), count( $renewals( $s ) ) );

$approve  = false;
$s        = $subscription( Test_Gateway::ID, 20 );
$declined = $act( $s, 'renew_now' );
$check( 'a declined early renewal is reported as declined', 402 === $declined->get_status(), $declined->get_status() );
$check( 'with the reason', str_contains( (string) ( $declined->get_data()['message'] ?? '' ), 'Declined by the harness' ), $declined->get_data() );
$approve = true;
$retry   = $act( wc_get_order( $s->get_id() ), 'renew_now' );
$check( 'and it can be tried again straight away', 200 === $retry->get_status(), $retry->get_data() );

$approve  = false;
$s        = $subscription( Test_Gateway::ID, -1 );
$declined = $act( $s, 'renew_now' );
$check( 'a declined overdue renewal is reported as declined too', 402 === $declined->get_status(), $declined->get_status() );
$approve = true;

echo "\n5. Customers who pay by link\n";
$sent = array();
$spy  = static function ( $args ) use ( &$sent ) {
	$sent[] = wp_strip_all_tags( (string) $args['message'] );
	return $args;
};
add_filter( 'wp_mail', $spy );
$emails = WC()->mailer()->get_emails();

$manual = $subscription( 'manual', 5 );
$emails['Submex_Renewal_Reminder']->trigger( $manual );
$body = (string) end( $sent );
$check( 'the reminder does not promise a charge', ! str_contains( $body, "We'll charge" ) && ! str_contains( $body, 'We&#039;ll charge' ), $body );
$check( 'nor that nothing needs doing', ! str_contains( $body, 'Nothing to do' ), $body );
$check( 'and says a link is coming', str_contains( $body, 'link to pay' ), $body );

$order = wc_create_order( array( 'customer_id' => 1 ) );
$order->set_total( '20' );
$order->save();
$made[] = $order;
$emails['Submex_Confirm_Payment']->trigger( $manual, $order, $order->get_checkout_payment_url() );
$body = (string) end( $sent );
$check( 'the pay email does not blame the bank', ! str_contains( $body, 'Your bank' ), $body );
$check( 'and its button says pay', str_contains( $body, 'Pay renewal' ), $body );

$card = $subscription( Test_Gateway::ID, 5 );
$emails['Submex_Renewal_Reminder']->trigger( $card );
$check( 'a card subscription still gets the charge wording', str_contains( (string) end( $sent ), 'charge' ), end( $sent ) );
remove_filter( 'wp_mail', $spy );

foreach ( $made as $thing ) {
	if ( $thing instanceof Subscription ) {
		as_unschedule_all_actions( '', array( 'subscription_id' => $thing->get_id() ), Renewal_Scheduler::GROUP );
		delete_transient( 'submex_renew_now_' . $thing->get_id() );
		foreach ( $renewals( $thing ) as $renewal ) {
			$renewal->delete( true );
		}
	}
	$thing->delete( true );
}

submex_test_done( $fail );
