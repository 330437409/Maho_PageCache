<?php

/**
 * Tag to URL index for page cache invalidation.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_TagIndex
{
    private const string REGISTRY_KEY = 'PC_TAG_REGISTRY';
    private const int INDEX_TTL = 2592000;

    /**
     * @param list<string> $tags
     */
    public function add(string $urlHash, array $tags): void
    {
        foreach (array_unique($tags) as $tag) {
            if ($tag === '') {
                continue;
            }
            $key = $this->key()->getTagKey($tag);
            $urls = Mage::app()->getCache()->load($key);
            if (!is_array($urls)) {
                $urls = [];
            }
            if (!in_array($urlHash, $urls, true)) {
                $urls[] = $urlHash;
            }
            Mage::app()->getCache()->save(array_values($urls), $key, ['PAGE_CACHE'], self::INDEX_TTL);
            $this->register($tag);
        }
    }

    /**
     * @return list<string>|false false when the index is missing
     */
    public function getUrls(string $tag): array|false
    {
        $urls = Mage::app()->getCache()->load($this->key()->getTagKey($tag));
        if (!is_array($urls)) {
            return false;
        }
        return array_values(array_filter($urls, static fn(mixed $url): bool => is_string($url) && $url !== ''));
    }

    public function isKnown(string $tag): bool
    {
        $registry = Mage::app()->getCache()->load(self::REGISTRY_KEY);
        if (!is_array($registry)) {
            // The registry was never created or was evicted. Be conservative.
            return true;
        }
        return in_array($tag, $registry, true);
    }

    public function remove(string $tag): void
    {
        Mage::app()->getCache()->remove($this->key()->getTagKey($tag));
        $registry = array_values(array_filter(
            $this->getRegistry(),
            static fn(string $value): bool => $value !== $tag,
        ));
        Mage::app()->getCache()->save($registry, self::REGISTRY_KEY, ['PAGE_CACHE'], self::INDEX_TTL);
    }

    public function removeUrl(string $tag, string $urlHash): void
    {
        $key = $this->key()->getTagKey($tag);
        $urls = Mage::app()->getCache()->load($key);
        if (!is_array($urls)) {
            return;
        }

        $urls = array_values(array_filter(
            $urls,
            static fn(mixed $value): bool => is_string($value) && $value !== $urlHash,
        ));
        if ($urls === []) {
            $this->remove($tag);
            return;
        }

        Mage::app()->getCache()->save($urls, $key, ['PAGE_CACHE'], self::INDEX_TTL);
    }

    public function flush(): void
    {
        Mage::app()->getCache()->cleanType('page_cache');
        Mage::app()->getCache()->save([], self::REGISTRY_KEY, ['PAGE_CACHE'], self::INDEX_TTL);
    }

    private function register(string $tag): void
    {
        $registry = $this->getRegistry();
        if (!in_array($tag, $registry, true)) {
            $registry[] = $tag;
            Mage::app()->getCache()->save($registry, self::REGISTRY_KEY, ['PAGE_CACHE'], self::INDEX_TTL);
        }
    }

    /**
     * @return list<string>
     */
    private function getRegistry(): array
    {
        $registry = Mage::app()->getCache()->load(self::REGISTRY_KEY);
        if (!is_array($registry)) {
            return [];
        }
        return array_values(array_filter($registry, static fn(mixed $tag): bool => is_string($tag) && $tag !== ''));
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }
}
