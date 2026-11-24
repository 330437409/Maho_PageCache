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
    public function warm(array $urls, int $concurrency): array
    {
        $client = $this->createClient();
        $concurrency = max(1, $concurrency);
        $result = ['warmed' => [], 'skipped' => [], 'failed' => []];

        foreach (array_chunk($urls, $concurrency) as $chunk) {
            $responses = [];
            foreach ($chunk as $url) {
                try {
                    $responses[$url] = $client->request('GET', $url, [
                        'headers' => [
                            'Accept' => 'text/html,application/xhtml+xml',
                            'User-Agent' => 'Maho-PageCache-Warm/1.0',
                        ],
                    ]);
                } catch (\Throwable $e) {
                    $result['failed'][$url] = $e->getMessage();
                }
            }

            foreach ($responses as $url => $response) {
                try {
                    $status = $response->getStatusCode();
                    $headers = $response->getHeaders(false);
                    $response->getContent(false);
                    $cache = strtoupper((string) ($headers['x-maho-cache'][0] ?? ''));

                    if ($status === 200 && $cache !== 'BYPASS') {
                        $result['warmed'][] = $url;
                    } elseif ($status === 200) {
                        $result['skipped'][$url] = 'bypass';
                    } elseif ($status >= 300 && $status < 400) {
                        $result['skipped'][$url] = 'redirect';
                    } elseif ($status === 404) {
                        $result['skipped'][$url] = 'not_found';
                    } else {
                        $result['failed'][$url] = 'HTTP ' . $status;
                    }
                } catch (\Throwable $e) {
                    $result['failed'][$url] = $e->getMessage();
                }
            }
        }

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

        return $result;
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
