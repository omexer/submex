<?php
/**
 * Both My Account screens render a real subscription without notices.
 *
 * @package Submex
 */

use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

require __DIR__ . '/bootstrap.php';

$notices = array();
set_error_handler( function ( $no, $str, $file ) use ( &$notices ) { if ( str_contains( $file, 'submex' ) ) { $notices[] = "$str @ " . basename( $file ); } return false; } );

$product = submex_test_product();
$s = new Subscription();
$s->set_customer_id( 1 ); $s->set_currency( get_woocommerce_currency() );
$s->set_billing_period( 'month' ); $s->set_billing_interval( 1 );
$s->set_next_payment( gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
$i = new WC_Order_Item_Product();
$i->set_props( array( 'name' => 'Render Probe Item', 'product_id' => $product->get_id(), 'quantity' => 1, 'subtotal' => '20', 'total' => '20' ) );
$s->add_item( $i );
$s->transition_to( Subscription_Status::Pending ); $s->calculate_totals( false ); $s->save();
$s->transition_to( Subscription_Status::Active ); $s->save();

$endpoint = \Submex\Plugin::instance()->get( 'account' );

ob_start(); $endpoint->render( '' ); $list = ob_get_clean();
$check( 'the list renders', '' !== trim( $list ) );
$check( 'and shows the subscription', str_contains( $list, '#' . $s->get_id() ) || str_contains( $list, (string) $s->get_id() ), substr( wp_strip_all_tags( $list ), 0, 160 ) );

ob_start(); $endpoint->render( (string) $s->get_id() ); $detail = ob_get_clean();
$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $detail ) );
$check( 'the detail renders', '' !== trim( $detail ) );
$check( 'with its line item', str_contains( $detail, 'Render Probe Item' ), substr( $text, 0, 200 ) );
$check( 'and the cancel form, which reads the renamed $can_cancel', str_contains( $detail, 'Cancel subscription' ), substr( $text, 0, 200 ) );
$check( 'no PHP notices from Submex while rendering', array() === $notices, $notices );

restore_error_handler();
$s->delete( true );
submex_test_done( $fail );
