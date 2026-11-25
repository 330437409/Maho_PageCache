<?php

/**
 * Durable purge outbox for reverse proxy and CDN invalidation.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_PurgeQueue
{
    public const TARGET_PROXY = 'proxy';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<string> $urls
     * @param list<string> $tags
     */
    public function enqueue(array $urls, array $tags, string $mode, string $target = self::TARGET_PROXY): string
    {
        $urls = $this->normalizeValues($urls);
        $tags = $this->normalizeValues($tags);
        $target = $this->normalizeTarget($target);
        $mode = $mode === 'full' ? 'full' : 'proxy';
        $dedupeKey = $this->dedupeKey($target, $mode, $urls, $tags);

        $existing = $this->findReusable($target, $dedupeKey);
        if ($existing !== null) {
            return (string) $existing;
        }

        $now = gmdate('Y-m-d H:i:s');
        $write = $this->write();
        $write->insert($this->table(), [
            'target' => $target,
            'dedupe_key' => $dedupeKey,
            'mode' => $mode,
            'urls' => $this->encode($urls),
            'tags' => $this->encode($tags),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (string) $write->lastInsertId($this->table(), 'outbox_id');
    }

    public function enqueueFullPurge(string $target = self::TARGET_PROXY): string
    {
        return $this->enqueue(['*'], ['*'], 'full', $target);
    }

    /**
     * Compatibility view of pending rows. New code should use claim().
     *
     * @return array<int, array{urls: list<string>, tags: list<string>, mode: string, created_at: int}>
     */
    public function getEntries(?string $target = null): array
    {
        $entries = [];
        foreach ($this->getPending($target, 0, null, true) as $id => $row) {
            $entries[$id] = [
                'urls' => $row['urls'],
                'tags' => $row['tags'],
                'mode' => $row['mode'],
                'created_at' => $row['created_at'],
            ];
        }

        return $entries;
    }

    public function count(?string $target = null): int
    {
        $read = $this->read();
        $select = $read->select()
            ->from($this->table(), ['total' => 'COUNT(*)'])
            ->where('status = ?', self::STATUS_PENDING);
        if ($target !== null) {
            $select->where('target = ?', $this->normalizeTarget($target));
        }

        return (int) $read->fetchOne($select);
    }

    /**
     * @return array<int, array{urls: list<string>, tags: list<string>, mode: string, attempts: int, created_at: int}>
     */
    public function getPending(
        ?string $target = null,
        int $limit = 0,
        ?string $tag = null,
        bool $includeDelayed = false,
    ): array {
        $read = $this->read();
        $select = $read->select()
            ->from($this->table(), ['outbox_id', 'urls', 'tags', 'mode', 'attempts', 'created_at'])
            ->where('status = ?', self::STATUS_PENDING)
            ->order('outbox_id ASC');
        if (!$includeDelayed) {
            $select->where('available_at <= ?', gmdate('Y-m-d H:i:s'));
        }
        if ($target !== null) {
            $select->where('target = ?', $this->normalizeTarget($target));
        }
        if ($limit > 0 && ($tag === null || $tag === '')) {
            $select->limit($limit);
        }

        $result = [];
        foreach ($read->fetchAll($select) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $urls = $this->decode((string) ($row['urls'] ?? '[]'));
            $tags = $this->decode((string) ($row['tags'] ?? '[]'));
            if ($tag !== null && $tag !== ''
                && !in_array($tag, $tags, true)
                && !in_array('*', $tags, true)
            ) {
                continue;
            }
            $id = (string) (int) ($row['outbox_id'] ?? 0);
            if ($id === '0') {
                continue;
            }
            $result[$id] = [
                'urls' => $urls,
                'tags' => $tags,
                'mode' => (string) ($row['mode'] ?? 'proxy'),
                'attempts' => (int) ($row['attempts'] ?? 0),
                'created_at' => $this->timestamp((string) ($row['created_at'] ?? '')),
            ];
        }

        if ($limit > 0 && count($result) > $limit) {
            $result = array_slice($result, 0, $limit, true);
        }

        return $result;
    }

    /**
     * Claim pending rows for one target with an optimistic status update.
     *
     * @return array<int, array{urls: list<string>, tags: list<string>, mode: string, attempts: int, created_at: int}>
     */
    public function claim(string $target, int $limit, ?string $tag = null): array
    {
        $target = $this->normalizeTarget($target);
        $this->recoverExpired($target);

        $now = gmdate('Y-m-d H:i:s');
        $worker = $this->workerId();
        $claimed = [];
        foreach ($this->getPending($target, $limit, $tag) as $id => $row) {
            $updated = $this->write()->update(
                $this->table(),
                [
                    'status' => self::STATUS_PROCESSING,
                    'locked_at' => $now,
                    'locked_by' => $worker,
                    'updated_at' => $now,
                ],
                [
                    'outbox_id = ?' => (int) $id,
                    'status = ?' => self::STATUS_PENDING,
                    'available_at <= ?' => $now,
                ],
            );
            if ($updated === 1) {
                $claimed[$id] = $row;
            }
        }

        return $claimed;
    }

    public function ack(string $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->write()->update(
            $this->table(),
            [
                'status' => self::STATUS_DELIVERED,
                'delivered_at' => $now,
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'updated_at' => $now,
            ],
            ['outbox_id = ?' => (int) $id, 'status IN (?)' => [self::STATUS_PENDING, self::STATUS_PROCESSING]],
        ) === 1;
    }

    public function fail(string $id, string $error): void
    {
        $read = $this->read();
        $row = $read->fetchRow(
            $read->select()
                ->from($this->table(), ['attempts', 'target'])
                ->where('outbox_id = ?', (int) $id),
        );
        if (!is_array($row)) {
            return;
        }

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $maxAttempts = Mage::helper('page_cache')->getPurgeMaxAttempts();
        $status = $attempts >= $maxAttempts ? self::STATUS_FAILED : self::STATUS_PENDING;
        $delay = $this->retryDelay($attempts);
        $now = time();

        $this->write()->update(
            $this->table(),
            [
                'status' => $status,
                'attempts' => $attempts,
                'available_at' => gmdate('Y-m-d H:i:s', $now + $delay),
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => substr($error, 0, 255),
                'updated_at' => gmdate('Y-m-d H:i:s', $now),
            ],
            ['outbox_id = ?' => (int) $id, 'status IN (?)' => [self::STATUS_PENDING, self::STATUS_PROCESSING]],
        );
    }

    public function retryFailed(string $target = self::TARGET_PROXY): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->write()->update(
            $this->table(),
            [
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'updated_at' => $now,
            ],
            ['target = ?' => $this->normalizeTarget($target), 'status = ?' => self::STATUS_FAILED],
        );
    }

    /**
     * @return array{pending: int, processing: int, delivered: int, failed: int, oldest_pending: int|null, last_success: string|null, last_failure: string|null, last_error: string|null}
     */
    public function getStatus(string $target = self::TARGET_PROXY): array
    {
        $target = $this->normalizeTarget($target);
        $read = $this->read();
        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_DELIVERED => 0,
            self::STATUS_FAILED => 0,
        ];
        foreach ($read->fetchAll(
            $read->select()
                ->from($this->table(), ['status', 'total' => 'COUNT(*)'])
                ->where('target = ?', $target)
                ->group('status'),
        ) as $row) {
            if (is_array($row) && isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
        }

        $oldest = $read->fetchOne(
            $read->select()
                ->from($this->table(), ['created_at'])
                ->where('target = ?', $target)
                ->where('status = ?', self::STATUS_PENDING)
                ->order('created_at ASC')
                ->limit(1),
        );
        $lastSuccess = $read->fetchOne(
            $read->select()
                ->from($this->table(), ['delivered_at'])
                ->where('target = ?', $target)
                ->where('status = ?', self::STATUS_DELIVERED)
                ->order('delivered_at DESC')
                ->limit(1),
        );
        $failure = $read->fetchRow(
            $read->select()
                ->from($this->table(), ['updated_at', 'last_error'])
                ->where('target = ?', $target)
                ->where('status = ?', self::STATUS_FAILED)
                ->order('updated_at DESC')
                ->limit(1),
        );

        return [
            'pending' => $counts[self::STATUS_PENDING],
            'processing' => $counts[self::STATUS_PROCESSING],
            'delivered' => $counts[self::STATUS_DELIVERED],
            'failed' => $counts[self::STATUS_FAILED],
            'oldest_pending' => is_string($oldest) && $oldest !== '' ? $this->timestamp($oldest) : null,
            'last_success' => is_string($lastSuccess) && $lastSuccess !== '' ? $lastSuccess : null,
            'last_failure' => is_array($failure) ? (string) ($failure['updated_at'] ?? '') : null,
            'last_error' => is_array($failure) ? (string) ($failure['last_error'] ?? '') : null,
        ];
    }

    public function cleanup(int $retentionDays = 7): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * 86400);
        return $this->write()->delete(
            $this->table(),
            [
                'status IN (?)' => [self::STATUS_DELIVERED, self::STATUS_FAILED],
                'updated_at < ?' => $cutoff,
            ],
        );
    }

    /**
     * Hard delete, kept for existing callers and tests.
     */
    public function remove(string $id): void
    {
        $this->write()->delete($this->table(), ['outbox_id = ?' => (int) $id]);
    }

    public function clear(): void
    {
        $this->write()->delete($this->table(), '1=1');
    }

    private function recoverExpired(string $target): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - Mage::helper('page_cache')->getPurgeLeaseSeconds());
        $now = gmdate('Y-m-d H:i:s');
        $this->write()->update(
            $this->table(),
            [
                'status' => self::STATUS_PENDING,
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => $now,
            ],
            [
                'target = ?' => $target,
                'status = ?' => self::STATUS_PROCESSING,
                'locked_at < ?' => $cutoff,
            ],
        );
    }

    private function findReusable(string $target, string $dedupeKey): ?int
    {
        $read = $this->read();
        $id = $read->fetchOne(
            $read->select()
                ->from($this->table(), ['outbox_id'])
                ->where('target = ?', $target)
                ->where('dedupe_key = ?', $dedupeKey)
                ->where('status IN (?)', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->order('outbox_id DESC')
                ->limit(1),
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    private function retryDelay(int $attempts): int
    {
        $base = Mage::helper('page_cache')->getPurgeRetryDelay();
        $exponent = min(16, max(0, $attempts - 1));
        return min(3600, $base * (2 ** $exponent));
    }

    private function dedupeKey(string $target, string $mode, array $urls, array $tags): string
    {
        sort($urls, SORT_STRING);
        sort($tags, SORT_STRING);
        return hash('sha256', $target . '|' . $mode . '|' . $this->encode($urls) . '|' . $this->encode($tags));
    }

    /**
     * @return list<string>
     */
    private function normalizeValues(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $result[$value] = true;
            }
        }

        return array_keys($result);
    }

    private function normalizeTarget(string $target): string
    {
        $target = trim($target);
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $target) === 1 ? $target : self::TARGET_PROXY;
    }

    private function encode(array $values): string
    {
        return (string) json_encode(array_values($values), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function decode(string $json): array
    {
        try {
            $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn(mixed $value): bool => is_string($value) && $value !== ''));
    }

    private function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }

    private function workerId(): string
    {
        return substr(gethostname() . ':' . getmypid(), 0, 64);
    }

    private function table(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/purge_outbox');
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
