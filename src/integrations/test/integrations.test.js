/**
 * The Integrations route draws what the endpoint says, and installs through the one installer.
 */
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Integrations } from '../index';

jest.mock( '@wordpress/api-fetch' );

const item = ( overrides = {} ) => ( {
	title: 'LearnDash',
	requires: 'LearnDash LMS',
	description: 'Enrol members in a course.',
	hint: '',
	category: 'Courses',
	icon: '',
	active: false,
	configure_url: '',
	configure_label: '',
	install: '',
	get_url: '',
	...overrides,
} );

const INSTALLER = {
	url: '/wp-admin/admin-ajax.php',
	action: 'submex_install_integration',
	nonce: 'n0nce',
};

let container;

async function render( onFail = () => {} ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );

	await act( async () => {
		createRoot( container ).render( <Integrations onFail={ onFail } /> );
	} );
}

const button = ( text ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( node ) => node.textContent === text
	);

describe( 'the integrations screen', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
		window.fetch = jest.fn();
	} );

	it( 'groups the integrations under their category, with where each is set up', async () => {
		apiFetch.mockResolvedValue( {
			integrations: [
				item( {
					active: true,
					configure_url: '/settings?section=ld',
					configure_label: 'Open LearnDash settings',
				} ),
				item( {
					title: 'MailPoet',
					requires: 'MailPoet',
					category: 'Email',
					get_url: 'https://example.test/mailpoet',
				} ),
			],
			installer: null,
		} );

		await render();

		const headings = [
			...container.querySelectorAll( 'section > h2' ),
		].map( ( node ) => node.textContent );
		expect( headings ).toEqual( [ 'Courses', 'Email' ] );
		expect( container.textContent ).toContain( 'Needs LearnDash LMS' );
		expect( container.textContent ).toContain( 'Connected' );

		const settings = [ ...container.querySelectorAll( 'a' ) ].find(
			( a ) => a.textContent === 'Open LearnDash settings'
		);
		expect( settings.getAttribute( 'href' ) ).toBe(
			'/settings?section=ld'
		);

		const getIt = [ ...container.querySelectorAll( 'a' ) ].find( ( a ) =>
			a.textContent.includes( 'Get it' )
		);
		expect( getIt.getAttribute( 'href' ) ).toBe(
			'https://example.test/mailpoet'
		);
		expect( getIt.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( button( 'Install' ) ).toBeUndefined();
	} );

	it( 'says there is nothing to connect rather than drawing an empty grid', async () => {
		apiFetch.mockResolvedValue( { integrations: [], installer: null } );

		await render();

		expect( container.textContent ).toContain( 'Nothing to connect yet' );
	} );

	it( 'installs through the installer’s own action and nonce, then redraws from the server', async () => {
		apiFetch
			.mockResolvedValueOnce( {
				integrations: [ item( { install: 'sfwd-lms' } ) ],
				installer: INSTALLER,
			} )
			.mockResolvedValueOnce( {
				integrations: [ item( { active: true } ) ],
				installer: INSTALLER,
			} );
		window.fetch.mockResolvedValue( {
			json: () => Promise.resolve( { success: true } ),
		} );

		await render();
		await act( async () => {
			button( 'Install' ).click();
		} );

		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/wp-admin/admin-ajax.php' );
		expect( options.body.get( 'action' ) ).toBe(
			'submex_install_integration'
		);
		expect( options.body.get( '_wpnonce' ) ).toBe( 'n0nce' );
		expect( options.body.get( 'slug' ) ).toBe( 'sfwd-lms' );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( container.textContent ).toContain( 'Connected' );
		expect( button( 'Install' ) ).toBeUndefined();
	} );

	it( 'says why an install was refused and leaves the button to try again', async () => {
		apiFetch.mockResolvedValue( {
			integrations: [ item( { install: 'sfwd-lms' } ) ],
			installer: INSTALLER,
		} );
		window.fetch.mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: false,
					data: {
						message:
							'You do not have permission to install plugins.',
					},
				} ),
		} );

		await render();
		await act( async () => {
			button( 'Install' ).click();
		} );

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'You do not have permission to install plugins.'
		);
		expect( button( 'Install' ).disabled ).toBe( false );
	} );

	it( 'tells the shell when it cannot load', async () => {
		apiFetch.mockRejectedValue( { code: 'rest_forbidden' } );
		const onFail = jest.fn();

		await render( onFail );

		expect( onFail ).toHaveBeenCalledTimes( 1 );
	} );
} );
