<?php

namespace Submex\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money in integer minor units.
 *
 * Float arithmetic on prices is how installment plans end up a penny short across
 * three charges, so nothing in Submex does maths on a float.
 */
final class Money {

	private function __construct(
		private readonly int $minor,
		private readonly string $currency,
		private readonly int $decimals
	) {}

	public static function from_minor( int $minor, string $currency = '', ?int $decimals = null ): self {
		return new self( $minor, self::resolve_currency( $currency ), $decimals ?? self::resolve_decimals() );
	}

	/**
	 * @param string|float|int $value A decimal amount, e.g. 29.99.
	 */
	public static function from_decimal( $value, string $currency = '', ?int $decimals = null ): self {
		$decimals = $decimals ?? self::resolve_decimals();
		$minor    = (int) round( (float) $value * ( 10 ** $decimals ) );

		return new self( $minor, self::resolve_currency( $currency ), $decimals );
	}

	public static function zero( string $currency = '', ?int $decimals = null ): self {
		return self::from_minor( 0, $currency, $decimals );
	}

	public function minor(): int {
		return $this->minor;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function decimals(): int {
		return $this->decimals;
	}

	public function decimal(): string {
		return number_format( $this->minor / ( 10 ** $this->decimals ), $this->decimals, '.', '' );
	}

	public function add( Money $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->minor + $other->minor, $this->currency, $this->decimals );
	}

	public function subtract( Money $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->minor - $other->minor, $this->currency, $this->decimals );
	}

	public function multiply( int $factor ): self {
		return new self( $this->minor * $factor, $this->currency, $this->decimals );
	}

	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	public function is_negative(): bool {
		return $this->minor < 0;
	}

	public function equals( Money $other ): bool {
		return $this->minor === $other->minor && $this->currency === $other->currency;
	}

	/**
	 * Split into N parts that always sum back to the original.
	 *
	 * Remainder pennies go to the earliest parts, so 3 × $100.00 from $300.01
	 * becomes 100.01 / 100.00 / 100.00 rather than drifting.
	 *
	 * @return self[]
	 */
	public function allocate( int $parts ): array {
		if ( $parts < 1 ) {
			throw new \InvalidArgumentException( 'Cannot allocate money into fewer than one part.' );
		}

		$base      = intdiv( $this->minor, $parts );
		$remainder = $this->minor - ( $base * $parts );
		$out       = array();

		for ( $i = 0; $i < $parts; $i++ ) {
			$extra = $i < abs( $remainder ) ? ( $remainder <=> 0 ) : 0;
			$out[] = new self( $base + $extra, $this->currency, $this->decimals );
		}

		return $out;
	}

	/**
	 * Split proportionally, preserving the total exactly.
	 *
	 * @param array<int|float> $ratios
	 * @return self[]
	 */
	public function allocate_by_ratios( array $ratios ): array {
		$total = array_sum( $ratios );
		if ( $total <= 0 ) {
			throw new \InvalidArgumentException( 'Allocation ratios must sum to more than zero.' );
		}

		$out       = array();
		$allocated = 0;

		foreach ( $ratios as $ratio ) {
			$share      = (int) floor( $this->minor * $ratio / $total );
			$out[]      = $share;
			$allocated += $share;
		}

		// Hand the rounding remainder to the earliest parts.
		$remainder = $this->minor - $allocated;
		for ( $i = 0; $i < abs( $remainder ); $i++ ) {
			$out[ $i % count( $out ) ] += ( $remainder <=> 0 );
		}

		return array_map( fn( int $minor ): self => new self( $minor, $this->currency, $this->decimals ), $out );
	}

	public function format(): string {
		return function_exists( 'wc_price' )
			? wc_price( (float) $this->decimal(), array( 'currency' => $this->currency ) )
			: $this->decimal();
	}

	private function assert_same_currency( Money $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Cannot combine %s with %s.', $this->currency, $other->currency ) )
			);
		}
	}

	private static function resolve_currency( string $currency ): string {
		if ( '' !== $currency ) {
			return $currency;
		}

		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	private static function resolve_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
	}
}
