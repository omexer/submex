/**
 * The Help route draws the tiles and the report the endpoint returns.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Help } from '../index';

jest.mock( '@wordpress/api-fetch' );

let container;

async function render( onFail = () => {} ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );

	await act( async () => {
		createRoot( container ).render( <Help onFail={ onFail } /> );
	} );
}

describe( 'the help screen', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'links each first check to its screen and shows the report to copy', async () => {
		apiFetch.mockResolvedValue( {
			tiles: [
				{
					title: 'Read the activity log',
					body: 'Open one.',
					label: 'All subscriptions',
					url: '/wp-admin/admin.php?page=submex-list',
				},
			],
			report: 'Submex: 1.0\nHPOS: yes\n',
		} );

		await render();

		const link = [ ...container.querySelectorAll( 'a' ) ].find(
			( a ) => a.textContent === 'All subscriptions'
		);
		expect( link.getAttribute( 'href' ) ).toBe(
			'/wp-admin/admin.php?page=submex-list'
		);
		expect( container.querySelector( 'textarea' ).value ).toBe(
			'Submex: 1.0\nHPOS: yes\n'
		);
	} );

	it( 'copies the report and says so', async () => {
		apiFetch.mockResolvedValue( { tiles: [], report: 'PHP: 8.1\n' } );
		const writeText = jest.fn( () => Promise.resolve() );
		Object.defineProperty( window.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		} );
		window.isSecureContext = true;

		await render();

		const copy = [ ...container.querySelectorAll( 'button' ) ].find(
			( b ) => b.textContent === 'Copy report'
		);
		await act( async () => {
			copy.click();
		} );

		expect( writeText ).toHaveBeenCalledWith( 'PHP: 8.1\n' );
		expect( container.textContent ).toContain( 'Copied' );
	} );

	it( 'tells the shell when it cannot load', async () => {
		apiFetch.mockRejectedValue( { code: 'rest_forbidden' } );
		const onFail = jest.fn();

		await render( onFail );

		expect( onFail ).toHaveBeenCalledTimes( 1 );
	} );
} );
