<?php

/**
 * Reverse proxy and CDN page cache backend.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Backend_Proxy extends Maho_PageCache_Model_Backend_Abstract
{
    #[\Override]
    public function getMode(): string
    {
        return Maho_PageCache_Helper_Data::MODE_PROXY;
    }

    #[\Override]
    public function getCacheControl(int $ttl): string
    {
        return 'public, s-maxage=' . max(1, $ttl) . ', max-age=0';
    }

    #[\Override]
    protected function applyModeHeaders(
        Maho_PageCache_Model_Context $context,
        int $ttl,
        array $tags,
        bool $debug,
        ?int $urlVersion = null,
    ): void {
        $this->response()->setHeader('X-Cache-Tags', $this->getTagHeader($tags), true);
    }
}
