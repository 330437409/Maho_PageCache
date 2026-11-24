<?php

/**
 * Summary and alerting for pending stock invalidations.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_StockPending
{
    /**
     * @return array{count: int, oldest_at: string|null, age: int|null}
     */
    public function getSummary(): array
    {
        try {
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow(
                $read->select()->from(
                    Mage::getSingleton('core/resource')->getTableName('page_cache/stock_pending'),
                    [
                        'pending_count' => new Maho\Db\Expr('COUNT(*)'),
                        'oldest_at' => new Maho\Db\Expr('MIN(created_at)'),
                    ],
                ),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return ['count' => 0, 'oldest_at' => null, 'age' => null];
        }

        $count = (int) ($row['pending_count'] ?? 0);
        $oldestAt = isset($row['oldest_at']) && is_string($row['oldest_at']) ? $row['oldest_at'] : null;
        if ($count === 0 || $oldestAt === null) {
            return ['count' => 0, 'oldest_at' => null, 'age' => null];
        }

        $created = strtotime($oldestAt . ' UTC');
        return [
            'count' => $count,
            'oldest_at' => $oldestAt,
            'age' => $created === false ? null : max(0, time() - $created),
        ];
    }

    /**
     * @param array{count: int, oldest_at: string|null, age: int|null} $summary
     */
    public function isStale(array $summary, int $maxAge): bool
    {
        return $maxAge > 0
            && $summary['count'] > 0
            && $summary['age'] !== null
            && $summary['age'] >= $maxAge;
    }

    public function logIfStale(int $maxAge): void
    {
        $summary = $this->getSummary();
        if (!$this->isStale($summary, $maxAge)) {
            return;
        }

        Mage::log(
            sprintf(
                'Page cache stock pending: %d product(s), oldest %d seconds. Run ./maho index:reindex cataloginventory_stock.',
                $summary['count'],
                (int) $summary['age'],
            ),
            Mage::LOG_WARNING,
            'page-cache-stock.log',
        );
    }
}
