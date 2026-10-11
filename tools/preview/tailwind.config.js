// Free's config, reading both plugins' sources so Pro's screens get their utilities too.
const base = require( '../../tailwind.config.js' );

module.exports = {
	...base,
	content: [ '../../src/**/*.{js,jsx}', '../../../submex-pro/src/**/*.{js,jsx}', './*.js' ].map( ( glob ) =>
		require( 'path' ).resolve( __dirname, glob )
	),
};
