<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A gateway's numbered setup guide from a `submex_setup` field: title, done, desc and steps (label, detail, done or filled, submex_show_when, action, copy, list).
 */
final class Setup_Card {

	public const TYPE = 'submex_setup';

	/**
	 * The steps for the values given, each with its state: done, current, next or manual.
	 *
	 * @param array<string, mixed>  $field
	 * @param array<string, string> $values
	 * @return array<int, array<string, mixed>>
	 */
	public static function steps( array $field, array $values ): array {
		$steps   = array();
		$current = false;

		foreach ( (array) ( $field['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) || ! Settings_Page::shown_when( Settings_Page::when_of( $step ), $values ) ) {
				continue;
			}

			$done = self::is_done( $step, $values );

			if ( null === $done ) {
				$step['state'] = 'manual';
			} elseif ( $done ) {
				$step['state'] = 'done';
			} else {
				$step['state'] = $current ? 'next' : 'current';
				$current       = true;
			}

			$steps[] = $step;
		}

		return $steps;
	}

	/**
	 * Null when nothing here can tell.
	 *
	 * @param array<string, mixed>  $step
	 * @param array<string, string> $values
	 */
	private static function is_done( array $step, array $values ): ?bool {
		$filled = array_map( 'strval', (array) ( $step['filled'] ?? array() ) );

		if ( ! $filled ) {
			return isset( $step['done'] ) ? (bool) $step['done'] : null;
		}

		foreach ( $filled as $id ) {
			if ( ! self::has_value( (string) ( $values[ $id ] ?? '' ) ) ) {
				return false;
			}
		}

		return ! isset( $step['done'] ) || (bool) $step['done'];
	}

	private static function has_value( string $value ): bool {
		return '' !== trim( $value ) && 'no' !== $value;
	}

	/**
	 * @param array<string, mixed>  $field
	 * @param array<string, string> $values
	 */
	public static function markup( array $field, array $values ): string {
		$steps = self::steps( $field, $values );

		if ( ! $steps ) {
			return '';
		}

		$checked  = array_filter( $steps, static fn( array $step ): bool => 'manual' !== $step['state'] );
		$done     = count( array_filter( $checked, static fn( array $step ): bool => 'done' === $step['state'] ) );
		$complete = $checked && count( $checked ) === $done;
		$title    = $complete && ! empty( $field['done'] ) ? (string) $field['done'] : (string) ( $field['title'] ?? '' );
		$desc     = (string) ( $field['desc'] ?? '' );

		$html  = '<div class="submex-setup' . ( $complete ? ' is-complete' : '' ) . '">';
		$html .= '<div class="submex-setup__head"><div class="submex-setup__intro">';
		$html .= '<h3 class="submex-setup__title">' . esc_html( $title ) . '</h3>';
		$html .= '' === $desc ? '' : '<p class="submex-setup__desc">' . wp_kses_post( $desc ) . '</p>';
		$html .= '</div>';

		if ( $checked ) {
			/* translators: 1: steps done, 2: steps that can be checked */
			$html .= '<span class="submex-setup__count">' . esc_html( sprintf( __( '%1$d of %2$d done', 'submex' ), $done, count( $checked ) ) ) . '</span>';
		}

		$html .= '</div><ol class="submex-setup__steps">';

		foreach ( $steps as $index => $step ) {
			$html .= self::step( $step, $index + 1, self::id_of( $field ) );
		}

		return $html . '</ol></div>';
	}

	/**
	 * @param array<string, mixed> $step
	 */
	private static function step( array $step, int $number, string $card ): string {
		$state = (string) $step['state'];
		$said  = array(
			'done'    => __( 'done', 'submex' ),
			'current' => __( 'to do', 'submex' ),
			'next'    => __( 'to do', 'submex' ),
			'manual'  => '',
		)[ $state ];

		$html  = '<li class="submex-setup__step is-' . esc_attr( $state ) . '">';
		$html .= '<span class="submex-setup__marker" aria-hidden="true">' . ( 'done' === $state ? '&#10003;' : esc_html( (string) $number ) ) . '</span>';
		$html .= '<div class="submex-setup__text"><span class="submex-setup__label">' . esc_html( (string) ( $step['label'] ?? '' ) );
		$html .= '' === $said ? '' : '<span class="screen-reader-text"> (' . esc_html( $said ) . ')</span>';
		$html .= '</span>';

		if ( '' !== (string) ( $step['detail'] ?? '' ) ) {
			$html .= '<span class="submex-setup__detail">' . wp_kses_post( (string) $step['detail'] ) . '</span>';
		}

		$html .= self::copy_box( (array) ( $step['copy'] ?? array() ), $card . '-copy-' . $number );
		$html .= self::list( (array) ( $step['list'] ?? array() ) );
		$html .= '</div>';

		$action = (array) ( $step['action'] ?? array() );

		// A later step cannot work until this one is done, and a done one needs nothing.
		if ( '' !== (string) ( $action['url'] ?? '' ) && in_array( $state, array( 'current', 'manual' ), true ) ) {
			$html .= self::button( $action, 'current' === $state );
		}

		return $html . '</li>';
	}

	/**
	 * @param array<string, mixed> $copy
	 */
	private static function copy_box( array $copy, string $id ): string {
		if ( '' === (string) ( $copy['value'] ?? '' ) ) {
			return '';
		}

		$id = sanitize_html_class( $id );

		return sprintf(
			'<div class="submex-setup__copy"><label class="submex-setup__copy-label" for="%1$s">%2$s</label><div class="submex-setup__copy-row"><input type="text" id="%1$s" class="submex-input submex-setup__copy-input" value="%3$s" readonly /><button type="button" class="submex-btn submex-btn--sm submex-setup__copy-button" data-submex-copy="%1$s">%4$s</button></div></div>',
			esc_attr( $id ),
			esc_html( (string) ( $copy['label'] ?? '' ) ),
			esc_attr( (string) $copy['value'] ),
			esc_html__( 'Copy', 'submex' )
		);
	}

	/**
	 * @param array<string, mixed> $list
	 */
	private static function list( array $list ): string {
		$items = array_map( 'strval', (array) ( $list['items'] ?? array() ) );

		if ( ! $items ) {
			return '';
		}

		$html = '<details class="submex-setup__list"><summary>' . esc_html( (string) ( $list['summary'] ?? '' ) ) . '</summary><ul>';

		foreach ( $items as $item ) {
			$html .= '<li><code>' . esc_html( $item ) . '</code></li>';
		}

		return $html . '</ul></details>';
	}

	/**
	 * @param array<string, mixed> $action
	 */
	private static function button( array $action, bool $primary ): string {
		$class = $primary ? 'submex-btn submex-btn--primary submex-btn--sm' : 'submex-btn submex-btn--sm';

		if ( empty( $action['external'] ) ) {
			return sprintf( '<a class="%s" href="%s">%s</a>', esc_attr( $class ), esc_url( (string) $action['url'] ), esc_html( (string) ( $action['text'] ?? '' ) ) );
		}

		return self::external_link( (string) $action['url'], (string) ( $action['text'] ?? '' ), $class );
	}

	/**
	 * A link to the provider's own site, which opens in a new tab and says so.
	 */
	public static function external_link( string $url, string $text, string $class = '' ): string {
		return sprintf(
			'<a%s href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
			'' === $class ? '' : ' class="' . esc_attr( $class ) . '"',
			esc_url( $url ),
			esc_html( $text ),
			esc_html__( '(opens in a new tab)', 'submex' )
		);
	}

	/**
	 * The card has no option, and so no id of its own; its copy boxes need one for their labels.
	 *
	 * @param array<string, mixed> $field
	 */
	private static function id_of( array $field ): string {
		return 'submex-setup-' . sanitize_key( (string) ( $field['title'] ?? '' ) );
	}

	/**
	 * The card as data, for the app to draw and tick as fields fill in.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	public static function describe( array $field ): array {
		$steps = array();

		foreach ( (array) ( $field['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$action = (array) ( $step['action'] ?? array() );
			$copy   = (array) ( $step['copy'] ?? array() );
			$list   = (array) ( $step['list'] ?? array() );

			$steps[] = array(
				'label'            => (string) ( $step['label'] ?? '' ),
				'detail'           => wp_kses_post( (string) ( $step['detail'] ?? '' ) ),
				'done'             => isset( $step['done'] ) ? (bool) $step['done'] : null,
				'filled'           => array_values( array_map( 'strval', (array) ( $step['filled'] ?? array() ) ) ),
				'submex_show_when' => (object) Settings_Page::when_of( $step ),
				'action'           => '' === (string) ( $action['url'] ?? '' ) ? null : array(
					'url'      => esc_url_raw( (string) $action['url'] ),
					'text'     => (string) ( $action['text'] ?? '' ),
					'external' => ! empty( $action['external'] ),
				),
				'copy'             => '' === (string) ( $copy['value'] ?? '' ) ? null : array(
					'label' => (string) ( $copy['label'] ?? '' ),
					'value' => (string) $copy['value'],
				),
				'list'             => empty( $list['items'] ) ? null : array(
					'summary' => (string) ( $list['summary'] ?? '' ),
					'items'   => array_values( array_map( 'strval', (array) $list['items'] ) ),
				),
			);
		}

		return array(
			'id'    => self::id_of( $field ),
			'title' => (string) ( $field['title'] ?? '' ),
			'done'  => (string) ( $field['done'] ?? '' ),
			'desc'  => wp_kses_post( (string) ( $field['desc'] ?? '' ) ),
			'steps' => $steps,
		);
	}
}
