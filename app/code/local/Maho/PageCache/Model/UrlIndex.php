<?php

/**
 * Persistent URL and tag index for proxy purge, warmup and static removal.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_UrlIndex
{
    private const int MAX_URL_VARIANTS = 200;

    private const array CACHE_TAGS = [
        'PAGE_CACHE',
        'PAGE_CACHE_HTML',
        'PAGE_CACHE_DYNAMIC',
    ];

    /**
     * @param list<string> $tags
     */
    public function add(Maho_PageCache_Model_Context $context, array $tags, int $version): void
    {
        $tags = $this->normalizeTags($tags);
        $urlHash = $context->getUrlHash();
        $url = $context->getPurgeUrl();
        $publicPath = $context->getPublicPath();
        $storeId = $context->getStoreId();
        $storeCode = $this->storeCode($storeId);
        $now = gmdate('Y-m-d H:i:s');

        $write = $this->write();
        try {
            $write->beginTransaction();
            $existing = $write->fetchRow(
                $write->select()
                    ->from($this->urlTable(), ['url_id', 'urls', 'variants_truncated', 'version'])
                    ->where('url_hash = ?', $urlHash),
            );

            $urls = $this->decodeUrls(is_array($existing) ? (string) ($existing['urls'] ?? '[]') : '[]');
            $urls[] = $url;
            $urls = $this->uniqueUrls($urls);
            $truncated = !empty($existing['variants_truncated'])
                || count($urls) > self::MAX_URL_VARIANTS;
            if (count($urls) > self::MAX_URL_VARIANTS) {
                $urls = array_slice($urls, -self::MAX_URL_VARIANTS);
            }

            $urlId = is_array($existing) ? (int) $existing['url_id'] : 0;
            $rowVersion = max(1, $version, is_array($existing) ? (int) ($existing['version'] ?? 1) : 1);
            if ($urlId > 0) {
                $write->update($this->urlTable(), [
                    'url' => $url,
                    'urls' => $this->encodeUrls($urls),
                    'variants_truncated' => $truncated ? 1 : 0,
                    'public_path' => $publicPath,
                    'store_id' => $storeId,
                    'store_code' => $storeCode,
                    'version' => $rowVersion,
                    'updated_at' => $now,
                ], ['url_id = ?' => $urlId]);
            } else {
                $write->insert($this->urlTable(), [
                    'url_hash' => $urlHash,
                    'url' => $url,
                    'urls' => $this->encodeUrls($urls),
                    'variants_truncated' => $truncated ? 1 : 0,
                    'public_path' => $publicPath,
                    'store_id' => $storeId,
                    'store_code' => $storeCode,
                    'version' => $rowVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $urlId = (int) $write->lastInsertId($this->urlTable(), 'url_id');
            }

            $write->delete($this->tagTable(), ['url_id = ?' => $urlId]);
            if ($tags !== []) {
                $write->insertMultiple($this->tagTable(), array_map(
                    static fn(string $tag): array => ['url_id' => $urlId, 'tag' => $tag],
                    $tags,
                ));
            }

            $write->commit();
        } catch (\Throwable $e) {
            try {
                $write->rollBack();
            } catch (\Throwable) {
            }
            Mage::logException($e);
        }
    }

    /**
     * @return list<array<string, mixed>>|null null on a database failure
     */
    public function getByTag(string $tag): ?array
    {
        try {
            $read = $this->read();
            $rows = $read->fetchAll(
                $read->select()
                    ->from(['u' => $this->urlTable()], [
                        'url_id',
                        'url_hash',
                        'url',
                        'urls',
                        'variants_truncated',
                        'public_path',
                        'store_code',
                        'store_id',
                        'version',
                    ])
                    ->join(['t' => $this->tagTable()], 't.url_id = u.url_id', [])
                    ->where('t.tag = ?', substr($tag, 0, 128))
                    ->order('u.url_id ASC'),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return null;
        }

        $result = [];
        foreach ($rows as $row) {
            $urls = $this->decodeUrls((string) ($row['urls'] ?? '[]'));
            if ($urls === []) {
                $urls = [(string) ($row['url'] ?? '')];
            }
            $row['urls'] = $urls;
            $result[] = $row;
        }

        return $result;
    }

    public function removeTag(string $tag): void
    {
        try {
            $write = $this->write();
            $write->delete($this->tagTable(), ['tag = ?' => substr($tag, 0, 128)]);

            $read = $this->read();
            $ids = array_map(intval(...), $read->fetchCol(
                $read->select()
                    ->from(['u' => $this->urlTable()], ['u.url_id'])
                    ->joinLeft(['t' => $this->tagTable()], 't.url_id = u.url_id', [])
                    ->where('t.url_id IS NULL'),
            ));
            if ($ids !== []) {
                $write->delete($this->urlTable(), $write->quoteInto('url_id IN (?)', $ids));
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function setVersion(int $urlId, int $version): void
    {
        try {
            $this->write()->update($this->urlTable(), [
                'version' => $version,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['url_id = ?' => $urlId]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function clear(): void
    {
        $write = $this->write();
        try {
            $write->beginTransaction();
            $write->delete($this->tagTable(), '1=1');
            $write->delete($this->urlTable(), '1=1');
            $write->commit();
        } catch (\Throwable $e) {
            try {
                $write->rollBack();
            } catch (\Throwable) {
            }
            Mage::logException($e);
        }
    }

    public function cleanup(int $maxAge): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $maxAge));
        try {
            $read = $this->read();
            $ids = array_map(intval(...), $read->fetchCol(
                $read->select()
                    ->from($this->urlTable(), ['url_id'])
                    ->where('updated_at < ?', $cutoff),
            ));
            if ($ids === []) {
                return 0;
            }

            $write = $this->write();
            $write->beginTransaction();
            $write->delete($this->tagTable(), $write->quoteInto('url_id IN (?)', $ids));
            $write->delete($this->urlTable(), $write->quoteInto('url_id IN (?)', $ids));
            $write->commit();

            return count($ids);
        } catch (\Throwable $e) {
            try {
                if (isset($write)) {
                    $write->rollBack();
                }
            } catch (\Throwable) {
            }
            Mage::logException($e);
            return 0;
        }
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '' || in_array($tag, self::CACHE_TAGS, true)) {
                continue;
            }
            $result[substr($tag, 0, 128)] = true;
        }

        return array_keys($result);
    }

    /**
     * @return list<string>
     */
    private function decodeUrls(string $json): array
    {
        try {
            $urls = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($urls)) {
            return [];
        }

        return array_values(array_filter(
            $urls,
            static fn(mixed $url): bool => is_string($url) && $url !== '',
        ));
    }

    /**
     * @param list<string> $urls
     */
    private function encodeUrls(array $urls): string
    {
        $encoded = json_encode(
            array_values($urls),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function uniqueUrls(array $urls): array
    {
        return array_values(array_unique($urls));
    }

    private function storeCode(int $storeId): string
    {
        try {
            $code = (string) Mage::app()->getStore($storeId)->getCode();
        } catch (\Throwable) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $code) === 1 ? $code : '';
    }

    private function urlTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/url');
    }

    private function tagTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/url_tag');
    }

    private function read(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function write(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }
}
