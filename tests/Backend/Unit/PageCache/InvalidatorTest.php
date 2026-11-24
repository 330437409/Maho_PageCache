<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'proxy');
    Mage::app()->getCache()->cleanType('page_cache');
});

afterEach(function () {
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '0');
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(
        Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue'),
        '1=1',
    );
    Mage::app()->getCache()->cleanType('page_cache');
});

function invalidatorStaticContext(string $path = '/category'): Maho_PageCache_Model_Context
{
    return new Maho_PageCache_Model_Context([
        'website_id' => 1,
        'store_id' => 1,
        'currency' => 'USD',
        'customer_group' => 0,
        'tax_country' => 'US',
        'tax_rate' => 0.0,
        'params' => [],
        'scheme' => 'https',
        'host' => 'example.com',
        'path' => $path,
        'mode' => 'local',
    ]);
}

it('removes the static file when a tag is invalidated', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $context = invalidatorStaticContext();
    $key = new Maho_PageCache_Model_Key();
    $urlHash = $context->getUrlHash();
    $path = Mage::getSingleton('page_cache/staticCache')->getFilePath($context);
    Mage::getSingleton('page_cache/staticCache')->save($context, '<html>category</html>');
    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'tags' => ['catalog_product_1'],
            'version' => 1,
            'ttl' => 3600,
            'store_code' => 'default',
        ],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeFalse();
});

it('clears every static file on full invalidation', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $cache = Mage::getSingleton('page_cache/staticCache');
    $cache->save(invalidatorStaticContext(), '<html>category</html>');
    $cache->save(invalidatorStaticContext('/'), '<html>home</html>');

    (new Maho_PageCache_Service_Invalidator())->invalidateAll();

    expect(glob($cache->getRoot() . '/*'))->toBe([]);
});

function indexPageCacheProduct(string $urlHash, int $productId): void
{
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        ['url' => 'https://example.com/product-' . $productId . '.html', 'tags' => ['catalog_product_' . $productId]],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_' . $productId]);
}

it('invalidates a product page when the stock status changes', function () {
    $urlHash = 'stock-hash';
    indexPageCacheProduct($urlHash, 123);
    $item = Mage::getModel('cataloginventory/stock_item');
    $item->setProductId(123);
    $item->setOrigData('is_in_stock', 1);
    $item->setData('is_in_stock', 0);

    (new Maho_PageCache_Model_Observer())->invalidateOnStockSave(
        new Maho\Event\Observer(['item' => $item]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($urlHash))->toBe(2);
});

it('does not invalidate a product page when only the stock quantity changes', function () {
    $urlHash = 'stock-qty-hash';
    indexPageCacheProduct($urlHash, 123);
    $item = Mage::getModel('cataloginventory/stock_item');
    $item->setProductId(123);
    $item->setOrigData('is_in_stock', 1);
    $item->setData('is_in_stock', 1);
    $item->setOrigData('qty', 10);
    $item->setData('qty', 4);

    (new Maho_PageCache_Model_Observer())->invalidateOnStockSave(
        new Maho\Event\Observer(['item' => $item]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($urlHash))->toBe(1);
});

it('invalidates product tags after an attribute mass update', function () {
    $urlHash = 'mass-update-hash';
    indexPageCacheProduct($urlHash, 123);

    (new Maho_PageCache_Model_Observer())->invalidateOnAttributeUpdate(
        new Maho\Event\Observer(['product_ids' => [123]]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($urlHash))->toBe(2);
});

it('falls back to a full invalidation for a large attribute mass update', function () {
    $version = new Maho_PageCache_Model_Version();
    $before = $version->getGlobal();

    (new Maho_PageCache_Model_Observer())->invalidateOnAttributeUpdate(
        new Maho\Event\Observer(['product_ids' => range(1, 31)]),
    );

    expect($version->getGlobal())->toBeGreaterThan($before);
});

it('invalidates a tag, increments the url version and enqueues a purge', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'proxy']));
    $key = new Maho_PageCache_Model_Key();
    $urlHash = $context->getUrlHash();

    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => ['catalog_product_1']],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    $entries = (new Maho_PageCache_Model_PurgeQueue())->getEntries();
    $urls = [];
    foreach ($entries as $entry) {
        $urls = array_merge($urls, $entry['urls']);
    }

    expect((new Maho_PageCache_Model_Version())->getUrl($urlHash))->toBe(2)
        ->and($urls)->toContain($context->getPurgeUrl())
        ->and((new Maho_PageCache_Model_TagIndex())->getUrls('catalog_product_1'))->toBeFalse();
});

it('falls back to a full invalidation when a known tag index is missing', function () {
    $version = new Maho_PageCache_Model_Version();
    $invalidator = new Maho_PageCache_Service_Invalidator();
    $index = new Maho_PageCache_Model_TagIndex();
    $key = new Maho_PageCache_Model_Key();

    $index->add('hash1', ['catalog_product_1']);
    Mage::app()->getCache()->remove($key->getTagKey('catalog_product_1'));
    $before = $version->getGlobal();

    $invalidator->invalidateTags(['catalog_product_1']);

    expect($version->getGlobal())->not->toBe($before);
});

it('invalidates the cache id tags of a generic model', function () {
    $model = new class extends Mage_Core_Model_Abstract {
        protected $_cacheTag = 'catalog_product';
    };
    $model->setIdFieldName('entity_id');
    $model->setId(123);

    $key = new Maho_PageCache_Model_Key();
    $urlHash = 'model-hash';
    Mage::app()->getCache()->save(
        ['url' => 'https://example.com/product.html', 'tags' => ['catalog_product_123']],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_123']);

    (new Maho_PageCache_Service_Invalidator())->invalidateEntity($model);

    expect((new Maho_PageCache_Model_Version())->getUrl($urlHash))->toBe(2)
        ->and((new Maho_PageCache_Model_TagIndex())->getUrls('catalog_product_123'))->toBeFalse();
});

it('increments the global version and clears the index on full invalidation', function () {
    $version = new Maho_PageCache_Model_Version();
    $index = new Maho_PageCache_Model_TagIndex();
    $key = new Maho_PageCache_Model_Key();
    $queue = new Maho_PageCache_Model_PurgeQueue();

    Mage::app()->getCache()->save(['url' => 'https://example.com/a'], $key->getMetaKey('hash1'), ['PAGE_CACHE'], 3600);
    $index->add('hash1', ['catalog_product_1']);
    $before = $version->getGlobal();

    (new Maho_PageCache_Service_Invalidator())->invalidateAll();

    $entries = $queue->getEntries();
    $urls = [];
    foreach ($entries as $entry) {
        $urls = array_merge($urls, $entry['urls']);
    }

    expect($version->getGlobal())->not->toBe($before)
        ->and($index->getUrls('catalog_product_1'))->toBeFalse()
        ->and($urls)->toContain('*');
});

it('does not reuse the global version across two full invalidations', function () {
    $invalidator = new Maho_PageCache_Service_Invalidator();
    $version = new Maho_PageCache_Model_Version();

    $invalidator->invalidateAll();
    $first = $version->getGlobal();

    $invalidator->invalidateAll();
    $second = $version->getGlobal();

    expect($second)->toBeGreaterThan($first);
});

it('invalidates the products and categories matched by a catalog rule', function () {
    $invalidator = new class extends Maho_PageCache_Service_Invalidator {
        protected function getCategoryIdsForProducts(array $productIds): array
        {
            return [55];
        }
    };
    $rule = new class extends Mage_CatalogRule_Model_Rule {
        public function getMatchingProductIds(): array
        {
            return [123 => [1 => 1]];
        }
    };
    $key = new Maho_PageCache_Model_Key();
    $index = new Maho_PageCache_Model_TagIndex();

    Mage::app()->getCache()->save(['url' => 'https://example.com/product-123.html'], $key->getMetaKey('product-hash'), ['PAGE_CACHE'], 3600);
    $index->add('product-hash', ['catalog_product_123']);
    Mage::app()->getCache()->save(['url' => 'https://example.com/category-55.html'], $key->getMetaKey('category-hash'), ['PAGE_CACHE'], 3600);
    $index->add('category-hash', ['catalog_category_55']);

    $invalidator->invalidateCatalogRule($rule);

    expect((new Maho_PageCache_Model_Version())->getUrl('product-hash'))->toBe(2)
        ->and((new Maho_PageCache_Model_Version())->getUrl('category-hash'))->toBe(2);
});

it('queues the affected urls for warmup when enabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    $context = invalidatorStaticContext('/category');
    $key = new Maho_PageCache_Model_Key();
    $urlHash = $context->getUrlHash();
    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'tags' => ['catalog_product_1'],
            'version' => 1,
            'ttl' => 3600,
            'store_code' => 'default',
            'store_id' => 1,
        ],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $rows = $read->fetchAll($read->select()->from(
        Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue'),
    ));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['url'])->toBe($context->getPurgeUrl())
        ->and((int) $rows[0]['store_id'])->toBe(1)
        ->and($rows[0]['reason'])->toBe('catalog_product_1');
});

it('does not queue warmup urls when warmup is disabled', function () {
    $context = invalidatorStaticContext('/category');
    $key = new Maho_PageCache_Model_Key();
    $urlHash = $context->getUrlHash();
    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => ['catalog_product_1'], 'version' => 1, 'ttl' => 3600],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect(Mage::getModel('page_cache/warmQueue')->count())->toBe(0);
});

it('clears the warmup queue on full invalidation', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/category'], 1, 'test');

    (new Maho_PageCache_Service_Invalidator())->invalidateAll('test');

    expect($queue->count())->toBe(0);
});

it('skips a missing meta instead of a full invalidation', function () {
    $index = new Maho_PageCache_Model_TagIndex();
    $index->add('missing-meta-hash', ['catalog_product_1']);
    $version = new Maho_PageCache_Model_Version();
    $before = $version->getGlobal();

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect($version->getGlobal())->toBe($before)
        ->and($index->getUrls('catalog_product_1'))->toBeFalse();
});

it('does not full-invalidate after a deliberate tag index flush', function () {
    (new Maho_PageCache_Model_TagIndex())->flush();
    $version = new Maho_PageCache_Model_Version();
    $before = $version->getGlobal();

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect($version->getGlobal())->toBe($before);
});

it('purges every stored url variant and removes the default static file', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    $context = invalidatorStaticContext('/category');
    $urlHash = $context->getUrlHash();
    $defaultUrl = $context->getPurgeUrl();
    $variantUrl = $defaultUrl . '?p=2';
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        [
            'url' => $variantUrl,
            'urls' => [$defaultUrl, $variantUrl],
            'variants_truncated' => false,
            'tags' => ['catalog_product_1'],
            'version' => 1,
            'ttl' => 3600,
            'store_code' => 'default',
            'store_id' => 1,
        ],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);
    $static = Mage::getSingleton('page_cache/staticCache');
    $static->save($context, '<html>category</html>');
    $path = $static->getFilePath($context);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    $urls = [];
    foreach ((new Maho_PageCache_Model_PurgeQueue())->getEntries() as $entry) {
        $urls = array_merge($urls, $entry['urls']);
    }
    $resource = Mage::getSingleton('core/resource');
    $read = $resource->getConnection('core_read');
    $warmUrls = $read->fetchCol(
        $read->select()->from($resource->getTableName('page_cache/warmup_queue'), ['url']),
    );

    expect($urls)->toContain($defaultUrl)
        ->and($urls)->toContain($variantUrl)
        ->and($warmUrls)->toContain($defaultUrl)
        ->and($warmUrls)->toContain($variantUrl)
        ->and(file_exists((string) $path))->toBeFalse();
});

it('enqueues a full purge when the variant list was truncated', function () {
    $context = invalidatorStaticContext('/category');
    $urlHash = $context->getUrlHash();
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'urls' => [$context->getPurgeUrl()],
            'variants_truncated' => true,
            'tags' => ['catalog_product_1'],
            'version' => 1,
            'ttl' => 3600,
            'store_code' => 'default',
            'store_id' => 1,
        ],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    $urls = [];
    foreach ((new Maho_PageCache_Model_PurgeQueue())->getEntries() as $entry) {
        $urls = array_merge($urls, $entry['urls']);
    }

    expect($urls)->toContain('*');
});

it('clears the stored etag when a tag is invalidated', function () {
    $context = invalidatorStaticContext('/category');
    $urlHash = $context->getUrlHash();
    $key = new Maho_PageCache_Model_Key();
    $version = new Maho_PageCache_Model_Version();
    $version->storeEtag($urlHash, 'W/"old"', 100);
    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'tags' => ['catalog_product_1'],
            'version' => 1,
            'ttl' => 3600,
            'store_code' => 'default',
            'store_id' => 1,
        ],
        $key->getMetaKey($urlHash),
        ['PAGE_CACHE'],
        3600,
    );
    (new Maho_PageCache_Model_TagIndex())->add($urlHash, ['catalog_product_1']);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect($version->getLastModified($urlHash))->toBe(0)
        ->and(Mage::app()->getCache()->load($key->getEtagKey($urlHash)))->toBeFalse();
});
