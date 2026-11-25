<?php

/**
 * Common page cache backend behavior.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

abstract class Maho_PageCache_Model_Backend_Abstract
{
    abstract public function getMode(): string;

    abstract public function getCacheControl(int $ttl): string;

    public function getVary(): string
    {
        return 'Accept-Encoding, Cookie';
    }

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
        $response = $this->response();
        $response->setHeader('Cache-Control', $this->getCacheControl($ttl), true);
        $response->setHeader('Vary', $this->getVary(), true);
        $response->setHeader('X-Maho-Cache', 'MISS', true);
        $response->setHeader('X-Maho-Cache-Mode', $this->getMode(), true);
        $response->setHeader('X-Maho-Cache-Context', $context->getHash(), true);

        if ($debug) {
            $response->setHeader('X-Maho-Cache-Tags', $this->getTagHeader($tags), true);
            $response->setHeader('X-Maho-Cache-Version', $context->getUrlHash(), true);
        }

        $this->applyModeHeaders($context, $ttl, $tags, $debug, $urlVersion);
    }

    public function applyNotModified(
        Maho_PageCache_Model_Context $context,
        string $etag,
        int $lastModified,
        bool $debug,
    ): void {
        $response = $this->response();
        $response->setHttpResponseCode(304);
        $response->clearBody();
        $response->setHeader('Cache-Control', $this->getCacheControl(0), true);
        $response->setHeader('Vary', $this->getVary(), true);
        $response->setHeader('X-Maho-Cache', 'REVALIDATED', true);
        $response->setHeader('X-Maho-Cache-Mode', $this->getMode(), true);
        $response->setHeader('X-Maho-Cache-Context', $context->getHash(), true);
        $response->setHeader('ETag', $etag, true);

        if ($lastModified > 0) {
            $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT', true);
        }

        if ($debug) {
            $response->setHeader('X-Maho-Cache-Version', $context->getUrlHash(), true);
        }
    }

    /**
     * @param list<string> $tags
     */
    abstract protected function applyModeHeaders(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        ?int $urlVersion = null,
    ): void;

    /**
     * @param list<string> $tags
     */
    protected function getTagHeader(array $tags): string
    {
        $helper = Mage::helper('page_cache');
        $tags = array_values(array_unique(array_map(
            static fn(string $tag): string => $helper->sanitizeHeaderValue($tag),
            $tags,
        )));
        $tags = array_filter($tags, static fn(string $tag): bool => $tag !== '');
        return implode(',', Maho_PageCache_Model_TagBudget::fit(array_values($tags)));
    }

    protected function response(): Mage_Core_Controller_Response_Http
    {
        /** @var Mage_Core_Controller_Response_Http $response */
        $response = Mage::app()->getResponse();
        return $response;
    }
}
