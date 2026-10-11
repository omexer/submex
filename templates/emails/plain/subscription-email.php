<?php
/**
 * Submex email body (plain text).
 *
 * @var string     $email_heading
 * @var string     $intro
 * @var array      $facts
 * @var array|null $cta
 * @var string     $outro
 * @var string     $additional_content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Escape for the linter, then decode: entities like &#039; are correct in HTML and
 * simply wrong in a plain-text body.
 */
$submex_text = static function ( $value ): string {
	return wp_specialchars_decode( esc_html( wp_strip_all_tags( (string) $value ) ), ENT_QUOTES );
};

echo '= ' . $submex_text( $email_heading ) . " =\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $submex_text( $intro ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

foreach ( $facts as $submex_label => $submex_value ) {
	if ( '' === $submex_value ) {
		continue;
	}
	echo $submex_text( $submex_label ) . ': ' . $submex_text( $submex_value ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( ! empty( $cta['url'] ) ) {
	echo "\n" . $submex_text( $cta['label'] ) . ":\n" . esc_url_raw( $cta['url'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( '' !== $outro ) {
	echo "\n" . $submex_text( $outro ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( ! empty( $additional_content ) ) {
	echo "\n" . $submex_text( $additional_content ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "\n----------\n\n";
echo $submex_text( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
