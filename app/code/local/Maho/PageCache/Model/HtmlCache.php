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
        $meta = $this->cache()->load($this->key()->getMetaKey($urlHash));
        $metaVersion = is_array($meta) && isset($meta['version']) ? (int) $meta['version'] : 1;
        $version = Mage::getSingleton('page_cache/version');

        return [
            'global' => $version->getGlobal(),
            'url' => max($metaVersion, $version->getUrl($urlHash)),
        ];
    }

    /**
     * @return array{global: int, url: int}
     */
    public function getCurrentVersions(Maho_PageCache_Model_Context $context): array
    {
        $version = Mage::getSingleton('page_cache/version');
        return [
            'global' => $version->getGlobal(),
            'url' => $version->getUrl($context->getUrlHash()),
        ];
    }

    /**
     * @param array{global: int, url: int}|null $versions
     */
    public function getLockName(Maho_PageCache_Model_Context $context, ?array $versions = null): string
    {
        return $this->key()->getLockName($context);
    }

    /**
     * @param array{global: int, url: int}|null $versions
     * @return array{entry: array<string, mixed>|null, versions: array{global: int, url: int}}
     */
    public function loadEnvelope(
        Maho_PageCache_Model_Context $context,
        ?array $versions = null,
    ): array {
        $versions ??= $this->getCurrentVersions($context);
        $stored = $this->cache()->load($this->key()->getEnvelopeKey($context));
        if (is_array($stored)) {
            $entry = Maho_PageCache_Model_CacheEnvelope::decode($stored);
            if ($entry === null
                || !Maho_PageCache_Model_CacheEnvelope::isValid($entry, $context, $versions)
            ) {
                return ['entry' => null, 'versions' => $versions];
            }
            return ['entry' => $entry, 'versions' => $versions];
        }

        return ['entry' => $this->loadLegacy($context), 'versions' => $versions];
    }

    /**
     * @param array{global: int, url: int}|null $versions
     * @return array<string, mixed>|null
     */
    public function load(Maho_PageCache_Model_Context $context, ?array $versions = null): ?array
    {
        return $this->loadEnvelope($context, $versions)['entry'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLegacy(Maho_PageCache_Model_Context $context): ?array
    {
        $key = $this->key();
        $meta = $this->cache()->load($key->getMetaKey($context->getUrlHash()));
        if (!is_array($meta) || !isset($meta['version'])) {
            return null;
        }

        $version = Mage::getSingleton('page_cache/version');
        $versions = [
            'global' => $version->getGlobal(),
            'url' => max((int) $meta['version'], $version->getUrl($context->getUrlHash())),
        ];
        $entry = $this->cache()->load($key->getHtmlKey($this->getHash($context, $versions)));
        if (!is_array($entry)) {
            return null;
        }

        $decoded = Maho_PageCache_Model_CacheEnvelope::decode($entry);
        if ($decoded === null || (string) ($decoded['mode'] ?? '') !== Maho_PageCache_Helper_Data::MODE_LOCAL) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param list<string> $tags
     * @param array<string, string> $headers
     * @param array{global: int, url: int} $versions
     * @param array<string, array<string, mixed>> $dynamicBlocks
     * @param array<string, array<array-key, int|string>> $proxyEntities
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
        array $proxyEntities = [],
    ): ?array {
        if ($this->isTooLarge($html)) {
            return null;
        }

        $ttl = max(1, $ttl);
        $storedHtml = $html;
        $htmlEncoding = 'plain';
        $helper = $this->helper();
        if ($helper->getCompressionEnabled()
            && function_exists('gzencode')
            && strlen($html) >= $helper->getCompressionMinBytes()
        ) {
            $compressed = $this->compress($html);
            if ($compressed !== false && strlen($compressed) < strlen($html)) {
                $storedHtml = $compressed;
                $htmlEncoding = 'gzip';
            }
        }

        $entry = [
            'schema_version' => Maho_PageCache_Model_CacheEnvelope::SCHEMA_VERSION,
            'html' => $storedHtml,
            'html_encoding' => $htmlEncoding,
            'headers' => $headers,
            'tags' => array_values(array_unique($tags)),
            'url' => $context->getPurgeUrl(),
            'url_hash' => $context->getUrlHash(),
            'context' => $context->getHash(),
            'context_hash' => $context->getHash(),
            'versions' => [
                'global' => (int) $versions['global'],
                'url' => (int) $versions['url'],
            ],
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
            'proxy_entities' => $this->sanitizeProxyEntities($proxyEntities),
        ];

        $key = $this->key();
        $urlHash = $context->getUrlHash();
        $metaSaved = $this->cache()->save(
            [
                'url' => $context->getPurgeUrl(),
                'tags' => $entry['tags'],
                'created_at' => $entry['created_at'],
                'mode' => $context->getMode(),
                'ttl' => $ttl,
                'version' => $versions['url'],
                'store_id' => $context->getStoreId(),
            ],
            $key->getMetaKey($urlHash),
            array_merge(['PAGE_CACHE'], $key->getCacheTags($tags)),
            $ttl + 3600,
        );
        if (!$metaSaved) {
            $this->cache()->remove($key->getEnvelopeKey($context));
            return null;
        }

        $saved = $this->cache()->save(
            $entry,
            $key->getEnvelopeKey($context),
            array_merge(['PAGE_CACHE', 'PAGE_CACHE_HTML'], $key->getCacheTags($tags)),
            $ttl,
        );
        if (!$saved) {
            return null;
        }

        Mage::getSingleton('page_cache/urlIndex')->add($context, $entry['tags'], $versions['url']);
        $this->addIndex($urlHash, $context->getHash(), $ttl + 3600);
        $entry['html'] = $html;
        return $entry;
    }

    /**
     * @param array{global: int, url: int} $versions
     */
    public function remove(Maho_PageCache_Model_Context $context, array $versions): void
    {
        $this->cache()->remove($this->key()->getEnvelopeKey($context));
        $this->cache()->remove($this->key()->getHtmlKey($this->getHash($context, $versions)));
        Mage::getSingleton('page_cache/staticCache')->removeByUrl(
            $context->getPurgeUrl(),
            $this->getStoreCode($context),
        );
    }

    protected function compress(string $html): string|false
    {
        return gzencode($html, $this->helper()->getCompressionLevel());
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
        $contexts = $this->cache()->load($this->key()->getHtmlIndexKey($urlHash));
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
            $this->cache()->save(
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

    /**
     * @param array<string, array<array-key, int|string>> $entities
     * @return array<string, list<int>>
     */
    private function sanitizeProxyEntities(array $entities): array
    {
        $allowed = ['product', 'category', 'cms_page', 'cms_block'];
        $result = [];
        $count = 0;
        foreach ($allowed as $type) {
            $ids = $entities[$type] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    continue;
                }
                $id = (int) $id;
                if ($id <= 0 || isset($result[$type][$id])) {
                    continue;
                }
                $result[$type][$id] = $id;
                $count++;
                if ($count >= 200) {
                    break 2;
                }
            }
        }

        return array_map(array_values(...), $result);
    }

    protected function cache(): Mage_Core_Model_Cache
    {
        return Mage::app()->getCache();
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
