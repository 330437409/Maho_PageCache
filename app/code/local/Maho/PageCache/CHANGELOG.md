# Changelog

All notable changes to `Maho_PageCache` are recorded here.

## Unreleased

### Added

- `page_cache/proxy` reuses product, category, CMS page and CMS block models within one request and loads a batch with one collection query. Server-side dynamic blocks can declare `page_cache_proxies` to preload their entities. Local hits restore the current product, category and CMS page from the cache entry and merge entity cache tags into the page tags. Model data is not stored in the page cache.

## 0.5.5 (2026-11-24)

### Added

- A top-level `Page Cache` admin menu with four standard report grid tabs, per-grid pagination and column filters, CSV and Excel XML export, a `Warm Now` button that processes one warmup queue batch, and a `Configuration` button. The existing `admin/report/page_cache` ACL path is kept for role compatibility.
- `page-cache:monitor` exports stock pending, queue and PHP counter metrics as Prometheus, JSON or text for an external monitoring system.
- Chinese labels for the Page Cache admin report and Warm Now flow.
- `--check` exits non-zero when the oldest pending stock invalidation crossed `page_cache/general/stock_pending_warn_age`.

## 0.5.4 (2026-11-24)

### Added

- `page_cache/general/stock_pending_warn_age`, default 3600 seconds, controls when the hourly `page_cache_stock_pending_alert` cron logs a warning for stale pending stock invalidations.
- `page-cache:status` reports the pending stock count, the oldest age and a warning when the threshold is crossed.

## 0.5.3 (2026-11-24)

### Added

- `page_cache/proxy/context_mode` with `cookie` (default) and `default` for URL-only proxies.
- `page_cache_static_file` table that tracks static exports and their expiry for indexed cleanup.
- `page_cache_stock_pending` table that carries stock invalidations across asynchronous index runs.
- `page_cache/dynamic/rate_limit` and `rate_limit_window` settings; dynamic requests over the limit answer 429.
- `page_cache_context_variant` registry hook for mobile theme, A/B and geo-IP modules; local mode partitions the cache and proxy mode bypasses.

### Changed

- Dynamic tokens use the store base URL scheme and host, and expire no earlier than the page TTL plus 300 seconds.
- Local HTML entries larger than 4 KB are stored gzip-compressed and decompressed on a local hit.
- PHP-side counters read from the statistics table instead of a cache load and save on every hit; statistics must be enabled, and `page-cache:status` reports counters as unavailable otherwise.
- Purge queue entries live for 24 hours and the queue index for 25 hours, so the index cannot expire before its entries.
- Static cleanup deletes files from the static file index and falls back to a directory scan only when the index is empty.
- The static export contract now requires `___store` cookies to return to PHP, and the nginx and Caddy examples include that rule.

### Fixed

- Dynamic block requests are limited to cacheable routes; a request to any other route bypasses instead of skipping the normal cacheability policy.
- Catalog rule pages are invalidated again after the rule index is applied, so a page cannot re-cache with the previous price.
- Product imports invalidate the affected products when the import finishes.
- Stock item changes invalidate the affected products again after the stock index is processed.
- A proxy that cannot honor `Vary: Cookie` can set `page_cache/proxy/context_mode=default`; store cookie switches then bypass instead of sharing a URL cache object.

## 0.5.2 (2026-11-24)

### Added

- `page_cache_url` and `page_cache_url_tag` tables, created by `./maho migrate`, that hold the page URL hash, query variants, public path, store and entity tags for proxy purge, warmup and static removal.

### Changed

- Refactored the page cache invalidation index to native tag-aware cache tags and the `page_cache_url` and `page_cache_url_tag` tables.
- Removed the cache-backed tag index and its registry; URL lookup, proxy purge, warmup and static removal now use the persistent URL index.
- No user-visible behavior change.

## 0.5.1 (2026-11-24)

### Changed

- Dynamic tokens use the store base URL scheme and host, and expire no earlier than the page TTL plus 300 seconds.
- Local HTML entries larger than 4 KB are stored gzip-compressed and decompressed on a local hit.
- PHP-side counters read from the statistics table instead of a cache load and save on every hit; statistics must be enabled, and `page-cache:status` reports counters as unavailable otherwise.
- Purge queue entries live for 24 hours and the queue index for 25 hours, so the index cannot expire before its entries.
- Static cleanup deletes files from the static file index and falls back to a directory scan only when the index is empty.
- The static export contract now requires `___store` cookies to return to PHP, and the nginx and Caddy examples include that rule.

### Fixed

- Page cache URLs keep the store code for purge, warmup and cache keys; static files use the public path without it.
- Query variants share one meta entry with a bounded URL list, and every variant is purged together; static files are removed for query variants.
- Revalidating and local conditional requests use ETag only; `If-Modified-Since` no longer returns a stale 304.
- Bypass responses set `Cache-Control: private, no-store, max-age=0` and `Vary`.
- A missing meta or an expired tag index no longer triggers a full invalidation; tag index TTLs and the deliberate-flush registry are aligned.

## 0.5.0 (2026-11-24)

### Added

- Server-side dynamic block placeholders for local mode hits: opted-in leaf blocks are rebuilt without generating the full layout.
- Block definition storage, signed `PC_DYNAMIC_SERVER` markers, signed context/store validation and anonymous fallback.
- `page_cache/dynamic/server_side` and `page_cache/dynamic/server_types` configuration.
- Static export strips server block markers and keeps the anonymous fallback.
- 163 unit tests covering the page cache, including server-side dynamic block placeholders.

## 0.4.0 (2026-11-24)

### Added

- Scheduled page cache warmup: invalidated URLs enter a persistent queue, and the `page_cache_warmup` cron warms them in batches.
- Queue limit, deduplication, retry backoff, permanent skip handling, batch delay and basic auth.
- `page_cache_warmup_queue` table and warmup status in `page-cache:status`.
- `page-cache:warm --queue` and `--delay`.
- 153 unit tests covering the page cache, including scheduled warmup.

## 0.3.0 (2026-11-24)

### Added

- Page cache statistics dashboard under `Reports > Page Cache`, with hit rate, PHP duration, trend, top missed URLs and recent flush/purge/warmup events.
- Database tables `page_cache_stat` and `page_cache_stat_hourly`, hourly rollup and retention cleanup.
- Statistics config, ACL `admin/report/page_cache`, and admin-only path suppression.
- Optional static HTML export for anonymous default pages: path-based `.html` and `.gz` files under `var/page-cache/static`, served by nginx or Caddy without PHP.
- Static export config, strict `maho_session` bypass, cache directory deny, tag/full invalidation, and the `page_cache_static_cleanup` cron.
- Public-path cache keys after URL rewrites, so rewritten pages map to their browser URL and static path.
- 137 unit tests covering the page cache, including static export and statistics.

## 0.2.0 (2026-11-23)

### Added

- HTTP page cache integration for anonymous GET requests, with `proxy` and `revalidating` modes.
- Session skip for configured routes without a Maho session cookie.
- `local` mode stores full page HTML in Maho's cache and serves a hit before layout generation, with named locks, conditional requests and an HTML size limit.
- Context-aware `Cache-Control`, `Vary`, `ETag`, `Last-Modified`, `X-Maho-Cache-*` and `X-Cache-Tags` headers.
- Ajax and ESI dynamic blocks with signed context-bound tokens, a block allowlist and comment placeholders that preserve list and table structure.
- Form key and SID placeholders, with lazy form key loading in local mode so a passive visit does not start a session.
- `page-cache:status`, `page-cache:purge` and `page-cache:warm` CLI commands.
- `System > Configuration > Page Cache` and a `page_cache` cache type, enabled by the module setup script.
- Entity tag invalidation for products, categories, CMS pages and blocks, with stock status and mass attribute update handling.
- Catalog rule invalidation for affected products and categories.
- Path segment and layered-navigation depth limits.
- 106 unit tests covering context, policy, versions, tags, queue, headers, local hits, dynamic blocks, warmup and invalidation.

### Changed

- Configuration section saves invalidate every page, including saves made through `config:set` and the configuration service.
- Full invalidations keep a monotonic global version and enqueue a full proxy purge in proxy mode.
- Dynamic block placeholders are HTML comments instead of `<div>` elements.

### Changed

- Dynamic tokens use the store base URL scheme and host, and expire no earlier than the page TTL plus 300 seconds.
- Local HTML entries larger than 4 KB are stored gzip-compressed and decompressed on a local hit.
- PHP-side counters read from the statistics table instead of a cache load and save on every hit; statistics must be enabled, and `page-cache:status` reports counters as unavailable otherwise.
- Purge queue entries live for 24 hours and the queue index for 25 hours, so the index cannot expire before its entries.
- Static cleanup deletes files from the static file index and falls back to a directory scan only when the index is empty.
- The static export contract now requires `___store` cookies to return to PHP, and the nginx and Caddy examples include that rule.

### Fixed

- The proxy cache key uses `Vary: Accept-Encoding, Cookie`; `X-Maho-Cache-Context` is debug-only.
- An evicted URL version key or missing metadata cannot revive old HTML or return a wrong 304.
- Purge URLs filter non-whitelisted query parameters, and failed purge hand-offs keep their queue entries.
- Stock quantity-only changes no longer invalidate product pages.
