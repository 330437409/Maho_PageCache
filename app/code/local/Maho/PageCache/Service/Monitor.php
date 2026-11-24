<?php

/**
 * Machine-readable page cache metrics for external monitoring.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Monitor
{
    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $helper = Mage::helper('page_cache');
        $stockPending = Mage::getService('page_cache/stockPending');
        $summary = $stockPending->getSummary();
        $warnAge = $helper->getStockPendingWarnAge();

        return [
            'enabled' => $helper->isEnabled(),
            'mode' => $helper->getMode(),
            'stock_pending' => $summary,
            'stock_pending_warn_age' => $warnAge,
            'stock_pending_stale' => $stockPending->isStale($summary, $warnAge),
            'purge_queue' => Mage::getModel('page_cache/purgeQueue')->count(),
            'warmup_enabled' => $helper->getWarmupEnabled(),
            'warmup_queue' => Mage::getModel('page_cache/warmQueue')->count(),
            'stats_enabled' => Mage::getSingleton('page_cache/stat')->isEnabled(),
            'counters' => Mage::getModel('page_cache/counter')->getToday(),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function formatPrometheus(array $metrics): string
    {
        $stock = is_array($metrics['stock_pending'] ?? null) ? $metrics['stock_pending'] : [];
        $counters = is_array($metrics['counters'] ?? null) ? $metrics['counters'] : [];
        $mode = (string) ($metrics['mode'] ?? '');

        $lines = [
            '# HELP maho_page_cache_info Page cache mode.',
            '# TYPE maho_page_cache_info gauge',
            sprintf('maho_page_cache_info{mode="%s"} 1', $this->escapeLabel($mode)),
            '# HELP maho_page_cache_enabled Whether page cache is enabled.',
            '# TYPE maho_page_cache_enabled gauge',
            'maho_page_cache_enabled ' . (($metrics['enabled'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_stats_enabled Whether PHP-side statistics are enabled.',
            '# TYPE maho_page_cache_stats_enabled gauge',
            'maho_page_cache_stats_enabled ' . (($metrics['stats_enabled'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_stock_pending_count Pending stock invalidation rows.',
            '# TYPE maho_page_cache_stock_pending_count gauge',
            'maho_page_cache_stock_pending_count ' . (int) ($stock['count'] ?? 0),
            '# HELP maho_page_cache_stock_pending_oldest_seconds Age of the oldest pending stock row.',
            '# TYPE maho_page_cache_stock_pending_oldest_seconds gauge',
            'maho_page_cache_stock_pending_oldest_seconds ' . (int) ($stock['age'] ?? 0),
            '# HELP maho_page_cache_stock_pending_stale Whether the oldest row crossed the warning age.',
            '# TYPE maho_page_cache_stock_pending_stale gauge',
            'maho_page_cache_stock_pending_stale ' . (($metrics['stock_pending_stale'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_stock_pending_warn_age_seconds Configured stock pending warning age.',
            '# TYPE maho_page_cache_stock_pending_warn_age_seconds gauge',
            'maho_page_cache_stock_pending_warn_age_seconds ' . (int) ($metrics['stock_pending_warn_age'] ?? 0),
            '# HELP maho_page_cache_purge_queue Pending proxy purge entries.',
            '# TYPE maho_page_cache_purge_queue gauge',
            'maho_page_cache_purge_queue ' . (int) ($metrics['purge_queue'] ?? 0),
            '# HELP maho_page_cache_warmup_queue Pending warmup entries.',
            '# TYPE maho_page_cache_warmup_queue gauge',
            'maho_page_cache_warmup_queue ' . (int) ($metrics['warmup_queue'] ?? 0),
            '# HELP maho_page_cache_warmup_enabled Whether scheduled warmup is enabled.',
            '# TYPE maho_page_cache_warmup_enabled gauge',
            'maho_page_cache_warmup_enabled ' . (($metrics['warmup_enabled'] ?? false) ? '1' : '0'),
        ];

        foreach (['hit', 'miss', 'bypass', 'revalidated'] as $result) {
            $lines[] = sprintf('# HELP maho_page_cache_%s_today %s events recorded today.', $result, ucfirst($result));
            $lines[] = sprintf('# TYPE maho_page_cache_%s_today gauge', $result);
            $lines[] = sprintf('maho_page_cache_%s_today %d', $result, (int) ($counters[$result] ?? 0));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function formatJson(array $metrics): string
    {
        $encoded = json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '{}\n' : $encoded . "\n";
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function formatText(array $metrics): string
    {
        $stock = is_array($metrics['stock_pending'] ?? null) ? $metrics['stock_pending'] : [];
        $counters = is_array($metrics['counters'] ?? null) ? $metrics['counters'] : [];

        return sprintf(
            "enabled=%d mode=%s stock_pending=%d oldest_seconds=%d stale=%d purge_queue=%d warmup_queue=%d hit=%d miss=%d bypass=%d revalidated=%d\n",
            ($metrics['enabled'] ?? false) ? 1 : 0,
            (string) ($metrics['mode'] ?? ''),
            (int) ($stock['count'] ?? 0),
            (int) ($stock['age'] ?? 0),
            ($metrics['stock_pending_stale'] ?? false) ? 1 : 0,
            (int) ($metrics['purge_queue'] ?? 0),
            (int) ($metrics['warmup_queue'] ?? 0),
            (int) ($counters['hit'] ?? 0),
            (int) ($counters['miss'] ?? 0),
            (int) ($counters['bypass'] ?? 0),
            (int) ($counters['revalidated'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function isAlerting(array $metrics): bool
    {
        return (bool) ($metrics['stock_pending_stale'] ?? false);
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
