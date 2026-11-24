<?php

/**
 * Durable-enough purge queue for reverse proxy cache invalidation.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_PurgeQueue
{
    private const int ENTRY_TTL = 86400;
    private const int INDEX_TTL = 90000;

    /**
     * @param list<string> $urls
     * @param list<string> $tags
     */
    public function enqueue(array $urls, array $tags, string $mode): string
    {
        $id = sprintf('%d_%d', (int) microtime(true), random_int(1000, 9999));
        $entry = [
            'urls' => array_values(array_unique($urls)),
            'tags' => array_values(array_unique($tags)),
            'mode' => $mode,
            'created_at' => time(),
        ];
        Mage::app()->getCache()->save($entry, $this->key()->getPurgeEntryKey($id), ['PAGE_CACHE'], self::ENTRY_TTL);

        $index = $this->getIndex();
        if (!in_array($id, $index, true)) {
            $index[] = $id;
        }
        Mage::app()->getCache()->save($index, $this->key()->getPurgeIndexKey(), ['PAGE_CACHE'], self::INDEX_TTL);

        return $id;
    }

    public function enqueueFullPurge(): string
    {
        return $this->enqueue(['*'], ['*'], 'full');
    }

    /**
     * @return array<string, array{urls: list<string>, tags: list<string>, mode: string, created_at: int}>
     */
    public function getEntries(): array
    {
        $entries = [];
        foreach ($this->getIndex() as $id) {
            $entry = Mage::app()->getCache()->load($this->key()->getPurgeEntryKey($id));
            if (is_array($entry) && isset($entry['urls'], $entry['tags'], $entry['mode'])) {
                $entries[$id] = [
                    'urls' => array_values(array_filter($entry['urls'], is_string(...))),
                    'tags' => array_values(array_filter($entry['tags'], is_string(...))),
                    'mode' => (string) $entry['mode'],
                    'created_at' => (int) ($entry['created_at'] ?? 0),
                ];
            }
        }
        return $entries;
    }

    public function count(): int
    {
        return count($this->getEntries());
    }

    public function remove(string $id): void
    {
        Mage::app()->getCache()->remove($this->key()->getPurgeEntryKey($id));
        $index = $this->getIndex();
        $index = array_values(array_filter($index, static fn(string $value): bool => $value !== $id));
        Mage::app()->getCache()->save($index, $this->key()->getPurgeIndexKey(), ['PAGE_CACHE'], self::INDEX_TTL);
    }

    public function clear(): void
    {
        foreach (array_keys($this->getEntries()) as $id) {
            Mage::app()->getCache()->remove($this->key()->getPurgeEntryKey($id));
        }
        Mage::app()->getCache()->remove($this->key()->getPurgeIndexKey());
    }

    /**
     * @return list<string>
     */
    private function getIndex(): array
    {
        $index = Mage::app()->getCache()->load($this->key()->getPurgeIndexKey());
        if (!is_array($index)) {
            return [];
        }
        return array_values(array_filter($index, static fn(mixed $id): bool => is_string($id) && $id !== ''));
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }
}
