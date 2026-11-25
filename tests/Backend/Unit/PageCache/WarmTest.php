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

function warmupTestQueueTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue');
}

function warmupTestQueueClean(): void
{
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(warmupTestQueueTable(), '1=1');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/unsecure/base_link_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/secure/base_link_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/url/use_store', '0');
    Mage::getService('page_cache/urlPolicy')->reset();
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/warmup/batch_size', '10');
    Mage::app()->getStore()->setConfig('page_cache/warmup/concurrency', '2');
    Mage::app()->getStore()->setConfig('page_cache/warmup/delay_ms', '25');
    Mage::app()->getStore()->setConfig('page_cache/warmup/max_attempts', '2');
    Mage::app()->getStore()->setConfig('page_cache/warmup/retry_delay', '60');
    Mage::app()->getStore()->setConfig('page_cache/warmup/queue_limit', '100');
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_user', '');
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_password', '');
    warmupTestQueueClean();
});

afterEach(function () {
    warmupTestQueueClean();
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '0');
    Mage::app()->getCache()->remove(Mage::getModel('page_cache/key')->getWarmupLastRunKey());
});

function warmServiceWithResponses(array $responses): Maho_PageCache_Service_Warm
{
    return new class ($responses) extends Maho_PageCache_Service_Warm {
        /**
         * @param list<MockResponse> $responses
         */
        public function __construct(private array $responses) {}

        #[\Override]
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

it('classifies permanent and retryable responses', function () {
    $service = warmServiceWithResponses([
        new MockResponse('ok', ['http_code' => 200, 'response_headers' => ['X-Maho-Cache' => 'MISS']]),
        new MockResponse('', ['http_code' => 200, 'response_headers' => ['X-Maho-Cache' => 'BYPASS']]),
        new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.example/redirected']]),
        new MockResponse('', ['http_code' => 404]),
        new MockResponse('', ['http_code' => 410]),
        new MockResponse('', ['http_code' => 401]),
        new MockResponse('', ['http_code' => 403]),
        new MockResponse('', ['http_code' => 429]),
        new MockResponse('', ['http_code' => 500]),
        new MockResponse('', ['http_code' => 400]),
    ]);

    $urls = [
        'https://example.com/ok',
        'https://example.com/bypass',
        'https://example.com/redirect',
        'https://example.com/missing',
        'https://example.com/gone',
        'https://example.com/unauthorized',
        'https://example.com/forbidden',
        'https://example.com/limited',
        'https://example.com/error',
        'https://example.com/bad-request',
    ];

    $result = $service->warm($urls, 1);

    expect($result['warmed'])->toBe(['https://example.com/ok'])
        ->and($result['skipped'])->toMatchArray([
            'https://example.com/bypass' => 'bypass',
            'https://example.com/redirect' => 'redirect',
            'https://example.com/missing' => 'not_found',
            'https://example.com/gone' => 'not_found',
            'https://example.com/unauthorized' => 'forbidden',
            'https://example.com/forbidden' => 'forbidden',
            'https://example.com/bad-request' => 'http_400',
        ])
        ->and($result['failed'])->toHaveKeys([
            'https://example.com/limited',
            'https://example.com/error',
        ]);
});

it('filters explicit urls outside the store origins', function () {
    $service = new Maho_PageCache_Service_Warm();

    expect($service->collectUrls([
        'https://example.com/allowed',
        'https://evil.example/collect',
        'http://169.254.169.254/latest/meta-data/',
    ], false, 10))->toBe(['https://example.com/allowed']);
});

it('skips warm urls outside the store origins without a request', function () {
    $service = new class extends Maho_PageCache_Service_Warm {
        public int $requests = 0;

        #[\Override]
        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient(function (): MockResponse {
                $this->requests++;
                return new MockResponse('ok', ['http_code' => 200]);
            });
        }
    };

    $result = $service->warm(['https://evil.example/collect'], 1);

    expect($result['warmed'])->toBe([])
        ->and($result['skipped']['https://evil.example/collect'])->toBe('disallowed_host')
        ->and($service->requests)->toBe(0);
});

it('sends basic auth when configured', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_user', 'staging');
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_password', 'secret');

    $service = new class extends Maho_PageCache_Service_Warm {
        /** @var list<array<string, mixed>> */
        public array $requests = [];

        #[\Override]
        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
                return new MockResponse('ok', ['http_code' => 200]);
            });
        }
    };

    $service->warm(['https://example.com/a'], 1);

    $headers = $service->requests[0]['options']['normalized_headers'] ?? [];

    expect($headers['authorization'][0] ?? null)
        ->toBe('Authorization: Basic ' . base64_encode('staging:secret'));
});

it('consumes queued urls and maps the result back to the queue', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue([
        'https://example.com/ok',
        'https://example.com/missing',
        'https://example.com/fail',
    ], 1, 'catalog_product_1');

    $service = new class extends Maho_PageCache_Service_Warm {
        /** @var list<array{urls: list<string>, concurrency: int, delay_ms: int}> */
        public array $calls = [];

        #[\Override]
        public function warm(array $urls, int $concurrency, int $delayMs = 0): array
        {
            $this->calls[] = ['urls' => $urls, 'concurrency' => $concurrency, 'delay_ms' => $delayMs];
            return [
                'warmed' => [$urls[0]],
                'skipped' => [$urls[1] => 'not_found'],
                'failed' => [$urls[2] => 'HTTP 500'],
            ];
        }
    };

    $summary = $service->consumeQueue();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow($read->select()->from(warmupTestQueueTable()));

    expect($summary['processed'])->toBe(3)
        ->and($summary['warmed'])->toBe(1)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['dropped'])->toBe(0)
        ->and($summary['remaining'])->toBe(1)
        ->and($queue->count())->toBe(1)
        ->and((int) $row['attempts'])->toBe(1)
        ->and($row['last_error'])->toBe('HTTP 500')
        ->and($service->calls[0]['concurrency'])->toBe(2)
        ->and($service->calls[0]['delay_ms'])->toBe(25)
        ->and($service->getLastRun())->toMatchArray([
            'processed' => 3,
            'warmed' => 1,
            'skipped' => 1,
            'failed' => 1,
            'dropped' => 0,
            'remaining' => 1,
        ]);
});

it('drops a row after the last failed attempt', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/max_attempts', '1');
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/fail'], 1, 'test');

    $service = new class extends Maho_PageCache_Service_Warm {
        #[\Override]
        public function warm(array $urls, int $concurrency, int $delayMs = 0): array
        {
            return ['warmed' => [], 'skipped' => [], 'failed' => [$urls[0] => 'HTTP 500']];
        }
    };

    $summary = $service->consumeQueue();

    expect($summary['failed'])->toBe(1)
        ->and($summary['dropped'])->toBe(1)
        ->and($queue->count())->toBe(0);
});

it('does nothing when the warmup lock is held', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/a'], 1, 'test');

    $lock = Mage::getSingleton('core/lock');
    expect($lock->acquire('page_cache_warmup_queue'))->toBeTrue();

    $service = new class extends Maho_PageCache_Service_Warm {
        public array $calls = [];

        #[\Override]
        public function warm(array $urls, int $concurrency, int $delayMs = 0): array
        {
            $this->calls[] = $urls;
            return ['warmed' => $urls, 'skipped' => [], 'failed' => []];
        }
    };

    try {
        $summary = $service->consumeQueue();
    } finally {
        $lock->release('page_cache_warmup_queue');
    }

    expect($summary['processed'])->toBe(0)
        ->and($service->calls)->toBe([])
        ->and($queue->count())->toBe(1);
});

it('does not send basic auth to a host outside the store origins', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_user', 'staging');
    Mage::app()->getStore()->setConfig('page_cache/warmup/auth_password', 'secret');

    $service = new class extends Maho_PageCache_Service_Warm {
        /** @var list<string> */
        public array $requests = [];

        #[\Override]
        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->requests[] = $url;
                return new MockResponse('ok', ['http_code' => 200]);
            });
        }
    };

    $result = $service->warm(['https://evil.example/a'], 1);

    expect($result['skipped']['https://evil.example/a'])->toBe('disallowed_host')
        ->and($service->requests)->toBe([]);
});

it('does not request a child sitemap outside the store origins', function () {
    $filename = 'pc_test_' . bin2hex(random_bytes(4)) . '.xml';
    $path = 'media/' . $filename;
    $sitemap = Mage::getModel('sitemap/sitemap');
    $sitemap->setSitemapPath('media')
        ->setSitemapFilename($filename)
        ->setStoreId(1)
        ->save();

    $index = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        <sitemap><loc>https://evil.example/evil.xml</loc></sitemap>
        <sitemap><loc>https://example.com/child.xml</loc></sitemap>
    </sitemapindex>
    XML;
    $child = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        <url><loc>https://example.com/from-child</loc></url>
    </urlset>
    XML;
    Mage::getStorage('sitemaps')->write($path, $index);

    $service = new class extends Maho_PageCache_Service_Warm {
        /** @var list<string> */
        public array $requests = [];

        #[\Override]
        protected function createClient(): HttpClientInterface
        {
            return new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->requests[] = $url;
                return new MockResponse(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url><loc>https://example.com/from-child</loc></url>
                </urlset>
                XML, ['http_code' => 200]);
            });
        }
    };

    try {
        $urls = $service->getSitemapUrls();
    } finally {
        $sitemap->delete();
        Mage::getStorage('sitemaps')->delete($path);
    }

    expect($urls)->toBe(['https://example.com/from-child'])
        ->and($service->requests)->toBe(['https://example.com/child.xml']);
});

it('collects active product and category urls and skips redirects', function () {
    $makeProduct = static function (int $status): Mage_Catalog_Model_Product {
        $product = Mage::getModel('catalog/product');
        $product->setStoreId(0)
            ->setSku('warm-active-' . uniqid())
            ->setName('Warm Active ' . uniqid())
            ->setPrice(10)
            ->setWeight(1)
            ->setStatus($status)
            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
            ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 1, 'qty' => 10, 'is_in_stock' => 1])
            ->save();
        return $product;
    };
    $makeCategory = static function (int $active): Mage_Catalog_Model_Category {
        $parent = Mage::getModel('catalog/category')->load(2);
        $category = Mage::getModel('catalog/category');
        $category->setStoreId(0)
            ->setName('Warm Category ' . uniqid())
            ->setPath($parent->getPath())
            ->setParentId((int) $parent->getId())
            ->setIsActive($active)
            ->setAttributeSetId((int) $category->getDefaultAttributeSetId())
            ->save();
        return $category;
    };

    $active = $makeProduct(Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
    $disabled = $makeProduct(Mage_Catalog_Model_Product_Status::STATUS_DISABLED);
    $redirect = $makeProduct(Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
    $category = $makeCategory(1);
    $inactiveCategory = $makeCategory(0);
    $paths = [
        'warm-active-' . uniqid() => ['catalog/product/view/id/' . $active->getId(), $active->getId(), null, ''],
        'warm-disabled-' . uniqid() . '.html' => ['catalog/product/view/id/' . $disabled->getId(), $disabled->getId(), null, ''],
        'warm-redirect-' . uniqid() . '.html' => ['catalog/product/view/id/' . $redirect->getId(), $redirect->getId(), null, 'RP'],
        'warm-category-' . uniqid() . '.html' => ['catalog/category/view/id/' . $category->getId(), null, $category->getId(), ''],
        'warm-inactive-category-' . uniqid() . '.html' => ['catalog/category/view/id/' . $inactiveCategory->getId(), null, $inactiveCategory->getId(), ''],
    ];
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $table = Mage::getSingleton('core/resource')->getTableName('core/url_rewrite');

    try {
        foreach ($paths as $requestPath => [$target, $productId, $categoryId, $options]) {
            $write->insert($table, [
                'store_id' => 1,
                'id_path' => 'warm/' . $requestPath,
                'request_path' => $requestPath,
                'target_path' => $target,
                'is_system' => 1,
                'options' => $options,
                'product_id' => $productId,
                'category_id' => $categoryId,
            ]);
        }

        $urls = (new Maho_PageCache_Service_Warm())->collectActiveCatalogUrls(1, 5000);

        expect($urls)->toContain('https://example.com/' . array_key_first($paths) . '/')
            ->and($urls)->toContain('https://example.com/' . array_keys($paths)[3])
            ->and($urls)->not->toContain('https://example.com/' . array_keys($paths)[1])
            ->and($urls)->not->toContain('https://example.com/' . array_keys($paths)[2])
            ->and($urls)->not->toContain('https://example.com/' . array_keys($paths)[4]);
    } finally {
        $write->delete($table, ['request_path IN (?)' => array_keys($paths)]);
        foreach ([$active, $disabled, $redirect] as $product) {
            try {
                $product->delete();
            } catch (\Throwable) {
            }
        }
        foreach ([$category, $inactiveCategory] as $category) {
            try {
                $category->delete();
            } catch (\Throwable) {
            }
        }
    }
});

it('keeps the warmup lock across a page cache flush', function () {
    $lock = Mage::getSingleton('core/lock');
    expect($lock->acquire('page_cache_warmup_queue'))->toBeTrue();

    try {
        Mage::app()->getCache()->cleanType('page_cache');
        expect($lock->isHeld('page_cache_warmup_queue'))->toBeTrue();
    } finally {
        $lock->release('page_cache_warmup_queue');
    }
});

it('releases the warmup lock when the queue run fails', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->clear();
    $queue->enqueue(['https://example.com/fail'], 1, 'test');
    $lock = Mage::getSingleton('core/lock');
    $service = new class extends Maho_PageCache_Service_Warm {
        #[\Override]
        public function warm(array $urls, int $concurrency, int $delayMs = 0): array
        {
            throw new \RuntimeException('warm failed');
        }
    };

    try {
        $service->consumeQueue();
        expect($lock->isHeld('page_cache_warmup_queue'))->toBeFalse();
    } finally {
        $lock->release('page_cache_warmup_queue');
        $queue->clear();
    }
});

it('builds the warmup base from the secure link url and keeps the store code', function () {
    $store = Mage::app()->getStore();
    $paths = [
        'web/url/use_store',
        'web/unsecure/base_url',
        'web/unsecure/base_link_url',
        'web/secure/base_url',
        'web/secure/base_link_url',
        'web/secure/use_in_frontend',
    ];
    $original = [];
    foreach ($paths as $path) {
        $original[$path] = $store->getConfig($path);
    }

    $service = new class extends Maho_PageCache_Service_Warm {
        public function base(Mage_Core_Model_Store $store): string
        {
            return $this->getStoreBaseUrl($store);
        }
    };

    try {
        $store->setConfig('web/url/use_store', '1');
        $store->setConfig('web/unsecure/base_url', 'http://example.com/');
        $store->setConfig('web/unsecure/base_link_url', 'http://example.com/');
        $store->setConfig('web/secure/base_url', 'https://example.com/');
        $store->setConfig('web/secure/base_link_url', 'https://example.com/');
        $store->setConfig('web/secure/use_in_frontend', '1');

        expect($service->base($store))->toBe('https://example.com/default');
    } finally {
        foreach ($original as $path => $value) {
            $store->setConfig($path, (string) $value);
        }
    }
});

it('fails loudly when active url collection fails', function () {
    $service = new class extends Maho_PageCache_Service_Warm {
        protected function getStoreBaseUrl(Mage_Core_Model_Store $store): string
        {
            throw new RuntimeException('database is down');
        }
    };

    expect(static fn(): array => $service->collectActiveCatalogUrls(1, 10))
        ->toThrow(RuntimeException::class, 'database is down');
});
