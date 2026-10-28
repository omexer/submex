/**
 * Registers Submex's gateways with the block checkout.
 *
 * Stripe and PayPal redirect to the provider, so neither renders a field here: the block shows the
 * title and description, and the redirect comes back from the server on submit.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { createElement as el } from '@wordpress/element';

const GATEWAYS = [ 'submex_stripe', 'submex_paypal' ];

GATEWAYS.forEach( ( name ) => {
	const settings = getSetting( `${ name }_data`, null );

	// Absent when the gateway is not available for this cart, which the server has
	// already decided. Registering it anyway would offer a method that cannot be used.
	if ( ! settings ) {
		return;
	}

	const label = decodeEntities( settings.title || '' );
	const description = decodeEntities( settings.description || '' );

	registerPaymentMethod( {
		name,
		label: el( 'span', {}, label ),
		content: el( 'div', {}, description ),
		edit: el( 'div', {}, description ),
		ariaLabel: label,
		canMakePayment: () => true,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} );
