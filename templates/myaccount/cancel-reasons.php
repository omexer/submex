<?php
/**
 * "Ask before cancelling": why, with Continue canceling one submit away and no reason required.
 *
 * Override: yourtheme/woocommerce/myaccount/cancel-reasons.php
 *
 * @var \Submex\Domain\Subscription $subscription
 * @var array<string, string>       $reasons
 * @var array<string, mixed>        $display
 * @var string                      $endpoint
 */

defined( 'ABSPATH' ) || exit;

use Submex\Admin\Allowed_Html;
use Submex\Frontend\MyAccount\Cancel_Flow;

$submex_id = (string) $subscription->get_id();
?>

<p class="submex-back">
	<a href="<?php echo esc_url( Cancel_Flow::details_url( $subscription ) ); ?>">
		&larr; <?php esc_html_e( 'Back to the subscription', 'submex' ); ?>
	</a>
</p>

<form method="post" class="submex-flow submex-flow--<?php echo esc_attr( (string) $display['align'] ); ?>">
	<?php wp_nonce_field( Cancel_Flow::nonce_action( $subscription->get_id() ) ); ?>
	<input type="hidden" name="submex_action" value="<?php echo esc_attr( Cancel_Flow::ACTION ); ?>" />
	<input type="hidden" name="submex_subscription" value="<?php echo esc_attr( $submex_id ); ?>" />

	<h2 class="submex-flow__title" style="font-size:<?php echo esc_attr( (string) (int) $display['title_size'] ); ?>px"><?php echo esc_html( (string) $display['title'] ); ?></h2>

	<div class="submex-flow__description" style="font-size:<?php echo esc_attr( (string) (int) $display['description_size'] ); ?>px">
		<?php echo wp_kses( wpautop( (string) $display['description'] ), Allowed_Html::rich_text() ); ?>
	</div>

	<?php if ( $reasons ) : ?>
		<fieldset class="submex-flow__reasons">
			<legend class="screen-reader-text"><?php esc_html_e( 'Your main reason for cancelling (optional)', 'submex' ); ?></legend>

			<?php foreach ( $reasons as $submex_reason => $submex_label ) : ?>
				<?php $submex_field = 'submex-reason-' . $submex_id . '-' . $submex_reason; ?>
				<div class="submex-flow__reason">
					<input type="radio" id="<?php echo esc_attr( $submex_field ); ?>" name="submex_reason" value="<?php echo esc_attr( (string) $submex_reason ); ?>" />
					<label for="<?php echo esc_attr( $submex_field ); ?>"><?php echo esc_html( (string) $submex_label ); ?></label>

					<?php if ( Cancel_Flow::OTHER === $submex_reason ) : ?>
						<div class="submex-flow__detail">
							<label for="submex-detail-<?php echo esc_attr( $submex_id ); ?>"><?php esc_html_e( 'Tell us more (optional)', 'submex' ); ?></label>
							<textarea id="submex-detail-<?php echo esc_attr( $submex_id ); ?>" name="submex_detail" rows="3" maxlength="2000"></textarea>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</fieldset>
	<?php endif; ?>

	<div class="submex-cancel__actions">
		<button type="submit" name="submex_choice" value="<?php echo esc_attr( Cancel_Flow::KEEP ); ?>" class="button submex-btn submex-btn--primary"><?php echo esc_html( (string) $display['keep_text'] ); ?></button>
		<button type="submit" name="submex_choice" value="<?php echo esc_attr( Cancel_Flow::CONTINUE ); ?>" class="button submex-btn"><?php echo esc_html( (string) $display['continue_text'] ); ?></button>
	</div>
</form>
