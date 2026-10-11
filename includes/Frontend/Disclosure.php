<?php

namespace Submex\Frontend;

use Submex\Domain\Billing_Schedule;
use Submex\Domain\Money;
use Submex\Product\Line_Terms;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The recurring-payment disclosure.
 *
 * A price line plus independent fact lines, never one composed sentence. Prose collapses
 * the moment two modifiers combine — "charged $29.00 every 1 month starting 24 August
 * after a 7 day free trial plus a $20.00 sign-up fee" is unreadable and untranslatable.
 * Each line here stands alone, so any combination composes and RTL survives.
 *
 * UX Spec 6.
 */
class Disclosure {

	/**
	 * Structured lines for a product, or for one line of it when $terms are given. Callers render; this decides what is true.
	 *
	 * @return array<int, array{key: string, text: string}>
	 */
	public function lines( \WC_Product $product, ?\DateTimeImmutable $now = null, ?Line_Terms $terms = null ): array {
		$now      = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$terms    = $terms ?? Line_Terms::for_product( $product );
		$schedule = $terms->schedule();
		$price    = $terms->recurring_price();
		$fee      = $terms->signup_fee();

		$lines = array();

		if ( $schedule->has_trial() ) {
			$lines[] = array(
				'key'  => 'trial',
				/* translators: %s: trial length such as "14 days" or "1 month" */
				'text' => sprintf( __( '%s free', 'submex' ), $schedule->describe_trial() ),
			);
		}

		if ( ! $fee->is_zero() ) {
			$lines[] = array(
				'key'  => 'signup_fee',
				/* translators: %s: sign-up fee amount */
				'text' => sprintf( __( '%s sign-up fee today', 'submex' ), $this->amount( $fee ) ),
			);
		}

		// Always state what is charged first and when. This is the single most common
		// point of confusion: am I paying now, or after the trial?
		$first_date = $schedule->trial_end_from( $now );
		$first_due  = $schedule->has_trial() ? $price : $price->add( $fee );

		$lines[] = array(
			'key'  => 'first_payment',
			'text' => $first_date
				/* translators: 1: amount, 2: date */
				? sprintf( __( 'First payment %1$s on %2$s', 'submex' ), $this->amount( $first_due ), $this->date( $first_date ) )
				/* translators: %s: amount */
				: sprintf( __( 'First payment %s today', 'submex' ), $this->amount( $first_due ) ),
		);

		$lines[] = array(
			'key'  => 'recurring',
			/* translators: 1: amount, 2: billing interval such as "every month" */
			'text' => sprintf( __( 'Then %1$s %2$s', 'submex' ), $this->amount( $price ), $schedule->describe() ),
		);

		$lines[] = array(
			'key'  => 'cancel',
			'text' => __( 'Cancel anytime', 'submex' ),
		);

		/**
		 * Filter the disclosure lines.
		 *
		 * @param array       $lines
		 * @param \WC_Product $product
		 * @param Line_Terms  $terms   What they were written from; its cart_item() or order_item() is the line, if any.
		 */
		return apply_filters( 'submex_disclosure_lines', $lines, $product, $terms );
	}

	/**
	 * The headline price, e.g. "$29.00 / month".
	 */
	public function price_line( \WC_Product $product, ?Line_Terms $terms = null ): string {
		$terms = $terms ?? Line_Terms::for_product( $product );

		/**
		 * Filter the headline price line, e.g. to state an instalment plan instead.
		 *
		 * @param string      $line
		 * @param \WC_Product $product
		 * @param Line_Terms  $terms
		 */
		return (string) apply_filters( 'submex_disclosure_price_line', $this->default_price_line( $terms ), $product, $terms );
	}

	/**
	 * The product's price as WooCommerce prints it, made to say how often it recurs.
	 *
	 * WooCommerce's own markup is kept and the interval appended, rather than replaced by
	 * price_line(): it carries the sale strikethrough and the tax suffix, and a plain
	 * amount would drop both. The exception is a headline something has rewritten - an
	 * instalment or split plan - where the bare amount plus "every month" would state a
	 * price the customer is not going to pay.
	 */
	public function price_html( string $woo_html, \WC_Product $product ): string {
		if ( '' === $woo_html || ! Subscription_Product::is_subscription( $product ) ) {
			return $woo_html;
		}

		$terms = Line_Terms::for_product( $product );
		$line  = $this->price_line( $product, $terms );

		if ( $line !== $this->default_price_line( $terms ) ) {
			return $line;
		}

		return $woo_html . ' <span class="submex-price-interval">' . esc_html( $terms->schedule()->describe() ) . '</span>';
	}

	private function default_price_line( Line_Terms $terms ): string {
		return sprintf(
			/* translators: 1: price, 2: billing interval such as "every month" */
			__( '%1$s %2$s', 'submex' ),
			$this->amount( $terms->recurring_price() ),
			$terms->schedule()->describe()
		);
	}

	/**
	 * With $terms, for a line the caller has decided is recurring, whatever the product is.
	 */
	public function render( \WC_Product $product, ?Line_Terms $terms = null ): string {
		if ( null === $terms && ! Subscription_Product::is_subscription( $product ) ) {
			return '';
		}

		// No headline price here: the product's own price line says it, and saying it twice
		// is what made the page read "$5.00" then "$5.00 every month".
		$html  = '<div class="submex-disclosure" role="group" aria-label="' . esc_attr__( 'Subscription terms', 'submex' ) . '">';
		$html .= '<ul class="submex-disclosure__facts">';

		foreach ( $this->lines( $product, null, $terms ) as $line ) {
			$html .= '<li class="submex-disclosure__fact submex-disclosure__fact--' . esc_attr( $line['key'] ) . '">' . wp_kses_post( $line['text'] ) . '</li>';
		}

		return $html . '</ul></div>';
	}

	/**
	 * The one place prose is used: the line beside the Place Order button, which has to
	 * be readable in a single glance. This is the sentence that legally matters.
	 */
	public function sentence( \WC_Product $product, ?Line_Terms $terms = null ): string {
		$terms = $terms ?? Line_Terms::for_product( $product );

		/**
		 * Filter the checkout sentence, for terms the default wording would misstate.
		 *
		 * @param string      $sentence
		 * @param \WC_Product $product
		 * @param Line_Terms  $terms
		 */
		return (string) apply_filters( 'submex_disclosure_sentence', $this->default_sentence( $terms ), $product, $terms );
	}

	private function default_sentence( Line_Terms $terms ): string {
		$schedule = $terms->schedule();
		$price    = $terms->recurring_price();

		if ( $schedule->has_trial() ) {
			return sprintf(
				/* translators: 1: trial length such as "14 days" or "1 month", 2: amount, 3: billing interval */
				__( "You're starting a subscription. After a free trial of %1\$s you'll be charged %2\$s %3\$s until you cancel.", 'submex' ),
				$schedule->describe_trial(),
				$this->amount( $price ),
				$schedule->describe()
			);
		}

		return sprintf(
			/* translators: 1: amount, 2: billing interval */
			__( "You're starting a subscription. You'll be charged %1\$s %2\$s until you cancel.", 'submex' ),
			$this->amount( $price ),
			$schedule->describe()
		);
	}

	/**
	 * Always through wc_price(), which is what makes zero-decimal (JPY) and
	 * three-decimal (KWD) currencies correct for free. bdi keeps RTL from reversing it.
	 */
	private function amount( Money $money ): string {
		return '<bdi>' . wp_strip_all_tags( $money->format() ) . '</bdi>';
	}

	private function date( \DateTimeImmutable $date ): string {
		return date_i18n( (string) get_option( 'date_format' ), $date->getTimestamp() );
	}
}
