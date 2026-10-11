<?php

namespace Submex\Gateways\PayPal;

use Submex\Domain\Billing_Schedule;
use Submex\Product\Line_Terms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a WooCommerce product onto a PayPal product + billing plan.
 *
 * PayPal will not bill an arbitrary amount on a schedule; it bills a *plan* it already
 * knows about. So every set of terms a product is sold on needs a plan created once at
 * PayPal and cached against the product, and a new plan whenever price or schedule changes.
 */
class PayPal_Plans {

	private const META_PRODUCT = '_submex_paypal_product_id';
	// Fingerprint => plan id: one product sold on several terms needs a plan for each.
	public const META_PLANS = '_submex_paypal_plans';

	public function __construct( private readonly PayPal_Client $client ) {}

	/**
	 * The plan id for this product, creating it at PayPal if needed.
	 *
	 * @return array{ok: bool, plan_id: string, error: string}
	 */
	public function plan_for( \WC_Product $product, ?Line_Terms $terms = null ): array {
		$terms  = $terms ?? Line_Terms::for_product( $product );
		$cycles = $this->total_cycles( $product, $terms );
		$hash   = $this->fingerprint( $product, $terms, $cycles );

		$cached = $this->cached_plans( $product );
		if ( isset( $cached[ $hash ] ) ) {
			return $this->ok( $cached[ $hash ] );
		}

		$paypal_product = $this->paypal_product_for( $product );
		if ( ! $paypal_product['ok'] ) {
			return $this->fail( $paypal_product['error'] );
		}

		$plan = $this->create_plan( $product, $terms, $cycles, $paypal_product['id'] );
		if ( ! $plan['ok'] ) {
			return $this->fail( $plan['error'] );
		}

		$product->update_meta_data( self::META_PLANS, array( $hash => $plan['id'] ) + $this->cached_plans( $product ) );
		$product->save();

		return $this->ok( $plan['id'] );
	}

	/**
	 * @return array<string, string>
	 */
	private function cached_plans( \WC_Product $product ): array {
		$plans = $product->get_meta( self::META_PLANS );

		return is_array( $plans ) ? array_filter( $plans, 'is_string' ) : array();
	}

	/**
	 * Everything a PayPal plan is built from. A change to any of it needs a new plan,
	 * because PayPal plans are immutable in the ways that matter to us.
	 */
	private function fingerprint( \WC_Product $product, Line_Terms $terms, int $cycles ): string {
		$schedule = $terms->schedule();

		return sha1(
			implode(
				'|',
				array(
					$product->get_id(),
					get_woocommerce_currency(),
					(string) $terms->recurring_price()->minor(),
					$schedule->period(),
					$schedule->interval(),
					$schedule->trial_length(),
					$schedule->trial_period(),
					(string) $terms->signup_fee()->minor(),
					$cycles,
				)
			)
		);
	}

	private function total_cycles( \WC_Product $product, Line_Terms $terms ): int {
		/**
		 * How many payments PayPal should take before it stops on its own.
		 *
		 * PayPal owns this schedule, so a limit Submex knows about — a payment cap, an
		 * instalment plan — has to be built into the plan or PayPal bills past the end.
		 *
		 * @param int         $total_cycles 0 bills forever.
		 * @param \WC_Product $product
		 * @param Line_Terms  $terms        The terms being sold; its order_item() is the order line, when there is one.
		 */
		return max( 0, (int) apply_filters( 'submex_paypal_total_cycles', 0, $product, $terms ) );
	}

	/**
	 * @return array{ok: bool, id: string, error: string}
	 */
	private function paypal_product_for( \WC_Product $product ): array {
		$existing = (string) $product->get_meta( self::META_PRODUCT );
		if ( '' !== $existing ) {
			return array(
				'ok'    => true,
				'id'    => $existing,
				'error' => '',
			);
		}

		$response = $this->client->post(
			'/v1/catalogs/products',
			array(
				'name'     => wp_strip_all_tags( $product->get_name() ),
				'type'     => $product->is_virtual() ? 'DIGITAL' : 'PHYSICAL',
				'category' => 'MERCHANDISE',
			)
		);

		if ( ! $response['ok'] || empty( $response['body']['id'] ) ) {
			return array(
				'ok'    => false,
				'id'    => '',
				'error' => $response['error'],
			);
		}

		$product->update_meta_data( self::META_PRODUCT, $response['body']['id'] );
		$product->save();

		return array(
			'ok'    => true,
			'id'    => (string) $response['body']['id'],
			'error' => '',
		);
	}

	/**
	 * @return array{ok: bool, id: string, error: string}
	 */
	private function create_plan( \WC_Product $product, Line_Terms $terms, int $total_cycles, string $paypal_product_id ): array {
		$schedule = $terms->schedule();
		$currency = get_woocommerce_currency();
		$cycles   = array();
		$sequence = 1;

		if ( $schedule->has_trial() ) {
			$cycles[] = array(
				'tenure_type'    => 'TRIAL',
				'sequence'       => $sequence++,
				'total_cycles'   => 1,
				'frequency'      => array(
					'interval_unit'  => $this->paypal_unit( $schedule->trial_period() ),
					'interval_count' => $schedule->trial_length(),
				),
				'pricing_scheme' => array(
					'fixed_price' => array(
						'value'         => '0',
						'currency_code' => $currency,
					),
				),
			);
		}

		$cycles[] = array(
			'tenure_type'    => 'REGULAR',
			'sequence'       => $sequence,
			// 0 means bill forever, which is what an open-ended subscription is.
			'total_cycles'   => $total_cycles,
			'frequency'      => array(
				'interval_unit'  => $this->interval_unit( $schedule ),
				'interval_count' => $schedule->interval(),
			),
			'pricing_scheme' => array(
				'fixed_price' => array(
					'value'         => $terms->recurring_price()->decimal(),
					'currency_code' => $currency,
				),
			),
		);

		$payload = array(
			'product_id'          => $paypal_product_id,
			'name'                => wp_strip_all_tags( $product->get_name() ),
			'billing_cycles'      => $cycles,
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'setup_fee_failure_action'  => 'CANCEL',
				'payment_failure_threshold' => 3,
			),
		);

		$fee = $terms->signup_fee();
		if ( ! $fee->is_zero() ) {
			$payload['payment_preferences']['setup_fee'] = array(
				'value'         => $fee->decimal(),
				'currency_code' => $currency,
			);
		}

		$response = $this->client->post( '/v1/billing/plans', $payload );

		if ( ! $response['ok'] || empty( $response['body']['id'] ) ) {
			return array(
				'ok'    => false,
				'id'    => '',
				'error' => $response['error'],
			);
		}

		return array(
			'ok'    => true,
			'id'    => (string) $response['body']['id'],
			'error' => '',
		);
	}

	private function interval_unit( Billing_Schedule $schedule ): string {
		return $this->paypal_unit( $schedule->period() );
	}

	private function paypal_unit( string $period ): string {
		return match ( $period ) {
			'day'   => 'DAY',
			'week'  => 'WEEK',
			'year'  => 'YEAR',
			default => 'MONTH',
		};
	}

	private function ok( string $plan_id ): array {
		return array(
			'ok'      => true,
			'plan_id' => $plan_id,
			'error'   => '',
		);
	}

	private function fail( string $error ): array {
		return array(
			'ok'      => false,
			'plan_id' => '',
			'error'   => $error,
		);
	}
}
