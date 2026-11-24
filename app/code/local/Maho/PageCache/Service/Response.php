<?php

/**
 * Page cache response headers and conditional request handling.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Response
{
    /**
     * @param list<string> $tags
     */
    public function apply(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        ?int $urlVersion = null,
    ): void {
        $this->backend($context)->apply($context, $ttl, $tags, $debug, $urlVersion);
    }

    public function applyNotModified(
        Maho_PageCache_Model_Context $context,
        string $etag,
        int $lastModified,
        bool $debug,
    ): void {
        $this->backend($context)->applyNotModified($context, $etag, $lastModified, $debug);
    }

    /**
     * @param list<string> $tags
     * @param array<string, mixed> $entry
     */
    public function applyLocalMiss(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        array $entry,
        ?int $urlVersion = null,
    ): void {
        $this->localBackend($context)->applyMiss($context, $ttl, $tags, $debug, $entry, $urlVersion);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function applyLocalHit(
        Maho_PageCache_Model_Context $context,
        array $entry,
        bool $debug,
    ): void {
        $this->localBackend($context)->applyHit($context, $entry, $debug);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function applyLocalHitNotModified(
        Maho_PageCache_Model_Context $context,
        array $entry,
        bool $debug,
    ): void {
        $this->localBackend($context)->applyHitNotModified($context, $entry, $debug);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function isLocalNotModified(
        Maho_PageCache_Model_Context $context,
        array $entry,
        string $ifNoneMatch,
    ): bool {
        if ($context->getMode() !== Maho_PageCache_Helper_Data::MODE_LOCAL || trim($ifNoneMatch) === '') {
            return false;
        }

        $etag = (string) ($entry['etag'] ?? '');
        return $etag !== '' && Mage::getSingleton('page_cache/version')->isNotModified($ifNoneMatch, $etag);
    }

    public function isNotModified(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        string $ifNoneMatch,
    ): bool {
        if ($context->getMode() !== Maho_PageCache_Helper_Data::MODE_REVALIDATING
            || trim($ifNoneMatch) === ''
        ) {
            return false;
        }

        $urlVersion = $this->getMetaVersion($context);
        if ($urlVersion === null) {
            return false;
        }

        return Mage::getSingleton('page_cache/version')->isNotModified(
            $ifNoneMatch,
            $this->getEtag($context, $ttl, $urlVersion),
        );
    }

    public function getEtag(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        ?int $urlVersion = null,
    ): string {
        return Mage::getSingleton('page_cache/version')->getEtag(
            $context->getUrlHash(),
            $context->getHash(),
            $ttl,
            $context->getMode(),
            $urlVersion,
        );
    }

    public function getMetaVersion(Maho_PageCache_Model_Context $context): ?int
    {
        $meta = Mage::app()->getCache()->load($this->key()->getMetaKey($context->getUrlHash()));
        if (!is_array($meta) || !isset($meta['version'])) {
            return null;
        }
        return (int) $meta['version'];
    }

    public function getLastModified(Maho_PageCache_Model_Context $context): int
    {
        return Mage::getSingleton('page_cache/version')->getLastModified($context->getUrlHash());
    }

    public function applyBypass(string $reason, bool $debug): void
    {
        $response = Mage::app()->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $response->setHeader('Vary', 'Accept-Encoding, Cookie', true);
        $response->setHeader('X-Maho-Cache', 'BYPASS', true);
        $response->setHeader(
            'X-Maho-Cache-Mode',
            Mage::helper('page_cache')->getMode() ?: 'disabled',
            true,
        );
        $response->setHeader('X-Maho-Cache-Bypass', $reason, true);
    }

    private function backend(Maho_PageCache_Model_Context $context): Maho_PageCache_Model_Backend_Abstract
    {
        $mode = $context->getMode();
        if (!in_array($mode, [
            Maho_PageCache_Helper_Data::MODE_PROXY,
            Maho_PageCache_Helper_Data::MODE_REVALIDATING,
            Maho_PageCache_Helper_Data::MODE_LOCAL,
        ], true)) {
            throw new \InvalidArgumentException('Invalid page cache mode: ' . $mode);
        }

        $backend = Mage::getModel('page_cache/backend_' . $mode);
        if (!$backend instanceof Maho_PageCache_Model_Backend_Abstract) {
            throw new \RuntimeException('Page cache backend is not available: ' . $mode);
        }
        return $backend;
    }

    private function localBackend(Maho_PageCache_Model_Context $context): Maho_PageCache_Model_Backend_Local
    {
        $backend = $this->backend($context);
        if (!$backend instanceof Maho_PageCache_Model_Backend_Local) {
            throw new \RuntimeException('Local page cache backend is not available.');
        }
        return $backend;
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }
}
