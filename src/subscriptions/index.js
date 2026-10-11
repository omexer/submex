/**
 * The subscriptions route: the list, or one subscription when the address names one.
 */
import { __ } from '@wordpress/i18n';
import { registerRoute } from '@submex/shell';
import { List } from './list';
import { Detail } from './detail';

export function Screen( { params, setParams, fail } ) {
	const id = Number( params.get( 'subscription' ) ) || 0;

	const open = ( next ) => {
		const query = new URLSearchParams( params );

		if ( next ) {
			query.set( 'subscription', String( next ) );
		} else {
			query.delete( 'subscription' );
		}

		setParams( query );
	};

	return id ? (
		<Detail id={ id } onBack={ () => open( 0 ) } onFail={ fail } />
	) : (
		<List
			initialStatus={ params.get( 'status' ) || '' }
			initialSearch={ params.get( 's' ) || '' }
			onOpen={ open }
			onFail={ fail }
		/>
	);
}

registerRoute( {
	page: 'submex-list',
	title: __( 'All subscriptions', 'submex' ),
	render: ( ctx ) => <Screen { ...ctx } />,
} );
