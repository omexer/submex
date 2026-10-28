/**
 * The block checkout shows a payment method only if this registers it. These cover which
 * gateways get registered, and the case that made the bug invisible: the server deciding
 * a gateway is not available for this cart.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';

// Virtual: WooCommerce puts these on the page at runtime, so there is no module on disk
// for jest to find and replace.
jest.mock(
	'@woocommerce/blocks-registry',
	() => ( { registerPaymentMethod: jest.fn() } ),
	{ virtual: true }
);
jest.mock( '@woocommerce/settings', () => ( { getSetting: jest.fn() } ), {
	virtual: true,
} );

const load = () => {
	jest.isolateModules( () => {
		require( '../index' );
	} );
};

describe( 'registering Submex with the block checkout', () => {
	beforeEach( () => {
		registerPaymentMethod.mockClear();
		getSetting.mockReset();
	} );

	it( 'registers PayPal when the server says it is available', () => {
		getSetting.mockImplementation(
			( key ) =>
				( {
					submex_paypal_data: {
						title: 'PayPal',
						description: 'Pay with PayPal.',
						supports: [ 'products' ],
					},
				} )[ key ] || null
		);

		load();

		expect( registerPaymentMethod ).toHaveBeenCalledTimes( 1 );
		expect( registerPaymentMethod.mock.calls[ 0 ][ 0 ].name ).toBe(
			'submex_paypal'
		);
		expect( registerPaymentMethod.mock.calls[ 0 ][ 0 ].ariaLabel ).toBe(
			'PayPal'
		);
	} );

	it( 'registers Stripe when the server says it is available', () => {
		getSetting.mockImplementation( ( key ) =>
			'submex_stripe_data' === key
				? { title: 'Card', description: '', supports: [ 'products' ] }
				: null
		);

		load();

		expect( registerPaymentMethod ).toHaveBeenCalledTimes( 1 );
		expect( registerPaymentMethod.mock.calls[ 0 ][ 0 ].name ).toBe(
			'submex_stripe'
		);
	} );

	it( 'leaves a gateway another plugin adds to that plugin', () => {
		getSetting.mockImplementation( ( key ) =>
			'submex_adyen_data' === key
				? { title: 'Card', description: '', supports: [ 'products' ] }
				: null
		);

		load();

		expect( registerPaymentMethod ).not.toHaveBeenCalled();
	} );

	it( 'registers nothing at all when no Submex gateway is available', () => {
		getSetting.mockReturnValue( null );

		load();

		expect( registerPaymentMethod ).not.toHaveBeenCalled();
	} );

	it( 'decodes a title the server encoded', () => {
		getSetting.mockImplementation( ( key ) =>
			'submex_paypal_data' === key
				? {
						title: 'PayPal &amp; Pay Later',
						description: '',
						supports: [],
				  }
				: null
		);

		load();

		expect( registerPaymentMethod.mock.calls[ 0 ][ 0 ].ariaLabel ).toBe(
			'PayPal & Pay Later'
		);
	} );
} );
