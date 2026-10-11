<?php

namespace Submex\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A subscription is a WooCommerce order type, so it inherits HPOS storage, line items,
 * addresses, tax lines and notes rather than reimplementing them.
 *
 * Extends WC_Order rather than WC_Abstract_Order: billing/shipping addresses, customer_id
 * and payment_method are defined on WC_Order, and a subscription needs all of them.
 */
class Subscription extends \WC_Order {

	public const TYPE = 'submex_sub';

	protected $object_type = self::TYPE;

	protected $data_store_name = self::TYPE;

	/**
	 * Schedule and billing props, persisted as order meta by the HPOS data store.
	 */
	protected $extra_data = array(
		'billing_period'    => 'month',
		'billing_interval'  => 1,
		'trial_end'         => null,
		'next_payment'      => null,
		'end_date'          => null,
		'parent_order_id'   => 0,
		'payment_token_id'  => 0,
		'schedule_sync_day' => 0,
		'period_index'      => 0,
	);

	/**
	 * Meta keys backing $extra_data. Kept private so the mapping lives in one place.
	 */
	private const META_MAP = array(
		'billing_period'    => '_submex_billing_period',
		'billing_interval'  => '_submex_billing_interval',
		'trial_end'         => '_submex_trial_end',
		'next_payment'      => '_submex_next_payment',
		'end_date'          => '_submex_end_date',
		'parent_order_id'   => '_submex_parent_order_id',
		'payment_token_id'  => '_submex_payment_token_id',
		'schedule_sync_day' => '_submex_schedule_sync_day',
		'period_index'      => '_submex_period_index',
	);

	/**
	 * Whether the schedule props have been pulled out of meta yet.
	 */
	private bool $extra_hydrated = false;

	public function __construct( $subscription = 0 ) {
		parent::__construct( $subscription );
	}

	/**
	 * Hydrate lazily on first access rather than in the constructor.
	 *
	 * wc_get_orders() reads in bulk and fills the object AFTER it is constructed, so
	 * anything hydrated in __construct is silently empty on every query-driven path -
	 * the admin list, the sweeper, bulk actions. Reading on demand works for both the
	 * single-read and bulk-read paths.
	 */
	protected function get_prop( $prop, $context = 'view' ) {
		if ( ! $this->extra_hydrated && isset( self::META_MAP[ $prop ] ) ) {
			$this->hydrate_extra_data();
		}

		return parent::get_prop( $prop, $context );
	}

	/**
	 * Hydrate before writing, for the same reason as reading - and one worse failure.
	 *
	 * WC_Data::set_prop only records a change when the new value differs from $data. On a
	 * freshly loaded object $data still holds this class's defaults, so setting a prop
	 * back to its default - set_next_payment( null ) on a subscription that has one -
	 * looked like no change at all, and hydration then restored the stored value on save.
	 * The write vanished silently.
	 */
	protected function set_prop( $prop, $value ) {
		if ( isset( self::META_MAP[ $prop ] ) && $this->is_being_read() ) {
			return;
		}

		if ( ! $this->extra_hydrated && isset( self::META_MAP[ $prop ] ) ) {
			$this->hydrate_extra_data();
		}

		parent::set_prop( $prop, $value );
	}

	/**
	 * The legacy store sets extra data from `_{prop}` meta during a read; ours lives in META_MAP and is hydrated.
	 */
	private function is_being_read(): bool {
		return ! $this->object_read;
	}

	/**
	 * A re-read resets $data to defaults, so the hydrated values must be read again too.
	 */
	public function set_defaults() {
		parent::set_defaults();
		$this->extra_hydrated = false;
	}

	public function get_type() {
		return self::TYPE;
	}

	/**
	 * Subscriptions use their own status set, not the shop_order one.
	 *
	 * WC_Abstract_Order::set_status() silently rewrites anything outside this list to
	 * "pending", so without the override every es-* status would be discarded on save.
	 */
	protected function get_valid_statuses() {
		return array_map(
			static fn( Subscription_Status $status ): string => 'wc-' . $status->value,
			Subscription_Status::cases()
		);
	}

	public function get_status( $context = 'view' ) {
		$status = $this->get_prop( 'status', $context );

		if ( empty( $status ) && 'view' === $context ) {
			return Subscription_Status::Pending->value;
		}

		// The data store may hand back a wc- prefixed slug; ours are es-* underneath.
		return is_string( $status ) && 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	public function get_status_enum(): ?Subscription_Status {
		return Subscription_Status::tryFrom( $this->get_status() );
	}

	/**
	 * Move to a new status, refusing transitions the state machine does not allow.
	 *
	 * @throws \InvalidArgumentException When the transition is not legal.
	 */
	public function transition_to( Subscription_Status $to, string $note = '' ): void {
		// Read the stored status, not the view default: a brand-new object reports
		// submex-pending while its prop is still unset, and skipping the write there would
		// leave WooCommerce's own "pending" on the record.
		$stored = $this->get_status( 'edit' );
		$from   = '' === $stored || null === $stored ? null : Subscription_Status::tryFrom( $stored );

		// Already there. A no-op, not an error — otherwise every caller needs a guard.
		if ( null !== $from && $from === $to ) {
			return;
		}

		if ( $from instanceof Subscription_Status && ! $from->can_transition_to( $to ) ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Cannot move subscription %d from %s to %s.', $this->get_id(), $from->value, $to->value ) )
			);
		}

		$this->set_status( $to->value, $note );

		/**
		 * Fires on every legal status transition.
		 *
		 * The subscription is NOT saved yet - this runs inside transition_to(), before the
		 * caller's save(). A listener that calls save() here commits a half-finished
		 * transition, so persist your own state elsewhere or defer it.
		 *
		 * @param Subscription $subscription
		 * @param string       $from
		 * @param string       $to
		 */
		do_action( 'submex_subscription_status_changed', $this, $from instanceof Subscription_Status ? $from->value : '', $to->value );
	}

	/**
	 * When the grace period after a failed renewal ends, as a timestamp; null when there is none.
	 */
	public function grace_ends_at(): ?int {
		/**
		 * The end of this subscription's grace period after a failed renewal. Submex alone gives none.
		 *
		 * @param int|null     $ends
		 * @param Subscription $subscription
		 */
		$ends = apply_filters( 'submex_grace_ends_at', null, $this );

		return is_numeric( $ends ) ? (int) $ends : null;
	}

	/**
	 * On hold after a failed renewal and still inside the grace period, so it keeps its access.
	 */
	public function in_grace(): bool {
		$ends = Subscription_Status::OnHold === $this->get_status_enum() ? $this->grace_ends_at() : null;

		return null !== $ends && time() < $ends;
	}

	// -------------------------------------------------------------- schedule props

	public function get_billing_period( $context = 'view' ) {
		return $this->get_prop( 'billing_period', $context );
	}

	public function set_billing_period( $value ) {
		if ( $this->is_being_read() ) {
			return;
		}

		$allowed = array( 'day', 'week', 'month', 'year' );
		if ( ! in_array( $value, $allowed, true ) ) {
			$this->error( 'submex_invalid_billing_period', 'Billing period must be one of: ' . implode( ', ', $allowed ) );
		}
		$this->set_prop( 'billing_period', $value );
	}

	public function get_billing_interval( $context = 'view' ) {
		return (int) $this->get_prop( 'billing_interval', $context );
	}

	public function set_billing_interval( $value ) {
		if ( $this->is_being_read() ) {
			return;
		}

		$value = absint( $value );
		if ( $value < 1 ) {
			$this->error( 'submex_invalid_billing_interval', 'Billing interval must be 1 or more.' );
		}
		$this->set_prop( 'billing_interval', $value );
	}

	public function get_trial_end( $context = 'view' ) {
		return $this->get_prop( 'trial_end', $context );
	}

	public function set_trial_end( $value ) {
		$this->set_prop( 'trial_end', $this->normalize_datetime( $value ) );
	}

	public function get_next_payment( $context = 'view' ) {
		return $this->get_prop( 'next_payment', $context );
	}

	public function set_next_payment( $value ) {
		$this->set_prop( 'next_payment', $this->normalize_datetime( $value ) );
	}

	public function get_end_date( $context = 'view' ) {
		return $this->get_prop( 'end_date', $context );
	}

	public function set_end_date( $value ) {
		$this->set_prop( 'end_date', $this->normalize_datetime( $value ) );
	}

	public function get_parent_order_id( $context = 'view' ) {
		return (int) $this->get_prop( 'parent_order_id', $context );
	}

	public function set_parent_order_id( $value ) {
		$this->set_prop( 'parent_order_id', absint( $value ) );
	}

	public function get_payment_token_id( $context = 'view' ) {
		return (int) $this->get_prop( 'payment_token_id', $context );
	}

	public function set_payment_token_id( $value ) {
		$this->set_prop( 'payment_token_id', absint( $value ) );
	}

	public function get_schedule_sync_day( $context = 'view' ) {
		return (int) $this->get_prop( 'schedule_sync_day', $context );
	}

	public function set_schedule_sync_day( $value ) {
		$this->set_prop( 'schedule_sync_day', absint( $value ) );
	}

	/**
	 * Index of the most recently claimed charge slot. See Developer Guide 5.1.
	 */
	public function get_period_index( $context = 'view' ) {
		return (int) $this->get_prop( 'period_index', $context );
	}

	public function set_period_index( $value ) {
		$this->set_prop( 'period_index', absint( $value ) );
	}

	// -------------------------------------------------------------- persistence

	/**
	 * Read the schedule props back out of order meta.
	 *
	 * The HPOS orders table has no columns for our props, so they live in
	 * wc_orders_meta and are hydrated here after the data store has loaded.
	 */
	public function hydrate_extra_data(): void {
		if ( $this->extra_hydrated || ! $this->get_id() ) {
			return;
		}

		// Set before the loop: get_meta() must not re-enter this method.
		$this->extra_hydrated = true;

		foreach ( self::META_MAP as $prop => $meta_key ) {
			$value = $this->get_meta( $meta_key, true );

			// Written straight to $data, not via set_prop: these are persisted values,
			// not pending edits, and must not show up in get_changes().
			if ( '' !== $value && null !== $value ) {
				$this->data[ $prop ] = $value;
			}
		}
	}

	/**
	 * Mirror the schedule props into meta immediately before the data store writes.
	 */
	public function save() {
		foreach ( self::META_MAP as $prop => $meta_key ) {
			$value = $this->get_prop( $prop, 'edit' );
			if ( null === $value || '' === $value ) {
				$this->delete_meta_data( $meta_key );
				continue;
			}
			$this->update_meta_data( $meta_key, $value );
		}

		return parent::save();
	}

	/**
	 * Accept timestamps, date strings and WC_DateTime; store a GMT string.
	 */
	private function normalize_datetime( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}
		if ( $value instanceof \WC_DateTime ) {
			return gmdate( 'Y-m-d H:i:s', $value->getTimestamp() );
		}
		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
