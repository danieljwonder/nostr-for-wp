# Build System Guide

## 🚀 Quick Start

### First Time Setup
```bash
npm install          # Install dependencies
npm run build        # Build the blocks
```

### Development
```bash
npm start           # Watch mode - auto-rebuilds on changes
# Edit files in src/blocks/
# Save → Auto-rebuild → Refresh WordPress
```

### Production Build
```bash
npm run build       # Minified, optimized build
```

### Creating Plugin ZIP for Distribution
```bash
npm run plugin-zip  # Creates a ZIP file ready for WordPress installation
```

This command creates a distributable ZIP file of the plugin, automatically excluding development files like `node_modules/`, `.git/`, and other non-essential files. The ZIP file will be created in the project root directory and can be directly uploaded to WordPress or distributed to users.

**Note:** Make sure to run `npm run build` first to ensure all compiled assets are included in the ZIP file.



### Directory Structure

```
src/blocks/              ← Edit these (source files)
└── nostr-notes/
    ├── index.js        # Block registration
    ├── edit.js         # Editor component
    ├── save.js         # Save function
    ├── style.scss      # Frontend styles
    └── editor.scss     # Editor styles

build/                   ← WordPress loads these (auto-generated)
└── blocks/
    └── nostr-notes/
        ├── block.json  # Block metadata
        ├── index.js   # Compiled & minified
        ├── index.css  # Compiled editor styles
        └── style-index.css # Compiled frontend styles
```

## Available Commands

| Command | Purpose |
|---------|---------|
| `npm install` | Install dependencies (first time only) |
| `npm start` | Development watch mode |
| `npm run build` | Production build |
| `npm run plugin-zip` | Create distributable plugin ZIP file |
| `npm run format` | Format code |
| `npm run lint:js` | Lint JavaScript |
| `npm run lint:css` | Lint CSS/SCSS |

## Adding New Blocks

1. **Create block directory:**
   ```bash
   cd src/blocks/
   npx @wordpress/create-block@latest my-new-block --no-plugin
   ```

2. **No webpack.config.js changes needed!**
   `@wordpress/scripts` automatically detects `block.json` files in `src/blocks/` and builds them to `build/blocks/`.

3. **Register in `nostr-for-wp.php`:**
   ```php
   register_block_type(NOSTR_FOR_WP_PLUGIN_DIR . 'build/blocks/my-new-block');
   ```

4. **Build:**
   ```bash
   npm run build
   ```

## Key Benefits

✅ Modern ES6+ JavaScript with JSX  
✅ SCSS preprocessing (variables, nesting)  
✅ Automatic dependency management  
✅ Development watch mode (auto-rebuild)  
✅ Production optimization (minification)  
✅ Code quality tools (ESLint, Prettier)  

## Troubleshooting

### Blocks not appearing in WordPress?
```bash
npm run build                    # Make sure build ran
ls build/blocks/nostr-notes/    # Verify files exist
```

### Changes not reflecting?
- Edit files in `src/blocks/` (not `build/blocks/`)
- Run `npm start` for auto-rebuild
- Refresh your browser

### Build errors?
```bash
rm -rf node_modules/ build/
npm install
npm run build
```

## Important Notes

**What to edit:**
- ✅ `src/blocks/*/` - All source code
- ❌ `build/*/` - Auto-generated (don't edit)
- ❌ `node_modules/` - Dependencies (don't edit)

**What to commit:**
- ✅ `src/` directory
- ✅ `build/` directory (so users don't need Node.js)
- ✅ `package.json` and `package-lock.json`
- ❌ `node_modules/` (excluded in `.gitignore`)

**After testing:**
You can delete the old `blocks/` directory once you've confirmed everything works.

## Resources

- [@wordpress/scripts documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/)
- [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
