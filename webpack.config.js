const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const reactReconcilerRoot = path.join(
	path.dirname( require.resolve( 'react-konva/package.json' ) ),
	'node_modules/react-reconciler'
);

module.exports = {
	...defaultConfig,
	entry: {
		admin: './src-js/admin/index.tsx',
		student: './src-js/student/index.tsx',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		// Resolve async chunks and PDF worker assets from the enqueued plugin script.
		publicPath: 'auto',
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			{
				test: /pdfjs-dist[\\/]build[\\/]pdf\.mjs$/,
				use: [ path.resolve( __dirname, 'build-tools/pdfjs-browser-loader.js' ) ],
			},
			{
				test: /pdf\.worker\.min\.mjs$/,
				type: 'asset/resource',
				generator: {
					filename: 'pdf.worker.min.js',
				},
			},
		],
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'react-reconciler$': path.join(
				reactReconcilerRoot,
				'cjs/react-reconciler.production.min.js'
			),
		},
	},
};
