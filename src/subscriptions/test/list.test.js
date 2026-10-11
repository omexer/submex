/**
 * The list can cancel subscriptions in bulk and charge a card. These cover what it sends,
 * what it says about a partial result, and what it does when asked to do something risky.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { List } from '../list';

jest.mock( '@wordpress/api-fetch' );

const ROWS = [
	{
		id: 812,
		status: 'submex-active',
		status_label: 'Active',
		customer_name: 'Ada Lovelace',
		customer_email: 'ada@example.com',
		total_formatted: '£24.00',
		next_payment_formatted: '12 September 2026',
		payment_method_title: 'PayPal',
		payment_method: 'submex_paypal',
		billable: true,
		edit_url: 'http://example.test/?subscription=812',
	},
	{
		id: 813,
		status: 'submex-cancelled',
		status_label: 'Cancelled',
		customer_name: '',
		customer_email: 'bob@example.com',
		total_formatted: '£9.00',
		next_payment_formatted: '',
		payment_method_title: '',
		payment_method: '',
		billable: false,
		edit_url: 'http://example.test/?subscription=813',
	},
];

const STATUSES = [
	{ key: 'submex-active', label: 'Active', count: 1 },
	{ key: 'submex-cancelled', label: 'Cancelled', count: 1 },
	{ key: 'submex-expired', label: 'Ended', count: 0 },
];

function respond( { rows = ROWS, total = rows.length } = {} ) {
	apiFetch.mockImplementation( ( options ) => {
		if ( options.path.startsWith( '/submex/v1/subscriptions/statuses' ) ) {
			return Promise.resolve( STATUSES );
		}

		if ( options.path.startsWith( '/submex/v1/subscriptions?' ) ) {
			return Promise.resolve( {
				json: () => Promise.resolve( rows ),
				headers: { get: () => String( total ) },
			} );
		}

		return Promise.resolve( {
			changed: [ 812 ],
			held: { 813: 'cannot cancel from cancelled' },
		} );
	} );
}

let container;

async function render( props = {} ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );

	await act( async () => {
		createRoot( container ).render(
			<List onFail={ () => {} } { ...props } />
		);
	} );

	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

const byText = ( text, tag = 'button' ) =>
	[ ...container.querySelectorAll( tag ) ].find(
		( node ) => node.textContent.trim() === text
	);

const listCalls = () =>
	apiFetch.mock.calls.filter( ( call ) =>
		call[ 0 ].path.startsWith( '/submex/v1/subscriptions?' )
	);

describe( 'the subscriptions list', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
		delete window.confirm;
	} );

	it( 'shows a row per subscription, and a status tab only where there is something in it', async () => {
		respond();
		await render();

		expect( container.textContent ).toContain( 'Ada Lovelace' );
		expect( container.textContent ).toContain( '£24.00' );
		expect( container.textContent ).toContain( '12 September 2026' );
		// The tab shows its count beside the label; a status with nothing in it has no tab.
		expect( container.textContent ).toContain( 'Active1' );
		expect( container.textContent ).not.toContain( 'Ended' );
	} );

	it( 'offers Renew now only on a subscription that can be billed', async () => {
		respond();
		await render();

		const renew = [ ...container.querySelectorAll( 'button' ) ].filter(
			( node ) => 'Renew now' === node.textContent
		);

		expect( renew ).toHaveLength( 1 );
	} );

	it( 'asks before charging a card, and does not charge when refused', async () => {
		respond();
		await render();

		window.confirm = jest.fn( () => false );
		apiFetch.mockClear();

		await act( async () => {
			byText( 'Renew now' ).click();
		} );

		expect( window.confirm ).toHaveBeenCalledWith(
			expect.stringContaining( '£24.00' )
		);
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'sends every selected id in one request, and reports what was left alone', async () => {
		respond();
		await render();

		await act( async () => {
			container.querySelectorAll( 'input[type="checkbox"]' )[ 0 ].click();
		} );

		apiFetch.mockClear();

		await act( async () => {
			byText( 'Apply' ).click();
		} );

		const bulk = apiFetch.mock.calls.find(
			( call ) => '/submex/v1/subscriptions/actions' === call[ 0 ].path
		);

		expect( bulk[ 0 ].data ).toEqual( {
			ids: [ 812, 813 ],
			action: 'cancel',
			status: undefined,
		} );
		expect( container.textContent ).toContain( '1 subscription updated' );
		expect( container.textContent ).toContain( 'left alone' );
	} );

	it( 'asks the server to sort, rather than sorting the page it happens to have', async () => {
		respond();
		await render();

		const header = [ ...container.querySelectorAll( 'button' ) ].find(
			( node ) => node.textContent.startsWith( 'Recurring total' )
		);

		await act( async () => {
			header.click();
		} );

		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );

		const last = listCalls().pop()[ 0 ].path;

		expect( last ).toContain( 'orderby=total' );
		expect( last ).toContain( 'order=ASC' );
	} );

	it( 'says a renewal is waiting on the payment provider, and does not offer to charge it again', async () => {
		const since = new Date( Date.now() - 3 * 86400000 )
			.toISOString()
			.slice( 0, 19 )
			.replace( 'T', ' ' );

		respond( {
			rows: [
				{
					...ROWS[ 0 ],
					next_payment: '2026-09-01 09:00:00',
					payment_pending: true,
					payment_pending_since: since,
					payment_pending_order: {
						id: 900,
						number: '900',
						url: 'http://example.test/order/900',
					},
				},
			],
		} );
		await render();

		expect( container.textContent ).toContain(
			'Payment processing · order #900 · submitted 3 days ago'
		);
		expect(
			container.querySelector( 'a[href="http://example.test/order/900"]' )
		).toBeTruthy();
		expect( container.textContent ).toContain( 'waiting for the payment' );
		expect( container.textContent ).not.toContain( 'overdue' );
		expect( byText( 'Renew now' ) ).toBeUndefined();
	} );

	it( 'tears itself down when the first load fails, leaving the server-rendered list', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		const onFail = jest.fn();

		await render( { onFail } );

		expect( onFail ).toHaveBeenCalled();
	} );
} );
