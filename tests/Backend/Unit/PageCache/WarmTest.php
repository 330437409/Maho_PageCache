<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function warmServiceWithResponses(array $responses): Maho_PageCache_Service_Warm
{
    return new class ($responses) extends Maho_PageCache_Service_Warm {
        /**
         * @param list<MockResponse> $responses
         */
        public function __construct(private array $responses) {}

        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient($this->responses);
        }
    };
}

it('parses page urls from a sitemap', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        <url><loc>https://example.com/</loc></url>
        <url><loc>https://example.com/category.html</loc></url>
    </urlset>
    XML;

    expect((new Maho_PageCache_Service_Warm())->parseSitemap($xml))->toBe([
        'https://example.com/',
        'https://example.com/category.html',
    ]);
});

it('parses child sitemap locations', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        <sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap>
    </sitemapindex>
    XML;

    expect((new Maho_PageCache_Service_Warm())->parseSitemap($xml))->toBe([
        'https://example.com/sitemap-1.xml',
    ]);
});

it('merges, deduplicates and limits explicit urls', function () {
    $service = new Maho_PageCache_Service_Warm();

    expect($service->collectUrls([
        'https://example.com/a',
        'https://example.com/a',
        'ftp://example.com/b',
        'https://example.com/c',
    ], false, 2))->toBe([
        'https://example.com/a',
        'https://example.com/c',
    ]);
});

it('warms cacheable urls and reports skipped and failed ones', function () {
    $service = warmServiceWithResponses([
        new MockResponse('ok', ['http_code' => 200, 'response_headers' => ['X-Maho-Cache' => 'MISS']]),
        new MockResponse('', ['http_code' => 200, 'response_headers' => ['X-Maho-Cache' => 'BYPASS']]),
        new MockResponse('', ['http_code' => 404]),
        new MockResponse('', ['http_code' => 500]),
    ]);

    $result = $service->warm([
        'https://example.com/a',
        'https://example.com/b',
        'https://example.com/c',
        'https://example.com/d',
    ], 2);

    expect($result['warmed'])->toBe(['https://example.com/a'])
        ->and($result['skipped']['https://example.com/b'])->toBe('bypass')
        ->and($result['skipped']['https://example.com/c'])->toBe('not_found')
        ->and($result['failed']['https://example.com/d'])->toBe('HTTP 500');
});

it('reports transport failures', function () {
    $service = new class extends Maho_PageCache_Service_Warm {
        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient(static function (): MockResponse {
                throw new \RuntimeException('connection refused');
            });
        }
    };

    $result = $service->warm(['https://example.com/a'], 1);

    expect($result['warmed'])->toBe([])
        ->and($result['failed']['https://example.com/a'])->toBe('connection refused');
});
