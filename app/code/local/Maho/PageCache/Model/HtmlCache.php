<?php

/**
 * Local page cache storage for full page HTML.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_HtmlCache
{
    /**
     * @param array{global: int, url: int}|null $versions
     */
    public function getHash(Maho_PageCache_Model_Context $context, ?array $versions = null): string
    {
        $versions ??= $this->getVersions($context);
        return hash(
            'sha256',
            $context->getUrlHash()
            . '|' . $context->getHash()
            . '|' . $versions['url']
            . '|' . $versions['global']
            . '|' . $context->getMode(),
        );
    }

    /**
     * @return array{global: int, url: int}
     */
    public function getVersions(Maho_PageCache_Model_Context $context): array
    {
        $urlHash = $context->getUrlHash();
        $meta = Mage::app()->getCache()->load($this->key()->getMetaKey($urlHash));
        $metaVersion = is_array($meta) && isset($meta['version']) ? (int) $meta['version'] : 1;
        $version = Mage::getSingleton('page_cache/version');

        return [
            'global' => $version->getGlobal(),
            'url' => max($metaVersion, $version->getUrl($urlHash)),
        ];
    }

    /**
     * @param array{global: int, url: int}|null $versions
     */
    public function getLockName(Maho_PageCache_Model_Context $context, ?array $versions = null): string
    {
        return 'page_cache_html_' . $this->getHash($context, $versions);
    }

    /**
     * @param array{global: int, url: int}|null $versions
     * @return array<string, mixed>|null
     */
    public function load(Maho_PageCache_Model_Context $context, ?array $versions = null): ?array
    {
        $key = $this->key();
        $meta = Mage::app()->getCache()->load($key->getMetaKey($context->getUrlHash()));
        if (!is_array($meta) || !isset($meta['version'])) {
            return null;
        }

        $entry = Mage::app()->getCache()->load(
            $key->getHtmlKey($this->getHash($context, $versions)),
        );
        if (!is_array($entry)
            || !isset($entry['html'])
            || !is_string($entry['html'])
            || $entry['html'] === ''
            || ($entry['mode'] ?? '') !== Maho_PageCache_Helper_Data::MODE_LOCAL
        ) {
            return null;
        }
        return $entry;
    }

    /**
     * @param list<string> $tags
     * @param array<string, string> $headers
     * @param array{global: int, url: int} $versions
     * @return array<string, mixed>|null
     */
    public function save(
        Maho_PageCache_Model_Context $context,
        string $html,
        array $tags,
        int $ttl,
        array $headers,
        array $versions,
        array $dynamicBlocks = [],
    ): ?array {
        if ($this->isTooLarge($html)) {
            return null;
        }

        $ttl = max(1, $ttl);
        $entry = [
            'html' => $html,
            'headers' => $headers,
            'tags' => array_values(array_unique($tags)),
            'url' => $context->getPurgeUrl(),
            'context' => $context->getHash(),
            'version' => $versions['url'],
            'global_version' => $versions['global'],
            'mode' => $context->getMode(),
            'ttl' => $ttl,
            'created_at' => time(),
            'etag' => Mage::getSingleton('page_cache/version')->getEtag(
                $context->getUrlHash(),
                $context->getHash(),
                $ttl,
                Maho_PageCache_Helper_Data::MODE_LOCAL,
                $versions['url'],
            ),
            'last_modified' => time(),
            'dynamic_blocks' => $dynamicBlocks,
        ];

        $key = $this->key();
        $urlHash = $context->getUrlHash();
        $metaSaved = Mage::app()->getCache()->save(
            [
                'url' => $context->getPurgeUrl(),
                'tags' => $entry['tags'],
                'created_at' => $entry['created_at'],
                'mode' => $context->getMode(),
                'ttl' => $ttl,
                'version' => $versions['url'],
                'store_code' => $this->getStoreCode($context),
                'store_id' => $context->getStoreId(),
            ],
            $key->getMetaKey($urlHash),
            ['PAGE_CACHE'],
            $ttl + 3600,
        );
        if (!$metaSaved) {
            return null;
        }

        Mage::getSingleton('page_cache/tagIndex')->add($urlHash, $entry['tags']);

        $saved = Mage::app()->getCache()->save(
            $entry,
            $key->getHtmlKey($this->getHash($context, $versions)),
            ['PAGE_CACHE', 'PAGE_CACHE_HTML'],
            $ttl,
        );
        if (!$saved) {
            return null;
        }

        $this->addIndex($urlHash, $context->getHash(), $ttl + 3600);
        return $entry;
    }

    /**
     * @param array{global: int, url: int} $versions
     */
    public function remove(Maho_PageCache_Model_Context $context, array $versions): void
    {
        Mage::app()->getCache()->remove($this->key()->getHtmlKey($this->getHash($context, $versions)));
        Mage::getSingleton('page_cache/staticCache')->removeByUrl(
            $context->getPurgeUrl(),
            $this->getStoreCode($context),
        );
    }

    public function isTooLarge(string $html): bool
    {
        return strlen($html) > $this->helper()->getHtmlMaxSize();
    }

    /**
     * @return array<string, string>
     */
    public function collectHeaders(Mage_Core_Controller_Response_Http $response): array
    {
        $allowed = ['content-type', 'content-language', 'content-disposition'];
        $headers = [];
        foreach ($response->getHeaders() as $header) {
            $name = strtolower((string) $header['name']);
            if (in_array($name, $allowed, true) && is_string($header['value'])) {
                $headers[(string) $header['name']] = $header['value'];
            }
        }
        return $headers;
    }

    /**
     * @return list<string>
     */
    public function getContexts(string $urlHash): array
    {
        $contexts = Mage::app()->getCache()->load($this->key()->getHtmlIndexKey($urlHash));
        if (!is_array($contexts)) {
            return [];
        }
        return array_values(array_filter(
            $contexts,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    private function addIndex(string $urlHash, string $contextHash, int $ttl): void
    {
        $contexts = $this->getContexts($urlHash);
        if (!in_array($contextHash, $contexts, true)) {
            $contexts[] = $contextHash;
            Mage::app()->getCache()->save(
                array_values($contexts),
                $this->key()->getHtmlIndexKey($urlHash),
                ['PAGE_CACHE'],
                $ttl,
            );
        }
    }

    private function getStoreCode(Maho_PageCache_Model_Context $context): string
    {
        try {
            $code = (string) Mage::app()->getStore($context->getStoreId())->getCode();
        } catch (\Throwable) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $code) === 1 ? $code : '';
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }

    private function helper(): Maho_PageCache_Helper_Data
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');
        return $helper;
    }
}
