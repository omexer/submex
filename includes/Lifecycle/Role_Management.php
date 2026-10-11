<?php

namespace Submex\Lifecycle;

use Submex\Data\Subscription_Query;
use Submex\Domain\Subscription;
use Submex\Domain\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives a subscriber a WordPress role while their subscription is live.
 *
 * Roles are how most membership setups gate content, so this is the join between billing
 * and access for everything that is not a WooCommerce download.
 */
class Role_Management {

	private const OPTION_ACTIVE   = 'submex_active_role';
	private const OPTION_INACTIVE = 'submex_inactive_role';

	/** Capabilities that mean a role can run the site or the store; no subscription grants them. */
	public const ADMINISTRATIVE = array(
		'manage_options',
		'promote_users',
		'create_users',
		'edit_users',
		'delete_users',
		'remove_users',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'update_plugins',
		'delete_plugins',
		'install_themes',
		'edit_themes',
		'switch_themes',
		'update_themes',
		'delete_themes',
		'edit_theme_options',
		'update_core',
		'unfiltered_html',
		'unfiltered_upload',
		'manage_woocommerce',
	);

	public function register(): void {
		add_action( 'submex_subscription_status_changed', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'submex_subscription_grace_ended', array( $this, 'demote' ) );
	}

	/**
	 * @param string $from
	 * @param string $to
	 */
	public function on_status_change( Subscription $subscription, $from, $to ): void {
		$user = $this->user_for( $subscription );

		if ( ! $user ) {
			return;
		}

		$status = Subscription_Status::tryFrom( (string) $to );

		if ( ! $status ) {
			return;
		}

		if ( $status->is_billable() ) {
			$this->apply( $user, (string) get_option( self::OPTION_ACTIVE, '' ) );
			return;
		}

		if ( ! $subscription->in_grace() ) {
			$this->demote( $subscription );
		}
	}

	public function demote( Subscription $subscription ): void {
		$user = $this->user_for( $subscription );

		// Only demote once nothing else is keeping them in: a customer with two
		// subscriptions must not lose access because one of them lapsed.
		if ( $user && ! $this->has_another_live_subscription( $user->ID, $subscription->get_id() ) ) {
			$this->apply( $user, (string) get_option( self::OPTION_INACTIVE, '' ) );
		}
	}

	public static function is_grantable( string $role ): bool {
		$object = '' === $role ? null : get_role( $role );

		if ( ! $object ) {
			return false;
		}

		foreach ( self::ADMINISTRATIVE as $capability ) {
			if ( $object->has_cap( $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string, string> Role slug => translated name. */
	public static function grantable_roles(): array {
		$roles = array();

		foreach ( wp_roles()->get_names() as $slug => $label ) {
			if ( self::is_grantable( $slug ) ) {
				$roles[ $slug ] = translate_user_role( $label );
			}
		}

		return $roles;
	}

	private function apply( \WP_User $user, string $role ): void {
		// A role saved by an older version never went through the dropdown.
		if ( ! self::is_grantable( $role ) ) {
			return;
		}

		// set_role() replaces every role, so staff would lose theirs by subscribing.
		foreach ( self::ADMINISTRATIVE as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return;
			}
		}

		if ( in_array( $role, (array) $user->roles, true ) ) {
			return;
		}

		$user->set_role( $role );
	}

	private function has_another_live_subscription( int $user_id, int $exclude_id ): bool {
		$ids = Subscription_Query::ids(
			array(
				'customer_id' => $user_id,
				'status'      => array( Subscription_Status::Active->value, Subscription_Status::Trialling->value, Subscription_Status::PendingCancel->value ),
				'limit'       => 5,
			)
		);

		if ( array_diff( $ids, array( $exclude_id ) ) ) {
			return true;
		}

		foreach ( Subscription_Query::get(
			array(
				'customer_id' => $user_id,
				'status'      => array( Subscription_Status::OnHold->value ),
				'limit'       => -1,
			)
		) as $held ) {
			if ( $held->get_id() !== $exclude_id && $held->in_grace() ) {
				return true;
			}
		}

		return false;
	}

	private function user_for( Subscription $subscription ): ?\WP_User {
		$user = $subscription->get_customer_id() ? get_userdata( $subscription->get_customer_id() ) : false;

		return $user instanceof \WP_User ? $user : null;
	}
}
