/**
 * The home screen decides what a merchant sees first. These cover the decisions: setup
 * until it is done, what needs attention, and where each link goes.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Dashboard } from '../index';

jest.mock( '@wordpress/api-fetch' );

const step = ( done, title, extra = {} ) => ( {
	done,
	title,
	detail: `${ title } detail`,
	action: null,
	form: '',
	...extra,
} );

function data( overrides = {} ) {
	return {
		greeting: 'Good morning, Ada',
		setup: {
			done: 1,
			total: 3,
			steps: [
				step( true, 'Connect a payment method' ),
				step( false, 'Create a subscription product', {
					form: 'create_product',
				} ),
				step( false, 'Run a test renewal', {
					action: { label: 'Run test renewal', url: '/test' },
				} ),
			],
		},
		stats: { mrr: '£1,240.00', active: 42, trialling: 5, on_hold: 0 },
		attention: [],
		recent: [
			{
				id: 812,
				customer: 'Ada Lovelace',
				product: 'Coffee box',
				status: 'submex-active',
				status_label: 'Active',
				total: '£40.00',
				created: '12 Sep 2026',
				url: '/wp-admin/admin.php?page=submex-list&subscription=812',
			},
		],
		links: {
			new_product: '/new',
			list: '/list',
			integrations: '/integrations',
			settings: '/settings',
			help: '/help',
		},
		create: { url: '/wp-admin/admin-post.php', nonce: 'n0nce' },
		...overrides,
	};
}

let container;

async function render( payload ) {
	apiFetch.mockResolvedValue( payload );
	container = document.createElement( 'div' );
	document.body.appendChild( container );

	await act( async () => {
		createRoot( container ).render( <Dashboard onFail={ () => {} } /> );
	} );
}

describe( 'the home screen', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'leads with setup while it is unfinished, and offers the quick product form on that step', async () => {
		await render( data() );

		expect( container.textContent ).toContain(
			'Get your first subscription running'
		);
		expect( container.textContent ).toContain( '1 of 3 done' );

		const form = container.querySelector( 'form' );
		expect( form.getAttribute( 'action' ) ).toBe(
			'/wp-admin/admin-post.php'
		);
		expect( form.querySelector( 'input[name="action"]' ).value ).toBe(
			'submex_create_product'
		);
		expect( form.querySelector( 'input[name="_wpnonce"]' ).value ).toBe(
			'n0nce'
		);
	} );

	it( 'drops the setup card entirely once every step is done', async () => {
		const done = data();
		done.setup = {
			...done.setup,
			done: 3,
			steps: done.setup.steps.map( ( s ) => ( { ...s, done: true } ) ),
		};

		await render( done );

		expect( container.textContent ).not.toContain(
			'Get your first subscription running'
		);
		expect( container.querySelector( 'form' ) ).toBeNull();
	} );

	it( 'says nothing needs attention rather than showing an empty box', async () => {
		await render( data() );

		expect( container.textContent ).toContain(
			'Nothing needs you right now'
		);
	} );

	it( 'lists what needs attention, each linking to the filtered list', async () => {
		await render(
			data( {
				attention: [
					{
						key: 'on_hold',
						label: '2 subscriptions on hold after a failed payment',
						count: 2,
						url: '/list?status=submex-on-hold',
						tone: 'bad',
					},
				],
			} )
		);

		const link = [ ...container.querySelectorAll( 'a' ) ].find( ( a ) =>
			a.textContent.includes( 'on hold after a failed payment' )
		);

		expect( link.getAttribute( 'href' ) ).toBe(
			'/list?status=submex-on-hold'
		);
		expect( container.textContent ).not.toContain(
			'Nothing needs you right now'
		);
	} );

	it( 'links each recent subscription to its own screen', async () => {
		await render( data() );

		const row = [ ...container.querySelectorAll( 'a' ) ].find( ( a ) =>
			a.textContent.includes( 'Ada Lovelace' )
		);

		expect( row.getAttribute( 'href' ) ).toContain( 'subscription=812' );
		expect( row.textContent ).toContain( '£40.00' );
	} );

	it( 'tells the shell when the request fails, so it can put the server-rendered home back', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		const onFail = jest.fn();
		container = document.createElement( 'div' );
		document.body.appendChild( container );

		await act( async () => {
			createRoot( container ).render( <Dashboard onFail={ onFail } /> );
		} );

		expect( onFail ).toHaveBeenCalledTimes( 1 );
	} );
} );
