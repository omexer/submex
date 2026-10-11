( function () {
	'use strict';

	var config = window.submexAdmin || {};

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.submex-install' );

		if ( ! button || ! config.installAction ) {
			return;
		}

		event.preventDefault();

		var cell = button.parentNode;
		var body = new FormData();

		body.append( 'action', config.installAction );
		body.append( '_wpnonce', config.installNonce );
		body.append( 'slug', button.dataset.slug );

		button.disabled = true;
		button.textContent = config.i18n.installing;

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( result ) {
				if ( result && result.success ) {
					// The row's status comes from PHP, so reload rather than guess at it.
					window.location.reload();
					return;
				}

				button.disabled = false;
				button.textContent = config.i18n.install;
				cell.appendChild( notice( ( result && result.data && result.data.message ) || config.i18n.failed ) );
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = config.i18n.install;
				cell.appendChild( notice( config.i18n.failed ) );
			} );
	} );

	/**
	 * Row actions post over fetch when the screen has told us how; otherwise the button is
	 * an ordinary submit and the page reloads, which is what happens with no JavaScript.
	 */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.submex-row-action' );

		if ( ! button || ! config.rowAction || ! button.dataset.submexAction ) {
			return;
		}

		event.preventDefault();

		var row = button.closest( 'tr' );
		var body = new FormData();

		body.append( 'action', config.rowAction );
		body.append( '_wpnonce', config.rowNonce );
		body.append( 'health_action', button.dataset.submexAction );
		body.append( 'subscription', button.dataset.submexId );

		setBusy( row, true );

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( result ) {
				setBusy( row, false );

				if ( result && result.success ) {
					flash( row, ( result.data && result.data.message ) || config.i18n.done, true );
					return;
				}

				flash( row, ( result && result.data && result.data.message ) || config.i18n.failed, false );
			} )
			.catch( function () {
				setBusy( row, false );
				flash( row, config.i18n.failed, false );
			} );
	} );

	function setBusy( row, busy ) {
		if ( ! row ) {
			return;
		}

		row.classList.toggle( 'submex-row--busy', busy );
		row.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = busy; } );
	}

	function flash( row, message, good ) {
		if ( ! row ) {
			return;
		}

		var cell = row.querySelector( 'td:last-child' ) || row;
		var existing = cell.querySelector( '.submex-row-result' );

		if ( existing ) {
			existing.remove();
		}

		var span = document.createElement( 'span' );

		span.className = 'submex-row-result ' + ( good ? 'is-good' : 'is-bad' );
		span.textContent = message;
		cell.appendChild( span );

		row.classList.toggle( 'submex-row--done', !! good );
	}

	function notice( message ) {
		var span = document.createElement( 'span' );

		span.className = 'submex-inline-error';
		span.textContent = ' ' + message;

		return span;
	}
}() );


// Copy buttons: data-submex-copy names the field to copy from.
document.addEventListener( 'click', function ( event ) {
	var button = event.target.closest( '[data-submex-copy]' );
	var field = button && document.getElementById( button.getAttribute( 'data-submex-copy' ) );

	if ( ! field ) {
		return;
	}

	var done = function () {
		var label = button.textContent;
		button.textContent = button.getAttribute( 'data-submex-copied' ) || label;
		setTimeout( function () {
			button.textContent = label;
		}, 1600 );
	};

	if ( navigator.clipboard && window.isSecureContext ) {
		navigator.clipboard.writeText( field.value ).then( done );
		return;
	}

	// Plain http admin screens have no clipboard API; selecting is the next best thing.
	field.select();
	document.execCommand( 'copy' );
	done();
} );

// The server header's notification centre opens without script; this only closes it like a menu.
( function () {
	function close( keepFocus ) {
		document.querySelectorAll( 'details[data-submex-notify][open]' ).forEach( function ( details ) {
			details.open = false;

			if ( keepFocus ) {
				details.querySelector( 'summary' ).focus();
			}
		} );
	}

	document.addEventListener( 'mousedown', function ( event ) {
		if ( ! event.target.closest( 'details[data-submex-notify]' ) ) {
			close( false );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && document.querySelector( 'details[data-submex-notify][open]' ) ) {
			close( true );
		}
	} );
} )();

// Settings rows marked data-submex-show-if="<switch id>" show only while that switch, and its own parent, is on.
( function () {
	var rows = document.querySelectorAll( '[data-submex-show-if]' );

	if ( ! rows.length ) {
		return;
	}

	function shown( row, depth ) {
		var parent = document.getElementById( row.getAttribute( 'data-submex-show-if' ) );

		if ( ! parent || 'checkbox' !== parent.type || depth > 10 ) {
			return true;
		}

		var parentRow = parent.closest( '[data-submex-show-if]' );

		return parent.checked && ( ! parentRow || shown( parentRow, depth + 1 ) );
	}

	function sync() {
		rows.forEach( function ( row ) {
			row.classList.toggle( 'is-hidden', ! shown( row, 0 ) );
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && 'checkbox' === event.target.type ) {
			sync();
		}
	} );

	sync();
}() );
