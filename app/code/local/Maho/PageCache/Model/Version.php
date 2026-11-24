<?php

/**
 * Version tokens and validators for revalidating cache entries.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Version
{
    public function getGlobal(): int
    {
        $value = Mage::app()->getCache()->load($this->key()->getVersionGlobalKey());
        return is_numeric($value) ? (int) $value : 1;
    }

    public function incrementGlobal(): int
    {
        $value = max(time(), $this->getGlobal() + 1);
        $this->setGlobal($value);
        return $value;
    }

    public function setGlobal(int $value): void
    {
        Mage::app()->getCache()->save($value, $this->key()->getVersionGlobalKey(), ['PAGE_CACHE']);
    }

    public function getUrl(string $urlHash): int
    {
        $value = Mage::app()->getCache()->load($this->key()->getVersionUrlKey($urlHash));
        return is_numeric($value) ? (int) $value : 1;
    }

    public function incrementUrl(string $urlHash): int
    {
        $value = $this->getUrl($urlHash) + 1;
        $this->setUrl($urlHash, $value);
        return $value;
    }

    public function setUrl(string $urlHash, int $value): void
    {
        Mage::app()->getCache()->save($value, $this->key()->getVersionUrlKey($urlHash), ['PAGE_CACHE']);
    }

    public function getEtag(
        string $urlHash,
        string $contextHash,
        int $ttl,
        string $mode,
        ?int $urlVersion = null,
    ): string {
        $urlVersion ??= $this->getUrl($urlHash);
        $bucket = $ttl > 0 ? (int) floor(time() / $ttl) : 0;
        $value = hash(
            'sha256',
            $this->getGlobal()
            . '|' . $urlVersion
            . '|' . $urlHash
            . '|' . $contextHash
            . '|' . $bucket
            . '|' . $mode,
        );
        return 'W/"' . $value . '"';
    }

    public function isNotModified(string $ifNoneMatch, string $etag): bool
    {
        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '' || $etag === '') {
            return false;
        }

        $expected = $this->normalizeEtag($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if ($candidate !== '' && $this->normalizeEtag($candidate) === $expected) {
                return true;
            }
        }
        return false;
    }

    public function storeEtag(string $urlHash, string $etag, int $lastModified): void
    {
        Mage::app()->getCache()->save(
            ['etag' => $etag, 'last_modified' => $lastModified],
            $this->key()->getEtagKey($urlHash),
            ['PAGE_CACHE'],
        );
    }

    public function getLastModified(string $urlHash): int
    {
        $data = Mage::app()->getCache()->load($this->key()->getEtagKey($urlHash));
        return is_array($data) && isset($data['last_modified']) ? (int) $data['last_modified'] : 0;
    }

    private function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }
        return trim($etag, '"');
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }
}
