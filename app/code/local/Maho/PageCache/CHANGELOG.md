# Changelog

All notable changes to `Maho_PageCache` are recorded here.

## 0.10.0 (2026-11-25)

### Added

- `page_cache/proxy/swr` adds `stale-while-revalidate` to the proxy `Cache-Control` header. The default is 0, which omits the directive; values are integers from 1 to 86400. local and revalidating headers do not change, and purge remains a hard delete.

## 0.9.2 (2026-11-25)

### Changed

- The Page Cache admin page now uses a compact setup callout, status cards and a cleaner overview. The overview moved out of the tabs, the range and store filters sit at the right of the report tab bar, and the tab set is Trend, Top Missed URLs, Recent Events and Invalidation Log. The summary grid and its export are gone because the status cards already carry the few numbers it showed. The page remains under `System > Page Cache`.
- The Page Cache admin page moved from the top-level menu to `System > Page Cache`, between Cache Management and Index Management. The ACL stays `report/page_cache` so existing roles keep access.
- FR-20 session cookie caching stays paused. There is no official read-only session API, and a custom session fork is not worth the security and maintenance risk. Strict session-cookie bypass remains; see ADR-0025.

## 0.9.1 (2026-11-25)

### Added

- `page-cache:monitor` now reports purge pending, processing, failed, oldest and stale state across all outbox targets, per-target purge series, warmup pending, due, oldest and lock state, and the invalidation audit count for the last 24 hours. `--check` also fails on stale purge rows or failed purge rows.
- Prometheus alert examples for failed or stale purges, warmup backlog, a stuck warmup lock and an invalidation spike.
- An operations runbook for a full `--active` warmup, including the `--limit` cap, concurrency and delay tuning, overlap with the cron queue and post-run checks.

### Fixed

- Statistics rollup no longer deletes raw events after each hourly aggregation. Raw rows stay until `raw_retention_days`, so 1/7/30-day summaries, trends, Top Missed URLs and the `hit_today` counters keep the completed hours.
- The page cache dashboard no longer runs warmup HTTP requests inside the admin request; `Warm Now` queues one warmup batch for a queue worker.
- `--active` warmup now builds URLs from the store link base, so store-code URLs and secure frontend URLs are correct, and it pages through active IDs instead of loading the whole catalog. A failed active URL collection now fails the command instead of returning an empty success.
- Allowed array query parameters now bypass the cache instead of being dropped from the cache key.
- Legacy `frontend` and `om_frontend` session cookies now bypass the cache like `maho_session`.
- A failed URL index write now prevents the page from being stored as a cacheable entry and falls back to a full purge for proxy pages.
- Catalog rules that match more than 30 product or category tags now fall back to a full invalidation.
- Gzip cache entries are rejected when the decompressed HTML exceeds `html_max_size`.
- Server-side dynamic blocks now re-check the cached block type and template path before rendering.
- ETag cache entries now expire with the page TTL.

## 0.9.0 (2026-11-25)

### Added

- Warmup now uses a sliding window: a finished request starts the next URL immediately, so a slow page no longer blocks the whole batch. `page_cache/warmup/delay_ms` is the interval between request starts.
- `page-cache:warm --active --store=N` reads active product and category URLs from URL rewrites, filtering disabled products, hidden products, inactive categories, redirects and non-store hosts. Directory URLs get a trailing slash.
- The admin dashboard shows warmup pending, due, oldest, lock state and the last run counts.
- Production nginx and Caddy profiles for Static Export, with session/admin/legacy/store cookie bypass, per-store static roots, query and method bypass, gzip, conditional cache headers, directory deny and rollback steps.
- Static Export benchmarks for nginx and Caddy, and a relative target of P95 at least 80% below Local HIT and RPS at least 5x Local HIT.

## 0.8.0 (2026-11-25)

### Added

- Purge delivery now uses the `page_cache_purge_outbox` table instead of cache entries. Each target has its own row, claim lease, attempts, backoff, ack and failed state, so a cache flush or process restart no longer drops pending purges.
- `page-cache:purge:status` reports pending, processing, failed and delivered rows, the oldest pending age and the last error. `page-cache:purge` adds `--target` and `--retry-failed`.
- A full proxy purge is queued on upgrade from 0.7.0, because the old cache-based queue is cleared by `./maho migrate`.
- Invalidation audit records tag and full invalidations in `page_cache_invalidation_log`, with area, cron job, type, reason, total and sample tags, affected URL count, purge/warm queue result, store and mode. The admin `Invalidations` tab supports filtering, CSV/Excel export and bulk delete; retention cleanup runs daily.

### Changed

- `page-cache:purge` claims outbox rows and acks after the event or purge file hand-off. Failed rows retry with exponential backoff and remain visible after the maximum attempts.

## 0.7.0 (2026-11-25)

This release also contains the 0.6.1 work (FR-21 tag budget and the audit P1 fixes). 0.6.1 was not tagged separately.

### Known issue

- A Local HIT improves P95 by 1.42%, below the RFC-007 target of 15%. Profiling shows the page cache hit logic takes about 0.66 ms, or 3% of a request; the rest is Maho bootstrap and routing. The gate stays open, and the target moves to a fast path or Static Export.

### Added

- `page_cache/proxy` reuses product, category, CMS page and CMS block models within one request and loads a batch with one collection query. Server-side dynamic blocks can declare `page_cache_proxies` to preload their entities. Local hits restore the current product, category and CMS page from the cache entry and merge entity cache tags into the page tags. Model data is not stored in the page cache.
- Local page cache compression is configurable with `page_cache/local/compression_enabled`, `compression_min_bytes` and `compression_level`. The defaults stay 4 KB and gzip level 6, and a failed compression or decompression falls back to plain HTML or a cache miss.
- Deployment profiles for nginx and Varnish keep analytics cookies out of the shared cache key, bypass every storefront session request, and isolate unknown cookies. Both profiles were tested against a real proxy with two store views.
- `page_cache/stats/mode` adds `off`, `full` and `sampled` modes. Sampling is decided once per request, so all request events of a request are kept or dropped together. Purge, flush and warmup events are always recorded, and the dashboard shows the mode and warns that sampled counts are estimates.
- Warmup accepts extra store alias origins in `page_cache/warmup/allowed_hosts`, one origin per line. Requests and Basic Auth are limited to the store base URLs and those origins, and a child sitemap outside them is never requested.
- The proxy `X-Cache-Tags` header is bounded to 50 tags. Pages that overflow carry `page_cache_overflow_product` when product tags were dropped and `page_cache_overflow` when other tags were dropped; tag purges include the matching overflow tag, so a listing page is not missed when one of its product tags was dropped.

### Changed

- Local page cache now stores a v2 CacheEnvelope with a stable key and versions, reducing a local HIT from five cache backend reads to three. Meta is kept for revalidating and dynamic responses, and old entries fall back until they expire.

### Removed

- The legacy `page_cache_url_tag` table. `./maho migrate` invalidates the page cache once, clears the static files and queues, then drops the table; the context index is rebuilt on demand.

### Fixed

- Page Cache admin strings now load from the module CSV, so the Chinese configuration and dashboard labels appear in the admin.
- The URL index keeps tags, store code and public path for every cache context instead of overwriting the previous context. Invalidation now removes every context variant and every context's static file.
- Warmup only requests URLs on the store's configured origins. The queue, the explicit URL filter, child sitemap requests and the HTTP client reject other hosts, so Basic Auth cannot be sent to an arbitrary server.

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
