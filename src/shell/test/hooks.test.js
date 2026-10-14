/**
 * The names Pro fills on the Retention Flow screen are a contract, and both plugins must
 * reach the same filters through them.
 */
import { applyFilters, addFilter, removeAllFilters } from '@wordpress/hooks';
import * as shell from '../index';

describe( 'the retention extension points', () => {
	it( 'are published under their exact names', () => {
		expect( shell.retentionHooks ).toEqual( {
			offersTab: 'submex.retention.offersTab',
			reasonStats: 'submex.retention.reasonStats',
			messageTypes: 'submex.retention.messageTypes',
		} );
		expect( Object.isFrozen( shell.retentionHooks ) ).toBe( true );
	} );

	it( 'share one set of filters with wp.hooks', () => {
		shell.addFilter( 'submex.retention.offersTab', 'test', () => 'pro' );
		expect( applyFilters( 'submex.retention.offersTab', 'free' ) ).toBe(
			'pro'
		);

		addFilter( 'submex.retention.messageTypes', 'test', ( types ) => [
			...types,
			'pause',
		] );
		expect(
			shell.applyFilters( 'submex.retention.messageTypes', [ 'cancel' ] )
		).toEqual( [ 'cancel', 'pause' ] );

		removeAllFilters( 'submex.retention.offersTab' );
		removeAllFilters( 'submex.retention.messageTypes' );
	} );
} );
