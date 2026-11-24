<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

class ProxyModelFixture
{
    /** @var list<int> */
    public static array $productIds = [];

    /** @var list<int> */
    public static array $storeIds = [];

    public static int $categoryId = 0;
    public static int $cmsPageId = 0;
    public static int $cmsBlockId = 0;

    /**
     * @return list<int>
     */
    public static function createProducts(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $product = Mage::getModel('catalog/product');
            $product->setStoreId(0)
                ->setSku('proxy-model-' . $i . '-' . uniqid())
                ->setName('Proxy Model Product ' . $i)
                ->setPrice(10 + $i)
                ->setWeight(1)
                ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
                ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
                ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
                ->setWebsiteIds([1])
                ->setStockData([
                    'use_config_manage_stock' => 0,
                    'manage_stock' => 1,
                    'qty' => 100,
                    'is_in_stock' => 1,
                ])
                ->save();
            $id = (int) $product->getId();
            self::$productIds[] = $id;
            $ids[] = $id;
        }

        return $ids;
    }

    public static function createCategory(): int
    {
        $parent = Mage::getModel('catalog/category')->load(2);
        if (!$parent->getId()) {
            $parent = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToSelect('name')
                ->addFieldToFilter('level', 1)
                ->setPageSize(1)
                ->getFirstItem();
        }

        $category = Mage::getModel('catalog/category');
        $category->setStoreId(0)
            ->setName('Proxy Model Category ' . uniqid())
            ->setPath($parent->getPath())
            ->setParentId((int) $parent->getId())
            ->setIsActive(1)
            ->setAttributeSetId((int) $category->getDefaultAttributeSetId())
            ->save();
        self::$categoryId = (int) $category->getId();

        return self::$categoryId;
    }

    public static function createCmsPage(): int
    {
        $page = Mage::getModel('cms/page');
        $page->setData([
            'identifier' => 'proxy-model-page-' . uniqid(),
            'title' => 'Proxy Model Page',
            'content' => '<p>proxy model page</p>',
            'root_template' => 'one_column',
            'is_active' => 1,
            'stores' => [0],
        ])->save();
        self::$cmsPageId = (int) $page->getId();

        return self::$cmsPageId;
    }

    public static function createCmsBlock(): int
    {
        $block = Mage::getModel('cms/block');
        $block->setData([
            'identifier' => 'proxy-model-block-' . uniqid(),
            'title' => 'Proxy Model Block',
            'content' => '<p>proxy model block</p>',
            'is_active' => 1,
            'stores' => [0],
        ])->save();
        self::$cmsBlockId = (int) $block->getId();

        return self::$cmsBlockId;
    }

    public static function createStore(): int
    {
        $store = Mage::getModel('core/store')
            ->setCode('proxy_model_' . uniqid())
            ->setName('Proxy Model Store')
            ->setWebsiteId(1)
            ->setGroupId(1)
            ->setIsActive(true)
            ->save();
        $id = (int) $store->getId();
        self::$storeIds[] = $id;

        return $id;
    }

    public static function cleanup(): void
    {
        foreach (self::$productIds as $id) {
            try {
                Mage::getModel('catalog/product')->load($id)->delete();
            } catch (\Throwable) {
            }
        }
        self::$productIds = [];

        foreach (self::$storeIds as $id) {
            try {
                Mage::getModel('core/store')->load($id)->delete();
            } catch (\Throwable) {
            }
        }
        self::$storeIds = [];

        if (self::$cmsBlockId > 0) {
            try {
                Mage::getModel('cms/block')->load(self::$cmsBlockId)->delete();
            } catch (\Throwable) {
            }
        }
        self::$cmsBlockId = 0;

        if (self::$cmsPageId > 0) {
            try {
                Mage::getModel('cms/page')->load(self::$cmsPageId)->delete();
            } catch (\Throwable) {
            }
        }
        self::$cmsPageId = 0;

        if (self::$categoryId > 0) {
            try {
                Mage::getModel('catalog/category')->load(self::$categoryId)->delete();
            } catch (\Throwable) {
            }
        }
        self::$categoryId = 0;
    }
}

function proxyModelContext(string $path = '/proxy-model.html', array $overrides = []): Maho_PageCache_Model_Context
{
    return new Maho_PageCache_Model_Context(array_merge([
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
    ], $overrides));
}

function proxyModelQueryCount(callable $operation): int
{
    $counter = new class {
        public int $count = 0;

        public function record(string $sql, array $bind): void
        {
            $this->count++;
        }
    };
    $adapters = [];
    foreach (Mage::getSingleton('core/resource')->getConnections() as $adapter) {
        if ($adapter && !isset($adapters[spl_object_id($adapter)])) {
            $hook = new class ($counter) {
                public mixed $previous = null;

                public function __construct(private readonly object $counter) {}

                public function record(string $sql, array $bind): void
                {
                    if ($this->previous) {
                        $this->previous['object']->{$this->previous['method']}($sql, $bind);
                    }
                    $this->counter->record($sql, $bind);
                }
            };
            $hook->previous = $adapter->setQueryHook(['object' => $hook, 'method' => 'record']);
            $adapters[spl_object_id($adapter)] = [$adapter, $hook];
        }
    }
    try {
        $operation();
    } finally {
        foreach ($adapters as [$adapter, $hook]) {
            $adapter->setQueryHook($hook->previous);
        }
    }

    return $counter->count;
}

function proxyModelCategoryId(): int
{
    return ProxyModelFixture::$categoryId;
}

function proxyModelCmsPageId(): int
{
    return ProxyModelFixture::$cmsPageId;
}

function proxyModelCmsBlockId(): int
{
    return ProxyModelFixture::$cmsBlockId;
}

function proxyModelResetRegistries(): void
{
    Mage::unregister('current_product');
    Mage::unregister('product');
    Mage::unregister('current_category');
    Mage::unregister('current_entity_key');
    Mage::unregister('cms_page');
    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_server_blocks');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/general/ttl', '3600');
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '2097152');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_side', '1');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_types', 'core/text');
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getModel('page_cache/urlIndex')->clear();
    Mage::getService('page_cache/proxy')->reset();
    proxyModelResetRegistries();

    ProxyModelFixture::createProducts(3);
    ProxyModelFixture::createCategory();
    ProxyModelFixture::createCmsPage();
    ProxyModelFixture::createCmsBlock();
});

afterEach(function () {
    Mage::getService('page_cache/proxy')->reset();
    ProxyModelFixture::cleanup();
    proxyModelResetRegistries();
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_side', '0');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_types', '');
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getModel('page_cache/urlIndex')->clear();
});

it('reuses one product instance for repeated lookups', function () {
    $id = ProxyModelFixture::$productIds[0];
    $proxy = Mage::getService('page_cache/proxy');

    $first = $proxy->getProduct($id);
    $queries = proxyModelQueryCount(fn() => $proxy->getProduct($id));
    $second = $proxy->getProduct($id);

    expect($first)->toBeInstanceOf(Mage_Catalog_Model_Product::class)
        ->and($first)->toBe($second)
        ->and((int) $first->getId())->toBe($id)
        ->and($queries)->toBe(0);
});

it('loads a batch without growing the query count per entity', function () {
    $ids = ProxyModelFixture::$productIds;
    $proxy = Mage::getService('page_cache/proxy');

    $proxy->reset();
    $one = proxyModelQueryCount(fn() => $proxy->getProducts([$ids[0]]));

    $proxy->reset();
    $three = proxyModelQueryCount(fn() => $proxy->getProducts($ids));

    expect($three)->toBeLessThanOrEqual($one + 1);
});

it('returns loaded models for a batch', function () {
    $ids = ProxyModelFixture::$productIds;
    $result = Mage::getService('page_cache/proxy')->getProducts($ids);

    expect($result)->toHaveKeys($ids);
    foreach ($ids as $id) {
        expect((int) $result[$id]->getId())->toBe($id)
            ->and($result[$id]->getName())->toStartWith('Proxy Model Product');
    }
});

it('loads categories, CMS pages and CMS blocks in batches', function () {
    $proxy = Mage::getService('page_cache/proxy');
    $categoryId = proxyModelCategoryId();
    $pageId = proxyModelCmsPageId();
    $blockId = proxyModelCmsBlockId();

    expect($proxy->getCategory($categoryId))->toBeInstanceOf(Mage_Catalog_Model_Category::class)
        ->and((int) $proxy->getCategory($categoryId)->getId())->toBe($categoryId)
        ->and($proxy->getCmsPage($pageId))->toBeInstanceOf(Mage_Cms_Model_Page::class)
        ->and((int) $proxy->getCmsPage($pageId)->getId())->toBe($pageId)
        ->and($proxy->getCmsBlock($blockId))->toBeInstanceOf(Mage_Cms_Model_Block::class)
        ->and((int) $proxy->getCmsBlock($blockId)->getId())->toBe($blockId)
        ->and($proxy->getCategories([$categoryId]))->toHaveKey($categoryId)
        ->and($proxy->getCmsPages([$pageId]))->toHaveKey($pageId)
        ->and($proxy->getCmsBlocks([$blockId]))->toHaveKey($blockId);
});

it('returns null for a missing entity and remembers the miss', function () {
    $proxy = Mage::getService('page_cache/proxy');
    $missing = 999999999;

    expect($proxy->getProduct($missing))->toBeNull();
    $queries = proxyModelQueryCount(fn() => $proxy->getProduct($missing));

    expect($queries)->toBe(0);
});

it('collects cache tags for loaded entities', function () {
    $id = ProxyModelFixture::$productIds[0];
    $proxy = Mage::getService('page_cache/proxy');
    $proxy->getProduct($id);

    expect($proxy->getTags())->toContain('catalog_product_' . $id);
});

it('ignores invalid proxy references', function () {
    $id = ProxyModelFixture::$productIds[0];
    $proxy = Mage::getService('page_cache/proxy');

    $proxy->preload('product:' . $id . ',product:' . $id . ',unknown:1,product:0,product:-1');
    $queries = proxyModelQueryCount(fn() => $proxy->getProduct($id));

    expect($queries)->toBe(0);
});

it('captures the current product, category and CMS page', function () {
    $product = Mage::getModel('catalog/product')->load(ProxyModelFixture::$productIds[0]);
    $category = Mage::getModel('catalog/category')->load(proxyModelCategoryId());
    $page = Mage::getModel('cms/page')->load(proxyModelCmsPageId());
    Mage::register('current_product', $product);
    Mage::register('current_category', $category);
    Mage::getSingleton('cms/page')->setData($page->getData());

    $context = Mage::getService('page_cache/proxy')->capturePageContext();

    expect($context['product'])->toContain((int) $product->getId())
        ->and($context['category'])->toContain((int) $category->getId())
        ->and($context['cms_page'])->toContain((int) $page->getId());
});

it('restores the page context registries', function () {
    $productId = ProxyModelFixture::$productIds[0];
    $categoryId = proxyModelCategoryId();
    $pageId = proxyModelCmsPageId();
    $proxy = Mage::getService('page_cache/proxy');

    $proxy->restorePageContext([
        'product' => [$productId],
        'category' => [$categoryId],
        'cms_page' => [$pageId],
    ]);

    expect(Mage::registry('current_product'))->toBeInstanceOf(Mage_Catalog_Model_Product::class)
        ->and((int) Mage::registry('current_product')->getId())->toBe($productId)
        ->and(Mage::registry('product'))->toBe(Mage::registry('current_product'))
        ->and(Mage::registry('current_category'))->toBeInstanceOf(Mage_Catalog_Model_Category::class)
        ->and((int) Mage::registry('current_category')->getId())->toBe($categoryId)
        ->and(Mage::registry('cms_page'))->toBeInstanceOf(Mage_Cms_Model_Page::class)
        ->and((int) Mage::getSingleton('cms/page')->getId())->toBe($pageId)
        ->and($proxy->getProduct())->toBe(Mage::registry('current_product'));
});

it('stores proxy entity ids in the local cache entry', function () {
    $id = ProxyModelFixture::$productIds[0];
    $context = proxyModelContext('/proxy-entry.html');
    $cache = new Maho_PageCache_Model_HtmlCache();

    $entry = $cache->save(
        $context,
        '<html>proxy entry</html>',
        ['PAGE_CACHE'],
        3600,
        [],
        ['global' => 1, 'url' => 1],
        [],
        ['product' => [$id]],
    );

    expect($entry)->not->toBeNull()
        ->and($entry['proxy_entities']['product'])->toContain($id)
        ->and($cache->load($context)['proxy_entities']['product'])->toContain($id);
});

it('restores the page context on a local hit with a server block', function () {
    $productId = ProxyModelFixture::$productIds[0];
    $context = proxyModelContext('/proxy-hit.html');
    $block = Mage::app()->getLayout()->createBlock('core/text', 'proxy.hit.block', [
        'text' => 'live proxy content',
        'page_cache_dynamic' => 1,
        'page_cache_dynamic_server' => 1,
    ]);
    $marker = Mage::getService('page_cache/dynamic')->createServerMarker(
        $block,
        '<span>anonymous</span>',
        $context,
        3600,
    );
    $entry = (new Maho_PageCache_Model_HtmlCache())->save(
        $context,
        $marker['html'],
        ['PAGE_CACHE'],
        3600,
        [],
        ['global' => 1, 'url' => 1],
        [$marker['id'] => $marker['definition']],
        ['product' => [$productId]],
    );

    (new Maho_PageCache_Model_Backend_Local())->applyHit($context, $entry, false);

    expect(Mage::app()->getResponse()->getBody())->toContain('live proxy content')
        ->and(Mage::registry('current_product'))->toBeInstanceOf(Mage_Catalog_Model_Product::class)
        ->and((int) Mage::registry('current_product')->getId())->toBe($productId);
});

it('preloads the entities declared by a server block', function () {
    $ids = ProxyModelFixture::$productIds;
    $context = proxyModelContext('/proxy-preload.html');
    $block = Mage::app()->getLayout()->createBlock('core/text', 'proxy.preload.block', [
        'text' => 'preloaded',
        'page_cache_dynamic' => 1,
        'page_cache_dynamic_server' => 1,
        'page_cache_proxies' => implode(',', array_map(static fn(int $id): string => 'product:' . $id, $ids)),
    ]);
    $marker = Mage::getService('page_cache/dynamic')->createServerMarker(
        $block,
        '<span>anonymous</span>',
        $context,
        3600,
    );
    $entry = (new Maho_PageCache_Model_HtmlCache())->save(
        $context,
        $marker['html'],
        ['PAGE_CACHE'],
        3600,
        [],
        ['global' => 1, 'url' => 1],
        [$marker['id'] => $marker['definition']],
    );

    (new Maho_PageCache_Model_Backend_Local())->applyHit($context, $entry, false);

    $proxy = Mage::getService('page_cache/proxy');
    $queries = proxyModelQueryCount(fn() => $proxy->getProduct($ids[0]));

    expect($queries)->toBe(0);
});

it('does not reuse a product instance across store views', function () {
    $id = ProxyModelFixture::$productIds[0];
    $storeId = ProxyModelFixture::createStore();
    $proxy = Mage::getService('page_cache/proxy');
    $originalStore = (int) Mage::app()->getStore()->getId();

    try {
        Mage::app()->setCurrentStore(1);
        $first = $proxy->getProduct($id);
        Mage::app()->setCurrentStore($storeId);
        $second = $proxy->getProduct($id);
    } finally {
        Mage::app()->setCurrentStore($originalStore);
    }

    expect($first)->not->toBe($second)
        ->and((int) $first->getId())->toBe($id)
        ->and((int) $second->getId())->toBe($id);
});
