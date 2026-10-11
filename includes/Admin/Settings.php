<?php

namespace Submex\Admin;

use Submex\Billing\Renewal_Scheduler;
use Submex\Billing\Renewal_Tax_Repair;
use Submex\Data\Charge_Slot_Repository;
use Submex\Data\Migrator;
use Submex\Emails\Notification_Settings;
use Submex\Lifecycle\Cancellation_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → Settings → Subscriptions.
 *
 * A tab inside WooCommerce settings rather than a screen of our own, so it inherits
 * Woo's markup, save handling and nonce checks. UX Spec 3.
 */
class Settings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'submex';
		$this->label = __( 'Subscriptions', 'submex' );

		parent::__construct();

		add_action( 'woocommerce_admin_field_submex_status', array( $this, 'render_status' ) );
		add_action( 'woocommerce_admin_field_submex_tax_repair', array( $this, 'render_tax_repair' ) );
	}

	public function get_sections(): array {
		$sections = array(
			''                  => __( 'General', 'submex' ),
			'customer_controls' => __( 'Customer controls', 'submex' ),
			'renewal'           => __( 'Renewal & billing', 'submex' ),
			'checkout'          => __( 'Cart & checkout', 'submex' ),
			'notifications'     => __( 'Email notifications', 'submex' ),
			'paypal'            => __( 'PayPal', 'submex' ),
		);

		// Core applies this in the method we are overriding; without it an extension can
		// add settings but has no tab to put them on.
		return (array) apply_filters( 'woocommerce_get_sections_' . $this->id, $sections ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own settings hook.
	}

	public function get_settings_for_default_section(): array {
		$settings = array(
			array(
				'title' => __( 'Health', 'submex' ),
				'type'  => 'title',
				'desc'  => __( 'Renewals run on the WooCommerce queue. If the queue stops, subscriptions stop billing — usually the first thing to check.', 'submex' ),
				'id'    => 'submex_health_title',
			),
			array( 'type' => 'submex_status' ),
			array( 'type' => 'submex_tax_repair' ),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_health_title',
			),

			array(
				'title' => __( 'Access', 'submex' ),
				'type'  => 'title',
				'desc'  => __( 'What a live subscription grants, and what lapsing takes away.', 'submex' ),
				'id'    => 'submex_access_title',
			),
			array(
				'title'    => __( 'Role while subscribed', 'submex' ),
				'desc_tip' => __( 'Assigned when a subscription becomes active. Administrators are never changed.', 'submex' ),
				'type'     => 'select',
				'id'       => 'submex_active_role',
				'default'  => '',
				'options'  => self::role_options(),
			),
			array(
				'title'    => __( 'Role once it ends', 'submex' ),
				'desc_tip' => __( 'Assigned only when the customer has no other live subscription.', 'submex' ),
				'type'     => 'select',
				'id'       => 'submex_inactive_role',
				'default'  => '',
				'options'  => self::role_options(),
			),
			array(
				'title'    => __( 'Delete data when the plugin is deleted', 'submex' ),
				'desc'     => __( 'Remove Submex\'s settings and its own database tables on uninstall', 'submex' ),
				'type'     => 'checkbox',
				'id'       => 'submex_delete_data_on_uninstall',
				'default'  => 'no',
				'desc_tip' => __( 'Off by default, so deactivating or deleting the plugin to try something else loses nothing. Subscriptions and their orders are never deleted either way — they are WooCommerce orders and part of your financial record.', 'submex' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_access_title',
			),
		);

		return $settings;
	}

	public function get_settings_for_customer_controls_section(): array {
		return array(
			array(
				'title' => __( 'Customer controls', 'submex' ),
				'type'  => 'title',
				'id'    => 'submex_customer_controls_title',
			),
			array(
				'title'    => __( 'Allow customers to renew early', 'submex' ),
				'desc'     => __( 'Customers can pay their next renewal before it is due. The renewal date stays the same.', 'submex' ),
				'type'     => 'checkbox',
				'id'       => \Submex\Lifecycle\Early_Renewal::OPTION,
				'default'  => 'no',
				'desc_tip' => __( 'Only offered for cards Submex charges itself, never for PayPal, which bills from a plan of its own.', 'submex' ),
			),
			array(
				'title'   => __( 'Allow customers to cancel', 'submex' ),
				'desc'    => __( 'Customers can cancel from My Account. When off, they are asked to contact you. In the UK and EU, customers must be able to cancel as easily as they signed up.', 'submex' ),
				'type'    => 'checkbox',
				'id'      => Cancellation_Policy::OPTION,
				'default' => 'yes',
			),
			array(
				'title'          => __( 'Cancellation takes effect', 'submex' ),
				'desc'           => __( 'At the end of the billing cycle, the customer keeps access until the date they have paid for. Immediately ends access at once, with no refund for the time left.', 'submex' ),
				'type'           => 'select',
				'id'             => Cancellation_Policy::OPTION_WHEN,
				'default'        => Cancellation_Policy::AT_PERIOD_END,
				'options'        => array(
					Cancellation_Policy::AT_PERIOD_END => __( 'End of billing cycle', 'submex' ),
					Cancellation_Policy::IMMEDIATELY   => __( 'Immediately', 'submex' ),
				),
				'submex_show_if' => Cancellation_Policy::OPTION,
			),
			array(
				'title'   => __( 'Allow customers to turn off auto-renew', 'submex' ),
				'desc'    => __( 'Customers can stop their subscription renewing. It then ends at the end of the current period, unless they turn renewal back on.', 'submex' ),
				'type'    => 'checkbox',
				'id'      => 'submex_allow_auto_renew_toggle',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_customer_controls_title',
			),
		);
	}

	public function get_settings_for_renewal_section(): array {
		return array(
			array(
				'title' => __( 'Renewal', 'submex' ),
				'type'  => 'title',
				'id'    => 'submex_renewals_title',
			),
			array(
				'title'   => __( 'Missed renewals', 'submex' ),
				'desc'    => __( 'What to do when a subscription falls more than one period behind, usually because the site stopped running its scheduled tasks. Charging for every missed period bills the customer several times at once, so only choose it if you deliver every period anyway.', 'submex' ),
				'id'      => 'submex_catch_up_policy',
				'type'    => 'select',
				'default' => 'rebase',
				'options' => array(
					'rebase'     => __( 'Charge once and move the schedule forward (recommended)', 'submex' ),
					'charge_all' => __( 'Charge for every missed period', 'submex' ),
				),
			),
			array(
				'title'   => __( 'Free first payments', 'submex' ),
				'desc'    => __( 'Ask for a payment method even when the first payment is zero, such as a free trial with no sign-up fee. Without one there is nothing to charge when the first renewal is due. Offline methods like bank transfer store nothing to charge either.', 'submex' ),
				'id'      => \Submex\Checkout\Trial_Payment::OPTION,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_renewals_title',
			),
		);
	}

	public function get_settings_for_checkout_section(): array {
		return array(
			array(
				'title' => __( 'Cart & checkout', 'submex' ),
				'type'  => 'title',
				'id'    => 'submex_checkout_title',
			),
			array(
				'title'    => __( 'Buying without an account', 'submex' ),
				'desc_tip' => __( 'A subscription has to belong to someone, so a guest who buys one gets an account created at checkout.', 'submex' ),
				'type'     => 'select',
				'id'       => 'submex_guest_checkout',
				'default'  => 'create_account',
				'options'  => array(
					'create_account' => __( 'Create an account for them automatically', 'submex' ),
					'require_login'  => __( 'Require them to log in first', 'submex' ),
				),
			),
			array(
				'title'   => __( 'Allow mixed checkout', 'submex' ),
				'desc'    => __( 'Customers can buy a subscription and one-time products in the same order. When off, a subscription is checked out on its own.', 'submex' ),
				'type'    => 'checkbox',
				'id'      => \Submex\Checkout\Cart_Validation::OPTION_MIXED,
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_checkout_title',
			),
		);
	}

	public function get_settings_for_notifications_section(): array {
		return Notification_Settings::fields();
	}

	public function get_settings_for_paypal_section(): array {
		return array(
			array(
				'title' => __( 'PayPal Subscriptions', 'submex' ),
				'type'  => 'title',
				'desc'  => __( 'PayPal manages the billing schedule itself and tells Submex about each payment by webhook. Add the webhook URL below to your PayPal app before going live, or renewals will not be recorded.', 'submex' ),
				'id'    => 'submex_paypal_title',
			),
			array(
				'title'   => __( 'Enable PayPal', 'submex' ),
				'desc'    => __( 'Offer PayPal for subscription purchases', 'submex' ),
				'id'      => 'submex_paypal_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Environment', 'submex' ),
				'id'      => 'submex_paypal_live',
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'  => __( 'Sandbox (testing)', 'submex' ),
					'yes' => __( 'Live', 'submex' ),
				),
			),
			array(
				'title' => __( 'Client ID', 'submex' ),
				'id'    => 'submex_paypal_client_id',
				'type'  => 'text',
				'css'   => 'width:26rem',
			),
			array(
				'title' => __( 'Secret', 'submex' ),
				'id'    => 'submex_paypal_secret',
				'type'  => 'password',
				'css'   => 'width:26rem',
			),
			array(
				'title'    => __( 'Webhook ID', 'submex' ),
				'desc'     => __( 'From your PayPal app, after you add the webhook URL below. Without it Submex cannot verify that a webhook really came from PayPal, and will reject every one.', 'submex' ),
				'id'       => 'submex_paypal_webhook_id',
				'type'     => 'text',
				'css'      => 'width:26rem',
				'desc_tip' => false,
			),
			array(
				'title'             => __( 'Webhook URL', 'submex' ),
				'desc'              => __( 'Add this URL to your PayPal app and subscribe it to the billing subscription and payment sale events.', 'submex' ),
				'id'                => 'submex_paypal_webhook_url',
				'type'              => 'text',
				'value'             => rest_url( 'submex/v1/webhook/paypal' ),
				'custom_attributes' => array(
					'readonly' => 'readonly',
					'onclick'  => 'this.select()',
				),
				'css'               => 'width:26rem',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'submex_paypal_title',
			),
		);
	}

	/**
	 * A plain readout of whether renewals can actually run.
	 */
	/**
	 * WooCommerce refuses a saved value outside these options, so this list is also the save-time guard.
	 *
	 * @return array<string, string>
	 */
	private static function role_options(): array {
		return array( '' => __( 'Leave the role alone', 'submex' ) ) + \Submex\Lifecycle\Role_Management::grantable_roles();
	}

	public function render_status(): void {
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Status', 'submex' ) . '</th><td class="forminp"><div class="submex-checks">';

		foreach ( self::status_checks() as $check ) {
			self::render_check( $check );
		}

		echo '</div></td></tr>';
	}

	/**
	 * One status line, with the rows behind it when it has any.
	 *
	 * @param array{label: string, ok: bool, good: string, bad: string, items?: string[]} $check Items are HTML, escaped where they were built.
	 */
	public static function render_check( array $check ): void {
		printf(
			'<div class="submex-check submex-check--%s"><span class="submex-check__dot"></span><div>'
				. '<span class="submex-check__label">%s</span><span class="submex-check__detail">%s</span>',
			$check['ok'] ? 'ok' : 'bad',
			esc_html( $check['label'] ),
			esc_html( $check['ok'] ? $check['good'] : $check['bad'] )
		);

		if ( ! $check['ok'] && ! empty( $check['items'] ) ) {
			echo '<ul class="submex-check__items">';

			foreach ( $check['items'] as $item ) {
				echo '<li>' . wp_kses( $item, array( 'a' => array( 'href' => true ) ) ) . '</li>';
			}

			echo '</ul>';
		}

		echo '</div></div>';
	}

	public function render_tax_repair(): void {
		$repair = \Submex\Plugin::instance()->get( 'tax_repair' );
		$ids    = $repair instanceof Renewal_Tax_Repair ? $repair->affected_ids() : array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the repair itself was verified.
		$done   = absint( $_GET['submex_tax_repaired'] ?? 0 );
		$failed = absint( $_GET['submex_tax_repair_failed'] ?? 0 );
		$from   = Settings_Page::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ? Renewal_Tax_Repair::FROM_SUBMEX : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $ids && ! $done && ! $failed ) {
			return;
		}

		printf(
			'<tr valign="top" id="%s"><th scope="row" class="titledesc">%s</th><td class="forminp">',
			esc_attr( Renewal_Tax_Repair::NOTICE ),
			esc_html__( 'Subscriptions renewing with tax added twice', 'submex' )
		);

		if ( $done ) {
			/* translators: %d: subscription number */
			echo '<p>' . esc_html( sprintf( __( 'Subscription #%d repaired. Its renewals now charge the price without adding tax again.', 'submex' ), $done ) ) . '</p>';
		}

		if ( $failed ) {
			/* translators: %d: subscription number */
			echo '<p><strong>' . esc_html( sprintf( __( 'Subscription #%d was not repaired: it has changed since this list was made. The list has been refreshed.', 'submex' ), $failed ) ) . '</strong></p>';
		}

		if ( $ids ) {
			echo '<p class="description">' . esc_html__( 'These subscriptions were created before Submex stored prices without tax. Because this store enters prices with tax, each renewal adds tax on top of a price that already includes it. Repair stores the price without tax, so renewals charge what the customer paid at checkout. Customers already charged extra may be owed a refund: check their past renewal orders.', 'submex' ) . '</p>';

			printf(
				'<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th></th></tr></thead><tbody>',
				esc_html__( 'Subscription', 'submex' ),
				esc_html__( 'Customer', 'submex' ),
				esc_html__( 'Recurring price', 'submex' )
			);

			foreach ( $ids as $id ) {
				$subscription = wc_get_order( $id );

				if ( ! $subscription instanceof \Submex\Domain\Subscription ) {
					continue;
				}

				$detail = add_query_arg(
					array(
						'page'         => Menu::LIST_SLUG,
						'subscription' => $id,
					),
					admin_url( 'admin.php' )
				);

				printf(
					'<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td><a class="button" href="%s" onclick="return confirm(%s)">%s</a></td></tr>',
					esc_url( $detail ),
					(int) $id,
					esc_html( (string) $subscription->get_billing_email() ),
					wp_kses_post( $subscription->get_formatted_order_total() ),
					esc_url( Renewal_Tax_Repair::repair_url( $id, $from ) ),
					esc_attr( (string) wp_json_encode( __( 'Store this subscription\'s price without tax, so its renewals stop adding tax twice?', 'submex' ) ) ),
					esc_html__( 'Repair', 'submex' )
				);
			}

			echo '</tbody></table>';
		}

		echo '</td></tr>';
	}

	/**
	 * Whether renewals can actually run. Shared with the Settings screen's status card.
	 *
	 * @return array<int, array{label: string, ok: bool, good: string, bad: string, items?: string[]}>
	 */
	public static function status_checks(): array {
		$scheduler = \Submex\Plugin::instance()->get( 'scheduler' );
		$migrator  = new Migrator();
		$slots     = \Submex\Plugin::instance()->get( 'charge_slots' );

		// An hour, not the repository's 15 minutes: a charge still being retried is not
		// stuck, and a warning that clears itself teaches merchants to ignore it.
		$stuck = $slots instanceof Charge_Slot_Repository ? $slots->stuck_charging( 60 ) : array();

		// Direct Debit takes days, not this long.
		$stale = $slots instanceof Charge_Slot_Repository ? $slots->stale_pending( 10 ) : array();

		$repair = \Submex\Plugin::instance()->get( 'tax_repair' );
		$twice  = $repair instanceof Renewal_Tax_Repair ? count( $repair->affected_ids() ) : 0;

		$checks = array(
			array(
				'label' => __( 'Renewal queue', 'submex' ),
				'ok'    => $scheduler instanceof Renewal_Scheduler ? $scheduler->queue_is_healthy() : false,
				'good'  => __( 'Processing normally', 'submex' ),
				'bad'   => __( 'Overdue tasks are piling up. Check that WordPress cron is running.', 'submex' ),
			),
			array(
				'label' => __( 'Unresolved charges', 'submex' ),
				'ok'    => empty( $stuck ),
				'good'  => __( 'None', 'submex' ),
				'bad'   => sprintf(
					/* translators: %d: number of subscriptions */
					_n(
						'%d subscription has a charge whose outcome is still unknown and is not billing. Open it and check the gateway.',
						'%d subscriptions have a charge whose outcome is still unknown and are not billing. Open them and check the gateway.',
						count( $stuck ),
						'submex'
					),
					count( $stuck )
				),
			),
			array(
				'label' => __( 'Payments awaiting confirmation', 'submex' ),
				'ok'    => empty( $stale ),
				'good'  => __( 'None overdue', 'submex' ),
				'bad'   => sprintf(
					/* translators: %d: number of renewals */
					_n(
						'%d renewal has waited more than 10 days for its payment provider to confirm it. Check that the provider\'s webhook reaches this site, and the payment in its dashboard.',
						'%d renewals have waited more than 10 days for their payment provider to confirm them. Check that the provider\'s webhook reaches this site, and the payments in its dashboard.',
						count( $stale ),
						'submex'
					),
					count( $stale )
				),
				'items' => array_map( array( self::class, 'awaiting_item' ), $stale ),
			),
			array(
				'label' => __( 'Double-charge protection', 'submex' ),
				'ok'    => $migrator->charge_slot_guard_intact(),
				'good'  => __( 'Active', 'submex' ),
				'bad'   => __( 'The unique index on the charge ledger is missing. Deactivate and reactivate Submex.', 'submex' ),
			),
			array(
				'label' => __( 'Renewal tax', 'submex' ),
				'ok'    => 0 === $twice,
				'good'  => __( 'Charged once', 'submex' ),
				'bad'   => sprintf(
					/* translators: %d: number of subscriptions */
					_n(
						'%d subscription renews with tax added twice. Repair it under WooCommerce → Settings → Subscriptions.',
						'%d subscriptions renew with tax added twice. Repair them under WooCommerce → Settings → Subscriptions.',
						$twice,
						'submex'
					),
					$twice
				),
			),
		);

		return $checks;
	}

	/**
	 * A renewal waiting on its payment provider, as HTML: the subscription, the amount, how long, and the renewal order.
	 */
	private static function awaiting_item( object $slot ): string {
		$subscription_id = (int) $slot->subscription_id;
		$order           = $slot->renewal_order_id ? wc_get_order( (int) $slot->renewal_order_id ) : null;
		$days            = (int) floor( ( time() - (int) strtotime( Charge_Slot_Repository::pending_since( $slot ) . ' UTC' ) ) / DAY_IN_SECONDS );
		$subscription    = sprintf(
			'<a href="%s">#%d</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::LIST_SLUG . '&subscription=' . $subscription_id ) ),
			$subscription_id
		);

		if ( ! $order instanceof \WC_Order ) {
			/* translators: 1: subscription number, 2: number of days */
			return sprintf( esc_html( _n( 'Subscription %1$s, waiting %2$d day', 'Subscription %1$s, waiting %2$d days', $days, 'submex' ) ), $subscription, $days );
		}

		return sprintf(
			/* translators: 1: subscription number, 2: amount, 3: number of days, 4: renewal order number */
			esc_html( _n( 'Subscription %1$s, %2$s, waiting %3$d day, renewal order %4$s', 'Subscription %1$s, %2$s, waiting %3$d days, renewal order %4$s', $days, 'submex' ) ),
			$subscription,
			wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			$days,
			sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) )
		);
	}
}
