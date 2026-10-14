<?php
/**
 * The one offer shown before a cancel, and the single submit that turns it down and cancels.
 *
 * The offer itself is drawn by whatever made it, through submex_retention_render_offer.
 *
 * Override: yourtheme/submex/myaccount/cancel-offer.php
 *
 * @var \Submex\Domain\Subscription $subscription
 * @var mixed                       $offer
 * @var string                      $reason_id
 * @var string                      $detail
 * @var string                      $decline_text
 */

defined( 'ABSPATH' ) || exit;

use Submex\Frontend\MyAccount\Cancel_Flow;
?>

<p class="submex-back">
	<a href="<?php echo esc_url( Cancel_Flow::details_url( $subscription ) ); ?>">
		&larr; <?php esc_html_e( 'Back to the subscription', 'submex' ); ?>
	</a>
</p>

<div class="submex-flow submex-flow--offer">
	<?php
	/**
	 * Draws the offer, and its own accept form, above the button that declines it and cancels.
	 *
	 * @param mixed                       $offer        What submex_retention_offer_for returned.
	 * @param \Submex\Domain\Subscription $subscription
	 * @param string                      $reason_id    The reason picked, or empty when none was.
	 */
	do_action( 'submex_retention_render_offer', $offer, $subscription, $reason_id );
	?>

	<form method="post" class="submex-flow__decline">
		<?php wp_nonce_field( Cancel_Flow::nonce_action( $subscription->get_id() ) ); ?>
		<input type="hidden" name="submex_action" value="<?php echo esc_attr( Cancel_Flow::ACTION ); ?>" />
		<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( (string) $subscription->get_id() ); ?>" />
		<input type="hidden" name="submex_choice" value="<?php echo esc_attr( Cancel_Flow::DECLINE ); ?>" />
		<input type="hidden" name="submex_reason" value="<?php echo esc_attr( $reason_id ); ?>" />
		<input type="hidden" name="submex_detail" value="<?php echo esc_attr( $detail ); ?>" />

		<div class="submex-cancel__actions">
			<button type="submit" class="button submex-btn"><?php echo esc_html( $decline_text ); ?></button>
		</div>
	</form>
</div>
