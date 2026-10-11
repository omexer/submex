/**
 * The notices on an app page: important Submex ones stay under the header as compact cards, the rest go in the bell.
 */
import { __ } from '@wordpress/i18n';

export const NOTICES = 'div.notice, div.error, div.updated';
export const IMPORTANT = 'submex-notice--important';
export const FEEDBACK = 'submex-notice--feedback';
export const SHOWN = 3;

const LONG = 100;
const SKIP = 'script, style, link, template';

const ICONS = {
	error: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
	warning:
		'<path d="M10.3 4.2 2.6 17.5a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
	success:
		'<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
	info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5h.01"/>',
};

let ids = 0;

export function isNotice( node ) {
	return (
		node instanceof window.HTMLElement &&
		node.matches( NOTICES ) &&
		! node.matches( '.inline, .below-h2' ) &&
		! node.parentElement?.closest( NOTICES )
	);
}

function severity( node ) {
	const has = ( name ) => node.classList.contains( name );

	if ( has( 'notice-error' ) || has( 'error' ) ) {
		return 'error';
	}

	if ( has( 'notice-warning' ) ) {
		return 'warning';
	}

	return has( 'notice-success' ) || has( 'updated' ) ? 'success' : 'info';
}

function textBefore( parent, child ) {
	for ( const node of parent.childNodes ) {
		if ( node === child ) {
			return false;
		}

		if ( node.textContent.trim() ) {
			return true;
		}
	}

	return false;
}

/**
 * Takes the title out of a notice's text: its leading bold words, or else its first sentence.
 *
 * @param {Element} detail
 * @return {string} The title.
 */
function takeTitle( detail ) {
	const first = detail.querySelector( 'p' ) || detail;
	const lead = first.firstElementChild;

	if ( lead && lead.matches( 'strong, b' ) && ! textBefore( first, lead ) ) {
		lead.remove();
		return lead.textContent.trim();
	}

	const text = first.firstChild;

	if ( ! text || window.Node.TEXT_NODE !== text.nodeType ) {
		return first.textContent.trim().split( /(?<=[.!?])\s/ )[ 0 ];
	}

	const sentence = text.textContent.match( /^\s*(.+?[.!?])\s+(?=\S)/s );

	if ( sentence ) {
		text.textContent = text.textContent.slice( sentence[ 0 ].length );
		return sentence[ 1 ];
	}

	text.remove();
	return text.textContent.trim();
}

function dropEmpty( detail ) {
	detail.querySelectorAll( 'p' ).forEach( ( p ) => {
		if ( ! p.textContent.trim() && ! p.querySelector( '*' ) ) {
			p.remove();
		}
	} );
}

/**
 * Redraws an important notice as one dense card, keeping its own links, buttons and dismissal working.
 *
 * @param {Element} node
 */
export function compact( node ) {
	if ( node.classList.contains( 'submex-alert' ) ) {
		return;
	}

	const tone = severity( node );
	const dismiss = node.querySelector(
		'a[href*="action=submex_dismiss_notice"]'
	);
	const actions = [
		...node.querySelectorAll( 'a.button, button.button, input.button' ),
	].filter( ( el ) => el !== dismiss && ! el.closest( 'li' ) );
	const closer = [ ...node.children ].filter( ( el ) =>
		el.classList.contains( 'notice-dismiss' )
	);

	const icon = document.createElement( 'span' );
	const text = document.createElement( 'div' );
	const title = document.createElement( 'p' );
	const detail = document.createElement( 'div' );
	const bar = document.createElement( 'div' );

	icon.className = 'submex-alert__icon';
	icon.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${ ICONS[ tone ] }</svg>`;
	text.className = 'submex-alert__text';
	text.id = `submex-alert-${ ++ids }`;
	title.className = 'submex-alert__title';
	detail.className = 'submex-alert__detail';
	bar.className = 'submex-alert__actions';

	[ ...node.childNodes ]
		.filter( ( child ) => ! closer.includes( child ) )
		.forEach( ( child ) => detail.appendChild( child ) );

	actions.forEach( ( action ) => bar.appendChild( action ) );

	if ( dismiss ) {
		const label = document.createElement( 'span' );

		label.className = 'screen-reader-text';
		label.textContent = dismiss.textContent.trim();
		dismiss.className = 'submex-alert__dismiss';
		dismiss.innerHTML =
			'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>';
		dismiss.appendChild( label );
		bar.appendChild( dismiss );
	}

	title.textContent = takeTitle( detail );
	dropEmpty( detail );

	const rest = detail.textContent.trim();

	text.appendChild( title );

	if ( rest ) {
		text.appendChild( detail );
	}

	if (
		rest.length > LONG ||
		title.textContent.length > LONG ||
		detail.querySelectorAll( 'li' ).length > 1 ||
		detail.querySelector( 'a, button, input, select' )
	) {
		const more = document.createElement( 'button' );

		more.type = 'button';
		more.className = 'submex-alert__more';
		more.textContent = __( 'Details', 'submex' );
		more.setAttribute( 'aria-expanded', 'false' );
		more.setAttribute( 'aria-controls', text.id );
		more.addEventListener( 'click', () => {
			const open = node.classList.toggle( 'is-open' );
			more.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		bar.prepend( more );
	}

	node.classList.add( 'submex-alert', `submex-alert--${ tone }` );
	node.prepend( icon, text, bar );
	closer.forEach( ( el ) => node.appendChild( el ) );
}

/**
 * Tracks the page's notices, placing important ones in `alerts` and the rest in the notification centre's `list`.
 *
 * @param {Object}   options
 * @param {Element}  options.alerts   Where important notices go.
 * @param {Element}  options.list     The notification centre.
 * @param {Function} options.isRoute  Whether a node belongs to the route on screen, which keeps its own.
 * @param {Function} options.onChange Called with the counts whenever they change.
 * @return {Object} The controller.
 */
export function createNotices( { alerts, list, isRoute, onChange } ) {
	const important = [];
	const other = [];
	const restorers = new Map();
	let observer = null;
	let last = '';

	const tracked = ( node ) => restorers.has( node );

	function insideTracked( node ) {
		for ( let el = node.parentElement; el; el = el.parentElement ) {
			if ( tracked( el ) ) {
				return true;
			}
		}

		return false;
	}

	function take( node ) {
		if ( tracked( node ) ) {
			return false;
		}

		const parent = node.parentNode;
		const next = node.nextSibling;

		restorers.set( node, () => {
			if ( parent && parent.isConnected ) {
				parent.insertBefore(
					node,
					next && next.parentNode === parent ? next : null
				);
			}
		} );

		if ( isNotice( node ) && node.classList.contains( IMPORTANT ) ) {
			compact( node );
			important.push( node );
		} else {
			other.push( node );
		}

		return true;
	}

	function candidate( node ) {
		return (
			isNotice( node ) &&
			node.isConnected &&
			! isRoute( node ) &&
			! insideTracked( node )
		);
	}

	function prune( nodes ) {
		for ( let i = nodes.length - 1; i >= 0; i-- ) {
			if ( ! nodes[ i ].isConnected ) {
				restorers.delete( nodes[ i ] );
				nodes.splice( i, 1 );
			}
		}
	}

	function place( nodes, host ) {
		nodes.forEach( ( node, index ) => {
			if ( host.children[ index ] !== node ) {
				host.insertBefore( node, host.children[ index ] || null );
			}
		} );
	}

	function counts() {
		const more = Math.max( 0, important.length - SHOWN );

		return { more, centre: more + other.length };
	}

	function layout() {
		prune( important );
		prune( other );
		place( important.slice( 0, SHOWN ), alerts );
		place( [ ...important.slice( SHOWN ), ...other ], list );

		const now = counts();
		const key = JSON.stringify( now );

		if ( key !== last ) {
			last = key;
			onChange( now );
		}
	}

	return {
		counts,

		/**
		 * Takes every notice under root, and what the server's own notification centre holds.
		 *
		 * @param {Element} root
		 */
		collect( root ) {
			let changed = false;

			root.querySelectorAll( '.submex-notify__list' ).forEach(
				( server ) => {
					if ( server === list ) {
						return;
					}

					[ ...server.children ]
						.filter( ( el ) => ! el.matches( SKIP ) )
						.forEach( ( el ) => {
							changed = take( el ) || changed;
						} );
				}
			);

			root.querySelectorAll( NOTICES ).forEach( ( node ) => {
				if ( candidate( node ) ) {
					changed = take( node ) || changed;
				}
			} );

			if ( changed ) {
				layout();
			}
		},

		/**
		 * Catches notices printed or moved after load, and notices that go away.
		 *
		 * @param {Element} root
		 */
		watch( root ) {
			observer = new window.MutationObserver( ( records ) => {
				let changed = false;

				records.forEach( ( record ) => {
					record.addedNodes.forEach( ( added ) => {
						if ( ! ( added instanceof window.HTMLElement ) ) {
							return;
						}

						// WordPress moves every notice under its header marker, ours included.
						if ( tracked( added ) ) {
							changed = true;
							return;
						}

						[ added, ...added.querySelectorAll( NOTICES ) ]
							.filter( candidate )
							.forEach( ( node ) => {
								changed = take( node ) || changed;
							} );
					} );

					record.removedNodes.forEach( ( removed ) => {
						changed = changed || tracked( removed );
					} );
				} );

				if ( changed ) {
					layout();
				}
			} );

			observer.observe( root, { childList: true, subtree: true } );
		},

		/**
		 * What the merchant just did on the last page is not news on this one.
		 */
		dropFeedback() {
			important
				.filter( ( node ) => node.classList.contains( FEEDBACK ) )
				.forEach( ( node ) => node.remove() );
			layout();
		},

		stop() {
			observer?.disconnect();
		},

		/**
		 * Puts every notice back where the server printed it.
		 */
		restore() {
			observer?.disconnect();
			[ ...restorers.values() ].reverse().forEach( ( put ) => put() );
			restorers.clear();
			important.length = 0;
			other.length = 0;
		},
	};
}
