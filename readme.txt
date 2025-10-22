=== Nostr for WordPress ===
Contributors: danieljwonder
Tags: nostr, social, sync, blockchain, decentralized
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Screenshots ==

1. Nostr settings page with connection status
2. Post editor with Nostr sync options
3. Relay configuration interface
4. Sync status and statistics

== Changelog ==

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

= 1.0.0 =
Initial release of Nostr for WordPress. Install and configure your Nostr connection to start syncing content.

== Technical Details ==

**Architecture:**

* **Nostr Client**: Handles WebSocket connections and event publishing
* **Sync Manager**: Orchestrates bidirectional synchronization
* **Content Mapper**: Transforms content between WordPress and Nostr formats
* **NIP-07 Handler**: Integrates with browser extensions for signing
* **Cron Handler**: Manages background polling for updates

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
