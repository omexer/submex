<?php
/**
 * Submex email body (HTML).
 *
 * Override at yourtheme/woocommerce/emails/subscription-email.php
 *
 * @var string      $email_heading
 * @var string      $intro
 * @var array       $facts
 * @var array|null  $cta
 * @var string      $outro
 * @var string      $additional_content
 */

/** @var \WC_Email $email Supplied by WC_Email::get_content_html(). */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
?>

<p><?php echo wp_kses_post( $intro ); ?></p>

<?php if ( ! empty( $facts ) ) : ?>
	<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;margin-bottom:1.5em" border="1">
		<tbody>
		<?php foreach ( $facts as $submex_label => $submex_value ) : ?>
			<?php
			if ( '' === $submex_value ) {
				continue; }
			?>
			<tr>
				<th scope="row" style="text-align:left;width:40%;border:1px solid #e5e5e5;padding:8px"><?php echo esc_html( $submex_label ); ?></th>
				<td style="border:1px solid #e5e5e5;padding:8px"><?php echo esc_html( $submex_value ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( ! empty( $cta['url'] ) ) : ?>
	<p style="margin:1.5em 0">
		<a href="<?php echo esc_url( $cta['url'] ); ?>" style="display:inline-block;padding:12px 22px;background:#7f54b3;color:#fff;text-decoration:none;border-radius:4px">
			<?php echo esc_html( $cta['label'] ); ?>
		</a>
	</p>
<?php endif; ?>

<?php if ( '' !== $outro ) : ?>
	<p><?php echo wp_kses_post( $outro ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $additional_content ) ) : ?>
	<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
