/**
 * The Settings route, driven through a stand-in for the shell: sections switch without
 * losing edits, dependent rows follow their switch, and a save sends only what changed.
 */
import { act } from 'react';
import { createRoot, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { registerEmailEditor } from '../extend';

jest.mock( '@wordpress/api-fetch' );

const MENU = {
	groups: [
		{
			id: 'general',
			label: 'General',
			icon: '<path d="M3 3h1"/>',
			list: false,
			sections: [ { id: 'general', title: 'General' } ],
		},
		{
			id: 'customers',
			label: 'Customer Controls',
			icon: '<path d="M3 3h1"/>',
			list: false,
			sections: [
				{ id: 'customer_controls', title: 'Customer controls' },
			],
		},
		{
			id: 'payments',
			label: 'Payments',
			icon: '<path d="M3 3h1"/>',
			list: true,
			sections: [
				{ id: 'mollie', title: 'Mollie' },
				{ id: 'paypal', title: 'PayPal' },
			],
		},
	],
	notices: [],
};

function field( id, kind, value, extra = {} ) {
	return {
		id,
		type: kind,
		title: `Title of ${ id }`,
		desc: '',
		desc_tip: false,
		help: '',
		suffix: '',
		options: [],
		default: '',
		value,
		placeholder: '',
		custom_attributes: {},
		submex_show_if: '',
		submex_joins: '',
		joined: [],
		...extra,
	};
}

const PAGES = {
	customer_controls: {
		section: 'customer_controls',
		group: 'customers',
		sections: [ 'customer_controls' ],
		cards: [
			{
				title: '',
				desc: '',
				anchor: 'customer_controls',
				rows: [
					field( 'allow_cancel', 'checkbox', 'yes', {
						help: 'Customers can cancel from <strong>My Account</strong>.',
					} ),
					field( 'cancel_when', 'select', 'end', {
						submex_show_if: 'allow_cancel',
						options: [
							{ value: 'end', label: 'End of billing cycle' },
							{ value: 'now', label: 'Immediately' },
						],
					} ),
					field( 'allow_pause', 'checkbox', 'yes' ),
					field( 'pause_length', 'number', '3', {
						submex_show_if: 'allow_pause',
						joined: [
							field( 'pause_unit', 'select', 'month', {
								submex_show_if: 'allow_pause',
								submex_joins: 'pause_length',
								options: [
									{ value: 'day', label: 'Days' },
									{ value: 'month', label: 'Months' },
								],
							} ),
						],
					} ),
					field( 'pause_reason', 'checkbox', 'yes', {
						submex_show_if: 'allow_pause',
					} ),
					field( 'reason_prompt', 'text', 'Why?', {
						submex_show_if: 'pause_reason',
					} ),
				],
			},
		],
	},
	general: {
		section: 'general',
		group: 'general',
		sections: [ 'general' ],
		cards: [
			{
				title: 'Health',
				desc: '',
				anchor: 'general',
				rows: [
					{
						type: 'submex_status',
						html: '<div class="submex-checks">Renewal queue</div>',
					},
				],
			},
			{
				title: 'Access',
				desc: '',
				anchor: null,
				rows: [ field( 'role_after', 'text', 'customer' ) ],
			},
		],
	},
};

PAGES.notifications = {
	section: 'notifications',
	group: 'notifications',
	sections: [ 'notifications' ],
	cards: [
		{
			title: '',
			desc: '',
			anchor: 'notifications',
			rows: [
				field(
					'woocommerce_submex_renewal_reminder_settings[enabled]',
					'checkbox',
					'yes',
					{
						title: 'Renewal reminder',
						submex_email: 'submex_renewal_reminder',
						preview_url:
							'/wp-admin/?preview_woocommerce_mail=true&type=Reminder',
					}
				),
				field( 'plain_switch', 'checkbox', 'yes' ),
			],
		},
	],
};

let saveReply;

function respond() {
	apiFetch.mockImplementation( ( options ) => {
		if ( options.method === 'POST' ) {
			return saveReply( options );
		}

		if ( options.path.startsWith( '/submex/v1/settings/' ) ) {
			const section = options.path
				.slice( '/submex/v1/settings/'.length )
				.split( '?' )[ 0 ];

			return Promise.resolve( PAGES[ section ] );
		}

		return Promise.resolve( MENU );
	} );
}

let route;
let container;
let root;
let setParams;
let setQuery;

function Host( { initial } ) {
	const [ params, setState ] = useState(
		() => new URLSearchParams( initial )
	);

	setQuery = setState;

	return route.render( { params, setParams, navigate: jest.fn() } );
}

async function flush() {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

async function render( initial = 'section=customer_controls' ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );

	await act( async () => {
		root.render( <Host initial={ initial } /> );
	} );
	await flush();
}

function el( selector ) {
	return container.querySelector( selector );
}

async function type( node, value ) {
	const proto = Object.getPrototypeOf( node );

	await act( async () => {
		Object.getOwnPropertyDescriptor( proto, 'value' ).set.call(
			node,
			value
		);
		node.dispatchEvent(
			new Event( node.tagName === 'SELECT' ? 'change' : 'input', {
				bubbles: true,
			} )
		);
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
	await flush();
}

function link( text ) {
	return Array.from( container.querySelectorAll( 'nav a' ) ).find(
		( a ) => a.textContent === text
	);
}

async function save() {
	await act( async () => {
		el( 'form' ).dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	} );
	await flush();
}

beforeAll( () => {
	window.submex = {
		shell: {
			registerRoute: ( registered ) => {
				route = registered;
			},
		},
	};

	require( '../index' );
} );

beforeEach( () => {
	window.history.replaceState(
		{},
		'',
		'/wp-admin/admin.php?page=submex-settings'
	);
	apiFetch.mockReset();
	respond();
	setParams = jest.fn( ( next ) => setQuery( new URLSearchParams( next ) ) );
	saveReply = ( options ) =>
		Promise.resolve( {
			saved: true,
			values: options.data.values,
			errors: [],
			messages: [],
		} );
} );

afterEach( () => {
	if ( root ) {
		act( () => root.unmount() );
		container.remove();
		root = null;
	}
} );

test( 'registers itself with the shell for the Settings page', () => {
	expect( route.page ).toBe( 'submex-settings' );
	expect( route.title ).toBe( 'Settings' );
} );

test( 'draws a section from its fields', async () => {
	await render();

	const toggle = el( '#allow_cancel' );

	expect( toggle.getAttribute( 'role' ) ).toBe( 'switch' );
	expect( toggle.checked ).toBe( true );
	expect( el( 'label[for="allow_cancel"]' ).textContent ).toBe(
		'Title of allow_cancel'
	);
	expect( el( '#allow_cancel-help' ).innerHTML ).toContain(
		'<strong>My Account</strong>'
	);
	expect( toggle.getAttribute( 'aria-describedby' ) ).toBe(
		'allow_cancel-help'
	);
	expect( el( '#pause_unit' ).value ).toBe( 'month' );
	expect( el( '#pause_length' ).closest( '.submex-settings__control' ) ).toBe(
		el( '#pause_unit' ).closest( '.submex-settings__control' )
	);
	expect( el( 'label[for="pause_unit"]' ).className ).toBe(
		'screen-reader-text'
	);
	expect( link( 'Customer Controls' ).getAttribute( 'aria-current' ) ).toBe(
		'page'
	);
	expect( el( 'button[type="submit"]' ).disabled ).toBe( true );
} );

test( 'draws PHP-rendered rows as they come', async () => {
	await render( '' );

	expect( el( '.submex-settings__row--wide' ).innerHTML ).toBe(
		'<div class="submex-checks">Renewal queue</div>'
	);
	expect(
		Array.from( container.querySelectorAll( 'h2' ) ).map(
			( h ) => h.textContent
		)
	).toEqual( [ 'Health', 'Access' ] );
} );

test( 'switching sections keeps unsaved edits, without reloading the page', async () => {
	await render();

	await type( el( '#cancel_when' ), 'now' );
	await click( link( 'General' ) );

	expect( setParams ).toHaveBeenLastCalledWith( {} );
	expect( el( '#role_after' ) ).not.toBeNull();
	expect( el( '.submex-settings__save-note' ).textContent ).toBe(
		'You have unsaved changes.'
	);

	await click( link( 'Customer Controls' ) );

	expect( setParams ).toHaveBeenLastCalledWith( {
		section: 'customer_controls',
	} );
	expect( el( '#cancel_when' ).value ).toBe( 'now' );
	expect( el( 'button[type="submit"]' ).disabled ).toBe( false );

	const fetched = apiFetch.mock.calls.filter( ( [ options ] ) =>
		options.path.startsWith( '/submex/v1/settings/customer_controls' )
	);

	expect( fetched ).toHaveLength( 1 );
} );

test( 'a notice from the last page load is shown until the next section', async () => {
	MENU.notices = [ { type: 'good', message: 'Licence activated.' } ];
	await render();
	MENU.notices = [];

	expect( el( '.submex-notice--good' ).textContent ).toBe(
		'Licence activated.'
	);

	await click( link( 'General' ) );

	expect( el( '.submex-notice--good' ) ).toBeNull();
} );

test( 'a listed group shows its sections under it once open', async () => {
	await render();

	expect( link( 'Mollie' ) ).toBeUndefined();

	PAGES.mollie = {
		section: 'mollie',
		group: 'payments',
		sections: [ 'mollie' ],
		cards: [
			{
				title: '',
				desc: '',
				anchor: 'mollie',
				rows: [ field( 'mollie_on', 'checkbox', 'no' ) ],
			},
		],
	};

	await click( link( 'Payments' ) );

	expect( link( 'Mollie' ).getAttribute( 'aria-current' ) ).toBe( 'page' );
	expect( link( 'Payments' ).getAttribute( 'aria-current' ) ).toBeNull();
	expect( link( 'PayPal' ) ).not.toBeUndefined();

	delete PAGES.mollie;
} );

test( 'rows follow their switch as it changes, and keep their value while hidden', async () => {
	await render();

	await type( el( '#cancel_when' ), 'now' );
	await click( el( '#allow_cancel' ) );

	expect( el( '#cancel_when' ) ).toBeNull();

	await click( el( '#allow_cancel' ) );

	expect( el( '#cancel_when' ).value ).toBe( 'now' );
} );

test( 'a row behind a switch that is itself hidden stays hidden', async () => {
	await render();

	expect( el( '#reason_prompt' ) ).not.toBeNull();

	await click( el( '#allow_pause' ) );

	expect( el( '#pause_reason' ) ).toBeNull();
	expect( el( '#pause_length' ) ).toBeNull();
	expect( el( '#reason_prompt' ) ).toBeNull();
} );

test( 'saving sends only the fields that changed', async () => {
	await render();

	await type( el( '#cancel_when' ), 'now' );
	await type( el( '#pause_length' ), '6' );
	await type( el( '#reason_prompt' ), 'Why?' );
	await save();

	const posts = apiFetch.mock.calls.filter(
		( [ options ] ) => options.method === 'POST'
	);

	expect( posts ).toHaveLength( 1 );
	expect( posts[ 0 ][ 0 ].path ).toBe(
		'/submex/v1/settings/customer_controls'
	);
	expect( posts[ 0 ][ 0 ].data ).toEqual( {
		values: { cancel_when: 'now', pause_length: '6' },
	} );
	expect( el( '[role="status"]' ).textContent ).toBe( 'Settings saved.' );
	expect( el( 'button[type="submit"]' ).disabled ).toBe( true );
	expect( el( '.submex-settings__save-note' ).textContent ).not.toBe(
		'You have unsaved changes.'
	);
} );

test( 'shows the value the store kept, when it changed what was sent', async () => {
	saveReply = () =>
		Promise.resolve( {
			saved: true,
			values: { pause_length: '1' },
			errors: [],
			messages: [],
		} );

	await render();
	await type( el( '#pause_length' ), '-4' );
	await save();

	expect( el( '#pause_length' ).value ).toBe( '1' );
} );

test( 'warnings the store raised on save are shown instead of the toast', async () => {
	saveReply = ( options ) =>
		Promise.resolve( {
			saved: true,
			values: options.data.values,
			errors: [ 'The retries add up to 96 hours.' ],
			messages: [],
		} );

	await render();
	await type( el( '#cancel_when' ), 'now' );
	await save();

	expect( el( '[role="alert"]' ).textContent ).toBe(
		'The retries add up to 96 hours.'
	);
	expect( el( '[role="status"]' ).textContent ).toBe( '' );
} );

test( 'a refused save shows why and keeps the edits', async () => {
	saveReply = () =>
		Promise.reject( {
			code: 'submex_unknown_setting',
			message: 'Not saved: cancel_when is not a setting on this page.',
		} );

	await render();
	await type( el( '#cancel_when' ), 'now' );
	await save();

	expect( el( '[role="alert"]' ).textContent ).toBe(
		'Not saved: cancel_when is not a setting on this page.'
	);
	expect( el( '#cancel_when' ).value ).toBe( 'now' );
	expect( el( 'button[type="submit"]' ).disabled ).toBe( false );
} );

test( 'leaving the route with unsaved changes asks first', async () => {
	await render();

	const away = document.createElement( 'a' );

	away.href = 'admin.php?page=submex';
	// Stands in for the shell, which routes the link itself.
	away.addEventListener( 'click', ( event ) => event.preventDefault() );
	document.body.appendChild( away );

	const confirm = jest.spyOn( window, 'confirm' ).mockReturnValue( false );
	const first = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
		button: 0,
	} );

	away.dispatchEvent( first );
	expect( confirm ).not.toHaveBeenCalled();

	await type( el( '#cancel_when' ), 'now' );

	const second = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
		button: 0,
	} );

	away.dispatchEvent( second );

	expect( confirm ).toHaveBeenCalledTimes( 1 );
	expect( second.defaultPrevented ).toBe( true );

	await click( link( 'General' ) );
	expect( confirm ).toHaveBeenCalledTimes( 1 );

	confirm.mockRestore();
	away.remove();
} );

test( 'a section that cannot load says so', async () => {
	apiFetch.mockImplementation( ( options ) =>
		options.path.startsWith( '/submex/v1/settings/' )
			? Promise.reject( {
					message: 'There is no such settings section.',
			  } )
			: Promise.resolve( MENU )
	);

	await render( 'section=nope' );

	expect( el( '[role="alert"]' ).textContent ).toBe(
		'There is no such settings section.'
	);
} );

test( 'an email row offers its preview in a new tab, and nothing to edit it with', async () => {
	await render( 'section=notifications&email=submex_renewal_reminder' );

	const preview = Array.from( container.querySelectorAll( 'a' ) ).find(
		( a ) => a.textContent.startsWith( 'Preview' )
	);

	expect( preview.getAttribute( 'href' ) ).toBe(
		'/wp-admin/?preview_woocommerce_mail=true&type=Reminder'
	);
	expect( preview.getAttribute( 'target' ) ).toBe( '_blank' );
	expect( preview.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
	expect( preview.textContent ).toBe(
		'Preview Renewal reminder (opens in a new tab)'
	);
	expect(
		el( '#plain_switch' ).closest( '.submex-settings__row' ).textContent
	).not.toContain( 'Preview' );
	expect( container.textContent ).not.toContain( 'Edit' );
	// Without an editor, an email in the address is ignored and the list is drawn.
	expect( el( '#plain_switch' ) ).not.toBeNull();
} );

describe( 'with an email editor registered', () => {
	beforeEach( () => {
		registerEmailEditor( ( { id, onBack, onDirty } ) => (
			<div>
				<p id="editing">{ id }</p>
				<button type="button" onClick={ () => onDirty( true ) }>
					Dirty
				</button>
				<button type="button" onClick={ onBack }>
					Back
				</button>
			</div>
		) );
	} );

	afterEach( () => registerEmailEditor( null ) );

	function button( text ) {
		return Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === text
		);
	}

	test( 'an email row opens that email in the editor, and comes back', async () => {
		await render( 'section=notifications' );

		expect(
			el( '#plain_switch' ).closest( '.submex-settings__row' ).textContent
		).not.toContain( 'Edit' );

		await click( el( 'button[aria-label="Edit Renewal reminder"]' ) );

		expect( setParams ).toHaveBeenLastCalledWith( {
			section: 'notifications',
			email: 'submex_renewal_reminder',
		} );
		expect( el( '#editing' ).textContent ).toBe(
			'submex_renewal_reminder'
		);

		await click( button( 'Back' ) );

		expect( setParams ).toHaveBeenLastCalledWith( {
			section: 'notifications',
		} );
		expect( el( '#plain_switch' ) ).not.toBeNull();
	} );

	test( 'leaving an editor with unsaved edits asks first', async () => {
		await render( 'section=notifications&email=submex_renewal_reminder' );
		await click( button( 'Dirty' ) );

		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( false );

		await click( button( 'Back' ) );
		await click( link( 'General' ) );

		expect( confirm ).toHaveBeenCalledTimes( 2 );
		expect( el( '#editing' ) ).not.toBeNull();

		confirm.mockRestore();
	} );
} );

describe( 'a gateway with an environment select', () => {
	const when = ( live ) => ( { pay_live: [ live ] } );

	beforeEach( () => {
		PAGES.testpay = {
			section: 'testpay',
			group: 'payments',
			sections: [ 'testpay' ],
			cards: [
				{
					title: '',
					desc: '',
					anchor: 'testpay',
					rows: [
						{
							type: 'submex_setup',
							html: '<div class="submex-setup">PHP</div>',
							submex_show_when: {},
							setup: {
								id: 'testpay-setup',
								title: 'Set up Testpay',
								done: 'Testpay is set up',
								desc: 'Renews <strong>cards</strong>.',
								steps: [
									{
										label: 'Add the webhook',
										detail: 'Paste this into Testpay.',
										done: null,
										filled: [],
										submex_show_when: {},
										action: {
											url: 'https://example.com/hooks',
											text: 'Open webhooks',
											external: true,
										},
										copy: {
											label: 'Webhook URL',
											value: 'https://shop.test/hook',
										},
										list: {
											summary: 'Events to send (2)',
											items: [ 'paid', 'failed' ],
										},
									},
									{
										label: 'Paste your sandbox key',
										detail: '',
										done: null,
										filled: [ 'pay_sandbox_key' ],
										submex_show_when: when( 'no' ),
										action: {
											url: 'https://sandbox.example.com/keys',
											text: 'Open sandbox keys',
											external: true,
										},
										copy: null,
										list: null,
									},
									{
										label: 'Paste your live key',
										detail: '',
										done: null,
										filled: [ 'pay_live_key' ],
										submex_show_when: when( 'yes' ),
										action: {
											url: 'https://example.com/keys',
											text: 'Open live keys',
											external: true,
										},
										copy: null,
										list: null,
									},
									{
										label: 'Turn on Testpay below',
										detail: '',
										done: null,
										filled: [ 'pay_on' ],
										submex_show_when: {},
										action: null,
										copy: null,
										list: null,
									},
								],
							},
						},
						field( 'pay_on', 'checkbox', 'no' ),
						field( 'pay_live', 'select', 'no', {
							options: [
								{ value: 'no', label: 'Sandbox' },
								{ value: 'yes', label: 'Live' },
							],
						} ),
						field( 'pay_sandbox_key', 'password', 'sb-key', {
							submex_show_when: when( 'no' ),
						} ),
						field( 'pay_live_key', 'password', '', {
							submex_show_when: when( 'yes' ),
						} ),
						field( 'pay_account', 'text', 'acct' ),
					],
				},
			],
		};
	} );

	afterEach( () => delete PAGES.testpay );

	function steps() {
		return Array.from(
			container.querySelectorAll( '.submex-setup__step' )
		).map( ( li ) => [
			li.querySelector( '.submex-setup__label' ).firstChild.textContent,
			li.className.replace( 'submex-setup__step ', '' ),
		] );
	}

	test( 'shows only the chosen environment, switching as the select changes', async () => {
		await render( 'section=testpay' );

		expect( el( '#pay_sandbox_key' ).value ).toBe( 'sb-key' );
		expect( el( '#pay_live_key' ) ).toBeNull();
		expect( el( '#pay_account' ) ).not.toBeNull();

		await type( el( '#pay_live' ), 'yes' );

		expect( el( '#pay_sandbox_key' ) ).toBeNull();
		expect( el( '#pay_live_key' ) ).not.toBeNull();
		expect( el( '#pay_account' ) ).not.toBeNull();

		await type( el( '#pay_live_key' ), 'live-key' );
		await type( el( '#pay_live' ), 'no' );

		expect( el( '#pay_sandbox_key' ).value ).toBe( 'sb-key' );

		await type( el( '#pay_live' ), 'yes' );

		expect( el( '#pay_live_key' ).value ).toBe( 'live-key' );
	} );

	test( 'saving after a switch sends the switch and what was typed, never the hidden keys', async () => {
		await render( 'section=testpay' );
		await type( el( '#pay_live' ), 'yes' );
		await type( el( '#pay_live_key' ), 'live-key' );
		await save();

		const post = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'POST'
		);

		expect( post[ 0 ].data ).toEqual( {
			values: { pay_live: 'yes', pay_live_key: 'live-key' },
		} );
	} );

	test( 'a setup card ticks its steps as the fields fill in', async () => {
		await render( 'section=testpay' );

		expect( el( '.submex-setup__title' ).textContent ).toBe(
			'Set up Testpay'
		);
		expect( el( '.submex-setup__desc' ).innerHTML ).toBe(
			'Renews <strong>cards</strong>.'
		);
		expect( steps() ).toEqual( [
			[ 'Add the webhook', 'is-manual' ],
			[ 'Paste your sandbox key', 'is-done' ],
			[ 'Turn on Testpay below', 'is-current' ],
		] );
		expect( el( '.submex-setup__count' ).textContent ).toBe(
			'1 of 2 done'
		);

		await type( el( '#pay_live' ), 'yes' );

		expect( steps() ).toEqual( [
			[ 'Add the webhook', 'is-manual' ],
			[ 'Paste your live key', 'is-current' ],
			[ 'Turn on Testpay below', 'is-next' ],
		] );

		const primary = container.querySelectorAll( '.submex-setup .submex-btn--primary' );

		expect( primary ).toHaveLength( 1 );
		expect( primary[ 0 ].getAttribute( 'href' ) ).toBe(
			'https://example.com/keys'
		);
		expect( primary[ 0 ].getAttribute( 'target' ) ).toBe( '_blank' );
		expect( primary[ 0 ].textContent ).toBe(
			'Open live keys (opens in a new tab)'
		);

		await type( el( '#pay_live_key' ), 'live-key' );
		await click( el( '#pay_on' ) );

		expect( el( '.submex-setup__title' ).textContent ).toBe(
			'Testpay is set up'
		);
		expect( el( '.submex-setup__count' ).textContent ).toBe(
			'2 of 2 done'
		);
		expect( el( '.submex-setup .submex-btn--primary' ) ).toBeNull();
	} );

	test( 'a step done in the provider keeps its link, its address and its events', async () => {
		await render( 'section=testpay' );

		const manual = el( '.submex-setup__step.is-manual' );
		const box = manual.querySelector( 'input' );

		expect( box.readOnly ).toBe( true );
		expect( box.value ).toBe( 'https://shop.test/hook' );
		expect( box.hasAttribute( 'name' ) ).toBe( false );
		expect(
			manual.querySelector( `label[for="${ box.id }"]` ).textContent
		).toBe( 'Webhook URL' );
		expect( manual.querySelector( 'summary' ).textContent ).toBe(
			'Events to send (2)'
		);
		expect(
			Array.from( manual.querySelectorAll( 'li code' ) ).map(
				( c ) => c.textContent
			)
		).toEqual( [ 'paid', 'failed' ] );
		expect(
			manual.querySelector( 'a.submex-btn' ).classList.contains(
				'submex-btn--primary'
			)
		).toBe( false );
	} );

	test( 'the copy button copies the address and says so', async () => {
		const writeText = jest.fn( () => Promise.resolve() );

		Object.defineProperty( window.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		} );

		await render( 'section=testpay' );
		await click( el( '.submex-setup__copy-button' ) );

		expect( writeText ).toHaveBeenCalledWith( 'https://shop.test/hook' );
		expect( el( '.submex-setup__copy-button' ).textContent ).toBe(
			'Copied'
		);
		expect( el( '.submex-setup__copy [role="status"]' ).textContent ).toBe(
			'Copied'
		);

		delete window.navigator.clipboard;
	} );

	test( 'after a save, the page is fetched again so rows PHP draws are current', async () => {
		await render( 'section=testpay' );
		await type( el( '#pay_account' ), 'acct-2' );
		await save();

		const reads = apiFetch.mock.calls.filter(
			( [ options ] ) =>
				! options.method &&
				options.path.startsWith( '/submex/v1/settings/testpay' )
		);

		expect( reads ).toHaveLength( 2 );
		expect( el( '#pay_account' ).value ).toBe( 'acct-2' );
	} );
} );
