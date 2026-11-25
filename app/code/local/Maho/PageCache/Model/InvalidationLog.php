<?php

/**
 * Invalidation audit records for the page cache.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_InvalidationLog
{
    /**
     * @param array{
     *     area?: string,
     *     cron_job?: string|null,
     *     type?: string,
     *     reason?: string|null,
     *     tags?: list<string>,
     *     tag_count?: int,
     *     affected_urls?: int,
     *     purge_queued?: bool,
     *     warm_queued?: bool,
     *     store_id?: int,
     *     mode?: string|null
     * } $data
     */
    public function record(array $data): void
    {
        if (!Mage::helper('page_cache')->getAuditEnabled()) {
            return;
        }

        try {
            $tags = $this->tags($data['tags'] ?? []);
            $sample = array_slice($tags, 0, Mage::helper('page_cache')->getAuditSampleTags());
            $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
            $this->write()->insert($this->table(), [
                'area' => $this->area($data['area'] ?? null),
                'cron_job' => $this->cronJob($data['cron_job'] ?? null),
                'invalidation_type' => substr((string) ($data['type'] ?? 'tag'), 0, 32),
                'reason' => $reason === '' ? null : substr($reason, 0, 255),
                'tag_count' => max(0, (int) ($data['tag_count'] ?? count($tags))),
                'sample_tags' => $this->encode($sample),
                'affected_urls' => max(0, (int) ($data['affected_urls'] ?? 0)),
                'purge_queued' => !empty($data['purge_queued']) ? 1 : 0,
                'warm_queued' => !empty($data['warm_queued']) ? 1 : 0,
                'store_id' => max(0, (int) ($data['store_id'] ?? Mage::app()->getStore()->getId())),
                'mode' => isset($data['mode']) ? substr((string) $data['mode'], 0, 16) : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $days = 1): array
    {
        $days = in_array($days, [1, 7, 30], true) ? $days : 1;
        $read = $this->read();
        $rows = $read->fetchAll(
            $read->select()
                ->from($this->table())
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - $days * 86400))
                ->order('log_id DESC')
                ->limit(5000),
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tags = $this->decode((string) ($row['sample_tags'] ?? '[]'));
            $row['sample_tags'] = implode(', ', $tags);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<int|string> $ids
     */
    public function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $ids),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return 0;
        }

        return $this->write()->delete($this->table(), ['log_id IN (?)' => array_slice($ids, 0, 1000)]);
    }

    public function countRecent(int $hours = 24): int
    {
        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $hours) * 3600);
            $read = $this->read();
            return (int) $read->fetchOne(
                $read->select()
                    ->from($this->table(), ['COUNT(*)'])
                    ->where('created_at >= ?', $cutoff),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return 0;
        }
    }

    public function cleanup(int $days = 30): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        return $this->write()->delete($this->table(), ['created_at < ?' => $cutoff]);
    }

    public function isEnabled(): bool
    {
        return Mage::helper('page_cache')->getAuditEnabled();
    }

    private function area(?string $area): string
    {
        if ($area !== null && $area !== '') {
            return substr($area, 0, 16);
        }
        if (PHP_SAPI === 'cli') {
            return Mage::registry('page_cache_audit_cron_job') ? 'cron' : 'cli';
        }

        return Mage::app()->getStore()->isAdmin() ? 'admin' : 'frontend';
    }

    private function cronJob(?string $job): ?string
    {
        $job ??= (string) Mage::registry('page_cache_audit_cron_job');
        $job = trim($job);
        return $job === '' ? null : substr($job, 0, 64);
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function tags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $result[$tag] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param list<string> $tags
     */
    private function encode(array $tags): string
    {
        return (string) json_encode(array_values($tags), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function decode(string $json): array
    {
        try {
            $tags = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, static fn(mixed $tag): bool => is_string($tag) && $tag !== ''));
    }

    private function table(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/invalidation_log');
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
