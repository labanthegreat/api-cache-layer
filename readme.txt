=== API Cache Layer ===
Contributors: labanthegreat
Tags: rest-api, cache, performance, api, optimization
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transparent caching layer for WordPress REST API responses with configurable TTL, analytics, cache warming, advanced rules, and cache invalidation.

== Description ==

API Cache Layer adds a transparent caching layer to your WordPress REST API. It intercepts GET requests and serves cached responses, dramatically reducing server load and response times for API-heavy sites, headless WordPress setups, and mobile apps.

**Key Features:**

* Cache REST API GET responses with configurable TTL (default: 3600s)
* Storage via WordPress transients or external object cache (Redis, Memcached)
* Per-route cache rules with wildcard pattern matching and priority ordering
* Cache key variation by query parameters, user role, or custom headers
* Stale-while-revalidate support with background refresh
* Cache tagging and tag-based invalidation
* Automatic cache invalidation on post, term, comment, user, and option changes
* Cascade invalidation across related endpoints
* ETag support with HTTP 304 responses
* Gzip compression for cached responses larger than 1 KB
* Adaptive TTL based on per-route access frequency
* Configurable maximum cache entries with automatic eviction of oldest entries
* Cache warming via WP-Cron with priority based on access popularity
* Automatic cache warm-up after flush
* Deploy detection: flushes cache and schedules warm-up on plugin/theme updates, theme switches, and auto-updates
* CI/CD webhook endpoint for external deploy notifications
* Invalidation webhook endpoint for external cache purge triggers
* Outbound webhook notifications on cache invalidation events
* Per-endpoint analytics: hit/miss rates, response times, cache sizes
* Analytics stored in custom database tables with automatic 90-day cleanup
* Invalidation log with source tracking (auto, manual, CLI, webhook)
* Per-route rate limiting with configurable window and limit
* Admin settings page under Settings > API Cache with tabbed UI
* Full WP-CLI support (`wp acl flush`, `wp acl warm`, `wp acl stats`, `wp acl analytics`, `wp acl list`, `wp acl log`, `wp acl rules`)
* Response headers for debugging: `X-ACL-Cache`, `X-ACL-Cache-TTL`, `ETag`

== Installation ==

1. Upload the `api-cache-layer` directory to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings > API Cache** to enable caching and configure options.
4. Optionally enable cache warming, analytics, and advanced cache rules from the settings page.

== Frequently Asked Questions ==

= Does this plugin work with headless WordPress setups? =

Yes. API Cache Layer caches standard WordPress REST API responses, making it ideal for headless or decoupled WordPress sites that rely heavily on the REST API.

= What storage backends are supported? =

The plugin supports WordPress transients (database-backed) out of the box. If you have an external object cache configured (Redis, Memcached), you can switch to the object cache backend for even better performance.

= Will cached data be served to logged-in users? =

By default, authenticated (logged-in) requests are never cached. You can allow caching for specific authenticated requests using the `acl_cache_authenticated` filter.

= How does automatic invalidation work? =

The plugin listens to WordPress lifecycle hooks for posts, terms, comments, users, and key options. When content changes, related cache entries are automatically invalidated, including cascade invalidation of related endpoints (e.g., taxonomy and author endpoints when a post is updated).

== Screenshots ==

1. Settings page with cache statistics donut chart, hit/miss counters, and general configuration
2. Analytics dashboard with hit rate over time chart, request totals, and time saved metrics
3. General settings with TTL, storage method, compression, ETag, and adaptive TTL options
4. Cache warmer with route warming progress, schedule settings, and batch configuration
5. Real-time monitor with auto-refresh, invalidation log, and live cache status

== Changelog ==

= 3.0.0 =
* Refactored to PSR-4 autoloading with namespaced classes.
* Added centralized AJAX handler for admin operations.
* Added admin settings page with full tabbed UI.

= 2.1.0 =
* Added deploy detection (plugin/theme updates, theme switches, auto-updates).
* Added CI/CD deploy webhook endpoint (`/acl/v1/deploy-notify`).
* Automatic cache flush and warm-up on deploy events.

= 2.0.0 =
* Added per-route cache rules with wildcard matching.
* Added cache key variation (query params, user roles, headers).
* Added stale-while-revalidate support.
* Added cache tagging and tag-based invalidation.
* Added per-route rate limiting.
* Added cache warming via WP-Cron with popularity-based priority.
* Added per-endpoint analytics with custom database tables.
* Added invalidation logging.
* Added adaptive TTL based on access patterns.
* Added ETag and HTTP 304 support.
* Added gzip compression.
* Added cascade invalidation for related endpoints.
* Added webhook support (inbound invalidation, outbound notifications).
* Added WP-CLI commands.
* Added deferred/batched database writes for reduced per-request overhead.
* Automatic invalidation on post, term, comment, user, and option changes.

= 1.0.0 =
* Initial release with basic REST API response caching.
* Transient and object cache backend support.
* Configurable TTL and endpoint exclusions.
* Cache statistics tracking.

== Upgrade Notice ==

= 3.0.0 =
Major refactor with PSR-4 autoloading and a new admin settings page. No breaking changes to stored data or configuration.

= 2.0.0 =
Major feature release. New database tables are created on activation for analytics. Existing cached data is fully compatible.
