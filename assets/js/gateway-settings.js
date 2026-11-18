( function () {
	'use strict';

	const HIDDEN = 'wc-settings-row-submex-hidden';
	const data = document.getElementById( 'submex-gateway-when' );
	let rows = {};

	try {
		rows = data ? JSON.parse( data.textContent ) : {};
	} catch ( error ) {
		rows = {};
	}

	function valueOf( id ) {
		const field = document.getElementById( id );

		if ( ! field ) {
			return '';
		}

		if ( 'checkbox' === field.type ) {
			return field.checked ? 'yes' : 'no';
		}

		return field.value;
	}

	function shown( when ) {
		return Object.keys( when ).every( function ( id ) {
			return -1 !== when[ id ].indexOf( valueOf( id ) );
		} );
	}

	function update() {
		Object.keys( rows ).forEach( function ( id ) {
			const field = document.getElementById( id );
			const row = field && field.closest( 'tr' );

			if ( row ) {
				row.classList.toggle( HIDDEN, ! shown( rows[ id ] ) );
			}
		} );

		document
			.querySelectorAll( '.submex-gateway-card[data-submex-show-when]' )
			.forEach( function ( card ) {
				try {
					card.hidden = ! shown(
						JSON.parse(
							card.getAttribute( 'data-submex-show-when' )
						)
					);
				} catch ( error ) {
					card.hidden = false;
				}
			} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.closest( '#mainform' ) ) {
			update();
		}
	} );

	// A restored form (back, forward, reload) can hold a different choice than the page was drawn for.
	window.addEventListener( 'pageshow', update );
	update();
} )();
