const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// Admin dashboard bundle — uses WP externals (React lives in wp.element)
const adminConfig = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src/admin/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js' ),
		filename: '[name].js',
		clean: true, // Clean the output dir once, on the admin build
	},
};

// Candidate embed bundle — self-contained, no WP globals required on public pages
const embedPlugins = ( defaultConfig.plugins || [] ).filter(
	( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

const embedConfig = {
	...defaultConfig,
	entry: {
		embed: path.resolve( __dirname, 'src/embed/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js' ),
		filename: '[name].js',
		clean: false, // Don't clean — admin bundle runs first and we share the output dir
	},
	externals: {}, // Bundle React + all deps directly; no WP globals assumed
	plugins: embedPlugins,
};

module.exports = [ adminConfig, embedConfig ];
