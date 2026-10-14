<?php
/**
 * The store's confirmation after a cancel, pause or accepted offer, shown once above the subscription.
 *
 * Override: yourtheme/woocommerce/myaccount/retention-message.php
 *
 * @var \Submex\Domain\Subscription $subscription
 * @var string                      $title
 * @var string                      $description Rich text; escaped below.
 * @var string                      $button_text
 * @var string                      $close_url
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="submex-message" role="status">
	<span class="submex-message__icon" aria-hidden="true">&#10003;</span>
	<h2 class="submex-message__title"><?php echo esc_html( $title ); ?></h2>
	<div class="submex-message__description"><?php echo wp_kses( wpautop( $description ), \Submex\Admin\Allowed_Html::rich_text() ); ?></div>
	<p class="submex-message__close">
		<a class="button submex-btn" href="<?php echo esc_url( $close_url ); ?>"><?php echo esc_html( $button_text ); ?></a>
	</p>
</div>
