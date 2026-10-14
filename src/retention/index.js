/**
 * The Retention Flow route.
 */
import { __ } from '@wordpress/i18n';
import { registerRoute } from '@submex/shell';
import { PAGE, Retention } from './screen';

registerRoute( {
	page: PAGE,
	title: __( 'Retention Flow', 'submex' ),
	render: ( ctx ) => (
		<Retention
			params={ ctx.params }
			setParams={ ctx.setParams }
			onFail={ ctx.fail }
		/>
	),
} );
