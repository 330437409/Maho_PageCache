<?php

/**
 * Cache warmup from sitemaps and explicit URL lists.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Maho_PageCache_Service_Warm
{
    public const string WARMUP_LOCK = 'page_cache_warmup_queue';

    private const int ACTIVE_ID_CHUNK = 500;

    /**
     * @return list<string>
     */
    public function parseSitemap(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                return [];
            }
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//*[local-name()="loc"]');
            if ($nodes === false) {
                return [];
            }

            $urls = [];
            foreach ($nodes as $node) {
                $url = trim($node->textContent);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
            return array_values(array_unique($urls));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param list<string> $explicitUrls
     * @return list<string>
     */
    public function collectUrls(
        array $explicitUrls,
        bool $includeSitemap,
        int $limit,
        bool $includeActive = false,
        int $storeId = 0,
    ): array {
        $urls = $explicitUrls;
        if ($includeSitemap) {
            $urls = array_merge($urls, $this->getSitemapUrls());
        }
        if ($includeActive) {
            $urls = array_merge(
                $urls,
                $this->collectActiveCatalogUrls(
                    $storeId > 0 ? $storeId : (int) Mage::app()->getStore()->getId(),
                    max(1, $limit),
                ),
            );
        }

        $policy = $this->urlPolicy();
        $urls = array_values(array_filter(
            $urls,
            static fn(mixed $url): bool => is_string($url)
                && preg_match('#^https?://#i', $url) === 1
                && $policy->isAllowed($url),
        ));

        return array_slice(array_values(array_unique($urls)), 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    public function collectActiveCatalogUrls(int $storeId, int $limit): array
    {
        if ($storeId <= 0) {
            return [];
        }

        try {
            $store = $storeId === (int) Mage::app()->getStore()->getId()
                ? Mage::app()->getStore()
                : Mage::app()->getStore($storeId);
            $base = $this->getStoreBaseUrl($store);
            if ($base === '') {
                return [];
            }

            $limit = max(1, $limit);
            $urls = [];
            $productCollection = Mage::getResourceModel('catalog/product_collection')
                ->setStoreId($storeId)
                ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
                ->addAttributeToFilter('visibility', ['in' => [2, 3, 4]]);
            $this->collectActiveRewriteUrls(
                $productCollection,
                'product_id',
                'catalog/product/view/',
                $storeId,
                $base,
                $limit,
                $urls,
            );

            if (count($urls) < $limit) {
                $categoryCollection = Mage::getResourceModel('catalog/category_collection')
                    ->setStoreId($storeId)
                    ->addAttributeToFilter('is_active', 1);
                $this->collectActiveRewriteUrls(
                    $categoryCollection,
                    'category_id',
                    'catalog/category/view/',
                    $storeId,
                    $base,
                    $limit,
                    $urls,
                );
            }

            $urls = array_keys($urls);
            sort($urls, SORT_STRING);
            return array_slice($urls, 0, $limit);
        } catch (\Throwable $e) {
            Mage::logException($e);
            throw $e;
        }
    }

    /**
     * @param array<string, true> $urls
     */
    private function collectActiveRewriteUrls(
        Mage_Catalog_Model_Resource_Collection_Abstract $collection,
        string $column,
        string $targetPrefix,
        int $storeId,
        string $base,
        int $limit,
        array &$urls,
    ): void {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('core/url_rewrite');
        $offset = 0;

        do {
            $ids = $collection->getAllIds(self::ACTIVE_ID_CHUNK, $offset);
            $ids = array_values(array_filter(
                array_map(intval(...), is_array($ids) ? $ids : []),
                static fn(int $id): bool => $id > 0,
            ));
            if ($ids === []) {
                return;
            }

            $select = $read->select()
                ->from(['u' => $table], ['request_path'])
                ->where('u.store_id = ?', $storeId)
                ->where('u.is_system = ?', 1)
                ->where('u.options IS NULL OR u.options = ?', '')
                ->where('u.request_path IS NOT NULL')
                ->where('u.request_path <> ?', '')
                ->where('u.' . $column . ' IN (?)', $ids)
                ->where('u.target_path LIKE ?', $targetPrefix . '%');

            foreach ($read->fetchCol($select) as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $path = '/' . ltrim($path, '/');
                if (!str_ends_with($path, '/') && pathinfo($path, PATHINFO_EXTENSION) === '') {
                    $path .= '/';
                }
                $url = $base . $path;
                if ($this->urlPolicy()->isAllowed($url)) {
                    $urls[$url] = true;
                }
                if (count($urls) >= $limit) {
                    return;
                }
            }

            $offset += count($ids);
        } while (count($ids) === self::ACTIVE_ID_CHUNK);
    }

    /**
     * @return list<string>
     */
    public function getSitemapUrls(): array
    {
        $collection = Mage::getResourceModel('sitemap/sitemap_collection');
        $client = null;
        $urls = [];

        foreach ($collection as $sitemap) {
            $path = $sitemap->getStoragePath();
            if ($path === null) {
                continue;
            }

            try {
                $xml = $sitemap->getMount()->withLocalFile(
                    $path,
                    static fn(string $file): string => (string) file_get_contents($file),
                );
            } catch (\Throwable $e) {
                Mage::logException($e);
                continue;
            }

            $sitemapUrls = [];
            foreach ($this->parseSitemap($xml) as $location) {
                if (!$this->urlPolicy()->isAllowed($location)) {
                    continue;
                }
                if (!$this->isSitemapUrl($location)) {
                    $sitemapUrls[] = $location;
                    continue;
                }

                $client ??= $this->createClient();
                try {
                    $childXml = $client->request('GET', $location, [
                        'headers' => ['Accept' => 'application/xml,text/xml,*/*'],
                    ])->getContent();
                } catch (\Throwable $e) {
                    Mage::logException($e);
                    continue;
                }
                $sitemapUrls = array_merge($sitemapUrls, $this->parseSitemap($childXml));
            }

            $sitemapUrls = array_filter(
                $sitemapUrls,
                fn(string $url): bool => $this->urlPolicy()->isAllowed($url),
            );

            $urls = array_merge($urls, $sitemapUrls);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param list<string> $urls
     * @return array{warmed: list<string>, skipped: array<string, string>, failed: array<string, string>}
     */
    public function warm(array $urls, int $concurrency, int $delayMs = 0): array
    {
        $concurrency = max(1, $concurrency);
        $delayMs = max(0, $delayMs);
        $result = ['warmed' => [], 'skipped' => [], 'failed' => []];

        $queue = [];
        foreach ($urls as $url) {
            if (!$this->urlPolicy()->isAllowed($url)) {
                $result['skipped'][$url] = 'disallowed_host';
                continue;
            }
            $queue[] = $url;
        }

        if ($queue !== []) {
            $headers = $this->requestHeaders();
            $result = $this->useCurlMulti()
                ? $this->warmCurl($queue, $concurrency, $delayMs, $headers)
                : $this->warmChunks($queue, $concurrency, $delayMs, $headers);
        }

        $this->recordWarmupStat($result);

        return $result;
    }

    protected function useCurlMulti(): bool
    {
        if (!function_exists('curl_multi_init')) {
            return false;
        }

        return new \ReflectionMethod($this, 'createClient')->getDeclaringClass()->getName() === self::class;
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml',
            'User-Agent' => 'Maho-PageCache-Warm/1.0',
        ];
        $auth = Mage::helper('page_cache')->getWarmupAuth();
        if ($auth !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($auth['user'] . ':' . $auth['password']);
        }

        return $headers;
    }

    /**
     * @param list<string> $urls
     * @param array<string, string> $headers
     * @return array{warmed: list<string>, skipped: array<string, string>, failed: array<string, string>}
     */
    private function warmCurl(array $urls, int $concurrency, int $delayMs, array $headers): array
    {
        $result = ['warmed' => [], 'skipped' => [], 'failed' => []];
        $queue = array_values($urls);
        $total = count($queue);
        $next = 0;
        $lastStart = 0.0;
        $multi = curl_multi_init();
        $active = [];
        $cacheHeaders = [];

        $startNext = function () use (
            &$next,
            &$lastStart,
            &$active,
            &$cacheHeaders,
            $queue,
            $total,
            $concurrency,
            $delayMs,
            $headers,
            $multi,
            &$result,
        ): bool {
            if ($next >= $total || count($active) >= $concurrency) {
                return false;
            }

            $url = $queue[$next++];
            if ($delayMs > 0 && $lastStart > 0.0) {
                $wait = $delayMs / 1000 - (microtime(true) - $lastStart);
                if ($wait > 0) {
                    usleep((int) round($wait * 1_000_000));
                }
            }
            $lastStart = microtime(true);

            $handle = curl_init($url);
            if ($handle === false) {
                $result['failed'][$url] = 'could not create curl handle';
                return true;
            }

            $id = spl_object_id($handle);
            $cacheHeaders[$id] = '';
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$cacheHeaders, $id): int {
                    if (stripos($line, 'X-Maho-Cache:') === 0) {
                        $cacheHeaders[$id] = trim(substr($line, 14));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static fn($handle, string $data): int => strlen($data),
            ]);

            curl_multi_add_handle($multi, $handle);
            $active[$id] = ['handle' => $handle, 'url' => $url];

            return true;
        };

        try {
            while ($next < $total || $active !== []) {
                while ($next < $total && count($active) < $concurrency) {
                    if (!$startNext()) {
                        break;
                    }
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    foreach ($active as $item) {
                        $result['failed'][$item['url']] = 'curl multi error ' . $status;
                        curl_multi_remove_handle($multi, $item['handle']);
                    }
                    $active = [];
                    break;
                }

                if ($active !== []) {
                    if (curl_multi_select($multi, 1.0) === -1) {
                        usleep(1000);
                    }
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);
                    $url = (string) ($active[$id]['url'] ?? '');
                    unset($active[$id]);

                    $error = curl_error($handle);
                    $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    if ($error !== '') {
                        $result['failed'][$url] = $error;
                    } else {
                        $this->classifyResponse($url, $httpCode, $cacheHeaders[$id] ?? '', $result);
                    }

                    curl_multi_remove_handle($multi, $handle);
                    unset($cacheHeaders[$id]);
                }
            }
        } finally {
            foreach ($active as $item) {
                curl_multi_remove_handle($multi, $item['handle']);
            }
            curl_multi_close($multi);
        }

        return $result;
    }

    /**
     * Symfony HttpClient fallback for tests and environments without ext-curl.
     *
     * @param list<string> $urls
     * @param array<string, string> $headers
     * @return array{warmed: list<string>, skipped: array<string, string>, failed: array<string, string>}
     */
    private function warmChunks(array $urls, int $concurrency, int $delayMs, array $headers): array
    {
        $client = $this->createClient();
        $result = ['warmed' => [], 'skipped' => [], 'failed' => []];
        $chunks = array_chunk($urls, $concurrency);

        foreach ($chunks as $index => $chunk) {
            $responses = [];
            foreach ($chunk as $url) {
                try {
                    $responses[$url] = $client->request('GET', $url, ['headers' => $headers]);
                } catch (\Throwable $e) {
                    $result['failed'][$url] = $e->getMessage();
                }
            }

            foreach ($responses as $url => $response) {
                try {
                    $status = $response->getStatusCode();
                    $responseHeaders = $response->getHeaders(false);
                    $response->getContent(false);
                    $cache = strtoupper((string) ($responseHeaders['x-maho-cache'][0] ?? ''));
                    $this->classifyResponse($url, $status, $cache, $result);
                } catch (\Throwable $e) {
                    $result['failed'][$url] = $e->getMessage();
                }
            }

            if ($delayMs > 0 && $index < count($chunks) - 1) {
                usleep($delayMs * 1000);
            }
        }

        return $result;
    }

    /**
     * @param array{warmed: list<string>, skipped: array<string, string>, failed: array<string, string>} $result
     */
    private function classifyResponse(string $url, int $status, string $cache, array &$result): void
    {
        $cache = strtoupper($cache);
        if ($status === 200 && $cache !== 'BYPASS') {
            $result['warmed'][] = $url;
        } elseif ($status === 200) {
            $result['skipped'][$url] = 'bypass';
        } elseif ($status >= 300 && $status < 400) {
            $result['skipped'][$url] = 'redirect';
        } elseif ($status === 404 || $status === 410) {
            $result['skipped'][$url] = 'not_found';
        } elseif ($status === 401 || $status === 403) {
            $result['skipped'][$url] = 'forbidden';
        } elseif ($status === 429 || $status >= 500) {
            $result['failed'][$url] = 'HTTP ' . $status;
        } else {
            $result['skipped'][$url] = 'http_' . $status;
        }
    }

    /**
     * @return array{at: int, processed: int, warmed: int, skipped: int, failed: int, dropped: int, remaining: int}
     */
    public function consumeQueue(int $limit = 0): array
    {
        $summary = $this->emptySummary();
        $helper = Mage::helper('page_cache');
        $queue = Mage::getSingleton('page_cache/warmQueue');
        if (!$helper->getWarmupEnabled()) {
            $summary['remaining'] = $queue->count();
            return $summary;
        }

        $lock = Mage::getSingleton('core/lock');
        if (!$lock->acquire(self::WARMUP_LOCK)) {
            $summary['remaining'] = $queue->count();
            return $summary;
        }

        try {
            $batchSize = $limit > 0 ? $limit : $helper->getWarmupBatchSize();
            $rows = $queue->claim($batchSize);
            if ($rows === []) {
                $summary['remaining'] = $queue->count();
                return $summary;
            }

            $byUrl = [];
            foreach ($rows as $row) {
                $byUrl[(string) $row['url']] = $row;
            }

            $result = $this->warm(
                array_keys($byUrl),
                $helper->getWarmupConcurrency(),
                $helper->getWarmupDelayMs(),
            );

            $warmed = 0;
            $skipped = 0;
            $failed = 0;
            $dropped = 0;

            foreach ($result['warmed'] as $url) {
                if (isset($byUrl[$url])) {
                    $queue->remove((int) $byUrl[$url]['queue_id']);
                    $warmed++;
                }
            }
            foreach ($result['skipped'] as $url => $reason) {
                if (isset($byUrl[$url])) {
                    $queue->remove((int) $byUrl[$url]['queue_id']);
                    $skipped++;
                }
            }
            foreach ($result['failed'] as $url => $reason) {
                if (!isset($byUrl[$url])) {
                    continue;
                }
                $failed++;
                $outcome = $queue->retry(
                    (int) $byUrl[$url]['queue_id'],
                    (string) $reason,
                    $helper->getWarmupMaxAttempts(),
                    $helper->getWarmupRetryDelay(),
                );
                if ($outcome === 'dropped') {
                    $dropped++;
                }
            }

            $summary = [
                'at' => time(),
                'processed' => count($rows),
                'warmed' => $warmed,
                'skipped' => $skipped,
                'failed' => $failed,
                'dropped' => $dropped,
                'remaining' => $queue->count(),
            ];
            $this->saveLastRun($summary);
            $this->logSummary($summary, $result['failed']);

            return $summary;
        } catch (\Throwable $e) {
            Mage::logException($e);
            return $summary;
        } finally {
            $lock->release(self::WARMUP_LOCK);
        }
    }

    /**
     * @return array{at: int, processed: int, warmed: int, skipped: int, failed: int, dropped: int, remaining: int}|null
     */
    public function getLastRun(): ?array
    {
        $last = Mage::app()->getCache()->load($this->key()->getWarmupLastRunKey());
        if (!is_array($last) || !isset($last['at'])) {
            return null;
        }

        return [
            'at' => (int) $last['at'],
            'processed' => (int) ($last['processed'] ?? 0),
            'warmed' => (int) ($last['warmed'] ?? 0),
            'skipped' => (int) ($last['skipped'] ?? 0),
            'failed' => (int) ($last['failed'] ?? 0),
            'dropped' => (int) ($last['dropped'] ?? 0),
            'remaining' => (int) ($last['remaining'] ?? 0),
        ];
    }

    /**
     * @param array{warmed: list<string>, skipped: array<string, string>, failed: array<string, string>} $result
     */
    private function recordWarmupStat(array $result): void
    {
        Mage::getSingleton('page_cache/stat')->record(
            Maho_PageCache_Model_Stat::EVENT_WARMUP,
            null,
            null,
            null,
            sprintf(
                'warmed=%d skipped=%d failed=%d',
                count($result['warmed']),
                count($result['skipped']),
                count($result['failed']),
            ),
        );
        Mage::getSingleton('page_cache/stat')->flush();
    }

    /**
     * @return array{at: int, processed: int, warmed: int, skipped: int, failed: int, dropped: int, remaining: int}
     */
    private function emptySummary(): array
    {
        return [
            'at' => time(),
            'processed' => 0,
            'warmed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'dropped' => 0,
            'remaining' => 0,
        ];
    }

    /**
     * @param array{at: int, processed: int, warmed: int, skipped: int, failed: int, dropped: int, remaining: int} $summary
     */
    private function saveLastRun(array $summary): void
    {
        try {
            Mage::app()->getCache()->save(
                $summary,
                $this->key()->getWarmupLastRunKey(),
                ['PAGE_CACHE'],
                604800,
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * @param array{at: int, processed: int, warmed: int, skipped: int, failed: int, dropped: int, remaining: int} $summary
     * @param array<string, string> $failed
     */
    private function logSummary(array $summary, array $failed): void
    {
        $message = sprintf(
            'Page cache warmup: processed=%d warmed=%d skipped=%d failed=%d dropped=%d remaining=%d',
            $summary['processed'],
            $summary['warmed'],
            $summary['skipped'],
            $summary['failed'],
            $summary['dropped'],
            $summary['remaining'],
        );
        if ($failed !== []) {
            $message .= '; failed=' . implode(',', array_slice(array_keys($failed), 0, 3));
        }
        Mage::log($message, Mage::LOG_INFO, 'page-cache-warmup.log');
    }

    protected function getStoreBaseUrl(Mage_Core_Model_Store $store): string
    {
        $secure = (bool) $store->getConfig(Mage_Core_Model_Store::XML_PATH_SECURE_IN_FRONTEND)
            && str_starts_with(
                strtolower((string) $store->getConfig(Mage_Core_Model_Store::XML_PATH_SECURE_BASE_URL)),
                'https://',
            );
        $base = (string) $store->getConfig($secure ? 'web/secure/base_link_url' : 'web/unsecure/base_link_url');
        if ($base === '') {
            $base = (string) $store->getConfig(
                $secure
                    ? Mage_Core_Model_Store::XML_PATH_SECURE_BASE_URL
                    : Mage_Core_Model_Store::XML_PATH_UNSECURE_BASE_URL,
            );
        }
        if ($base === '' && $secure) {
            $base = (string) $store->getConfig(Mage_Core_Model_Store::XML_PATH_UNSECURE_BASE_URL);
        }

        $base = rtrim($base, '/');
        if ($base !== '' && $store->getStoreInUrl()) {
            $base .= '/' . $store->getCode();
        }

        return $base;
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }

    protected function urlPolicy(): Maho_PageCache_Service_UrlPolicy
    {
        return Mage::getService('page_cache/urlPolicy');
    }

    protected function createClient(): HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => 30,
            'max_redirects' => 0,
        ]);
    }

    private function isSitemapUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return str_ends_with(strtolower($path), '.xml');
    }
}
