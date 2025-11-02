const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		'nostr-note/index': path.resolve(process.cwd(), 'src/blocks/nostr-note', 'index.js'),
		'nostr-notes/index': path.resolve(process.cwd(), 'src/blocks/nostr-notes', 'index.js'),
	},
	output: {
		filename: '[name].js',
		path: path.resolve(process.cwd(), 'build/blocks'),
	},
};

