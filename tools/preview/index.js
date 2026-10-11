/**
 * Renders one admin screen, chosen by the URL hash, against fixture data.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import { Dashboard } from '../../src/dashboard/index';
import { List } from '../../src/subscriptions/list';
import { Detail } from '../../src/subscriptions/detail';
import { App as Reports } from '@submex/pro/reports/index';
import { App as Health } from '@submex/pro/health/index';
import { fixtures } from './fixtures';

import '../../src/ui/globals.css';
import './styles.css';

const params = new URLSearchParams( window.location.search );

apiFetch.use( ( options ) => {
	const path = options.path.split( '?' )[ 0 ];
	let data = fixtures[ path ];

	if ( 'function' === typeof data ) {
		data = data( params.get( 'setup' ) === 'done' );
	}

	if ( undefined === data ) {
		return Promise.reject( { message: `No fixture for ${ path }` } );
	}

	if ( false === options.parse ) {
		return Promise.resolve(
			new window.Response( JSON.stringify( data ), { headers: { 'X-WP-Total': String( data.length || 0 ) } } )
		);
	}

	return Promise.resolve( data );
} );

const noop = () => {};
const screens = {
	dashboard: () => <Dashboard onReady={ noop } onFail={ noop } />,
	list: () => <List onReady={ noop } onFail={ noop } onOpen={ () => ( window.location.hash = 'detail' ) } />,
	detail: () => <Detail id={ 812 } onReady={ noop } onFail={ noop } />,
	reports: () => <Reports onReady={ noop } onFail={ noop } />,
	health: () => <Health onReady={ noop } onFail={ noop } />,
};

const titles = {
	dashboard: [ 'Home', '' ],
	list: [ 'All subscriptions', 'Everyone who pays you on a schedule' ],
	detail: [ 'Subscription #812', '' ],
	reports: [ 'Reports', 'Recurring revenue, new subscriptions and why people cancel.' ],
	health: [ 'Health', 'Subscriptions at risk of not renewing, why, and what fixes them.' ],
};

function render() {
	const name = window.location.hash.replace( '#', '' ) || 'dashboard';
	const [ title, subtitle ] = titles[ name ] || titles.dashboard;

	document.querySelector( '.submex-crumbs__current' ).textContent = title;
	document.querySelector( '.submex-head__title' ).textContent = title;
	document.querySelector( '.submex-head__subtitle' ).textContent = subtitle;
	// The same class the real shell uses; the hidden attribute loses to the flex rule.
	document.querySelector( '.submex-head' ).classList.toggle( 'screen-reader-text', 'dashboard' === name || 'detail' === name );

	const host = document.getElementById( 'screen' );
	host.innerHTML = '';
	createRoot( host ).render( ( screens[ name ] || screens.dashboard )() );
}

window.addEventListener( 'hashchange', render );
render();
