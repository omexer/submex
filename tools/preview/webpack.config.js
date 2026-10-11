/**
 * A standalone build of the admin screens, for looking at them outside WordPress.
 *
 * The production build leaves React and the wp.* packages to WordPress; this one bundles
 * them, and answers every REST request from fixtures, so a screen can be opened as a
 * plain page and screenshotted. Development only - nothing here ships.
 */
const path = require( 'path' );
const defaults = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtraction = require( '@wordpress/dependency-extraction-webpack-plugin' );

const free = path.resolve( __dirname, '../..' );
const pro = path.resolve( free, '../submex-pro' );
const own = ( name ) => path.resolve( free, 'node_modules', name );

module.exports = {
	...defaults,
	entry: { preview: path.resolve( __dirname, 'index.js' ) },
	output: {
		path: process.env.SUBMEX_PREVIEW_OUT || path.resolve( free, '.preview' ),
		filename: '[name].js',
	},
	externals: {},
	plugins: defaults.plugins.filter( ( plugin ) => ! ( plugin instanceof DependencyExtraction ) ),
	resolve: {
		...defaults.resolve,
		alias: {
			...( defaults.resolve?.alias || {} ),
			'@submex/ui': path.resolve( free, 'src/ui/index.js' ),
			'@submex/pro': path.resolve( pro, 'src' ),
			// One copy of each, whichever plugin imports it: two Reacts break every hook.
			react: own( 'react' ),
			'react-dom': own( 'react-dom' ),
			'@wordpress/element': own( '@wordpress/element' ),
			'@wordpress/i18n': own( '@wordpress/i18n' ),
			'@wordpress/api-fetch': own( '@wordpress/api-fetch' ),
		},
	},
};
