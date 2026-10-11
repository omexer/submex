/**
 * The route registry and the URL it keeps in step: every route is a real admin.php?page= address.
 */
const routes = new Map();
const listeners = new Set();
let location = null;

// Real page loads, on an object so tests can watch them without leaving the page.
export const browser = {
	load: ( href ) => window.location.assign( href ),
	reload: () => window.location.reload(),
};

export function registerRoute( { page, title, render } ) {
	routes.set( page, { page, title, render } );
}

export function getRoute( page ) {
	return routes.get( page ) || null;
}

export function hasRoute( page ) {
	return routes.has( page );
}

function toParams( input ) {
	if ( input instanceof URLSearchParams ) {
		return new URLSearchParams( input );
	}

	const params = new URLSearchParams();

	Object.entries( input || {} ).forEach( ( [ key, value ] ) => {
		if (
			null !== value &&
			undefined !== value &&
			'' !== value &&
			false !== value
		) {
			params.set( key, String( value ) );
		}
	} );

	params.delete( 'page' );

	return params;
}

function adminPath() {
	return new URL( 'admin.php', window.location.href ).pathname;
}

/**
 * The route an address points at, or null when it is not an admin.php?page= address on this site.
 *
 * @param {string} href
 * @return {{page: string, params: URLSearchParams, hash: string}|null} The route.
 */
export function parse( href ) {
	let url;

	try {
		url = new URL( href, window.location.href );
	} catch ( error ) {
		return null;
	}

	const page = url.searchParams.get( 'page' );

	if (
		url.origin !== window.location.origin ||
		url.pathname !== adminPath() ||
		! page
	) {
		return null;
	}

	const params = new URLSearchParams( url.search );
	params.delete( 'page' );

	return { page, params, hash: url.hash };
}

export function hrefFor( page, params ) {
	const url = new URL( 'admin.php', window.location.href );
	const query = new URLSearchParams( { page } );

	toParams( params ).forEach( ( value, key ) => query.append( key, value ) );
	url.search = query.toString();

	return url.toString();
}

export function current() {
	return location
		? {
				page: location.page,
				params: new URLSearchParams( location.params ),
		  }
		: null;
}

export function subscribe( listener ) {
	listeners.add( listener );

	return () => listeners.delete( listener );
}

function commit( page, params, kind ) {
	location = { page, params };
	listeners.forEach( ( listener ) => listener( { ...current(), kind } ) );
}

export function navigate( page, params = {} ) {
	const href = hrefFor( page, params );

	if ( ! routes.has( page ) ) {
		browser.load( href );
		return;
	}

	window.history.pushState( { submex: page }, '', href );
	commit( page, toParams( params ), 'navigate' );
}

export function setParams( params, { replace = false } = {} ) {
	if ( ! location ) {
		return;
	}

	const href = hrefFor( location.page, params );

	window.history[ replace ? 'replaceState' : 'pushState' ](
		{ submex: location.page },
		'',
		href
	);
	commit( location.page, toParams( params ), 'params' );
}

function onPopState() {
	const next = parse( window.location.href );

	if ( ! next || ! routes.has( next.page ) ) {
		browser.reload();
		return;
	}

	// A fragment link on the same screen pops too, and should not redraw it.
	if (
		location &&
		next.page === location.page &&
		next.params.toString() === location.params.toString()
	) {
		return;
	}

	commit( next.page, next.params, 'pop' );
}

export function start( page ) {
	const here = parse( window.location.href );

	location = { page, params: here ? here.params : new URLSearchParams() };
	window.addEventListener( 'popstate', onPopState );
}
