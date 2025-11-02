# Nostr for WordPress

Two-way synchronization between WordPress content and Nostr protocol. Supports kind 1 notes and kind 30023 long-form content with NIP-07 browser extension signing.

## Features

- 🔄 Two-way sync between WordPress and Nostr
- 📝 Support for kind 1 (short notes) and kind 30023 (long-form articles)  
- 🔐 NIP-07 browser extension integration
- 🎨 Gutenberg blocks for displaying Nostr content
- ⚡ WebSocket relay connections
- 🔁 Automatic background sync

## Quick Start

### For Users

1. Install and activate the plugin in WordPress
2. Go to Settings > Nostr to configure
3. Add blocks to your posts/pages

### For Developers

```bash
# Clone and setup
git clone https://github.com/danieljwonder/nostr-for-wp.git
cd nostr-for-wp
npm install
npm run build

# Development
npm start           # Watch mode with auto-rebuild
# Edit files in src/blocks/
```

See **[BUILD.md](BUILD.md)** for complete build documentation.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Node.js 18+ (for development only)

## Project Structure

```
src/blocks/          # Source files (edit these)
build/               # Compiled files (auto-generated)
includes/            # PHP classes
admin/               # Admin interface
```

## Development

The plugin uses `@wordpress/scripts` for modern block development:

- Modern ES6+ JavaScript with JSX
- SCSS preprocessing
- Development watch mode
- Automatic optimization

## Documentation

- **[BUILD.md](BUILD.md)** - Complete build system guide
- **[readme.txt](readme.txt)** - WordPress.org plugin readme

## License

GPL v2 or later

## Credits

- Author: Daniel Wonder
- Built with [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts)
