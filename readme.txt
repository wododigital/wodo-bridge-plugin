=== WODO Bridge ===
Contributors: wododigital
Tags: rest-api, ai, mcp, elementor, application-passwords
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scope-gated REST surface that lets the WODO Bridge aggregator drive WordPress content, Elementor pages, taxonomies, and media on behalf of AI assistants.

== Description ==

WODO Bridge exposes a clean REST surface under `wodo-bridge/v2` and authenticates calls through WordPress Application Passwords plus a plugin-managed scope layer. It is the WordPress half of the WODO Bridge two-tier system; the aggregator runs separately on Railway and brokers MCP tool calls from ChatGPT, Claude (web and Desktop), and Cursor.

Highlights:

* Application Password authentication with eleven scoped grants (`posts.read`, `posts.write`, `cpt.read`, `cpt.write`, `taxonomy.write`, `media.read`, `media.write`, `elementor.read`, `elementor.write`, `webhooks.manage`, `admin.full`).
* Auto-discovery of every public custom post type, including ACF, CPT-UI, and WooCommerce, exposed under a uniform `/cpt/{type}` surface.
* Block-aware reads and writes via `parse_blocks()` / `serialize_blocks()` round-trip, so AI clients can reason about Gutenberg structure without re-parsing.
* Elementor parity (kit, widgets, page study, write) preserved from v1, with hardened depth and size caps and a `*_source` field on the `/study` endpoint that resolves global tokens to concrete values.
* Outbound webhooks signed with HMAC-SHA256 plus an SSRF blocklist on target URLs, with exponential-backoff retry and a per-delivery log.
* Token-bucket rate limiter, per-request activity log persisted in `wodo_bridge_activity`, and an Ed25519 site identity signature on `/site/identity` that pairs with the aggregator's first-connect pinning.

== Installation ==

1. Upload the plugin ZIP to `wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin** from the WordPress dashboard.
2. Activate the plugin under **Plugins → Installed Plugins**.
3. Visit **Settings → WODO Bridge** to confirm activation and paste your aggregator URL.
4. Connect the site from the WODO Bridge aggregator dashboard. The aggregator drives the App Password handshake; you do not paste credentials anywhere on the WP side.

A complete walkthrough including HTTPS / 2FA prerequisites lives in `docs/plugin-installation.md` in the project repository.

== Frequently Asked Questions ==

= Does this plugin send any data home? =

No. It only responds to inbound REST calls from the aggregator and emits outbound webhooks to URLs you configure in the aggregator dashboard.

= What PHP version is required? =

PHP 8.0 or newer. PHP 8.1 / 8.2 are tested.

= What if Elementor is not installed? =

All `/elementor/*` endpoints return HTTP 409 with `code: elementor_not_active`. The rest of the surface — posts, CPTs, taxonomies, media, webhooks — continues to function.

= How are Application Passwords kept safe? =

The plugin stores only the WP-side App Password UUID and the granted scope set. The actual credential lives in WordPress core's `wp_application_passwords` storage. The aggregator side encrypts the App Password at rest using XChaCha20-Poly1305 under a per-deployment KEK; see the project's `docs/03-architecture.md` for details.

= Can I use this without the aggregator? =

The plugin exposes a clean REST API and works with any client that can present a WP Application Password and the right scopes. The aggregator is the recommended way to use it from AI assistants because it handles encryption, multi-site routing, and the MCP transport.

= How do I revoke a token? =

Either click **Revoke** next to the token in **Settings → WODO Bridge** in wp-admin, or revoke it from the aggregator dashboard. Either path deletes the WP-side App Password so the credential cannot authenticate again.

== Screenshots ==

1. Settings → WODO Bridge admin page showing connection status and active tokens.
2. Recent activity feed with per-token, per-endpoint detail.
3. Webhook subscription list with delivery history.

== Changelog ==

= 2.0.0-alpha =
* Initial greenfield build. No upgrade path from v1.
* Adds: REST namespace `wodo-bridge/v2`, eleven-scope catalog, generic CPT surface, Gutenberg block-aware writes, Elementor parity + writes, webhook subscriptions with HMAC-SHA256 signing, token-bucket rate limiter, activity log + CSV export, plugin admin UI under Settings → WODO Bridge, Ed25519 site identity signature.
* Removes: v1 single-key API, Elementor-only surface, on-WP credentials storage.

== Upgrade Notice ==

= 2.0.0-alpha =
First public release. v1 was a private build with no upgrade path; deactivate and delete v1 before installing v2.
