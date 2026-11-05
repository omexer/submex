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

$subscription = static function ( string $gateway, int $days, Subscription_Status $status = Subscription_Status::Active ) use ( &$made ): Subscription {
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
	$s->transition_to( $status );
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

$overdue = $subscription( Test_Gateway::ID, -2 );
$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $overdue->get_id() );
$request->set_param( 'next_payment', gmdate( 'Y-m-d H:i', time() - 3 * DAY_IN_SECONDS ) );
$check( 'a date in the past is refused, or saving would bill at once', 400 === rest_do_request( $request )->get_status() );
$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $overdue->get_id() );
$request->set_param( 'next_payment', substr( (string) $overdue->get_next_payment(), 0, 16 ) );
$request->set_param( 'end_date', gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS ) );
$check( 'an unchanged overdue date does not block setting an end date', 200 === rest_do_request( $request )->get_status() );
$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $overdue->get_id() );
$request->set_param( 'end_date', '' );
rest_do_request( $request );
$check( 'and an end date can be cleared', empty( wc_get_order( $overdue->get_id() )->get_end_date() ), wc_get_order( $overdue->get_id() )->get_end_date() );

echo "\n3b. A subscription the provider bills\n";
$plugin->get( 'gateways' )->add(
	new class() implements \Submex\Gateways\Recurring_Gateway {
		public function id(): string { return 'sk_managed'; }
		public function title(): string { return 'Managed harness'; }
		public function model(): \Submex\Gateways\Gateway_Model { return \Submex\Gateways\Gateway_Model::GatewayManaged; }
		public function supports( string $f ): bool { return true; }
		public function create_mandate( Subscription $s, \WC_Order $o ): \Submex\Gateways\Charge_Result { return \Submex\Gateways\Charge_Result::success( 'm' ); }
		public function charge_renewal( Subscription $s, \WC_Order $r, string $k ): \Submex\Gateways\Charge_Result { return \Submex\Gateways\Charge_Result::success( 'c' ); }
		public function reconcile( Subscription $s, string $k ): ?\Submex\Gateways\Charge_Result { return null; }
		public function cancel_mandate( Subscription $s ): bool { return true; }
		public function update_payment_method( Subscription $s, string $t ): bool { return true; }
	}
);
$managed = $subscription( 'sk_managed', 10 );
$check( 'Renew now is refused, not reported as done', 409 === $act( $managed, 'renew_now' )->get_status() );
$request = new WP_REST_Request( 'PUT', '/submex/v1/subscriptions/' . $managed->get_id() );
$request->set_param( 'next_payment', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
$check( 'and its date cannot be moved here', 409 === rest_do_request( $request )->get_status() );

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

echo "\n6. When customers cannot cancel\n";
$was = get_option( \Submex\Lifecycle\Cancellation_Policy::OPTION, null );
update_option( \Submex\Lifecycle\Cancellation_Policy::OPTION, 'no' );
$disclosure = new \Submex\Frontend\Disclosure();
$promise    = submex_test_product();
$words      = strtolower( wp_strip_all_tags( implode( ' ', array_column( $disclosure->lines( $promise ), 'text' ) ) . ' ' . $disclosure->sentence( $promise ) ) );
$check( 'the terms do not promise cancelling', ! str_contains( $words, 'cancel' ), $words );
$sent = array();
add_filter( 'wp_mail', $spy );
$quiet = $subscription( Test_Gateway::ID, 5 );
$emails['Submex_Subscription_Started']->trigger( $quiet );
$check( 'nor does the started email', ! str_contains( (string) end( $sent ), 'cancel at any time' ), end( $sent ) );
$emails['Submex_Renewal_Reminder']->trigger( $quiet );
$check( 'nor the reminder', ! str_contains( strtolower( (string) end( $sent ) ), 'cancel' ), end( $sent ) );
remove_filter( 'wp_mail', $spy );
null === $was ? delete_option( \Submex\Lifecycle\Cancellation_Policy::OPTION ) : update_option( \Submex\Lifecycle\Cancellation_Policy::OPTION, $was );

echo "\n7. A sign-up fee in the consent\n";
$fee = new \Submex\Product\Simple_Subscription();
$fee->set_name( 'SK fee plan' );
$fee->set_regular_price( '30' );
$fee->update_meta_data( Subscription_Product::META_PERIOD, 'month' );
$fee->update_meta_data( Subscription_Product::META_INTERVAL, 1 );
$fee->update_meta_data( Subscription_Product::META_SIGNUP_FEE, '10' );
$fee->save();
$made[] = $fee;
$check( 'is stated', str_contains( html_entity_decode( wp_strip_all_tags( $disclosure->sentence( wc_get_product( $fee->get_id() ) ) ) ), 'sign-up fee of $10.00' ), $disclosure->sentence( wc_get_product( $fee->get_id() ) ) );

echo "\n8. Cancelled by the store\n";
$sent = array();
add_filter( 'wp_mail', $spy );
$gone = $subscription( Test_Gateway::ID, 5 );
$gone->transition_to( Subscription_Status::Cancelled );
$gone->save();
$emails['Submex_Merchant_Cancelled']->trigger( $gone );
$check( 'the merchant email gives no access date', ! str_contains( (string) end( $sent ), 'Access ends' ), end( $sent ) );
remove_filter( 'wp_mail', $spy );

echo "\n9. The list and the numbers\n";
$soon_s = $subscription( Test_Gateway::ID, 2 );
$late_s = $subscription( Test_Gateway::ID, 90 );
$order_ids = static function ( string $order ) use ( $soon_s, $late_s ): array {
	$request = new WP_REST_Request( 'GET', '/submex/v1/subscriptions' );
	$request->set_param( 'orderby', 'next_payment' );
	$request->set_param( 'order', $order );
	$request->set_param( 'per_page', 100 );
	$rows = rest_do_request( $request )->get_data();

	return array_values( array_intersect( array_column( $rows, 'id' ), array( $soon_s->get_id(), $late_s->get_id() ) ) );
};
$check( 'sorting by next payment ascending puts the sooner first', array( $soon_s->get_id(), $late_s->get_id() ) === $order_ids( 'ASC' ), $order_ids( 'ASC' ) );
$check( 'and descending puts it last', array( $late_s->get_id(), $soon_s->get_id() ) === $order_ids( 'DESC' ), $order_ids( 'DESC' ) );

$fetch = static function ( Subscription $s ): array {
	return rest_do_request( new WP_REST_Request( 'GET', '/submex/v1/subscriptions/' . $s->get_id() ) )->get_data();
};
$free_trial = wc_create_order( array( 'customer_id' => 1 ) );
$free_trial->set_total( '0' );
$free_trial->payment_complete();
$made[]     = $free_trial;
$trial_sub  = $subscription( Test_Gateway::ID, 7 );
$trial_sub->set_parent_order_id( $free_trial->get_id() );
$trial_sub->save();
$check( 'a free trial has made no payments', 0 === $fetch( $trial_sub )['payments_made'], $fetch( $trial_sub )['payments_made'] );
$paid = wc_create_order( array( 'customer_id' => 1 ) );
$paid->set_total( '20' );
$paid->payment_complete();
$made[]   = $paid;
$paid_sub = $subscription( Test_Gateway::ID, 7 );
$paid_sub->set_parent_order_id( $paid->get_id() );
$paid_sub->save();
$check( 'a paid checkout has made one', 1 === $fetch( $paid_sub )['payments_made'], $fetch( $paid_sub )['payments_made'] );
$check( 'the screen knows who pays by link', true === $fetch( $manual )['pays_by_link'] && false === $fetch( $paid_sub )['pays_by_link'] );

echo "\n10. Reactivate\n";
$sent = array();
$running = $act( $paid_sub, 'reactivate' );
$check( 'a running subscription is refused', 409 === $running->get_status(), $running->get_status() );
$trialling = $subscription( Test_Gateway::ID, 7, Subscription_Status::Trialling );
$act( $trialling, 'reactivate' );
$check( 'a trial stays a trial', Subscription_Status::Trialling === wc_get_order( $trialling->get_id() )->get_status_enum(), wc_get_order( $trialling->get_id() )->get_status() );
$held = $subscription( Test_Gateway::ID, 7 );
$held->transition_to( Subscription_Status::OnHold );
$held->save();
$act( $held, 'reactivate' );
$check( 'one on hold becomes active', Subscription_Status::Active === wc_get_order( $held->get_id() )->get_status_enum() );
$check( 'and the customer is told', (bool) as_next_scheduled_action( \Submex\Emails\Mailer::ACTION_REACTIVATED, null, Renewal_Scheduler::GROUP ) );
as_unschedule_all_actions( \Submex\Emails\Mailer::ACTION_REACTIVATED );

echo "\n11. What the customer sees on their order\n";
$summary = new \Submex\Checkout\Order_Summary();
$bought  = wc_create_order( array( 'customer_id' => 1 ) );
$bought->add_product( submex_test_product(), 1 );
$bought->calculate_totals( false );
$bought->save();
$made[] = $bought;
$plugin->get( 'subscription_factory' )->from_checkout( $bought->get_id() );
$bought  = wc_get_order( $bought->get_id() );
$started = wc_get_order( (int) $bought->get_meta( '_submex_subscription_id' ) );
$made[]  = $started;
$render = static function ( callable $print ): string {
	ob_start();
	$print();
	return (string) ob_get_clean();
};
$check( 'unpaid, the thank-you page says it starts once paid', str_contains( $render( static fn() => $summary->thank_you( $bought->get_id() ) ), 'once this order is paid' ) );
$bought->payment_complete( 'x' );
$bought = wc_get_order( $bought->get_id() );
$page   = $render( static fn() => $summary->thank_you( $bought->get_id() ) );
$check( 'paid, it states the price and next date', str_contains( $page, 'every month' ) && str_contains( $page, 'next payment is on' ), $page );
$check( 'the customer email has it', str_contains( $render( static fn() => $summary->email( $bought, false, false ) ), 'Your subscription' ) );
$check( 'the plain text email too', str_contains( $render( static fn() => $summary->email( $bought, false, true ) ), 'next payment is on' ) );
$check( 'but not the store email', '' === $render( static fn() => $summary->email( $bought, true, false ) ) );
$started->transition_to( Subscription_Status::Cancelled );
$started->save();
$check( 'once cancelled, a later email promises no next payment', '' === $render( static fn() => $summary->email( wc_get_order( $bought->get_id() ), false, false ) ) );
$renewal_order = wc_create_order( array( 'customer_id' => 1 ) );
$renewal_order->update_meta_data( '_submex_subscription_id', $started->get_id() );
$renewal_order->save();
$made[] = $renewal_order;
$check( 'nor a renewal order', '' === $render( static fn() => $summary->thank_you( $renewal_order->get_id() ) ) );

echo "\n12. The setup checklist\n";
$guide_steps = ( new \Submex\Admin\Setup_Guide( $plugin->get( 'scheduler' ) ) )->steps();
$payment_step = array_values( array_filter( $guide_steps, static fn( $step ) => str_contains( strtolower( (string) ( $step['title'] ?? '' ) ), 'payment' ) ) )[0] ?? array();
$offered = array_filter( WC()->payment_gateways()->payment_gateways(), static fn( $m ) => str_starts_with( (string) $m->id, 'submex' ) && 'yes' === $m->enabled && $m->is_available() );
$check( 'a gateway only ticks the step if it is really offered', (bool) ( $payment_step['done'] ?? false ) === (bool) $offered, array( $payment_step['done'] ?? null, array_keys( $offered ) ) );

foreach ( $made as $thing ) {
	if ( $thing instanceof Subscription ) {
		as_unschedule_all_actions( '', array( 'subscription_id' => $thing->get_id() ), Renewal_Scheduler::GROUP );
		delete_transient( 'submex_renew_now_' . $thing->get_id() );
		foreach ( $renewals( $thing ) as $renewal ) {
			$renewal->delete( true );
		}
	}
	if ( $thing instanceof WC_Data && $thing->get_id() ) {
		$thing->delete( true );
	}
}

submex_test_done( $fail );
