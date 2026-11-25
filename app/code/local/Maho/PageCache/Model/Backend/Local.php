<?php

/**
 * Local page cache backend that stores full page HTML in Maho's cache.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Backend_Local extends Maho_PageCache_Model_Backend_Abstract
{
    #[\Override]
    public function getMode(): string
    {
        return Maho_PageCache_Helper_Data::MODE_LOCAL;
    }

    #[\Override]
    public function getCacheControl(int $ttl): string
    {
        return 'private, max-age=0, must-revalidate';
    }

    /**
     * @param list<string> $tags
     * @param array<string, mixed> $entry
     */
    public function applyMiss(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        array $entry,
        ?int $urlVersion = null,
    ): void {
        $this->apply($context, $ttl, $tags, $debug, $urlVersion);
        $this->applyEntryHeaders($context, $entry, $debug, 'MISS', $ttl);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function applyHit(
        Maho_PageCache_Model_Context $context,
        array $entry,
        bool $debug,
    ): void {
        $response = $this->response();
        $response->setHttpResponseCode(200);
        $html = (string) ($entry['html'] ?? '');
        $html = Mage::getService('page_cache/dynamic')->renderServerBlocks($html, $entry, $context);
        $response->setBody($html);
        $this->applyEntryHeaders($context, $entry, $debug, 'HIT', (int) ($entry['ttl'] ?? 0));
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function applyHitNotModified(
        Maho_PageCache_Model_Context $context,
        array $entry,
        bool $debug,
    ): void {
        $response = $this->response();
        $response->setHttpResponseCode(304);
        $response->clearBody();
        $this->applyEntryHeaders($context, $entry, $debug, 'HIT', 0);
    }

    #[\Override]
    protected function applyModeHeaders(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        ?int $urlVersion = null,
    ): void {
        $version = Mage::getSingleton('page_cache/version');
        $lastModified = time();
        $etag = $version->getEtag(
            $context->getUrlHash(),
            $context->getHash(),
            $ttl,
            $this->getMode(),
            $urlVersion,
        );
        $version->storeEtag($context->getUrlHash(), $etag, $lastModified, $tags, $ttl);

        $response = $this->response();
        $response->setHeader('ETag', $etag, true);
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT', true);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function applyEntryHeaders(
        Maho_PageCache_Model_Context $context,
        array $entry,
        bool $debug,
        string $status,
        int $ttl,
    ): void {
        $response = $this->response();
        $response->setHeader('Cache-Control', $this->getCacheControl($ttl), true);
        $response->setHeader('Vary', $this->getVary(), true);
        $response->setHeader('X-Maho-Cache', $status, true);
        $response->setHeader('X-Maho-Cache-Mode', $this->getMode(), true);
        $response->setHeader('X-Maho-Cache-Context', $context->getHash(), true);

        $etag = (string) ($entry['etag'] ?? '');
        if ($etag !== '') {
            $response->setHeader('ETag', $etag, true);
        }

        $lastModified = (int) ($entry['last_modified'] ?? 0);
        if ($lastModified > 0) {
            $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT', true);
        }

        $headers = $entry['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $response->setHeader($name, $value, true);
                }
            }
        }

        if ($debug) {
            $tags = $entry['tags'] ?? [];
            $response->setHeader(
                'X-Maho-Cache-Tags',
                $this->getTagHeader(is_array($tags) ? $tags : []),
                true,
            );
            $response->setHeader('X-Maho-Cache-Version', $context->getUrlHash(), true);
        }
    }
}
