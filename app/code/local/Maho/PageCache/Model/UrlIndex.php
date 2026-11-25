<?php

/**
 * Persistent URL, context and tag index for proxy purge, warmup and static removal.
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
        $contextHash = $context->getHash();
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

            $urlVersion = max(1, $version, is_array($existing) ? (int) ($existing['version'] ?? 1) : 1);
            $write->insertOnDuplicate($this->urlTable(), [
                'url_hash' => $urlHash,
                'url' => $url,
                'urls' => $this->encodeUrls($urls),
                'variants_truncated' => $truncated ? 1 : 0,
                'public_path' => $publicPath,
                'store_id' => $storeId,
                'store_code' => $storeCode,
                'version' => $urlVersion,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['url', 'urls', 'variants_truncated', 'public_path', 'store_id', 'store_code', 'version', 'updated_at']);

            $urlRow = $write->fetchRow(
                $write->select()
                    ->from($this->urlTable(), ['url_id'])
                    ->where('url_hash = ?', $urlHash),
            );
            $urlId = is_array($urlRow) ? (int) ($urlRow['url_id'] ?? 0) : 0;
            if ($urlId <= 0) {
                throw new \RuntimeException('Could not resolve the page cache URL index row.');
            }

            $write->insertOnDuplicate($this->contextTable(), [
                'url_id' => $urlId,
                'context_hash' => $contextHash,
                'store_id' => $storeId,
                'store_code' => $storeCode,
                'public_path' => $publicPath,
                'url' => $url,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['store_id', 'store_code', 'public_path', 'url', 'updated_at']);

            $contextRow = $write->fetchRow(
                $write->select()
                    ->from($this->contextTable(), ['context_id'])
                    ->where('url_id = ?', $urlId)
                    ->where('context_hash = ?', $contextHash),
            );
            $contextId = is_array($contextRow) ? (int) ($contextRow['context_id'] ?? 0) : 0;
            if ($contextId <= 0) {
                throw new \RuntimeException('Could not resolve the page cache URL context row.');
            }

            if ($tags !== []) {
                $write->insertOnDuplicate($this->contextTagTable(), array_map(
                    static fn(string $tag): array => ['context_id' => $contextId, 'tag' => $tag],
                    $tags,
                ), ['tag']);
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
        $tag = substr($tag, 0, 128);
        try {
            $read = $this->read();
            $contextUrlIds = array_values(array_unique(array_map(intval(...), $read->fetchCol(
                $read->select()
                    ->from(['t' => $this->contextTagTable()], [])
                    ->join(['c' => $this->contextTable()], 'c.context_id = t.context_id', ['c.url_id'])
                    ->where('t.tag = ?', $tag),
            ))));
            $contextRows = [];
            if ($contextUrlIds !== []) {
                $contextRows = $read->fetchAll(
                    $read->select()
                        ->from(['c' => $this->contextTable()], [
                            'context_id',
                            'context_hash',
                            'store_id',
                            'store_code',
                            'public_path',
                            'context_url' => 'url',
                        ])
                        ->join(['u' => $this->urlTable()], 'u.url_id = c.url_id', [
                            'url_id',
                            'url_hash',
                            'url',
                            'urls',
                            'variants_truncated',
                            'version',
                        ])
                        ->where('c.url_id IN (?)', $contextUrlIds)
                        ->order('u.url_id ASC')
                        ->order('c.context_id ASC'),
                );
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
            return null;
        }

        $result = [];
        foreach ($contextRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $urls = $this->decodeUrls((string) ($row['urls'] ?? '[]'));
            if ($urls === []) {
                $urls = [(string) ($row['url'] ?? '')];
            }
            $row['urls'] = $urls;
            $row['context_id'] = (int) ($row['context_id'] ?? 0);
            $row['context_hash'] = (string) ($row['context_hash'] ?? '');
            $row['context_url'] = (string) ($row['context_url'] ?? $row['url'] ?? '');
            $result[] = $row;
        }

        return $result;
    }

    public function removeTag(string $tag): void
    {
        $tag = substr($tag, 0, 128);
        try {
            $write = $this->write();
            $write->delete($this->contextTagTable(), ['tag = ?' => $tag]);

            $orphanContexts = array_map(intval(...), $this->read()->fetchCol(
                $this->read()->select()
                    ->from(['c' => $this->contextTable()], ['c.context_id'])
                    ->joinLeft(['t' => $this->contextTagTable()], 't.context_id = c.context_id', [])
                    ->where('t.context_id IS NULL'),
            ));
            if ($orphanContexts !== []) {
                $write->delete($this->contextTable(), $write->quoteInto('context_id IN (?)', $orphanContexts));
            }

            $orphanUrls = array_map(intval(...), $this->read()->fetchCol(
                $this->read()->select()
                    ->from(['u' => $this->urlTable()], ['u.url_id'])
                    ->joinLeft(['c' => $this->contextTable()], 'c.url_id = u.url_id', [])
                    ->where('c.context_id IS NULL'),
            ));
            if ($orphanUrls !== []) {
                $write->delete($this->urlTable(), $write->quoteInto('url_id IN (?)', $orphanUrls));
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function countUrls(): int
    {
        try {
            $read = $this->read();
            return (int) $read->fetchOne(
                $read->select()->from($this->urlTable(), ['total' => 'COUNT(*)']),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return 0;
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
            $write->delete($this->contextTagTable(), '1=1');
            $write->delete($this->contextTable(), '1=1');
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
            $contextIds = array_map(intval(...), $read->fetchCol(
                $read->select()
                    ->from($this->contextTable(), ['context_id'])
                    ->where('url_id IN (?)', $ids),
            ));
            if ($contextIds !== []) {
                $write->delete($this->contextTagTable(), $write->quoteInto('context_id IN (?)', $contextIds));
                $write->delete($this->contextTable(), $write->quoteInto('context_id IN (?)', $contextIds));
            }
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

    private function contextTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/url_context');
    }

    private function contextTagTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/url_context_tag');
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
