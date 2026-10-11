/* global jQuery, submexProductFields */
jQuery( function ( $ ) {
	var config = window.submexProductFields || {},
		$panel = $( '#woocommerce-product-data' ),
		$options = $panel.find( '.submex-product-options' ),
		$pricingSection = $panel.find( '.submex-section--pricing' ),
		$pricingRows = $pricingSection.find( '.form-field' ),
		$wooPricing = $panel.find( '#general_product_data .options_group.pricing' ).first(),
		$pricingTitle = $( '<h4 class="submex-section__title submex-pricing-title"></h4>' ).text( config.pricingTitle || '' );

	function paymentType() {
		var $select = $panel.find( '.' + config.paymentTypeClass + ' select' );

		return $select.length ? $select.val() : config.defaultType;
	}

	// A checkbox reads as yes or no, so _virtual=no can ask for WooCommerce's own Virtual box.
	function valueOf( $field ) {
		if ( $field.is( ':checkbox' ) ) {
			return $field.is( ':checked' ) ? 'yes' : 'no';
		}

		return String( $field.val() );
	}

	// data-submex-when="field_id=a|b" on any input in a row shows the row only for those values.
	function conditionsMet( $row ) {
		var met = true;

		$row.find( '[data-submex-when]' ).each( function () {
			var rule = String( $( this ).attr( 'data-submex-when' ) ).split( '=' ),
				$field = $( '#' + rule[ 0 ] ),
				allowed = ( rule[ 1 ] || '' ).split( '|' );

			if ( $field.length && allowed.indexOf( valueOf( $field ) ) === -1 ) {
				met = false;
			}
		} );

		return met;
	}

	// Hidden rows are disabled as well, so a value the merchant cannot see is never saved.
	function syncRows() {
		var current = 'submex-for-' + paymentType();

		$options.find( '.form-field' ).each( function () {
			var $row = $( this ),
				typed = /(^|\s)submex-for-/.test( this.className ),
				shown = ( ! typed || $row.hasClass( current ) ) && conditionsMet( $row );

			$row.toggleClass( 'submex-type-hidden', ! shown );
			$row.find( 'input, select, textarea' ).each( function () {
				var $input = $( this );

				if ( ! shown && ! $input.prop( 'disabled' ) ) {
					$input.prop( 'disabled', true ).attr( 'data-submex-disabled', '1' );
				} else if ( shown && $input.attr( 'data-submex-disabled' ) ) {
					$input.prop( 'disabled', false ).removeAttr( 'data-submex-disabled' );
				}
			} );
		} );
	}

	// A simple subscription's price fields are WooCommerce's; the sign-up fee joins them.
	function syncPricing() {
		var simple = $( '#product-type' ).val() === config.simpleType && $wooPricing.length;

		if ( simple ) {
			$wooPricing.prepend( $pricingTitle ).append( $pricingRows );
		} else {
			$pricingTitle.detach();
			$pricingSection.append( $pricingRows );
		}
	}

	function syncSections() {
		$panel.find( '.submex-section' ).each( function () {
			var $section = $( this ),
				visible = $section.find( '.form-field' ).filter( function () {
					return $( this ).css( 'display' ) !== 'none';
				} );

			$section.toggleClass( 'submex-section--empty', ! visible.length );
		} );
	}

	function sync() {
		syncPricing();
		syncRows();
		syncSections();
	}

	$panel.on( 'click', '.submex-section__toggle', function () {
		var $button = $( this ),
			open = $button.attr( 'aria-expanded' ) !== 'true';

		$button.attr( 'aria-expanded', open ? 'true' : 'false' );
		$button.closest( '.submex-section' ).find( '.submex-section__body' ).prop( 'hidden', ! open );
	} );

	$options.on( 'change', 'select, input', function () {
		syncRows();
		syncSections();
	} );

	$( '#_virtual' ).on( 'change', function () {
		syncRows();
		syncSections();
	} );

	// After WooCommerce's own show/hide for the new type, whichever handler ran first.
	$( document.body ).on( 'woocommerce-product-type-change', function () {
		window.setTimeout( sync, 0 );
	} );

	sync();
} );
