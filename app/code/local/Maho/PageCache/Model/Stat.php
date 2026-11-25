<?php

/**
 * Page cache statistics recording, rollup and dashboard data.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Stat
{
    public const MODE_OFF = 'off';
    public const MODE_FULL = 'full';
    public const MODE_SAMPLED = 'sampled';

    public const EVENT_HIT = 'hit';
    public const EVENT_MISS = 'miss';
    public const EVENT_BYPASS = 'bypass';
    public const EVENT_REVALIDATED = 'revalidated';
    public const EVENT_DYNAMIC = 'dynamic';
    public const EVENT_PURGE = 'purge';
    public const EVENT_FLUSH = 'flush';
    public const EVENT_WARMUP = 'warmup';

    public const EVENTS = [
        self::EVENT_HIT,
        self::EVENT_MISS,
        self::EVENT_BYPASS,
        self::EVENT_REVALIDATED,
        self::EVENT_DYNAMIC,
        self::EVENT_PURGE,
        self::EVENT_FLUSH,
        self::EVENT_WARMUP,
    ];

    private const int MAX_BUFFER = 50;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private ?bool $sampled = null;

    public function isEnabled(): bool
    {
        return $this->helper()->getStatsMode() !== self::MODE_OFF;
    }

    public function record(
        string $eventType,
        ?string $route = null,
        ?string $urlPath = null,
        ?int $durationMs = null,
        ?string $reason = null,
    ): void {
        $mode = $this->helper()->getStatsMode();
        if ($mode === self::MODE_OFF || !in_array($eventType, self::EVENTS, true)) {
            return;
        }

        $systemEvent = in_array($eventType, [
            self::EVENT_FLUSH,
            self::EVENT_PURGE,
            self::EVENT_WARMUP,
        ], true);
        if (Mage::app()->getStore()->isAdmin() && !$systemEvent) {
            return;
        }
        if ($mode === self::MODE_SAMPLED && !$systemEvent && !$this->isSampledRequest()) {
            return;
        }

        $now = time();
        $duration = $durationMs;
        if ($duration === null && in_array($eventType, [
            self::EVENT_HIT,
            self::EVENT_MISS,
            self::EVENT_BYPASS,
            self::EVENT_REVALIDATED,
            self::EVENT_DYNAMIC,
        ], true)) {
            $duration = $this->getRequestDuration();
        }
        $maxDuration = $this->helper()->getStatsMaxDurationMs();
        if ($duration !== null && ($duration < 0 || $duration > $maxDuration)) {
            $duration = $duration > $maxDuration ? $maxDuration : null;
        }

        $route = $systemEvent ? null : $this->normalizeRoute($route);
        $this->buffer[] = [
            'event_type' => $eventType,
            'route' => $route,
            'url_path' => $systemEvent ? null : $this->normalizePath($urlPath),
            'store_id' => (int) Mage::app()->getStore()->getId(),
            'mode' => $this->helper()->getMode() ?: null,
            'duration_ms' => $duration,
            'reason' => $reason === null || $reason === '' ? null : substr($reason, 0, 64),
            'hour_bucket' => gmdate('Y-m-d H:00:00', $now),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ];

        if (count($this->buffer) >= self::MAX_BUFFER) {
            $this->flush();
        }
    }

    private function isSampledRequest(): bool
    {
        if ($this->sampled === null) {
            $rate = $this->helper()->getStatsSampleRate();
            $this->sampled = $rate >= 1.0
                || ($rate > 0.0 && random_int(1, 1_000_000) <= (int) round($rate * 1_000_000));
        }

        return $this->sampled;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        try {
            $this->write()->insertMultiple($this->table('page_cache/stat'), $events);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function rollup(): int
    {
        $lock = Mage::getSingleton('core/lock');
        $lockName = 'page_cache_stats_rollup';
        if (!$lock->acquire($lockName)) {
            return 0;
        }

        $rawTable = $this->table('page_cache/stat');
        $rollupTable = $this->table('page_cache/stat_hourly');
        $currentHour = gmdate('Y-m-d H:00:00');
        $processed = 0;

        try {
            $read = $this->read();
            $write = $this->write();
            $maxRolledUp = $read->fetchOne($read->select()->from($rollupTable, ['MAX(bucket)']));
            $select = $read->select()
                ->from($rawTable, [
                    'hour_bucket',
                    'store_id',
                    'event_type',
                    'route',
                    'event_count' => new Maho\Db\Expr('COUNT(*)'),
                    'duration_sum' => new Maho\Db\Expr('COALESCE(SUM(duration_ms), 0)'),
                    'duration_count' => new Maho\Db\Expr('SUM(CASE WHEN duration_ms IS NULL THEN 0 ELSE 1 END)'),
                ])
                ->where('hour_bucket < ?', $currentHour)
                ->where('created_at < ?', $currentHour)
                ->group(['hour_bucket', 'store_id', 'event_type', 'route']);
            if (is_string($maxRolledUp) && $maxRolledUp !== '') {
                $select->where('hour_bucket > ?', $maxRolledUp);
            }
            $rows = $read->fetchAll($select);

            $write->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $where = [
                        'bucket = ?' => (string) $row['hour_bucket'],
                        'store_id = ?' => (int) $row['store_id'],
                        'event_type = ?' => (string) $row['event_type'],
                        'route = ?' => (string) ($row['route'] ?? ''),
                    ];
                    $existsSelect = $read->select()
                        ->from($rollupTable, ['COUNT(*)'])
                        ->where('bucket = ?', (string) $row['hour_bucket'])
                        ->where('store_id = ?', (int) $row['store_id'])
                        ->where('event_type = ?', (string) $row['event_type'])
                        ->where('route = ?', (string) ($row['route'] ?? ''));
                    $exists = (int) $read->fetchOne($existsSelect);
                    $eventCount = (int) $row['event_count'];
                    $durationSum = (int) $row['duration_sum'];
                    $durationCount = (int) $row['duration_count'];

                    if ($exists > 0) {
                        $write->update($rollupTable, [
                            'event_count' => new Maho\Db\Expr('event_count + ' . $eventCount),
                            'duration_sum' => new Maho\Db\Expr('duration_sum + ' . $durationSum),
                            'duration_count' => new Maho\Db\Expr('duration_count + ' . $durationCount),
                        ], $where);
                    } else {
                        $write->insert($rollupTable, [
                            'bucket' => (string) $row['hour_bucket'],
                            'store_id' => (int) $row['store_id'],
                            'event_type' => (string) $row['event_type'],
                            'route' => (string) ($row['route'] ?? ''),
                            'event_count' => $eventCount,
                            'duration_sum' => $durationSum,
                            'duration_count' => $durationCount,
                        ]);
                    }
                    $processed++;
                }

                $write->commit();
            } catch (\Throwable $e) {
                $write->rollBack();
                Mage::logException($e);
                return 0;
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
            return 0;
        } finally {
            $lock->release($lockName);
        }

        return $processed;
    }

    public function cleanup(): int
    {
        $rawCutoff = gmdate('Y-m-d H:i:s', time() - $this->helper()->getStatsRawRetentionDays() * 86400);
        $rollupCutoff = gmdate('Y-m-d H:i:s', time() - $this->helper()->getStatsRollupRetentionDays() * 86400);
        $deleted = 0;

        try {
            $deleted += $this->write()->delete($this->table('page_cache/stat'), ['created_at < ?' => $rawCutoff]);
            $deleted += $this->write()->delete($this->table('page_cache/stat_hourly'), ['bucket < ?' => $rollupCutoff]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        return $deleted;
    }

    /**
     * @return array<string, int>
     */
    public function getTodayCounts(): array
    {
        $counts = array_fill_keys([
            self::EVENT_HIT,
            self::EVENT_MISS,
            self::EVENT_BYPASS,
            self::EVENT_REVALIDATED,
        ], 0);

        try {
            $this->flush();
            $read = $this->read();
            $select = $read->select()
                ->from($this->table('page_cache/stat'), [
                    'event_type',
                    'event_count' => new Maho\Db\Expr('COUNT(*)'),
                ])
                ->where('created_at >= ?', gmdate('Y-m-d 00:00:00'))
                ->where('event_type IN (?)', array_keys($counts))
                ->group('event_type');
            foreach ($read->fetchAll($select) as $row) {
                $eventType = (string) $row['event_type'];
                if (array_key_exists($eventType, $counts)) {
                    $counts[$eventType] = (int) $row['event_count'];
                }
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        return $counts;
    }

    /**
     * @return array{hit: int, miss: int, bypass: int, revalidated: int, dynamic: int, purge: int, flush: int, warmup: int, hit_rate: float, avg_duration_ms: float|null}
     */
    public function getSummary(int $days, int $storeId = 0): array
    {
        $summary = array_fill_keys(self::EVENTS, 0);
        $durationSum = 0;
        $durationCount = 0;

        try {
            $read = $this->read();
            [$table, $timeColumn, $start] = $this->rangeSource($days);
            $columns = [
                'event_type',
                'event_count' => new Maho\Db\Expr('COUNT(*)'),
                'duration_sum' => new Maho\Db\Expr('COALESCE(SUM(duration_ms), 0)'),
                'duration_count' => new Maho\Db\Expr('SUM(CASE WHEN duration_ms IS NULL THEN 0 ELSE 1 END)'),
            ];
            if ($table === $this->table('page_cache/stat_hourly')) {
                $columns = [
                    'event_type',
                    'event_count' => new Maho\Db\Expr('SUM(event_count)'),
                    'duration_sum' => new Maho\Db\Expr('SUM(duration_sum)'),
                    'duration_count' => new Maho\Db\Expr('SUM(duration_count)'),
                ];
            }

            $select = $read->select()
                ->from($table, $columns)
                ->where($timeColumn . ' >= ?', $start)
                ->group('event_type');
            if ($storeId > 0) {
                $select->where('store_id = ?', $storeId);
            }

            foreach ($read->fetchAll($select) as $row) {
                $eventType = (string) $row['event_type'];
                if (array_key_exists($eventType, $summary)) {
                    $summary[$eventType] = (int) $row['event_count'];
                }
                if (in_array($eventType, [
                    self::EVENT_HIT,
                    self::EVENT_MISS,
                    self::EVENT_REVALIDATED,
                    self::EVENT_DYNAMIC,
                ], true)) {
                    $durationSum += (int) $row['duration_sum'];
                    $durationCount += (int) $row['duration_count'];
                }
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        $hitBase = $summary[self::EVENT_HIT] + $summary[self::EVENT_MISS] + $summary[self::EVENT_REVALIDATED];
        $summary['hit_rate'] = $hitBase > 0
            ? round(($summary[self::EVENT_HIT] + $summary[self::EVENT_REVALIDATED]) / $hitBase * 100, 1)
            : 0.0;
        $summary['avg_duration_ms'] = $durationCount > 0 ? round($durationSum / $durationCount, 1) : null;

        return $summary;
    }

    /**
     * @return list<array{bucket: string, hit: int, miss: int, bypass: int, revalidated: int, dynamic: int}>
     */
    public function getTrend(int $days, int $storeId = 0): array
    {
        $trend = [];
        try {
            $read = $this->read();
            $days = max(1, $days);
            $hourly = $days > 30;
            $perDay = $days > 1;
            $table = $hourly ? $this->table('page_cache/stat_hourly') : $this->table('page_cache/stat');
            $timeColumn = $hourly ? 'bucket' : 'hour_bucket';
            $start = gmdate('Y-m-d H:i:s', time() - $days * 86400);
            $columns = [
                'bucket' => $timeColumn,
                'event_type',
                'event_count' => new Maho\Db\Expr($hourly ? 'SUM(event_count)' : 'COUNT(*)'),
            ];

            $select = $read->select()
                ->from($table, $columns)
                ->where($timeColumn . ' >= ?', $start)
                ->group([$timeColumn, 'event_type'])
                ->order($timeColumn . ' ASC');
            if ($storeId > 0) {
                $select->where('store_id = ?', $storeId);
            }

            foreach ($read->fetchAll($select) as $row) {
                $bucket = $perDay
                    ? substr((string) $row['bucket'], 0, 10)
                    : (string) $row['bucket'];
                $trend[$bucket] ??= ['bucket' => $bucket, 'hit' => 0, 'miss' => 0, 'bypass' => 0, 'revalidated' => 0, 'dynamic' => 0];
                $eventType = (string) $row['event_type'];
                if (array_key_exists($eventType, $trend[$bucket])) {
                    $trend[$bucket][$eventType] += (int) $row['event_count'];
                }
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        return array_values($trend);
    }

    /**
     * @return list<array{url_path: string, miss_count: int}>
     */
    public function getTopMissed(int $days, int $storeId = 0, int $limit = 10): array
    {
        try {
            $read = $this->read();
            $start = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
            $select = $read->select()
                ->from($this->table('page_cache/stat'), [
                    'url_path',
                    'miss_count' => new Maho\Db\Expr('COUNT(*)'),
                ])
                ->where('event_type = ?', self::EVENT_MISS)
                ->where('created_at >= ?', $start)
                ->where('url_path IS NOT NULL')
                ->group('url_path')
                ->order('miss_count DESC')
                ->limit(max(1, $limit));
            if ($storeId > 0) {
                $select->where('store_id = ?', $storeId);
            }

            return array_map(
                static fn(array $row): array => [
                    'url_path' => (string) $row['url_path'],
                    'miss_count' => (int) $row['miss_count'],
                ],
                $read->fetchAll($select),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    public function getLastRollupBucket(): ?string
    {
        try {
            $read = $this->read();
            $value = $read->fetchOne(
                $read->select()->from($this->table('page_cache/stat_hourly'), ['MAX(bucket)']),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentEvents(int $days = 30, int $limit = 20): array
    {
        try {
            $read = $this->read();
            $start = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
            $select = $read->select()
                ->from($this->table('page_cache/stat'))
                ->where('event_type IN (?)', [self::EVENT_FLUSH, self::EVENT_PURGE, self::EVENT_WARMUP])
                ->where('created_at >= ?', $start)
                ->order('created_at DESC')
                ->limit(max(1, $limit));
            return $read->fetchAll($select);
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function rangeSource(int $days): array
    {
        if ($days > 30) {
            return [
                $this->table('page_cache/stat_hourly'),
                'bucket',
                gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400),
            ];
        }

        return [
            $this->table('page_cache/stat'),
            'created_at',
            gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400),
        ];
    }

    private function normalizeRoute(?string $route): ?string
    {
        if ($route === null || $route === '') {
            $request = Mage::app()->getRequest();
            $route = trim(
                $request->getRequestedRouteName() . '_'
                . $request->getRequestedControllerName() . '_'
                . $request->getRequestedActionName(),
                '_',
            );
        }

        return $route === '' ? null : substr($route, 0, 128);
    }

    private function normalizePath(?string $urlPath): ?string
    {
        if ($urlPath === null || $urlPath === '') {
            $request = Mage::app()->getRequest();
            $urlPath = (string) ($request->getOriginalPathInfo() ?: $request->getPathInfo());
        }
        if ($urlPath === '') {
            return null;
        }

        $query = strpos($urlPath, '?');
        if ($query !== false) {
            $urlPath = substr($urlPath, 0, $query);
        }

        return $urlPath === '' ? null : substr($urlPath, 0, 512);
    }

    private function getRequestDuration(): ?int
    {
        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return null;
        }

        return (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
    }

    private function table(string $alias): string
    {
        return Mage::getSingleton('core/resource')->getTableName($alias);
    }

    private function read(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    protected function write(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function helper(): Maho_PageCache_Helper_Data
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');
        return $helper;
    }
}
