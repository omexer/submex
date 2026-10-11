/**
 * The subscriptions route reads which subscription to show from the address, and changes it through the shell.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Screen } from '../index';

jest.mock( '@wordpress/api-fetch' );

const ROW = {
	id: 812,
	status: 'submex-active',
	status_label: 'Active',
	customer_name: 'Ada Lovelace',
	customer_email: 'ada@example.com',
	total_formatted: '£24.00',
	billing_interval: 1,
	billing_period: 'month',
	next_payment: '',
	end_date: '',
	billable: true,
	edit_url: '/wp-admin/admin.php?page=submex-list&subscription=812',
};

let container;

async function render( query, setParams ) {
	apiFetch.mockImplementation( ( options ) => {
		if ( options.path.startsWith( '/submex/v1/subscriptions?' ) ) {
			return Promise.resolve( {
				json: () => Promise.resolve( [ ROW ] ),
				headers: { get: () => '1' },
			} );
		}

		if ( options.path.endsWith( '/panels' ) ) {
			return Promise.resolve( {
				html: '<div class="card" id="extension-panel">Live QR</div>',
			} );
		}

		if (
			options.path.endsWith( '/activity' ) ||
			options.path.endsWith( '/statuses' )
		) {
			return Promise.resolve( [] );
		}

		return Promise.resolve( ROW );
	} );

	container = document.createElement( 'div' );
	document.body.appendChild( container );

	await act( async () => {
		createRoot( container ).render(
			<Screen
				params={ new URLSearchParams( query ) }
				setParams={ setParams }
				fail={ () => {} }
			/>
		);
	} );

	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

describe( 'the subscriptions route', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'filters the list by the status in the address', async () => {
		await render( 'status=submex-on-hold', jest.fn() );

		const call = apiFetch.mock.calls.find( ( [ options ] ) =>
			options.path.startsWith( '/submex/v1/subscriptions?' )
		);
		expect(
			new URLSearchParams( call[ 0 ].path.split( '?' )[ 1 ] ).get(
				'status'
			)
		).toBe( 'submex-on-hold' );
	} );

	it( 'opens a subscription by adding it to the address, keeping the filter', async () => {
		const setParams = jest.fn();
		await render( 'status=submex-active', setParams );

		const link = [ ...container.querySelectorAll( 'a' ) ].find(
			( a ) => a.textContent === 'Ada Lovelace'
		);
		await act( async () => {
			link.click();
		} );

		expect( setParams.mock.calls[ 0 ][ 0 ].toString() ).toBe(
			'status=submex-active&subscription=812'
		);
	} );

	it( 'shows the subscription the address names, with what extensions draw under it, and goes back by dropping it', async () => {
		const setParams = jest.fn();
		await render( 'subscription=812&status=submex-active', setParams );

		expect( container.textContent ).toContain( 'Subscription #812' );
		expect(
			container.querySelector( '#extension-panel' ).textContent
		).toBe( 'Live QR' );

		const back = [ ...container.querySelectorAll( 'button' ) ].find(
			( b ) => b.textContent.includes( 'All subscriptions' )
		);
		await act( async () => {
			back.click();
		} );

		expect( setParams.mock.calls[ 0 ][ 0 ].toString() ).toBe(
			'status=submex-active'
		);
	} );
} );
