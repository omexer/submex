const defaults = require( '@wordpress/scripts/config/webpack.config' );

/**
 * The shared UI, the app shell, and the routes that register with it.
 *
 * Submex Pro cannot import from this package at build time - it is a separate plugin with
 * its own build - so the shared UI and the shell are published on globals and consumed as
 * externals, exactly how WordPress ships wp.components to everyone else.
 */
module.exports = {
	...defaults,
	entry: {
		ui: {
			import: './src/ui/index.js',
			library: { name: [ 'submex', 'ui' ], type: 'window' },
		},
		shell: {
			import: './src/shell/index.js',
			library: { name: [ 'submex', 'shell' ], type: 'window' },
		},
		dashboard: './src/dashboard/index.js',
		subscriptions: './src/subscriptions/index.js',
		integrations: './src/integrations/index.js',
		retention: './src/retention/index.js',
		help: './src/help/index.js',
		settings: {
			import: './src/settings/index.js',
			library: { name: [ 'submex', 'settings' ], type: 'window' },
		},
		blocks: './src/blocks/index.js',
	},
	externals: {
		...( defaults.externals || {} ),
		'@submex/ui': [ 'submex', 'ui' ],
		'@submex/shell': [ 'submex', 'shell' ],
		// WooCommerce puts these on the page itself; they are not packages to bundle.
		'@woocommerce/blocks-registry': [ 'wc', 'wcBlocksRegistry' ],
		'@woocommerce/settings': [ 'wc', 'wcSettings' ],
	},
};
