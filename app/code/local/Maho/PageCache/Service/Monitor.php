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
        $purgeQueue = Mage::getModel('page_cache/purgeQueue');
        $purge = $purgeQueue->getAggregateStatus();
        $purgeOldest = $purge['oldest_pending'] === null ? 0 : max(0, time() - $purge['oldest_pending']);
        $warmup = Mage::getModel('page_cache/warmQueue')->getStats();

        return [
            'enabled' => $helper->isEnabled(),
            'mode' => $helper->getMode(),
            'stock_pending' => $summary,
            'stock_pending_warn_age' => $warnAge,
            'stock_pending_stale' => $stockPending->isStale($summary, $warnAge),
            'purge_queue' => $purge['pending'],
            'purge_pending' => $purge['pending'],
            'purge_processing' => $purge['processing'],
            'purge_failed' => $purge['failed'],
            'purge_oldest_seconds' => $purgeOldest,
            'purge_stale' => $purgeOldest > $helper->getPurgeStaleAfter(),
            'purge_target_count' => count($purge['targets']),
            'purge_targets' => $purge['targets'],
            'warmup_enabled' => $helper->getWarmupEnabled(),
            'warmup_queue' => $warmup['pending'],
            'warmup_pending' => $warmup['pending'],
            'warmup_due' => $warmup['due'],
            'warmup_oldest_seconds' => $warmup['oldest_seconds'] ?? 0,
            'warmup_lock' => Mage::getSingleton('core/lock')->isHeld(Maho_PageCache_Service_Warm::WARMUP_LOCK),
            'invalidation_24h' => Mage::getSingleton('page_cache/invalidationLog')->countRecent(24),
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
            '# HELP maho_page_cache_purge_queue Pending purge entries across all targets.',
            '# TYPE maho_page_cache_purge_queue gauge',
            'maho_page_cache_purge_queue ' . (int) ($metrics['purge_queue'] ?? 0),
            '# HELP maho_page_cache_purge_pending Pending purge entries across all targets.',
            '# TYPE maho_page_cache_purge_pending gauge',
            'maho_page_cache_purge_pending ' . (int) ($metrics['purge_pending'] ?? 0),
            '# HELP maho_page_cache_purge_target_count Number of purge targets with outbox rows.',
            '# TYPE maho_page_cache_purge_target_count gauge',
            'maho_page_cache_purge_target_count ' . (int) ($metrics['purge_target_count'] ?? 0),
            '# HELP maho_page_cache_purge_processing Purge rows currently claimed.',
            '# TYPE maho_page_cache_purge_processing gauge',
            'maho_page_cache_purge_processing ' . (int) ($metrics['purge_processing'] ?? 0),
            '# HELP maho_page_cache_purge_failed Purge rows that reached the maximum attempts.',
            '# TYPE maho_page_cache_purge_failed gauge',
            'maho_page_cache_purge_failed ' . (int) ($metrics['purge_failed'] ?? 0),
            '# HELP maho_page_cache_purge_oldest_seconds Age of the oldest pending purge row across all targets.',
            '# TYPE maho_page_cache_purge_oldest_seconds gauge',
            'maho_page_cache_purge_oldest_seconds ' . (int) ($metrics['purge_oldest_seconds'] ?? 0),
            '# HELP maho_page_cache_purge_stale Whether the oldest pending purge crossed the stale age.',
            '# TYPE maho_page_cache_purge_stale gauge',
            'maho_page_cache_purge_stale ' . (($metrics['purge_stale'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_warmup_queue Pending warmup entries.',
            '# TYPE maho_page_cache_warmup_queue gauge',
            'maho_page_cache_warmup_queue ' . (int) ($metrics['warmup_queue'] ?? 0),
            '# HELP maho_page_cache_warmup_pending Pending warmup entries.',
            '# TYPE maho_page_cache_warmup_pending gauge',
            'maho_page_cache_warmup_pending ' . (int) ($metrics['warmup_pending'] ?? 0),
            '# HELP maho_page_cache_warmup_due Warmup entries due now.',
            '# TYPE maho_page_cache_warmup_due gauge',
            'maho_page_cache_warmup_due ' . (int) ($metrics['warmup_due'] ?? 0),
            '# HELP maho_page_cache_warmup_oldest_seconds Age of the oldest warmup entry.',
            '# TYPE maho_page_cache_warmup_oldest_seconds gauge',
            'maho_page_cache_warmup_oldest_seconds ' . (int) ($metrics['warmup_oldest_seconds'] ?? 0),
            '# HELP maho_page_cache_warmup_lock Whether the warmup consumer lock is held.',
            '# TYPE maho_page_cache_warmup_lock gauge',
            'maho_page_cache_warmup_lock ' . (($metrics['warmup_lock'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_warmup_enabled Whether scheduled warmup is enabled.',
            '# TYPE maho_page_cache_warmup_enabled gauge',
            'maho_page_cache_warmup_enabled ' . (($metrics['warmup_enabled'] ?? false) ? '1' : '0'),
            '# HELP maho_page_cache_invalidation_24h Invalidation audit records in the last 24 hours.',
            '# TYPE maho_page_cache_invalidation_24h gauge',
            'maho_page_cache_invalidation_24h ' . (int) ($metrics['invalidation_24h'] ?? 0),
        ];

        $targets = is_array($metrics['purge_targets'] ?? null) ? $metrics['purge_targets'] : [];
        if ($targets !== []) {
            $lines[] = '# HELP maho_page_cache_purge_target_pending Pending purge entries for one target.';
            $lines[] = '# TYPE maho_page_cache_purge_target_pending gauge';
            $lines[] = '# HELP maho_page_cache_purge_target_failed Failed purge entries for one target.';
            $lines[] = '# TYPE maho_page_cache_purge_target_failed gauge';
            $lines[] = '# HELP maho_page_cache_purge_target_oldest_seconds Age of the oldest pending purge row for one target.';
            $lines[] = '# TYPE maho_page_cache_purge_target_oldest_seconds gauge';
            foreach ($targets as $target => $status) {
                if (!is_array($status)) {
                    continue;
                }
                $label = $this->escapeLabel((string) $target);
                $lines[] = sprintf('maho_page_cache_purge_target_pending{target="%s"} %d', $label, (int) ($status['pending'] ?? 0));
                $lines[] = sprintf('maho_page_cache_purge_target_failed{target="%s"} %d', $label, (int) ($status['failed'] ?? 0));
                $oldest = $status['oldest_pending'] === null ? 0 : max(0, time() - (int) $status['oldest_pending']);
                $lines[] = sprintf('maho_page_cache_purge_target_oldest_seconds{target="%s"} %d', $label, $oldest);
            }
        }

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
            "enabled=%d mode=%s stock_pending=%d oldest_seconds=%d stale=%d purge_queue=%d purge_failed=%d purge_oldest_seconds=%d purge_stale=%d purge_targets=%d warmup_queue=%d warmup_due=%d warmup_oldest_seconds=%d warmup_lock=%d invalidation_24h=%d hit=%d miss=%d bypass=%d revalidated=%d\n",
            ($metrics['enabled'] ?? false) ? 1 : 0,
            (string) ($metrics['mode'] ?? ''),
            (int) ($stock['count'] ?? 0),
            (int) ($stock['age'] ?? 0),
            ($metrics['stock_pending_stale'] ?? false) ? 1 : 0,
            (int) ($metrics['purge_queue'] ?? 0),
            (int) ($metrics['purge_failed'] ?? 0),
            (int) ($metrics['purge_oldest_seconds'] ?? 0),
            ($metrics['purge_stale'] ?? false) ? 1 : 0,
            (int) ($metrics['purge_target_count'] ?? 0),
            (int) ($metrics['warmup_queue'] ?? 0),
            (int) ($metrics['warmup_due'] ?? 0),
            (int) ($metrics['warmup_oldest_seconds'] ?? 0),
            ($metrics['warmup_lock'] ?? false) ? 1 : 0,
            (int) ($metrics['invalidation_24h'] ?? 0),
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
        return (bool) ($metrics['stock_pending_stale'] ?? false)
            || (bool) ($metrics['purge_stale'] ?? false)
            || (int) ($metrics['purge_failed'] ?? 0) > 0;
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
