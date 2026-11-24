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
    private const string WARMUP_LOCK = 'page_cache_warmup_queue';

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
    public function collectUrls(array $explicitUrls, bool $includeSitemap, int $limit): array
    {
        $urls = $explicitUrls;
        if ($includeSitemap) {
            $urls = array_merge($urls, $this->getSitemapUrls());
        }

        $urls = array_values(array_filter(
            $urls,
            static fn(mixed $url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1,
        ));

        return array_slice(array_values(array_unique($urls)), 0, max(1, $limit));
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

            $baseUrl = Mage::app()->getStore((int) $sitemap->getStoreId())
                ->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
            $baseHost = parse_url((string) $baseUrl, PHP_URL_HOST);
            if (is_string($baseHost) && $baseHost !== '') {
                $sitemapUrls = array_filter(
                    $sitemapUrls,
                    static function (string $url) use ($baseHost): bool {
                        $host = parse_url($url, PHP_URL_HOST);
                        return !is_string($host) || strcasecmp($host, $baseHost) === 0;
                    },
                );
            }

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
        $client = $this->createClient();
        $concurrency = max(1, $concurrency);
        $delayMs = max(0, $delayMs);
        $result = ['warmed' => [], 'skipped' => [], 'failed' => []];

        $headers = [
            'Accept' => 'text/html,application/xhtml+xml',
            'User-Agent' => 'Maho-PageCache-Warm/1.0',
        ];
        $auth = Mage::helper('page_cache')->getWarmupAuth();
        if ($auth !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($auth['user'] . ':' . $auth['password']);
        }

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
                } catch (\Throwable $e) {
                    $result['failed'][$url] = $e->getMessage();
                }
            }

            if ($delayMs > 0 && $index < count($chunks) - 1) {
                usleep($delayMs * 1000);
            }
        }

        $this->recordWarmupStat($result);

        return $result;
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

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
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
