<?php

namespace Submex\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tag lists for wp_kses() where markup is assembled before it is printed: icons, form rows and notices.
 */
final class Allowed_Html {

	private const ARIA = array( 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden', 'aria-expanded', 'aria-controls', 'aria-current', 'aria-live', 'aria-checked', 'aria-pressed', 'aria-haspopup', 'aria-selected', 'aria-invalid', 'aria-required', 'aria-disabled', 'aria-busy', 'aria-modal' );

	/**
	 * @return array<string, array<string, bool>>
	 */
	public static function svg(): array {
		$shape = array_fill_keys( array( 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'transform', 'class', 'opacity' ), true );

		return array(
			'svg'      => array_fill_keys( array( 'xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'aria-hidden', 'focusable', 'role', 'class' ), true ),
			'path'     => $shape,
			'rect'     => $shape,
			'circle'   => $shape,
			'ellipse'  => $shape,
			'line'     => $shape,
			'polyline' => $shape,
			'polygon'  => $shape,
			'g'        => $shape,
		);
	}

	/**
	 * Admin form markup: ours, and WooCommerce's own field output in the settings screen.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function form(): array {
		$global = array_fill_keys( array_merge( array( 'class', 'id', 'style', 'title', 'role', 'tabindex', 'hidden', 'lang', 'dir', 'data-*' ), self::ARIA ), true );
		$field  = array_fill_keys( array( 'name', 'value', 'type', 'checked', 'selected', 'disabled', 'readonly', 'required', 'multiple', 'placeholder', 'min', 'max', 'step', 'size', 'maxlength', 'minlength', 'pattern', 'autocomplete', 'rows', 'cols', 'for', 'form', 'accept', 'list', 'label', 'inputmode', 'spellcheck', 'wrap', 'formaction', 'formmethod', 'formnovalidate', 'formenctype', 'formtarget' ), true );

		$tags = array();

		$tags['tr'] = $global + array( 'valign' => true );

		foreach ( array( 'div', 'span', 'p', 'strong', 'b', 'em', 'i', 'small', 'code', 'br', 'hr', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'nav', 'section', 'header', 'footer', 'details', 'summary', 'fieldset', 'legend', 'table', 'thead', 'tbody', 'tfoot', 'mark', 'abbr', 'sup', 'sub', 'dl', 'dt', 'dd' ) as $tag ) {
			$tags[ $tag ] = $global;
		}

		foreach ( array( 'input', 'select', 'option', 'optgroup', 'textarea', 'label', 'button', 'datalist', 'output' ) as $tag ) {
			$tags[ $tag ] = $global + $field;
		}

		$tags['a']        = $global + array_fill_keys( array( 'href', 'target', 'rel', 'download' ), true );
		$tags['img']      = $global + array_fill_keys( array( 'src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes' ), true );
		$tags['th']       = $global + array_fill_keys( array( 'scope', 'colspan', 'rowspan', 'valign' ), true );
		$tags['td']       = $global + array_fill_keys( array( 'colspan', 'rowspan', 'valign' ), true );
		$tags['details']  = $global + array( 'open' => true );
		$tags['template'] = $global;
		$tags['form']     = $global + array_fill_keys( array( 'action', 'method', 'enctype', 'target', 'novalidate' ), true );

		return $tags + self::svg();
	}

	/**
	 * The rich text a merchant can write into retention screens and messages, and nothing more.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function rich_text(): array {
		$tags = array_fill_keys( array( 'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li' ), array() );

		$tags['a'] = array_fill_keys( array( 'href', 'target', 'rel' ), true );

		return $tags;
	}
}
