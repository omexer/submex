<?php
/**
 * Customer subscription list. Cards on mobile, table on wide screens.
 *
 * Override: yourtheme/submex/myaccount/subscriptions.php
 *
 * @var \Submex\Domain\Subscription[] $subscriptions
 * @var string                        $endpoint
 */

defined( 'ABSPATH' ) || exit;

use Submex\Frontend\MyAccount\Status_Presenter;

if ( empty( $subscriptions ) ) : ?>
	<div class="submex-empty">
		<p><?php esc_html_e( "You don't have any subscriptions.", 'submex' ); ?></p>
		<a class="woocommerce-Button button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<?php esc_html_e( 'Browse products', 'submex' ); ?>
		</a>
	</div>
	<?php return; ?>
<?php endif; ?>

<ul class="submex">
	<?php
	foreach ( $subscriptions as $submex_subscription ) :
		$submex_state = Status_Presenter::for( $submex_subscription );
		?>
		<li class="submex-card submex-card--<?php echo esc_attr( $submex_state['tone'] ); ?>">
			<div class="submex-card__head">
				<h3 class="submex-card__title">
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint . '/' . $submex_subscription->get_id() ) ); ?>">
						<?php echo esc_html( Status_Presenter::title( $submex_subscription ) ); ?>
					</a>
				</h3>
				<span class="submex-badge submex-badge--<?php echo esc_attr( $submex_state['tone'] ); ?>">
					<?php echo esc_html( $submex_state['label'] ); ?>
				</span>
			</div>

			<p class="submex-card__price"><?php echo wp_kses_post( $submex_subscription->get_formatted_order_total() ); ?></p>

			<?php if ( $submex_state['detail'] ) : ?>
				<p class="submex-card__detail"><?php echo esc_html( $submex_state['detail'] ); ?></p>
			<?php endif; ?>

			<div class="submex-card__actions">
				<?php $submex_action = \Submex\Frontend\MyAccount\Status_Presenter::primary_action( $submex_subscription ); ?>
				<?php if ( $submex_action ) : ?>
					<a class="button submex-btn submex-btn--primary" href="<?php echo esc_url( $submex_action['url'] ); ?>">
						<?php echo esc_html( $submex_action['label'] ); ?>
					</a>
				<?php endif; ?>
				<a class="submex-card__link" href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint . '/' . $submex_subscription->get_id() ) ); ?>">
					<?php esc_html_e( 'View details', 'submex' ); ?>
				</a>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
