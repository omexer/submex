/**
 * The router keeps real admin addresses in step with the route on screen.
 */
const ADMIN = 'http://localhost/wp-admin/admin.php';

let router;
let popListeners;
let spies;

beforeEach( () => {
	jest.resetModules();
	router = require( '../router' );
	window.history.replaceState( {}, '', `${ ADMIN }?page=home` );

	popListeners = [];
	const add = window.addEventListener.bind( window );
	spies = [ jest.spyOn( window, 'addEventListener' ) ];
	spies[ 0 ].mockImplementation( ( type, fn, options ) => {
		if ( 'popstate' === type ) {
			popListeners.push( fn );
		}
		add( type, fn, options );
	} );

	router.registerRoute( { page: 'home', title: 'Home', render: () => null } );
	router.registerRoute( { page: 'list', title: 'List', render: () => null } );
	router.start( 'home' );
} );

afterEach( () => {
	popListeners.forEach( ( fn ) =>
		window.removeEventListener( 'popstate', fn )
	);
	spies.forEach( ( spy ) => spy.mockRestore() );
} );

function pop( href ) {
	window.history.replaceState( {}, '', href );
	window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
}

describe( 'the router', () => {
	it( 'pushes a real admin address and tells the shell which route to draw', () => {
		const seen = jest.fn();
		router.subscribe( seen );
		const before = window.history.length;

		router.navigate( 'list', { status: 'submex-active', empty: '' } );

		expect( window.location.href ).toBe(
			`${ ADMIN }?page=list&status=submex-active`
		);
		expect( window.history.length ).toBe( before + 1 );
		expect( router.current().page ).toBe( 'list' );
		expect( router.current().params.toString() ).toBe(
			'status=submex-active'
		);
		expect( seen ).toHaveBeenCalledWith(
			expect.objectContaining( { page: 'list', kind: 'navigate' } )
		);
	} );

	it( 'loads a page it has no route for, rather than drawing nothing', () => {
		const load = jest.fn();
		router.browser.load = load;
		const seen = jest.fn();
		router.subscribe( seen );

		router.navigate( 'someone-elses-page', { tab: 'x' } );

		expect( load ).toHaveBeenCalledWith(
			`${ ADMIN }?page=someone-elses-page&tab=x`
		);
		expect( window.location.href ).toBe( `${ ADMIN }?page=home` );
		expect( seen ).not.toHaveBeenCalled();
	} );

	it( 'changes the current route’s query, pushing or replacing as asked', () => {
		router.navigate( 'list' );
		const before = window.history.length;

		router.setParams( { subscription: 12 } );
		expect( window.location.href ).toBe(
			`${ ADMIN }?page=list&subscription=12`
		);
		expect( window.history.length ).toBe( before + 1 );

		router.setParams( new URLSearchParams( 'subscription=13' ), {
			replace: true,
		} );
		expect( window.location.href ).toBe(
			`${ ADMIN }?page=list&subscription=13`
		);
		expect( window.history.length ).toBe( before + 1 );
		expect( router.current() ).toEqual( {
			page: 'list',
			params: new URLSearchParams( 'subscription=13' ),
		} );
	} );

	it( 'draws the route back and forward lead to', () => {
		const seen = jest.fn();
		router.subscribe( seen );

		pop( `${ ADMIN }?page=list&subscription=4` );

		expect( seen ).toHaveBeenCalledWith(
			expect.objectContaining( { page: 'list', kind: 'pop' } )
		);
		expect( router.current().params.get( 'subscription' ) ).toBe( '4' );
	} );

	it( 'reloads when back leads to a page with no route', () => {
		const reload = jest.fn();
		router.browser.reload = reload;

		pop( `${ ADMIN }?page=wc-settings` );

		expect( reload ).toHaveBeenCalled();
	} );

	it( 'ignores a pop that leaves the route as it is', () => {
		const seen = jest.fn();
		router.subscribe( seen );

		pop( `${ ADMIN }?page=home#section` );

		expect( seen ).not.toHaveBeenCalled();
	} );

	it( 'only reads admin.php?page= addresses on this site as routes', () => {
		expect(
			router.parse( 'admin.php?page=list&s=ada' ).params.get( 's' )
		).toBe( 'ada' );
		expect(
			router.parse( 'https://example.test/wp-admin/admin.php?page=list' )
		).toBeNull();
		expect( router.parse( '/wp-admin/post-new.php?page=list' ) ).toBeNull();
		expect( router.parse( '/wp-admin/admin.php' ) ).toBeNull();
	} );
} );
