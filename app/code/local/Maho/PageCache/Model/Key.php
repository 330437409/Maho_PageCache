<?php

/**
 * Cache key builder for page cache metadata, tags and versions.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Key
{
    public function getUrlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    public function getMetaKey(string $urlHash): string
    {
        return 'PC_META_' . $urlHash;
    }

    public function getCacheTag(string $tag): string
    {
        return 'PAGE_CACHE_TAG_' . strtoupper($this->sanitizeTag($tag));
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public function getCacheTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)
                || $tag === ''
                || in_array($tag, ['PAGE_CACHE', 'PAGE_CACHE_HTML', 'PAGE_CACHE_DYNAMIC'], true)
            ) {
                continue;
            }
            $result[$this->getCacheTag($tag)] = true;
        }

        return array_keys($result);
    }

    public function getVersionGlobalKey(): string
    {
        return 'PC_VERSION_GLOBAL';
    }

    public function getVersionUrlKey(string $urlHash): string
    {
        return 'PC_VERSION_URL_' . $urlHash;
    }

    public function getEtagKey(string $urlHash): string
    {
        return 'PC_ETAG_' . $urlHash;
    }

    public function getHtmlKey(string $hash): string
    {
        return 'PC_HTML_' . $hash;
    }

    public function getHtmlIndexKey(string $urlHash): string
    {
        return 'PC_HTML_INDEX_' . $urlHash;
    }

    public function getPurgeIndexKey(): string
    {
        return 'PC_PURGE_INDEX';
    }

    public function getPurgeEntryKey(string $id): string
    {
        return 'PC_PURGE_' . $id;
    }

    public function getStatKey(string $date, string $result): string
    {
        return 'PC_STAT_' . $date . '_' . $result;
    }

    public function getWarmupLastRunKey(): string
    {
        return 'PC_WARMUP_LAST_RUN';
    }

    public function sanitizeTag(string $tag): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:-]/', '_', $tag);
    }
}
