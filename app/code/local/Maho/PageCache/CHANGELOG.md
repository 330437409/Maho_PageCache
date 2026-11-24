# Changelog

All notable changes to `Maho_PageCache` are recorded here.

## Unreleased

### Added

- Server-side dynamic block placeholders for local mode hits: opted-in leaf blocks are rebuilt without generating the full layout.
- Block definition storage, signed `PC_DYNAMIC_SERVER` markers, signed context/store validation and anonymous fallback.
- `page_cache/dynamic/server_side` and `page_cache/dynamic/server_types` configuration.
- Static export strips server block markers and keeps the anonymous fallback.

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

### Fixed

- The proxy cache key uses `Vary: Accept-Encoding, Cookie`; `X-Maho-Cache-Context` is debug-only.
- An evicted URL version key or missing metadata cannot revive old HTML or return a wrong 304.
- Purge URLs filter non-whitelisted query parameters, and failed purge hand-offs keep their queue entries.
- Stock quantity-only changes no longer invalidate product pages.
