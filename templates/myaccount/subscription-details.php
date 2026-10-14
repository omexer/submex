<?php
/**
 * Single subscription. Ordered by what customers actually ask first:
 * when am I charged next, what is in it, how do I stop it.
 *
 * Override: yourtheme/woocommerce/myaccount/subscription-details.php
 *
 * @var \Submex\Domain\Subscription $subscription
 * @var string                      $endpoint
 */

defined( 'ABSPATH' ) || exit;

use Submex\Domain\Subscription_Status;
use Submex\Frontend\MyAccount\Status_Presenter;
use Submex\Lifecycle\Cancellation_Policy;

$submex_state   = Status_Presenter::for( $subscription );
$submex_pending = Status_Presenter::pending_charge( $subscription );
$submex_by_link = Status_Presenter::pays_by_link( $subscription );
$submex_live    = ! in_array(
	$subscription->get_status_enum(),
	array( Subscription_Status::Cancelled, Subscription_Status::Expired, Subscription_Status::Switched, Subscription_Status::PendingCancel ),
	true
);

// A term the customer agreed to, such as a minimum number of payments, can hold both buttons back.
$submex_term_allows    = $submex_live && apply_filters( 'submex_can_cancel', true, $subscription, get_current_user_id() );
$submex_can_cancel     = $submex_term_allows && Cancellation_Policy::is_offered();
$submex_cancel_refused = $submex_live && ! $submex_can_cancel ? \Submex\Frontend\MyAccount\Account_Endpoint::cancel_refused_message( $subscription ) : '';
?>

<p class="submex-back">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>">
		&larr; <?php esc_html_e( 'All subscriptions', 'submex' ); ?>
	</a>
</p>

<div class="submex-detail">
	<div class="submex-card__head">
		<h2><?php echo esc_html( Status_Presenter::title( $subscription ) ); ?></h2>
		<span class="submex-badge submex-badge--<?php echo esc_attr( $submex_state['tone'] ); ?>">
			<?php echo esc_html( $submex_state['label'] ); ?>
		</span>
	</div>

	<?php if ( $submex_state['detail'] ) : ?>
		<p class="submex-detail__state"><?php echo esc_html( $submex_state['detail'] ); ?></p>
	<?php endif; ?>

	<?php $submex_action = \Submex\Frontend\MyAccount\Status_Presenter::primary_action( $subscription ); ?>
	<?php if ( $submex_action ) : ?>
		<p class="submex-detail__action">
			<a class="submex-btn submex-btn--primary" href="<?php echo esc_url( $submex_action['url'] ); ?>">
				<?php echo esc_html( $submex_action['label'] ); ?>
			</a>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( "What's included", 'submex' ); ?></h3>
	<ul class="submex-items">
		<?php foreach ( $subscription->get_items() as $submex_item ) : ?>
			<li>
				<?php echo esc_html( $submex_item->get_name() ); ?>
				<?php if ( $submex_item->get_quantity() > 1 ) : ?>
					<span class="submex-qty">&times; <?php echo esc_html( (string) $submex_item->get_quantity() ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="submex-detail__total">
		<strong><?php esc_html_e( 'Recurring total', 'submex' ); ?>:</strong>
		<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
	</p>

	<?php
	/**
	 * Renders after the subscription's totals, for anything an extension needs to show
	 * the customer here - licence keys, downloads, delivery dates.
	 *
	 * @param \Submex\Domain\Subscription $subscription
	 */
	do_action( 'submex_after_subscription_totals', $subscription );
	?>

	<?php
	/**
	 * Extra actions a customer can take on this subscription.
	 *
	 * @param array                        $actions      slug => button label
	 * @param \Submex\Domain\Subscription $subscription
	 */
	$submex_extra_actions = (array) apply_filters( 'submex_myaccount_actions', array(), $subscription );
	?>
	<?php if ( $submex_extra_actions ) : ?>
		<p class="submex-actions">
			<?php foreach ( $submex_extra_actions as $submex_slug => $submex_label ) : ?>
				<form method="post" style="display:inline-block;margin:0 .4em .4em 0">
					<?php wp_nonce_field( 'submex_pro_' . $subscription->get_id() ); ?>
					<input type="hidden" name="submex_pro_action" value="<?php echo esc_attr( (string) $submex_slug ); ?>" />
					<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( (string) $subscription->get_id() ); ?>" />
					<?php
					/**
					 * Fields an extension needs on one of its action forms, such as a reason for pausing.
					 *
					 * @param string                      $slug
					 * @param \Submex\Domain\Subscription $subscription
					 */
					do_action( 'submex_myaccount_action_fields', (string) $submex_slug, $subscription );
					?>
					<button type="submit" class="button submex-btn"><?php echo esc_html( (string) $submex_label ); ?></button>
				</form>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php if ( \Submex\Lifecycle\Early_Renewal::is_available_for( $subscription ) ) : ?>
		<h3><?php esc_html_e( 'Pay early', 'submex' ); ?></h3>

		<p>
			<?php
			printf(
				/* translators: 1: amount, 2: the date it would otherwise be taken */
				esc_html__( 'You can pay your next %1$s now instead of on %2$s. Your renewal date stays the same.', 'submex' ),
				wp_kses_post( $subscription->get_formatted_order_total() ),
				esc_html( date_i18n( (string) get_option( 'date_format' ), strtotime( $subscription->get_next_payment() . ' UTC' ) ) )
			);
			?>
		</p>

		<form method="post" class="submex-renew-early">
			<?php wp_nonce_field( 'submex_renew_early_' . $subscription->get_id() ); ?>
			<input type="hidden" name="submex_action" value="renew_early" />
			<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( (string) $subscription->get_id() ); ?>" />
			<button type="submit" class="button submex-btn"><?php esc_html_e( 'Pay now', 'submex' ); ?></button>
		</form>
	<?php endif; ?>

	<?php
	$submex_auto_offered = \Submex\Lifecycle\Auto_Renewal::is_offered() && $submex_term_allows;
	$submex_auto_on      = \Submex\Lifecycle\Auto_Renewal::is_on( $subscription );
	?>
	<?php if ( $submex_auto_offered ) : ?>
		<h3><?php echo $submex_by_link ? esc_html__( 'Renewal', 'submex' ) : esc_html__( 'Automatic renewal', 'submex' ); ?></h3>

		<p class="submex-auto-renew__state">
			<?php
			if ( $submex_by_link ) {
				echo $submex_auto_on
					? esc_html__( 'At the end of each period we send you a renewal to pay.', 'submex' )
					: esc_html__( 'Renewal is off. You keep access until the end of the period you have paid for, and you will not be asked to pay again.', 'submex' );
			} else {
				echo $submex_auto_on
					? esc_html__( 'This subscription renews automatically.', 'submex' )
					: esc_html__( 'Automatic renewal is off. You keep access until the end of the period you have paid for, and you will not be charged again.', 'submex' );
			}
			?>
		</p>

		<form method="post" class="submex-auto-renew">
			<?php wp_nonce_field( 'submex_auto_renew_' . $subscription->get_id() ); ?>
			<input type="hidden" name="submex_action" value="auto_renew" />
			<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( (string) $subscription->get_id() ); ?>" />
			<input type="hidden" name="submex_auto_renew" value="<?php echo $submex_auto_on ? 'off' : 'on'; ?>" />

			<button type="submit" class="button submex-btn">
				<?php
				if ( $submex_by_link ) {
					echo $submex_auto_on
						? esc_html__( 'Stop renewing', 'submex' )
						: esc_html__( 'Turn renewal back on', 'submex' );
				} else {
					echo $submex_auto_on
						? esc_html__( 'Turn off automatic renewal', 'submex' )
						: esc_html__( 'Turn automatic renewal back on', 'submex' );
				}
				?>
			</button>
		</form>
	<?php endif; ?>

	<?php if ( $submex_can_cancel ) : ?>
		<?php
		if ( Cancellation_Policy::is_immediate() ) {
			$submex_effect = __( 'Your subscription ends now, and access ends with it. There is no refund for the time left.', 'submex' );
		} elseif ( $subscription->get_next_payment() && ! $submex_pending ) {
			$submex_effect = sprintf(
				/* translators: %s: date the current period ends */
				__( 'Your subscription stays active until %s. You will not be charged again.', 'submex' ),
				date_i18n( (string) get_option( 'date_format' ), strtotime( $subscription->get_next_payment() . ' UTC' ) )
			);
		} else {
			$submex_effect = __( 'Your subscription stays active until the end of the current period. You will not be charged again.', 'submex' );
		}
		?>
		<h3><?php esc_html_e( 'Cancel this subscription', 'submex' ); ?></h3>

		<?php if ( \Submex\Lifecycle\Retention_Flow::is_on() ) : ?>
			<p class="submex-cancel__effect"><?php echo esc_html( $submex_effect ); ?></p>

			<div class="submex-cancel__actions">
				<a class="button submex-btn" href="<?php echo esc_url( \Submex\Frontend\MyAccount\Cancel_Flow::view_url( $subscription ) ); ?>"><?php esc_html_e( 'Cancel subscription', 'submex' ); ?></a>
			</div>
		<?php else : ?>
			<form method="post" class="submex-cancel">
				<?php wp_nonce_field( 'submex_cancel_' . $subscription->get_id() ); ?>
				<input type="hidden" name="submex_action" value="cancel" />
				<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( (string) $subscription->get_id() ); ?>" />

				<p class="submex-cancel__effect"><?php echo esc_html( $submex_effect ); ?></p>

				<div class="submex-cancel__actions">
					<button type="submit" class="button submex-btn"><?php esc_html_e( 'Cancel subscription', 'submex' ); ?></button>
					<a class="button submex-btn" href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>">
						<?php esc_html_e( 'Keep subscription', 'submex' ); ?>
					</a>
				</div>
			</form>
		<?php endif; ?>
	<?php elseif ( $submex_cancel_refused ) : ?>
		<p class="submex-cancel__refused"><?php echo esc_html( $submex_cancel_refused ); ?></p>
	<?php endif; ?>
</div>
