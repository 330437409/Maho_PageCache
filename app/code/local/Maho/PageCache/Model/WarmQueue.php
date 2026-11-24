<?php

/**
 * Queue of invalidated URLs that the scheduler warms again.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_WarmQueue
{
    private const int MAX_URL_LENGTH = 2048;

    /**
     * @param list<string> $urls
     * @return int number of new rows
     */
    public function enqueue(array $urls, int $storeId = 0, string $reason = ''): int
    {
        if (!Mage::helper('page_cache')->getWarmupEnabled()) {
            return 0;
        }

        $urls = $this->normalizeUrls($urls);
        if ($urls === []) {
            return 0;
        }

        $reason = $reason === '' ? null : substr($reason, 0, 64);
        $inserted = 0;

        foreach ($urls as $url) {
            try {
                $now = gmdate('Y-m-d H:i:s');
                $existing = $this->read()->fetchOne(
                    $this->read()->select()
                        ->from($this->table(), ['queue_id'])
                        ->where('url_hash = ?', $this->hash($url)),
                );

                if ($existing !== false && $existing !== null) {
                    $this->write()->update($this->table(), [
                        'url' => $url,
                        'store_id' => max(0, $storeId),
                        'reason' => $reason,
                        'available_at' => $now,
                        'updated_at' => $now,
                    ], ['queue_id = ?' => (int) $existing]);
                    continue;
                }

                $this->write()->insert($this->table(), [
                    'url_hash' => $this->hash($url),
                    'url' => $url,
                    'store_id' => max(0, $storeId),
                    'reason' => $reason,
                    'attempts' => 0,
                    'available_at' => $now,
                    'last_error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                Mage::logException($e);
            }
        }

        $this->enforceLimit();

        return $inserted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        try {
            $read = $this->read();
            return $read->fetchAll(
                $read->select()
                    ->from($this->table())
                    ->where('available_at <= ?', gmdate('Y-m-d H:i:s'))
                    ->order('queue_id ASC')
                    ->limit(max(1, $limit)),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function peek(int $limit): array
    {
        try {
            $read = $this->read();
            return $read->fetchAll(
                $read->select()
                    ->from($this->table())
                    ->order('queue_id ASC')
                    ->limit(max(1, $limit)),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    public function remove(int $queueId): void
    {
        try {
            $this->write()->delete($this->table(), ['queue_id = ?' => $queueId]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * @return 'retried'|'dropped'
     */
    public function retry(int $queueId, string $error, int $maxAttempts, int $retryDelay): string
    {
        try {
            $read = $this->read();
            $row = $read->fetchRow(
                $read->select()->from($this->table())->where('queue_id = ?', $queueId),
            );
            if (!is_array($row)) {
                return 'dropped';
            }

            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            if ($attempts >= max(1, $maxAttempts)) {
                $this->remove($queueId);
                return 'dropped';
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->write()->update($this->table(), [
                'attempts' => $attempts,
                'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $retryDelay) * $attempts),
                'last_error' => $error === '' ? null : substr($error, 0, 255),
                'updated_at' => $now,
            ], ['queue_id = ?' => $queueId]);

            return 'retried';
        } catch (\Throwable $e) {
            Mage::logException($e);
            return 'dropped';
        }
    }

    public function count(): int
    {
        try {
            $read = $this->read();
            return (int) $read->fetchOne($read->select()->from($this->table(), ['COUNT(*)']));
        } catch (\Throwable $e) {
            Mage::logException($e);
            return 0;
        }
    }

    public function clear(): void
    {
        try {
            $this->write()->delete($this->table(), '1=1');
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function normalizeUrls(array $urls): array
    {
        $valid = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $valid[$url] = true;
        }

        return array_keys($valid);
    }

    private function enforceLimit(): void
    {
        $limit = max(1, Mage::helper('page_cache')->getWarmupQueueLimit());
        $count = $this->count();
        if ($count <= $limit) {
            return;
        }

        try {
            $read = $this->read();
            $ids = $read->fetchCol(
                $read->select()
                    ->from($this->table(), ['queue_id'])
                    ->order('queue_id ASC')
                    ->limit($count - $limit),
            );
            if ($ids !== []) {
                $write = $this->write();
                $write->delete($this->table(), $write->quoteInto('queue_id IN (?)', $ids));
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    private function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function table(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue');
    }

    private function read(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function write(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }
}
