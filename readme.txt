=== Nostr for WordPress ===
Contributors: danieljwonder
Tags: nostr, social, sync, blockchain, decentralized
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-way synchronization between WordPress content and Nostr protocol. Supports kind 1 notes and kind 30023 long-form content with NIP-07 browser extension signing.

== Description ==

Nostr for WordPress enables seamless two-way synchronization between your WordPress site and the Nostr protocol. Share your WordPress content on Nostr and publish Nostr content to your WordPress site.

**Key Features:**

* **Two-Way Sync**: Automatically sync content between WordPress and Nostr
* **NIP-07 Integration**: Use your browser extension to sign events securely
* **Custom Post Types**: Dedicated "Notes" post type for kind 1 events
* **Long-Form Content**: Sync standard WordPress posts as kind 30023 events
* **Relay Management**: Configure multiple Nostr relays for redundancy
* **Conflict Resolution**: Smart timestamp-based conflict resolution
* **Opt-in Sync**: Choose which content to sync with Nostr
* **Real-time Updates**: Automatic sync on content changes
* **Custom Frontend Display**: Theme-agnostic display for notes with hidden titles and clean styling
* **Block Templates**: Automatic block templates for block themes
* **Gutenberg Blocks**: Display notes anywhere using custom blocks
* **Shortcodes**: Embed notes in posts, pages, or widgets using shortcodes

**Content Types:**

* **Kind 1 (Notes)**: Short text notes using the custom "Notes" post type
* **Kind 30023 (Long-form)**: Standard WordPress posts with full content, metadata, and tags

**Requirements:**

* WordPress 5.0 or higher
* PHP 7.4 or higher
* NIP-07 compatible browser extension (e.g., Alby, nos2x, etc.)
* Nostr relay access

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/nostr-for-wp` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Nostr to configure your Nostr connection
4. Install a NIP-07 compatible browser extension
5. Connect your Nostr account in the settings page
6. Configure your preferred relays
7. Enable sync for individual posts and notes

== Usage ==

**Displaying Notes:**

* **Shortcodes:**
  * `[nostr_note id="123"]` - Display a single note by ID
  * `[nostr_notes limit="10" orderby="date" order="DESC"]` - Display multiple notes with customizable options

* **Gutenberg Blocks:**
  * **Nostr Notes Block** - Add from the block inserter (Widgets category). Configure number of notes, sort order, and display options.
  * **Nostr Note Block** - Display a single note by selecting from a dropdown list.

* **Automatic Display:**
  * Notes automatically use custom templates that hide duplicated titles and show clean, card-based styling
  * Works with both classic and block themes
  * Block templates are automatically applied for block themes

== Frequently Asked Questions ==

= Do I need a Nostr account? =

Yes, you need a Nostr account and a NIP-07 compatible browser extension to use this plugin. Popular extensions include Alby, nos2x, and others.

= What content gets synced? =

By default, all new posts and notes are set to sync with Nostr. You can disable sync for individual posts using the checkbox in the post editor.

= How does conflict resolution work? =

When content is edited in both WordPress and Nostr, the version with the most recent timestamp wins. This ensures the latest changes are always preserved.

= Can I use multiple relays? =

Yes, you can configure multiple Nostr relays for redundancy. The plugin will attempt to publish to all configured relays.

= Is my private key stored on the server? =

No, your private key is never stored on the server. All signing is done client-side through your browser extension using the NIP-07 standard.

= What happens if a relay is down? =

The plugin will attempt to publish to all configured relays. If some relays are down, the content will still be published to available relays.

= How are notes displayed on the frontend? =

Notes automatically use custom display templates that hide duplicated titles and show only the content with clean metadata. The plugin provides block templates for block themes and works seamlessly with classic themes. You can also use shortcodes or Gutenberg blocks to display notes anywhere on your site.

= Can I customize how notes look? =

The plugin includes CSS styling that provides a clean, card-based design for notes. This styling is theme-agnostic and works across different WordPress themes. For advanced customization, you can override the CSS or modify the templates in the plugin's `templates/` and `block-templates/` directories.

== Screenshots ==

1. Nostr settings page with connection status
2. Post editor with Nostr sync options
3. Relay configuration interface
4. Sync status and statistics

== Changelog ==

= 1.2.0 =
* Added custom frontend display for notes with theme-agnostic styling
* Implemented block templates for block themes (single-note.html, archive-note.html)
* Added Gutenberg blocks: Nostr Notes block and Nostr Note block
* Added shortcodes: [nostr_note] and [nostr_notes] for arbitrary note display
* Enhanced note display to hide duplicated titles and show clean metadata
* Improved CSS targeting for better theme compatibility
* Notes now display with full event IDs on separate lines

= 1.1.0 =
* (Previous version features)

= 1.0.0 =
* Initial release
* Two-way sync between WordPress and Nostr
* NIP-07 browser extension integration
* Custom "Notes" post type for kind 1 events
* Long-form content sync (kind 30023)
* Relay management and configuration
* Conflict resolution with timestamp comparison
* Opt-in sync for individual posts
* Real-time sync on content changes
* Background polling for Nostr updates
* Admin interface for settings and management

== Upgrade Notice ==

= 1.2.0 =
This version adds frontend display customization, Gutenberg blocks, and shortcodes for displaying notes. Notes will now automatically use the new clean display format. No action required, but you can now use shortcodes and blocks to display notes anywhere on your site.

= 1.1.0 =
(Previous upgrade notice)

= 1.0.0 =
Initial release of Nostr for WordPress. Install and configure your Nostr connection to start syncing content.

== Technical Details ==

**Architecture:**

* **Nostr Client**: Handles WebSocket connections and event publishing
* **Sync Manager**: Orchestrates bidirectional synchronization
* **Content Mapper**: Transforms content between WordPress and Nostr formats
* **NIP-07 Handler**: Integrates with browser extensions for signing
* **Cron Handler**: Manages background polling for updates
* **Frontend Display**: Customizes note display with theme-agnostic templates and styling
* **Shortcode Handler**: Provides shortcodes for arbitrary note display
* **Block Registration**: Registers Gutenberg blocks for note display

**Security:**

* No private keys stored server-side
* All signing done client-side via NIP-07
* Secure AJAX endpoints with nonce verification
* User permission checks for all operations

**Performance:**

* Efficient background polling with configurable intervals
* Minimal database queries
* Caching of connection status
* Optimized content transformations

**Compatibility:**

* WordPress 5.0+
* PHP 7.4+
* Modern browsers with NIP-07 support
* Standard Nostr relays

== Support ==

For support, feature requests, or bug reports, please visit the [GitHub repository](https://github.com/danieljwonder/nostr-for-wp).

== Privacy Policy ==

This plugin does not collect or store personal data. All Nostr interactions are handled through your configured relays and browser extension. No data is sent to third-party services.

== Credits ==

Built for the Nostr community to bridge the gap between traditional web publishing and decentralized social protocols.
