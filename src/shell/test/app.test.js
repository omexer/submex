/**
 * The shell hides the server render once a route draws, removes it on the first move, and
 * puts it back when the first route cannot draw.
 */
const ADMIN = 'http://localhost/wp-admin/admin.php';

let router;
let app;
let added;
let spies;
let root;
let act;

const SERVER = `
	<ul id="adminmenu"><li><ul class="wp-submenu">
		<li class="current"><a class="current" aria-current="page" href="admin.php?page=first">First</a></li>
		<li><a href="admin.php?page=second">Second</a></li>
	</ul></li></ul>
	<div id="wpbody-content">
		<div id="submex-app" class="submex-ui" data-page="first"></div><div id="submex-fallback">
			<header><details class="submex-notify" data-submex-notify><summary class="submex-notify__bell">1</summary>
				<div class="submex-notify__panel" id="submex-other-notices"><div class="submex-notify__list"><div class="notice notice-info"><p>Elsewhere</p></div></div></div>
			</details></header>
			<div class="wrap">
				<h1>First</h1>
				<hr class="wp-header-end">
				<div class="notice notice-success submex-notice--important submex-notice--feedback"><p>Saved.</p></div>
				<p>Server first</p>
				<div class="notice inline"><p>Inline hint</p></div>
			</div>
		</div>
	</div>
`;

function track( target ) {
	const add = target.addEventListener.bind( target );
	spies.push( jest.spyOn( target, 'addEventListener' ) );
	spies[ spies.length - 1 ].mockImplementation( ( type, fn, options ) => {
		added.push( [ target, type, fn ] );
		add( type, fn, options );
	} );
}

async function boot( routes ) {
	jest.resetModules();
	// The same React the fresh shell renders with, or act() would not cover it.
	act = require( 'react' ).act;
	router = require( '../router' );
	app = require( '../app' );
	routes.forEach( ( route ) => router.registerRoute( route ) );

	await act( async () => {
		root = app.boot();
	} );
}

async function click( node ) {
	await act( async () => {
		node.dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
				button: 0,
			} )
		);
	} );
}

const fallback = () => document.getElementById( 'submex-fallback' );
const host = () => document.getElementById( 'submex-app' );
const menu = ( text ) =>
	[ ...document.querySelectorAll( '#adminmenu a' ) ].find(
		( a ) => a.textContent === text
	);

function Fails( { fail } ) {
	require( '@wordpress/element' ).useEffect( () => fail(), [ fail ] );
	return <p>Trying</p>;
}

beforeEach( () => {
	added = [];
	spies = [];
	track( window );
	track( document );
	window.history.replaceState( {}, '', `${ ADMIN }?page=first` );
	document.title = 'Old ‹ Shop — WordPress';
	document.body.innerHTML = SERVER;
	document.body.className = 'js submex-app-page';
	window.submexShellData = {
		links: {
			home: '/home',
			help: '/help',
			settings: '/settings',
			upgrade: 'https://example.test/pro',
		},
	};
	window.scrollTo = jest.fn();
} );

afterEach( async () => {
	if ( root ) {
		await act( async () => root.unmount() );
	}
	root = null;
	added.forEach( ( [ target, type, fn ] ) =>
		target.removeEventListener( type, fn )
	);
	spies.forEach( ( spy ) => spy.mockRestore() );
} );

describe( 'the app shell', () => {
	it( 'draws the route, hides the server render, and keeps what WordPress said about the page', async () => {
		await boot( [
			{
				page: 'first',
				title: 'First route',
				render: () => <p>Route one</p>,
			},
		] );

		expect( host().textContent ).toContain( 'Route one' );
		expect(
			host().querySelector( '.submex-crumbs__current' ).textContent
		).toBe( 'First route' );
		expect( fallback().hidden ).toBe( true );
		expect(
			host().querySelector( '.submex-alerts' ).textContent
		).toContain( 'Saved.' );
		expect(
			host().querySelector( '.submex-notify__bell' ).textContent
		).toBe( '1' );
		expect(
			host().querySelector( '.submex-notify__list' ).textContent
		).toBe( 'Elsewhere' );
		expect( fallback().textContent ).toContain( 'Inline hint' );
		expect( document.body.classList.contains( 'submex-app-page' ) ).toBe(
			true
		);
		expect( document.title ).toBe( 'First route ‹ Shop — WordPress' );
		expect(
			host()
				.querySelector( 'a.submex-shell__upgrade' )
				.getAttribute( 'href' )
		).toBe( 'https://example.test/pro' );
	} );

	it( 'leaves out Upgrade when there is nothing to upgrade to', async () => {
		window.submexShellData.links.upgrade = '';

		await boot( [
			{ page: 'first', title: 'First', render: () => <p>Route one</p> },
		] );

		expect( host().querySelector( '.submex-shell__upgrade' ) ).toBeNull();
	} );

	it( 'moves to another route in place, dropping the server render and the first page’s notices', async () => {
		await boot( [
			{ page: 'first', title: 'First', render: () => <p>Route one</p> },
			{
				page: 'second',
				title: 'Second',
				render: ( ctx ) => <p>Route two { ctx.params.get( 'x' ) }</p>,
			},
		] );

		await click( menu( 'Second' ) );

		expect( window.location.href ).toBe( `${ ADMIN }?page=second` );
		expect( host().textContent ).toContain( 'Route two' );
		expect( host().textContent ).not.toContain( 'Route one' );
		expect( fallback() ).toBeNull();
		expect( host().textContent ).not.toContain( 'Saved.' );
		expect(
			host().querySelector( '.submex-notify__list' ).textContent
		).toBe( 'Elsewhere' );
		expect( document.title ).toBe( 'Second ‹ Shop — WordPress' );
		expect( menu( 'Second' ).classList.contains( 'current' ) ).toBe( true );
		expect( menu( 'First' ).classList.contains( 'current' ) ).toBe( false );

		await act( async () => {
			window.history.replaceState( {}, '', `${ ADMIN }?page=first` );
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		expect( host().textContent ).toContain( 'Route one' );
		expect( menu( 'First' ).classList.contains( 'current' ) ).toBe( true );
	} );

	it( 'keeps a route mounted while only its query changes', async () => {
		let mounts = 0;

		function Counting( { ctx } ) {
			require( '@wordpress/element' ).useEffect( () => {
				mounts++;
			}, [] );
			return (
				<button
					type="button"
					onClick={ () => ctx.setParams( { id: 7 } ) }
				>
					Open { ctx.params.get( 'id' ) || 'none' }
				</button>
			);
		}

		await boot( [
			{
				page: 'first',
				title: 'First',
				render: ( ctx ) => <Counting ctx={ ctx } />,
			},
		] );
		await click(
			[ ...host().querySelectorAll( 'button' ) ].find( ( b ) =>
				b.textContent.startsWith( 'Open' )
			)
		);

		expect( window.location.href ).toBe( `${ ADMIN }?page=first&id=7` );
		expect( host().textContent ).toContain( 'Open 7' );
		expect( mounts ).toBe( 1 );
	} );

	it( 'puts the server render back, with its notices, when the first route fails', async () => {
		await boot( [
			{
				page: 'first',
				title: 'First',
				render: ( ctx ) => <Fails fail={ ctx.fail } />,
			},
		] );

		expect( fallback().hidden ).toBe( false );
		expect( host().childElementCount ).toBe( 0 );
		expect( fallback().textContent ).toContain( 'Saved.' );
		expect(
			fallback().querySelector( '.submex-notify__list' ).textContent
		).toBe( 'Elsewhere' );
		expect( document.body.classList.contains( 'submex-app-page' ) ).toBe(
			false
		);
		expect(
			fallback()
				.querySelector( 'hr' )
				.classList.contains( 'wp-header-end' )
		).toBe( true );
		expect(
			fallback().querySelector( '.wp-header-end + .notice' ).textContent
		).toBe( 'Saved.' );
	} );

	it( 'treats a route that throws as a failed route', async () => {
		function Throws() {
			throw new Error( 'broken route' );
		}

		// React rethrows render errors through a window error event in development; handling it also keeps React from logging.
		const swallow = ( event ) => event.preventDefault();
		window.addEventListener( 'error', swallow );

		await boot( [
			{ page: 'first', title: 'First', render: () => <Throws /> },
		] );

		expect( fallback().hidden ).toBe( false );
		expect( host().childElementCount ).toBe( 0 );
	} );

	it( 'shows a reload card when a later route fails', async () => {
		await boot( [
			{ page: 'first', title: 'First', render: () => <p>Route one</p> },
			{
				page: 'second',
				title: 'Second',
				render: ( ctx ) => <Fails fail={ ctx.fail } />,
			},
		] );

		const reload = jest.fn();
		router.browser.reload = reload;

		await click( menu( 'Second' ) );

		expect( fallback() ).toBeNull();
		expect( host().textContent ).toContain( 'This page did not load.' );

		const link = [ ...host().querySelectorAll( 'a' ) ].find(
			( a ) => 'Reload' === a.textContent
		);
		await click( link );

		expect( reload ).toHaveBeenCalled();
		expect( window.location.href ).toBe( `${ ADMIN }?page=second` );
	} );

	it( 'shows the reload card, not the old server render, when a later route throws', async () => {
		function Throws() {
			throw new Error( 'broken route' );
		}

		const swallow = ( event ) => event.preventDefault();
		window.addEventListener( 'error', swallow );

		await boot( [
			{ page: 'first', title: 'First', render: () => <p>Route one</p> },
			{ page: 'second', title: 'Second', render: () => <Throws /> },
		] );
		await click( menu( 'Second' ) );

		expect( fallback() ).toBeNull();
		expect( host().textContent ).toContain( 'This page did not load.' );
	} );

	it( 'ignores a failure reported by a route that is no longer on screen', async () => {
		let lateFail;

		await boot( [
			{
				page: 'first',
				title: 'First',
				render: ( ctx ) => {
					lateFail = ctx.fail;
					return <p>Route one</p>;
				},
			},
			{ page: 'second', title: 'Second', render: () => <p>Route two</p> },
		] );

		await click( menu( 'Second' ) );
		await act( async () => lateFail() );

		expect( host().textContent ).toContain( 'Route two' );
		expect( host().textContent ).not.toContain( 'This page did not load.' );
	} );

	it( 'leaves a page with no route to its server render, and still moves to a route in place', async () => {
		await boot( [
			{ page: 'second', title: 'Second', render: () => <p>Route two</p> },
		] );

		expect( host().childElementCount ).toBe( 0 );
		expect( fallback().hidden ).toBe( false );
		expect(
			fallback()
				.querySelector( 'hr' )
				.classList.contains( 'wp-header-end' )
		).toBe( true );

		await click( menu( 'Second' ) );

		expect( fallback() ).toBeNull();
		expect( host().textContent ).toContain( 'Route two' );
		expect(
			host().querySelector( '.submex-notify__list' ).textContent
		).toBe( 'Elsewhere' );
	} );

	it( 'loads a page that has no route instead of drawing nothing', async () => {
		await boot( [
			{
				page: 'first',
				title: 'First',
				render: ( ctx ) => (
					<button
						type="button"
						onClick={ () => ctx.navigate( 'elsewhere', { a: 1 } ) }
					>
						Go
					</button>
				),
			},
		] );

		const load = jest.fn();
		router.browser.load = load;

		await click(
			[ ...host().querySelectorAll( 'button' ) ].find(
				( b ) => 'Go' === b.textContent
			)
		);

		expect( load ).toHaveBeenCalledWith( `${ ADMIN }?page=elsewhere&a=1` );
	} );
} );
