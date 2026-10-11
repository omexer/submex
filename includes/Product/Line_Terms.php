<?php

namespace Submex\Product;

use Submex\Domain\Billing_Schedule;
use Submex\Domain\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What one cart or order line is sold on: its cycle, trial, sign-up fee and price per period.
 *
 * The product's own subscription settings by default. The `submex_line_terms` filter lets a
 * line carry terms of its own, so one product can be sold on more than one schedule.
 */
final class Line_Terms {

	/** @var \WeakMap<\WC_Product, self>|null Per cart line, resolved before the cart reprices it for today. */
	private static ?\WeakMap $cart_lines = null;

	/**
	 * @param array<string, mixed>|null $cart_item
	 */
	private function __construct(
		private readonly Billing_Schedule $schedule,
		private readonly float $price,
		private readonly Money $signup_fee,
		private readonly ?array $cart_item,
		private readonly ?\WC_Order_Item_Product $order_item
	) {}

	/**
	 * The product's terms with no line to read: the product page.
	 */
	public static function for_product( \WC_Product $product ): self {
		return self::resolve( $product, (float) Subscription_Product::recurring_price( $product )->decimal(), null, null );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function for_cart_item( array $item, string $key = '' ): ?self {
		$product = $item['data'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		self::$cart_lines ??= new \WeakMap();

		if ( isset( self::$cart_lines[ $product ] ) ) {
			return self::$cart_lines[ $product ];
		}

		/**
		 * The price per period for this cart line, before today's adjustments.
		 *
		 * @param float       $price
		 * @param \WC_Product $product
		 * @param array       $item    The cart item.
		 * @param string      $key     Its cart key.
		 */
		$price = (float) apply_filters( 'submex_cart_recurring_price', (float) $product->get_price( 'edit' ), $product, $item, $key );

		// Kept for the request: the cart lowers this line's price to what is due today, and a second read would start from that.
		self::$cart_lines[ $product ] = self::resolve( $product, $price, $item, null );

		return self::$cart_lines[ $product ];
	}

	public static function for_order_item( \WC_Order_Item_Product $item, ?\WC_Product $product = null ): ?self {
		$product = $product ?? $item->get_product();

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		return self::resolve( $product, (float) Subscription_Product::recurring_price( $product )->decimal(), null, $item );
	}

	public function schedule(): Billing_Schedule {
		return $this->schedule;
	}

	/**
	 * The price per period as WooCommerce prices a product: a decimal, with or without tax as the store enters prices.
	 */
	public function price(): float {
		return $this->price;
	}

	public function recurring_price(): Money {
		return Money::from_decimal( $this->price );
	}

	public function signup_fee(): Money {
		return $this->signup_fee;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function cart_item(): ?array {
		return $this->cart_item;
	}

	public function order_item(): ?\WC_Order_Item_Product {
		return $this->order_item;
	}

	/**
	 * @return array{price: float, period: string, interval: int, trial_length: int, trial_period: string, signup_fee: float}
	 */
	public function to_array(): array {
		return array(
			'price'        => $this->price,
			'period'       => $this->schedule->period(),
			'interval'     => $this->schedule->interval(),
			'trial_length' => $this->schedule->trial_length(),
			'trial_period' => $this->schedule->trial_period(),
			'signup_fee'   => (float) $this->signup_fee->decimal(),
		);
	}

	/**
	 * @param array<string, mixed>|null $cart_item
	 */
	private static function resolve( \WC_Product $product, float $price, ?array $cart_item, ?\WC_Order_Item_Product $order_item ): self {
		$schedule = Subscription_Product::schedule( $product );
		$defaults = array(
			'price'        => $price,
			'period'       => $schedule->period(),
			'interval'     => $schedule->interval(),
			'trial_length' => $schedule->trial_length(),
			'trial_period' => $schedule->trial_period(),
			'signup_fee'   => (float) Subscription_Product::signup_fee( $product )->decimal(),
		);

		/**
		 * The terms one line is sold on.
		 *
		 * Runs for a cart line ($cart_item set), an order line ($order_item set, when the
		 * subscription is created and when PayPal prices its plan) and the product page
		 * (neither set). Return the whole array; anything missing or invalid falls back to
		 * the product's own terms.
		 *
		 * @param array                       $terms      price (float, per period, as product prices are entered), period ('day'|'week'|'month'|'year'), interval (int >= 1), trial_length (int >= 0), trial_period ('day'|'week'|'month'|'year'), signup_fee (float).
		 * @param \WC_Product                 $product
		 * @param array|null                  $cart_item
		 * @param \WC_Order_Item_Product|null $order_item
		 */
		$terms = apply_filters( 'submex_line_terms', $defaults, $product, $cart_item, $order_item );
		$terms = is_array( $terms ) ? $terms + $defaults : $defaults;

		return new self(
			new Billing_Schedule(
				self::period( $terms['period'], $defaults['period'] ),
				max( 1, (int) $terms['interval'] ),
				max( 0, (int) $terms['trial_length'] ),
				null,
				self::period( $terms['trial_period'], 'day' )
			),
			is_numeric( $terms['price'] ) ? max( 0.0, (float) $terms['price'] ) : $defaults['price'],
			Money::from_decimal( is_numeric( $terms['signup_fee'] ) ? max( 0.0, (float) $terms['signup_fee'] ) : $defaults['signup_fee'] ),
			$cart_item,
			$order_item
		);
	}

	/**
	 * @param mixed $period
	 */
	private static function period( $period, string $fallback ): string {
		return in_array( $period, Billing_Schedule::PERIODS, true ) ? $period : $fallback;
	}
}
