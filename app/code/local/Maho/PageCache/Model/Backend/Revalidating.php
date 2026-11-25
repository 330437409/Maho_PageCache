<?php

/**
 * Revalidating page cache backend using ETag and Last-Modified.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Backend_Revalidating extends Maho_PageCache_Model_Backend_Abstract
{
    #[\Override]
    public function getMode(): string
    {
        return Maho_PageCache_Helper_Data::MODE_REVALIDATING;
    }

    #[\Override]
    public function getCacheControl(int $ttl): string
    {
        return 'public, max-age=0, must-revalidate';
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
        $urlHash = $context->getUrlHash();
        $etag = $version->getEtag($urlHash, $context->getHash(), $ttl, $this->getMode(), $urlVersion);
        $lastModified = time();
        $version->storeEtag($urlHash, $etag, $lastModified, $tags, $ttl);

        $response = $this->response();
        $response->setHeader('ETag', $etag, true);
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT', true);
    }
}
