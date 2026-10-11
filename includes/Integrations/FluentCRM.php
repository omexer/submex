<?php

namespace Submex\Integrations;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies FluentCRM tags and lists to the customer while the subscription is live; only what Submex added is ever removed.
 */
class FluentCRM implements Integration {

	public const PRODUCT_TAGS  = '_submex_fluentcrm_tags';
	public const PRODUCT_LISTS = '_submex_fluentcrm_lists';

	public const USER_TAGS  = '_submex_fluentcrm_added_tags';
	public const USER_LISTS = '_submex_fluentcrm_added_lists';

	/** When Submex started recording what it added; subscriptions created before it are treated as having added everything. */
	public const OPTION_SINCE = 'submex_fluentcrm_tracked_since';

	/** Submex Pro 0.48 and older register their own copy. */
	private const PRO_COPY = 'SubmexPro\Integrations\FluentCRM';

	public function slug(): string {
		return 'fluentcrm';
	}

	public function title(): string {
		return __( 'FluentCRM', 'submex' );
	}

	public function is_available(): bool {
		return function_exists( 'FluentCrmApi' ) && ! class_exists( self::PRO_COPY, false );
	}

	public function hooks(): void {
		add_option( self::OPTION_SINCE, time() );
	}

	/**
	 * The Integrations screen tile.
	 *
	 * @param array $integrations
	 */
	public static function describe( $integrations ): array {
		$integrations = (array) $integrations;

		if ( class_exists( self::PRO_COPY, false ) ) {
			return $integrations;
		}

		$integration = new self();

		$integrations[] = array(
			'key'         => $integration->slug(),
			'title'       => $integration->title(),
			'requires'    => 'FluentCRM',
			'slug'        => 'fluent-crm',
			'url'         => '',
			'active'      => $integration->is_available(),
			'category'    => __( 'Email and CRM', 'submex' ),
			'description' => __( 'Gives the contact your chosen lists and tags while the subscription is live.', 'submex' ),
			'icon'        => '',
			'hint'        => __( 'Set per product in the subscription product\'s settings.', 'submex' ),
		);

		return $integrations;
	}

	public function grant( Subscription $subscription ): void {
		$tags  = Integrations::product_ids( $subscription, self::PRODUCT_TAGS );
		$lists = Integrations::product_ids( $subscription, self::PRODUCT_LISTS );

		if ( ( ! $tags && ! $lists ) || ! $this->is_available() ) {
			return;
		}

		$contact = $this->contact( $subscription );

		if ( ! $contact ) {
			return;
		}

		$tags  = $this->attach( $contact, 'tags', $tags );
		$lists = $this->attach( $contact, 'lists', $lists );

		$this->remember( $subscription, self::USER_TAGS, $tags );
		$this->remember( $subscription, self::USER_LISTS, $lists );

		if ( ! $tags && ! $lists ) {
			return;
		}

		do_action(
			'submex_integration_granted',
			$this->slug(),
			$subscription,
			array(
				'tags'  => $tags,
				'lists' => $lists,
			)
		);
	}

	public function revoke( Subscription $subscription ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		$tags  = $this->ours( $subscription, self::USER_TAGS, self::PRODUCT_TAGS );
		$lists = $this->ours( $subscription, self::USER_LISTS, self::PRODUCT_LISTS );

		if ( ! $tags && ! $lists ) {
			return;
		}

		$contact = $this->contact( $subscription );

		if ( ! $contact ) {
			return;
		}

		$removed_tags  = $this->detach( $contact, 'tags', $tags );
		$removed_lists = $this->detach( $contact, 'lists', $lists );

		// Released either way: one the contact no longer held is no longer ours to take back.
		if ( null !== $removed_tags ) {
			$this->forget( $subscription, self::USER_TAGS, $tags );
		}

		if ( null !== $removed_lists ) {
			$this->forget( $subscription, self::USER_LISTS, $lists );
		}

		if ( ! $removed_tags && ! $removed_lists ) {
			return;
		}

		do_action(
			'submex_integration_revoked',
			$this->slug(),
			$subscription,
			array(
				'tags'  => $removed_tags,
				'lists' => $removed_lists,
			)
		);
	}

	public function render_fields( ?\WC_Product $product ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		Integrations::render_multiselect(
			array(
				'id'          => self::PRODUCT_TAGS,
				'label'       => __( 'FluentCRM tags', 'submex' ),
				'options'     => $this->options( 'tags' ),
				'value'       => $product ? (array) $product->get_meta( self::PRODUCT_TAGS ) : array(),
				'empty'       => __( 'No FluentCRM tags found.', 'submex' ),
				'description' => __( 'Applied while the subscription is active or trialling, and removed when it is not. Tags and lists the contact already had are left alone.', 'submex' ),
			)
		);

		Integrations::render_multiselect(
			array(
				'id'      => self::PRODUCT_LISTS,
				'label'   => __( 'FluentCRM lists', 'submex' ),
				'options' => $this->options( 'lists' ),
				'value'   => $product ? (array) $product->get_meta( self::PRODUCT_LISTS ) : array(),
				'empty'   => __( 'No FluentCRM lists found.', 'submex' ),
			)
		);
	}

	public function save_fields( \WC_Product $product ): void {
		if ( ! $this->is_available() || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		$product->update_meta_data( self::PRODUCT_TAGS, Integrations::posted_ids( self::PRODUCT_TAGS ) );
		$product->update_meta_data( self::PRODUCT_LISTS, Integrations::posted_ids( self::PRODUCT_LISTS ) );
	}

	/** FluentCrmApi( 'contacts' )->createOrUpdate( array $data ) returns a Subscriber model, or false for an invalid email. */
	protected function contact( Subscription $subscription ): ?object {
		$email = $subscription->get_billing_email();

		if ( '' === $email ) {
			return null;
		}

		$api = FluentCrmApi( 'contacts' );

		// FluentCRM's FCApi wrapper only has __call, so method_exists() is always false on it.
		if ( ! is_object( $api ) || ! is_callable( array( $api, 'createOrUpdate' ) ) ) {
			do_action( 'submex_integration_unsupported', $this->slug(), 'createOrUpdate' );
			return null;
		}

		$contact = $api->createOrUpdate(
			array(
				'email'      => $email,
				'first_name' => $subscription->get_billing_first_name(),
				'last_name'  => $subscription->get_billing_last_name(),
			)
		);

		return is_object( $contact ) ? $contact : null;
	}

	/**
	 * Gives the contact the ids it does not hold yet.
	 *
	 * @param int[] $ids
	 * @return int[] The ids it was given.
	 */
	private function attach( object $contact, string $relation, array $ids ): array {
		$held = $this->held( $contact, $relation );
		$new  = null === $held ? $ids : array_values( array_diff( $ids, $held ) );

		return $this->call( $contact, 'tags' === $relation ? 'attachTags' : 'attachLists', $new ) ? $new : array();
	}

	/**
	 * Takes back the ids the contact still holds.
	 *
	 * @param int[] $ids
	 * @return int[]|null The ids taken back, or null when FluentCRM refused the call.
	 */
	private function detach( object $contact, string $relation, array $ids ): ?array {
		$held = $this->held( $contact, $relation );
		$gone = null === $held ? $ids : array_values( array_intersect( $ids, $held ) );

		return $this->call( $contact, 'tags' === $relation ? 'detachTags' : 'detachLists', $gone ) ? $gone : null;
	}

	/** FluentCRM Subscriber methods: attachTags/detachTags/attachLists/detachLists( array $ids ). */
	private function call( object $contact, string $method, array $ids ): bool {
		if ( ! $ids ) {
			return true;
		}

		if ( ! method_exists( $contact, $method ) ) {
			do_action( 'submex_integration_unsupported', $this->slug(), $method );
			return false;
		}

		$contact->$method( $ids );

		return true;
	}

	/**
	 * The contact's tag or list ids, read off its `tags` / `lists` relation; null when it cannot be read.
	 *
	 * @return int[]|null
	 */
	private function held( object $contact, string $relation ): ?array {
		$items = isset( $contact->{$relation} ) ? $contact->{$relation} : null;

		if ( ! is_iterable( $items ) ) {
			return null;
		}

		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = (int) ( is_object( $item ) ? ( $item->id ?? 0 ) : ( $item['id'] ?? 0 ) );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * What this subscription may take back: what it releases, narrowed to what Submex added.
	 *
	 * @return int[]
	 */
	private function ours( Subscription $subscription, string $user_key, string $product_key ): array {
		$ids     = Integrations::releasable_ids( $subscription, $product_key );
		$user_id = (int) $subscription->get_customer_id();

		if ( ! $ids || ! $user_id ) {
			return $ids;
		}

		$added = $this->added( $user_id, $user_key );

		foreach ( Subscription_Query::get(
			array(
				'limit'       => -1,
				'status'      => null,
				'customer_id' => $user_id,
			)
		) as $any ) {
			if ( $this->untracked( $any ) ) {
				$added = array_merge( $added, Integrations::product_ids( $any, $product_key ) );
			}
		}

		return array_values( array_intersect( $ids, $added ) );
	}

	/** Created before Submex recorded what it added, so whatever its products configure counts as added. */
	private function untracked( Subscription $subscription ): bool {
		$created = $subscription->get_date_created();

		return ! $created || $created->getTimestamp() < (int) get_option( self::OPTION_SINCE, time() );
	}

	/**
	 * @param int[] $ids
	 */
	private function remember( Subscription $subscription, string $key, array $ids ): void {
		$user_id = (int) $subscription->get_customer_id();

		if ( $user_id && $ids ) {
			update_user_meta( $user_id, $key, array_values( array_unique( array_merge( $this->added( $user_id, $key ), $ids ) ) ) );
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function forget( Subscription $subscription, string $key, array $ids ): void {
		$user_id = (int) $subscription->get_customer_id();

		if ( $user_id && $ids ) {
			update_user_meta( $user_id, $key, array_values( array_diff( $this->added( $user_id, $key ), $ids ) ) );
		}
	}

	/**
	 * @return int[]
	 */
	private function added( int $user_id, string $key ): array {
		$added = get_user_meta( $user_id, $key, true );

		return is_array( $added ) ? array_map( 'intval', $added ) : array();
	}

	/**
	 * FluentCrmApi( 'tags' | 'lists' )->all() yields models with id and title; FluentCRM answers it through __call, so it is not method_exists-checked.
	 *
	 * @return array<int, string>
	 */
	private function options( string $resource ): array {
		$options = array();

		try {
			$api   = FluentCrmApi( $resource );
			$items = is_object( $api ) ? $api->all() : null;
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_iterable( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			$id = (int) ( is_object( $item ) ? ( $item->id ?? 0 ) : ( $item['id'] ?? 0 ) );

			if ( $id ) {
				$options[ $id ] = (string) ( is_object( $item ) ? ( $item->title ?? '' ) : ( $item['title'] ?? '' ) );
			}
		}

		return $options;
	}
}
