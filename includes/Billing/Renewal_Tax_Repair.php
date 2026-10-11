<?php

namespace Submex\Billing;

use Submex\Admin\Menu;
use Submex\Admin\Notice_Dismissals;
use Submex\Admin\Notices;
use Submex\Admin\Settings_Page;
use Submex\Data\Activity_Repository;
use Submex\Data\Subscription_Query;
use Submex\Domain\Money;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;
use Submex\Product\Subscription_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriptions created before lines were stored without tax renew with tax added twice; the store repairs each one.
 */
class Renewal_Tax_Repair {

	public const OPTION_SINCE = 'submex_tax_exclusive_lines_since';

	public const ACTION = 'submex_repair_renewal_tax';

	public const CACHE = 'submex_tax_added_twice';

	public const NOTICE = 'submex-tax-added-twice';

	public const FROM_SUBMEX = 'submex';

	public function __construct( private readonly Activity_Repository $activity ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'mark_cut_over' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Every subscription created from here on stores its line without tax; only older ones can be affected.
	 */
	public function mark_cut_over(): void {
		// Autoloaded, so the check costs nothing on later requests.
		add_option( self::OPTION_SINCE, gmdate( 'Y-m-d H:i:s' ), '', true );
	}

	/**
	 * @return int[]
	 */
	public function affected_ids(): array {
		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$ids = array();

		if ( $this->store_can_be_affected() ) {
			$candidates = Subscription_Query::ids(
				array(
					'status'       => array( Subscription_Status::Active->value, Subscription_Status::Trialling->value, Subscription_Status::OnHold->value ),
					'date_created' => '<' . $this->since(),
					'limit'        => -1,
				)
			);

			foreach ( $candidates as $id ) {
				$subscription = wc_get_order( $id );

				if ( $subscription instanceof Subscription && $this->is_affected( $subscription ) ) {
					$ids[] = $id;
				}
			}
		}

		set_transient( self::CACHE, $ids, HOUR_IN_SECONDS );

		return $ids;
	}

	public function is_affected( Subscription $subscription ): bool {
		$status  = $subscription->get_status_enum();
		$created = $subscription->get_date_created();
		$lines   = $subscription->get_items();

		if ( ! $this->store_can_be_affected()
			|| ! in_array( $status, array( Subscription_Status::Active, Subscription_Status::Trialling, Subscription_Status::OnHold ), true )
			|| ! $created || $created->getTimestamp() >= $this->since()
			|| $subscription->get_items( 'tax' ) || 0.0 !== (float) $subscription->get_total_tax()
			|| '' !== (string) $subscription->get_meta( '_submex_renewal_price_applied' )
			|| ! $lines ) {
			return false;
		}

		foreach ( $lines as $line ) {
			$product = $line instanceof \WC_Order_Item_Product ? $line->get_product() : null;

			if ( ! $product instanceof \WC_Product || ! $product->is_taxable() ) {
				return false;
			}

			$quantity = max( 1, (int) $line->get_quantity() );
			$gross    = Subscription_Product::recurring_price( $product )->multiply( $quantity );

			// Only a line still at the price as entered: anything else was set on purpose since.
			if ( $gross->minor() !== Money::from_decimal( $line->get_total(), $subscription->get_currency() )->minor() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Store the line without tax and let the subscription add tax back, as a new one would.
	 */
	public function repair( Subscription $subscription ): bool {
		if ( ! $this->is_affected( $subscription ) ) {
			return false;
		}

		$before = $subscription->get_total();

		foreach ( $subscription->get_items() as $line ) {
			$net = wc_format_decimal(
				wc_get_price_excluding_tax(
					$line->get_product(),
					array(
						'qty'   => 1,
						'price' => $line->get_total(),
						'order' => $subscription,
					)
				)
			);

			$line->set_subtotal( $net );
			$line->set_total( $net );
		}

		$subscription->calculate_totals( true );
		$subscription->save();

		$this->activity->log(
			$subscription->get_id(),
			Activity_Repository::TYPE_NOTE,
			sprintf( 'Renewal tax repaired: the line is now stored without tax, so renewals stop adding tax twice. Recurring total %1$s before, %2$s now.', $before, $subscription->get_total() ),
			array(),
			'admin'
		);

		delete_transient( self::CACHE );

		return true;
	}

	public function handle(): void {
		$id = absint( $_GET['subscription'] ?? 0 );

		if ( ! current_user_can( Menu::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::ACTION . '_' . $id ) ) {
			wp_die( esc_html__( 'That request could not be verified.', 'submex' ) );
		}

		$subscription = wc_get_order( $id );
		$repaired     = $subscription instanceof Subscription && $this->repair( $subscription );

		// The cached list still has this row; left alone it would stay for up to an hour.
		if ( ! $repaired ) {
			delete_transient( self::CACHE );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'submex_tax_repaired'      => $repaired ? $id : 0,
					'submex_tax_repair_failed' => $repaired ? 0 : $id,
				),
				self::list_url( sanitize_key( wp_unslash( $_GET['from'] ?? '' ) ) )
			)
		);
		exit;
	}

	public static function repair_url( int $subscription_id, string $from = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => self::ACTION,
					'subscription' => $subscription_id,
					'from'         => self::FROM_SUBMEX === $from ? self::FROM_SUBMEX : 'woocommerce',
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $subscription_id
		);
	}

	/**
	 * The list lives on both settings screens; each one's repair comes back to it.
	 */
	public static function list_url( string $from = self::FROM_SUBMEX ): string {
		return self::FROM_SUBMEX === $from
			? Settings_Page::section_url()
			: add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'submex',
				),
				admin_url( 'admin.php' )
			);
	}

	public function notice(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) || ! Notices::in_context( true ) ) {
			return;
		}

		$count = count( $this->affected_ids() );

		if ( ! Notice_Dismissals::shows( self::NOTICE, $count ) ) {
			return;
		}

		printf(
			'<div class="%s"><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
			esc_attr( Notices::important( 'warning' ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of subscriptions */
					_n(
						'Submex: %d subscription renews with tax added twice, so its customer pays more than at checkout and may be owed a refund.',
						'Submex: %d subscriptions renew with tax added twice, so their customers pay more than at checkout and may be owed refunds.',
						$count,
						'submex'
					),
					$count
				)
			),
			esc_url( self::list_url() . '#' . self::NOTICE ),
			esc_html__( 'Review and repair', 'submex' ),
			esc_url( Notice_Dismissals::url( self::NOTICE, $count ) ),
			esc_html__( 'Dismiss', 'submex' )
		);
	}

	private function store_can_be_affected(): bool {
		return wc_tax_enabled() && wc_prices_include_tax();
	}

	private function since(): int {
		$since = (string) get_option( self::OPTION_SINCE, '' );
		$time  = '' === $since ? false : strtotime( $since . ' UTC' );

		return false === $time ? 0 : $time;
	}
}
