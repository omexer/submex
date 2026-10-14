/**
 * The Retention Flow screen: the switch, reordering by keyboard, adding, deleting only after
 * asking, a preview that shows what customers will see, and the three places Pro plugs in.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addFilter, removeAllFilters } from '@wordpress/hooks';
import { retentionHooks } from '@submex/shell';
import { ProCard, Retention } from '../screen';
import { cleanRichText } from '../rich-text';

jest.mock( '@wordpress/api-fetch' );

const reason = ( id, label, overrides = {} ) => ( {
	id,
	label,
	enabled: true,
	usage: 0,
	...overrides,
} );

const SCREEN = {
	enabled: false,
	reasons: [
		reason( 'too_expensive', 'Too expensive', { usage: 2 } ),
		reason( 'not_using', 'Not using it' ),
		reason( 'temporary', 'Only pausing', { enabled: false } ),
	],
	display: {
		title: 'Before you go, what’s the main reason?',
		description: '<p>Please tell us <strong>why</strong>.</p>',
		keep_text: 'Keep Subscription',
		continue_text: 'Continue canceling',
		title_size: 18,
		description_size: 14,
		align: 'center',
	},
	messages: {
		cancel: {
			enabled: true,
			title: 'Your subscription has been cancelled',
			description: 'You won’t be charged again.',
			button_text: 'Close',
		},
	},
	upgrade_url: 'https://example.test/pro',
};

let container;
let root;
let calls;

function respond( screen = SCREEN ) {
	calls = [];
	apiFetch.mockImplementation( ( options ) => {
		calls.push( options );

		const method = options.method || 'GET';

		if ( 'GET' === method && '/submex/v1/retention' === options.path ) {
			return Promise.resolve( JSON.parse( JSON.stringify( screen ) ) );
		}

		if ( 'PUT' === method ) {
			return Promise.resolve(
				options.data.order.map( ( id ) =>
					screen.reasons.find( ( item ) => item.id === id )
				)
			);
		}

		if ( 'POST' === method && options.path.endsWith( '/reasons' ) ) {
			return Promise.resolve(
				reason( 'r_abcd1234', options.data.label )
			);
		}

		if ( 'POST' === method && options.path.includes( '/messages/' ) ) {
			return Promise.resolve( {
				...screen.messages.cancel,
				...options.data,
			} );
		}

		if ( 'POST' === method && '/submex/v1/retention' === options.path ) {
			return Promise.resolve( {
				...screen,
				...( 'enabled' in options.data
					? { enabled: options.data.enabled }
					: {} ),
				display: { ...screen.display, ...options.data.display },
			} );
		}

		return Promise.resolve( { deleted: true } );
	} );
}

const flush = () => act( () => new Promise( ( r ) => setTimeout( r, 0 ) ) );

async function render( tab = '' ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );

	const params = new URLSearchParams( tab ? { tab } : {} );

	await act( async () => {
		root.render(
			<Retention
				params={ params }
				setParams={ jest.fn() }
				onFail={ jest.fn() }
			/>
		);
	} );
	await flush();
}

const byLabel = ( label ) =>
	container.querySelector( `[aria-label="${ label }"]` );

const rows = () =>
	[
		...container.querySelectorAll(
			'ul[aria-label="Cancellation reasons"] > li'
		),
	].map( ( li ) => li.querySelector( 'span.sb-flex-1' )?.textContent );

async function click( node ) {
	await act( async () => {
		node.dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} )
		);
	} );
	await flush();
}

async function type( node, value ) {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			Object.getPrototypeOf( node ),
			'value'
		).set.call( node, value );
		node.dispatchEvent(
			new Event( node.tagName === 'SELECT' ? 'change' : 'input', {
				bubbles: true,
			} )
		);
	} );
}

async function submit( form ) {
	await act( async () => {
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	} );
	await flush();
}

beforeEach( () => {
	apiFetch.mockReset();
	respond();
	window.confirm = jest.fn( () => true );
} );

afterEach( () => {
	act( () => root?.unmount() );
	container?.remove();
	removeAllFilters( retentionHooks.offersTab );
	removeAllFilters( retentionHooks.reasonStats );
	removeAllFilters( retentionHooks.messageTypes );
} );

describe( 'the Retention Flow screen', () => {
	it( 'lists every reason, and switches the flow on from the header', async () => {
		await render();

		expect( rows() ).toEqual( [
			'Too expensive',
			'Not using it',
			'Only pausing',
		] );

		const toggle = container.querySelector( '#submex-retention-enabled' );

		expect( toggle.getAttribute( 'role' ) ).toBe( 'switch' );
		expect(
			container.querySelector( 'label[for="submex-retention-enabled"]' )
				.textContent
		).toBe( 'Ask before cancelling' );

		await click( toggle );

		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention',
			method: 'POST',
			data: { enabled: true },
		} );
		expect( toggle.checked ).toBe( true );
	} );

	it( 'moves a reason with the keyboard buttons, and saves the new order', async () => {
		await render();

		expect( byLabel( 'Move “Too expensive” up' ).disabled ).toBe( true );

		await click( byLabel( 'Move “Not using it” up' ) );

		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention/reasons',
			method: 'PUT',
			data: { order: [ 'not_using', 'too_expensive', 'temporary' ] },
		} );
		expect( rows() ).toEqual( [
			'Not using it',
			'Too expensive',
			'Only pausing',
		] );
	} );

	it( 'moves a reason by dragging it onto another row', async () => {
		await render();

		const items = container.querySelectorAll(
			'ul[aria-label="Cancellation reasons"] > li'
		);

		await act( async () => {
			items[ 2 ].dispatchEvent(
				new Event( 'dragstart', { bubbles: true } )
			);
		} );
		await act( async () => {
			items[ 0 ].dispatchEvent(
				new Event( 'drop', { bubbles: true, cancelable: true } )
			);
		} );
		await flush();

		expect( calls.at( -1 ).data ).toEqual( {
			order: [ 'temporary', 'too_expensive', 'not_using' ],
		} );
	} );

	it( 'adds a reason at the end of the list', async () => {
		await render();

		await type( byLabel( 'New reason' ), '  Moving abroad ' );
		await submit( byLabel( 'New reason' ).closest( 'form' ) );

		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention/reasons',
			method: 'POST',
			data: { label: 'Moving abroad' },
		} );
		expect( rows().at( -1 ) ).toBe( 'Moving abroad' );
		expect( byLabel( 'New reason' ).value ).toBe( '' );
	} );

	it( 'asks before deleting, naming the offers that use the reason', async () => {
		await render();

		window.confirm = jest.fn( () => false );
		await click( byLabel( 'Delete “Too expensive”' ) );

		expect( window.confirm.mock.calls[ 0 ][ 0 ] ).toContain(
			'used by 2 offers'
		);
		expect( calls.some( ( call ) => 'DELETE' === call.method ) ).toBe(
			false
		);
		expect( rows() ).toContain( 'Too expensive' );

		window.confirm = jest.fn( () => true );
		await click( byLabel( 'Delete “Not using it”' ) );

		expect( window.confirm.mock.calls[ 0 ][ 0 ] ).not.toContain(
			'used by'
		);
		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention/reasons/not_using',
			method: 'DELETE',
		} );
		expect( rows() ).toEqual( [ 'Too expensive', 'Only pausing' ] );
	} );

	it( 'previews the reasons screen with the enabled reasons and the text being edited', async () => {
		await render();

		const preview = container.querySelector(
			'[data-testid="retention-preview"]'
		);

		expect( preview.textContent ).toContain( 'Too expensive' );
		expect( preview.textContent ).toContain( 'Not using it' );
		expect( preview.textContent ).not.toContain( 'Only pausing' );
		expect( preview.textContent ).toContain( 'Keep Subscription' );
		expect( preview.textContent ).toContain( 'Continue canceling' );
		expect( preview.querySelector( 'strong' ).textContent ).toBe( 'why' );

		await type(
			container.querySelector( '#submex-retention-title' ),
			'Wait a moment'
		);
		await type(
			container.querySelector( '#submex-retention-keep' ),
			'Stay'
		);
		await type(
			container.querySelector( '#submex-retention-align' ),
			'left'
		);

		expect( preview.textContent ).toContain( 'Wait a moment' );
		expect( preview.textContent ).toContain( 'Stay' );
		expect( preview.style.textAlign ).toBe( 'left' );

		await submit(
			container
				.querySelector( '#submex-retention-title' )
				.closest( 'form' )
		);

		expect( calls.at( -1 ).data.display ).toMatchObject( {
			title: 'Wait a moment',
			keep_text: 'Stay',
			align: 'left',
		} );
	} );

	it( 'shows a plain Pro card on the Offers tab, which Pro can replace', async () => {
		await render( 'offers' );

		expect( container.textContent ).toContain(
			'Offers are part of Submex Pro'
		);
		expect( container.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
			'https://example.test/pro'
		);
		expect(
			container.querySelector(
				'#submex-retention-panel :is(input,select,textarea)'
			)
		).toBe( null );

		act( () => root.unmount() );
		container.remove();

		const seen = [];
		addFilter( retentionHooks.offersTab, 'test', ( current ) => {
			seen.push( current );

			return function OffersBuilder( props ) {
				return <p data-testid="pro-offers">{ props.reasons.length }</p>;
			};
		} );

		await render( 'offers' );

		expect( seen ).toEqual( [ ProCard ] );
		expect(
			container.querySelector( '[data-testid="pro-offers"]' ).textContent
		).toBe( '3' );
		expect( container.textContent ).not.toContain(
			'Offers are part of Submex Pro'
		);
	} );

	it( 'lets Pro add header cards and per-reason stats', async () => {
		const seen = [];
		addFilter( retentionHooks.reasonStats, 'test', ( current, reasons ) => {
			seen.push( [ current, reasons.map( ( item ) => item.id ) ] );

			return {
				Header: ( { reasons: list } ) => (
					<p data-testid="stats-header">{ list.length } reasons</p>
				),
				Row: ( { reason: item } ) => (
					<span data-testid="stats-row">{ item.id }</span>
				),
			};
		} );

		await render();

		expect( seen.at( -1 ) ).toEqual( [
			null,
			[ 'too_expensive', 'not_using', 'temporary' ],
		] );
		expect(
			container.querySelector( '[data-testid="stats-header"]' )
				.textContent
		).toBe( '3 reasons' );
		expect(
			[
				...container.querySelectorAll( '[data-testid="stats-row"]' ),
			].map( ( node ) => node.textContent )
		).toEqual( [ 'too_expensive', 'not_using', 'temporary' ] );
	} );

	it( 'edits a confirmation message in a dialog with a live preview, and switches it off', async () => {
		const seen = [];
		addFilter( retentionHooks.messageTypes, 'test', ( types, messages ) => {
			seen.push( [ types, Object.keys( messages ) ] );

			return types.map( ( item ) => ( {
				...item,
				label: `${ item.label }!`,
			} ) );
		} );

		await render( 'messages' );

		expect( seen[ 0 ] ).toEqual( [
			[ { id: 'cancel', label: 'Subscription Cancel' } ],
			[ 'cancel' ],
		] );
		expect( container.textContent ).toContain(
			'What customers see after they cancel, pause or accept an offer.'
		);
		expect( container.textContent ).toContain( 'Subscription Cancel!' );

		await click( byLabel( 'Edit the Subscription Cancel! message' ) );

		const dialog = container.querySelector( '[role="dialog"]' );

		expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );

		const title = dialog.querySelector( 'input' );

		await type( title, 'All done' );

		expect(
			container.querySelector( '[data-testid="message-preview"]' )
				.textContent
		).toContain( 'All done' );

		await submit( title.closest( 'form' ) );

		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention/messages/cancel',
			method: 'POST',
			data: {
				title: 'All done',
				description: 'You won’t be charged again.',
				button_text: 'Close',
			},
		} );
		expect( container.querySelector( '[role="dialog"]' ) ).toBe( null );

		await click( byLabel( 'Show the Subscription Cancel! message' ) );

		expect( calls.at( -1 ) ).toEqual( {
			path: '/submex/v1/retention/messages/cancel',
			method: 'POST',
			data: { enabled: false },
		} );
	} );

	it( 'closes the message dialog with Escape', async () => {
		await render( 'messages' );
		await click( byLabel( 'Edit the Subscription Cancel message' ) );

		await act( async () => {
			document.dispatchEvent(
				new window.KeyboardEvent( 'keydown', {
					key: 'Escape',
					bubbles: true,
				} )
			);
		} );

		expect( container.querySelector( '[role="dialog"]' ) ).toBe( null );
	} );
} );

describe( 'cleanRichText', () => {
	it( 'keeps the allowlist and drops everything else', () => {
		expect(
			cleanRichText(
				'<p onclick="x()">Hi <b>there</b> <i>you</i> <u>ok</u></p><script>alert(1)</script><img src="x" onerror="y()"><h1>Big</h1><ul><li>one</li></ul>'
			)
		).toBe(
			'<p>Hi <strong>there</strong> <em>you</em> <u>ok</u></p>Big<ul><li>one</li></ul>'
		);
	} );

	it( 'keeps safe links and strips the rest of their attributes', () => {
		expect(
			cleanRichText(
				'<a href="https://example.test" target="_blank" rel="noopener" style="color:red">ok</a><a href="javascript:alert(1)">bad</a>'
			)
		).toBe(
			'<a href="https://example.test" target="_blank" rel="noopener">ok</a><a>bad</a>'
		);
	} );
} );
