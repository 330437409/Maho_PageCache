<?php

/**
 * Page cache configuration and header helpers.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_ENABLED = 'page_cache/general/enabled';
    public const XML_PATH_MODE = 'page_cache/general/mode';
    public const XML_PATH_TTL = 'page_cache/general/ttl';
    public const XML_PATH_CACHEABLE_ROUTES = 'page_cache/general/cacheable_routes';
    public const XML_PATH_SESSION_SKIP_ROUTES = 'page_cache/general/session_skip_routes';
    public const XML_PATH_ALLOWED_PARAMS = 'page_cache/general/allowed_params';
    public const XML_PATH_RESPECT_NO_CACHE = 'page_cache/general/respect_no_cache';
    public const XML_PATH_DEBUG_HEADERS = 'page_cache/general/debug_headers';
    public const XML_PATH_PROXY_CONTEXT_MODE = 'page_cache/proxy/context_mode';
    public const XML_PATH_PROXY_SWR = 'page_cache/proxy/swr';
    public const XML_PATH_STOCK_PENDING_WARN_AGE = 'page_cache/general/stock_pending_warn_age';
    public const XML_PATH_HTML_MAX_SIZE = 'page_cache/general/html_max_size';
    public const XML_PATH_COMPRESSION_ENABLED = 'page_cache/local/compression_enabled';
    public const XML_PATH_COMPRESSION_MIN_BYTES = 'page_cache/local/compression_min_bytes';
    public const XML_PATH_COMPRESSION_LEVEL = 'page_cache/local/compression_level';
    public const XML_PATH_DYNAMIC_INJECTION = 'page_cache/dynamic/injection';
    public const XML_PATH_DYNAMIC_TTL = 'page_cache/dynamic/ttl';
    public const XML_PATH_DYNAMIC_SERVER_SIDE = 'page_cache/dynamic/server_side';
    public const XML_PATH_DYNAMIC_SERVER_TYPES = 'page_cache/dynamic/server_types';
    public const XML_PATH_DYNAMIC_RATE_LIMIT = 'page_cache/dynamic/rate_limit';
    public const XML_PATH_DYNAMIC_RATE_LIMIT_WINDOW = 'page_cache/dynamic/rate_limit_window';
    public const XML_PATH_WARMUP_ENABLED = 'page_cache/warmup/enabled';
    public const XML_PATH_WARMUP_CONCURRENCY = 'page_cache/warmup/concurrency';
    public const XML_PATH_WARMUP_BATCH_SIZE = 'page_cache/warmup/batch_size';
    public const XML_PATH_WARMUP_DELAY_MS = 'page_cache/warmup/delay_ms';
    public const XML_PATH_WARMUP_MAX_ATTEMPTS = 'page_cache/warmup/max_attempts';
    public const XML_PATH_WARMUP_RETRY_DELAY = 'page_cache/warmup/retry_delay';
    public const XML_PATH_WARMUP_QUEUE_LIMIT = 'page_cache/warmup/queue_limit';
    public const XML_PATH_WARMUP_AUTH_USER = 'page_cache/warmup/auth_user';
    public const XML_PATH_WARMUP_AUTH_PASSWORD = 'page_cache/warmup/auth_password';
    public const XML_PATH_WARMUP_ALLOWED_HOSTS = 'page_cache/warmup/allowed_hosts';
    public const XML_PATH_FILTER_PATH_MARKER = 'page_cache/general/filter_path_marker';
    public const XML_PATH_MAX_FILTER_DEPTH = 'page_cache/general/max_filter_depth';
    public const XML_PATH_MAX_PATH_SEGMENTS = 'page_cache/general/max_path_segments';
    public const XML_PATH_STATIC_ENABLED = 'page_cache/static/enabled';
    public const XML_PATH_AUDIT_ENABLED = 'page_cache/audit/enabled';
    public const XML_PATH_AUDIT_RETENTION_DAYS = 'page_cache/audit/retention_days';
    public const XML_PATH_AUDIT_SAMPLE_TAGS = 'page_cache/audit/sample_tags';
    public const XML_PATH_PURGE_MAX_ATTEMPTS = 'page_cache/purge/max_attempts';
    public const XML_PATH_PURGE_RETRY_DELAY = 'page_cache/purge/retry_delay';
    public const XML_PATH_PURGE_STALE_AFTER = 'page_cache/purge/stale_after';
    public const XML_PATH_PURGE_RETENTION_DAYS = 'page_cache/purge/retention_days';
    public const XML_PATH_PURGE_LEASE_SECONDS = 'page_cache/purge/lease_seconds';
    public const XML_PATH_STATS_ENABLED = 'page_cache/stats/enabled';
    public const XML_PATH_STATS_MODE = 'page_cache/stats/mode';
    public const XML_PATH_STATS_SAMPLE_RATE = 'page_cache/stats/sample_rate';
    public const XML_PATH_STATS_RAW_RETENTION_DAYS = 'page_cache/stats/raw_retention_days';
    public const XML_PATH_STATS_ROLLUP_RETENTION_DAYS = 'page_cache/stats/rollup_retention_days';
    public const XML_PATH_STATS_MAX_DURATION_MS = 'page_cache/stats/max_duration_ms';

    public const MODE_PROXY = 'proxy';
    public const MODE_REVALIDATING = 'revalidating';
    public const MODE_LOCAL = 'local';
    public const PROXY_CONTEXT_COOKIE = 'cookie';
    public const PROXY_CONTEXT_DEFAULT = 'default';

    public function isEnabled(): bool
    {
        $cacheOptions = Mage::app()->useCache();
        $typeEnabled = !is_array($cacheOptions)
            || !array_key_exists('page_cache', $cacheOptions)
            || !empty($cacheOptions['page_cache']);

        return $typeEnabled && Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    public function getMode(): string
    {
        $mode = (string) Mage::getStoreConfig(self::XML_PATH_MODE);
        return in_array($mode, [self::MODE_PROXY, self::MODE_REVALIDATING, self::MODE_LOCAL], true) ? $mode : '';
    }

    public function getTtl(): int
    {
        $ttl = (int) Mage::getStoreConfig(self::XML_PATH_TTL);
        return $ttl > 0 ? $ttl : 3600;
    }

    /**
     * @return list<string>
     */
    public function getCacheableRoutes(): array
    {
        return $this->getLines(self::XML_PATH_CACHEABLE_ROUTES);
    }

    /**
     * @return list<string>
     */
    public function getSessionSkipRoutes(): array
    {
        return $this->getLines(self::XML_PATH_SESSION_SKIP_ROUTES);
    }

    /**
     * @return list<string>
     */
    public function getAllowedParams(): array
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_ALLOWED_PARAMS);
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $param): bool => $param !== ''));
    }

    public function respectNoCache(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_RESPECT_NO_CACHE);
    }

    public function isDebugHeaders(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_DEBUG_HEADERS);
    }

    public function getStockPendingWarnAge(): int
    {
        return max(0, (int) Mage::getStoreConfig(self::XML_PATH_STOCK_PENDING_WARN_AGE));
    }

    public function getProxyContextMode(): string
    {
        $mode = (string) Mage::getStoreConfig(self::XML_PATH_PROXY_CONTEXT_MODE);
        return $mode === self::PROXY_CONTEXT_DEFAULT ? self::PROXY_CONTEXT_DEFAULT : self::PROXY_CONTEXT_COOKIE;
    }

    public function getProxySwr(): int
    {
        $value = trim((string) Mage::getStoreConfig(self::XML_PATH_PROXY_SWR));
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return 0;
        }

        return min(86400, (int) $value);
    }

    public function getHtmlMaxSize(): int
    {
        $size = (int) Mage::getStoreConfig(self::XML_PATH_HTML_MAX_SIZE);
        return $size > 0 ? $size : 2097152;
    }

    public function getCompressionEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_COMPRESSION_ENABLED);
    }

    public function getCompressionMinBytes(): int
    {
        $bytes = (int) Mage::getStoreConfig(self::XML_PATH_COMPRESSION_MIN_BYTES);
        return $bytes > 0 ? $bytes : 4096;
    }

    public function getCompressionLevel(): int
    {
        $level = (int) Mage::getStoreConfig(self::XML_PATH_COMPRESSION_LEVEL);
        return $level >= 1 && $level <= 9 ? $level : 6;
    }

    public function getDynamicInjection(): string
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_DYNAMIC_INJECTION) === 'esi' ? 'esi' : 'ajax';
    }

    public function getDynamicTtl(): int
    {
        return max(0, (int) Mage::getStoreConfig(self::XML_PATH_DYNAMIC_TTL));
    }

    public function getDynamicServerSide(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_DYNAMIC_SERVER_SIDE);
    }

    public function getDynamicRateLimit(): int
    {
        return max(0, (int) Mage::getStoreConfig(self::XML_PATH_DYNAMIC_RATE_LIMIT));
    }

    public function getDynamicRateLimitWindow(): int
    {
        $window = (int) Mage::getStoreConfig(self::XML_PATH_DYNAMIC_RATE_LIMIT_WINDOW);
        return $window > 0 ? $window : 60;
    }

    /**
     * @return list<string>
     */
    public function getDynamicServerTypes(): array
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_DYNAMIC_SERVER_TYPES);
        if (trim($value) === '') {
            return [];
        }

        $types = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_map(trim(...), $types)));
    }

    public function getWarmupEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_WARMUP_ENABLED);
    }

    public function getWarmupConcurrency(): int
    {
        $concurrency = (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_CONCURRENCY);
        return $concurrency > 0 ? $concurrency : 4;
    }

    public function getWarmupBatchSize(): int
    {
        $size = (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_BATCH_SIZE);
        return $size > 0 ? $size : 50;
    }

    public function getWarmupDelayMs(): int
    {
        return max(0, (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_DELAY_MS));
    }

    public function getWarmupMaxAttempts(): int
    {
        $attempts = (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_MAX_ATTEMPTS);
        return $attempts > 0 ? $attempts : 3;
    }

    public function getWarmupRetryDelay(): int
    {
        $delay = (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_RETRY_DELAY);
        return $delay > 0 ? $delay : 300;
    }

    public function getWarmupQueueLimit(): int
    {
        $limit = (int) Mage::getStoreConfig(self::XML_PATH_WARMUP_QUEUE_LIMIT);
        return $limit > 0 ? $limit : 10000;
    }

    /**
     * @return list<string>
     */
    public function getWarmupAllowedHosts(): array
    {
        $raw = (string) Mage::getStoreConfig(self::XML_PATH_WARMUP_ALLOWED_HOSTS);
        $lines = preg_split('/\R/', $raw) ?: [];
        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn(string $line): bool => $line !== '',
        ));
    }

    /**
     * @return array{user: string, password: string}|null
     */
    public function getWarmupAuth(): ?array
    {
        $user = trim((string) Mage::getStoreConfig(self::XML_PATH_WARMUP_AUTH_USER));
        if ($user === '') {
            return null;
        }

        $password = (string) Mage::getStoreConfig(self::XML_PATH_WARMUP_AUTH_PASSWORD);
        if ($password !== '') {
            $password = Mage::helper('core')->tryDecrypt($password) ?? $password;
        }

        return ['user' => $user, 'password' => $password];
    }

    public function getFilterPathMarker(): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_FILTER_PATH_MARKER), " \t\n\r\0\x0B/");
    }

    public function getMaxFilterDepth(): int
    {
        return max(0, (int) Mage::getStoreConfig(self::XML_PATH_MAX_FILTER_DEPTH));
    }

    public function getMaxPathSegments(): int
    {
        $segments = (int) Mage::getStoreConfig(self::XML_PATH_MAX_PATH_SEGMENTS);
        return $segments > 0 ? $segments : 8;
    }

    public function getStaticExportEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_STATIC_ENABLED);
    }

    public function getAuditEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_AUDIT_ENABLED);
    }

    public function getAuditRetentionDays(): int
    {
        $days = (int) Mage::getStoreConfig(self::XML_PATH_AUDIT_RETENTION_DAYS);
        return $days > 0 ? $days : 30;
    }

    public function getAuditSampleTags(): int
    {
        $count = (int) Mage::getStoreConfig(self::XML_PATH_AUDIT_SAMPLE_TAGS);
        return $count > 0 ? $count : 20;
    }

    public function getPurgeMaxAttempts(): int
    {
        $attempts = (int) Mage::getStoreConfig(self::XML_PATH_PURGE_MAX_ATTEMPTS);
        return $attempts > 0 ? $attempts : 5;
    }

    public function getPurgeRetryDelay(): int
    {
        $delay = (int) Mage::getStoreConfig(self::XML_PATH_PURGE_RETRY_DELAY);
        return $delay > 0 ? $delay : 60;
    }

    public function getPurgeStaleAfter(): int
    {
        $seconds = (int) Mage::getStoreConfig(self::XML_PATH_PURGE_STALE_AFTER);
        return $seconds > 0 ? $seconds : 3600;
    }

    public function getPurgeRetentionDays(): int
    {
        $days = (int) Mage::getStoreConfig(self::XML_PATH_PURGE_RETENTION_DAYS);
        return $days > 0 ? $days : 7;
    }

    public function getPurgeLeaseSeconds(): int
    {
        $seconds = (int) Mage::getStoreConfig(self::XML_PATH_PURGE_LEASE_SECONDS);
        return $seconds > 0 ? $seconds : 300;
    }

    public function getStatsEnabled(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_STATS_ENABLED);
    }

    public function getStatsMode(): string
    {
        if (!$this->getStatsEnabled()) {
            return Maho_PageCache_Model_Stat::MODE_OFF;
        }

        $mode = strtolower((string) Mage::getStoreConfig(self::XML_PATH_STATS_MODE));
        return in_array($mode, [
            Maho_PageCache_Model_Stat::MODE_OFF,
            Maho_PageCache_Model_Stat::MODE_FULL,
            Maho_PageCache_Model_Stat::MODE_SAMPLED,
        ], true) ? $mode : Maho_PageCache_Model_Stat::MODE_FULL;
    }

    public function getStatsSampleRate(): float
    {
        $rate = (float) Mage::getStoreConfig(self::XML_PATH_STATS_SAMPLE_RATE);
        return max(0.0, min(1.0, $rate));
    }

    public function getStatsRawRetentionDays(): int
    {
        $days = (int) Mage::getStoreConfig(self::XML_PATH_STATS_RAW_RETENTION_DAYS);
        return $days > 0 ? $days : 30;
    }

    public function getStatsRollupRetentionDays(): int
    {
        $days = (int) Mage::getStoreConfig(self::XML_PATH_STATS_ROLLUP_RETENTION_DAYS);
        return $days > 0 ? $days : 365;
    }

    public function getStatsMaxDurationMs(): int
    {
        $milliseconds = (int) Mage::getStoreConfig(self::XML_PATH_STATS_MAX_DURATION_MS);
        return $milliseconds > 0 ? $milliseconds : 60000;
    }

    public function sanitizeHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.:,\-]/', '', $value), ',');
    }

    /**
     * @return list<string>
     */
    private function getLines(string $path): array
    {
        $value = (string) Mage::getStoreConfig($path);
        $lines = preg_split('/\R/', $value) ?: [];
        $lines = array_map(trim(...), $lines);
        $lines = array_filter($lines, static fn(string $line): bool => $line !== '');
        return array_values(array_unique($lines));
    }
}
