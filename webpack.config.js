const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		'nostr-notes/index': path.resolve(process.cwd(), 'src/blocks/nostr-notes', 'index.js'),
	},
};

