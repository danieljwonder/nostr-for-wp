const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const CopyWebpackPlugin = require('copy-webpack-plugin');

module.exports = {
	...defaultConfig,
	entry: {
		'nostr-note/index': path.resolve(process.cwd(), 'src/blocks/nostr-note', 'index.js'),
		'nostr-notes/index': path.resolve(process.cwd(), 'src/blocks/nostr-notes', 'index.js'),
	},
	plugins: [
		...defaultConfig.plugins,
		new CopyWebpackPlugin({
			patterns: [
				{
					from: 'src/blocks/nostr-note/block.json',
					to: 'nostr-note/block.json',
				},
				{
					from: 'src/blocks/nostr-notes/block.json',
					to: 'nostr-notes/block.json',
				},
			],
		}),
	],
};

