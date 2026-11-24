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
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'revalidating');
    Mage::app()->getCache()->cleanType('page_cache');
});

afterEach(function () {
    $request = Mage::app()->getRequest();
    $request->getSymfonyRequest()->headers->remove('If-None-Match');
    Mage::unregister('page_cache_request_cacheable');
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getModel('page_cache/urlIndex')->clear();
});

function observerStaticContext(string $path = '/category'): Maho_PageCache_Model_Context
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

it('invalidates imported products when the import finishes', function () {
    $context = observerStaticContext('/imported-product.html');
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_123'], 1);
    $adapter = new class {
        /** @return list<int> */
        public function getAffectedEntityIds(): array
        {
            return [123];
        }
    };

    (new Maho_PageCache_Model_Observer())->invalidateImportedProducts(
        new Maho\Event\Observer(['adapter' => $adapter]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($context->getUrlHash()))->toBe(2)
        ->and(Mage::getModel('page_cache/urlIndex')->getByTag('catalog_product_123'))->toBe([]);
});

it('invalidates affected products after catalog rules are applied', function () {
    $context = observerStaticContext('/rule-product.html');
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_123'], 1);

    (new Maho_PageCache_Model_Observer())->invalidateAfterCatalogRuleApply(
        new Maho\Event\Observer(['product' => 123]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($context->getUrlHash()))->toBe(2)
        ->and(Mage::getModel('page_cache/urlIndex')->getByTag('catalog_product_123'))->toBe([]);
});

it('invalidates products from the affected product condition after a catalog rule apply', function () {
    $context = observerStaticContext('/rule-condition.html');
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_1'], 1);
    $condition = new class implements Mage_Catalog_Model_Product_Condition_Interface {
        public function applyToCollection($collection)
        {
            return $this;
        }

        public function getIdsSelect($dbAdapter)
        {
            return $dbAdapter->select()
                ->from(Mage::getSingleton('core/resource')->getTableName('core/store'), ['store_id'])
                ->where('store_id = ?', 1);
        }
    };

    (new Maho_PageCache_Model_Observer())->invalidateAfterCatalogRuleApply(
        new Maho\Event\Observer(['product_condition' => $condition]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($context->getUrlHash()))->toBe(2)
        ->and(Mage::getModel('page_cache/urlIndex')->getByTag('catalog_product_1'))->toBe([]);
});

it('invalidates stock pages again after the stock index is processed', function () {
    $context = observerStaticContext('/stock-product.html');
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_1'], 1);
    $item = Mage::getModel('cataloginventory/stock_item');
    $item->setProductId(1);
    $item->setOrigData('is_in_stock', 1);
    $item->setData('is_in_stock', 0);
    $observer = new Maho_PageCache_Model_Observer();
    $observer->invalidateOnStockSave(new Maho\Event\Observer(['item' => $item]));
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_1'], 2);

    $observer->flushPendingStockInvalidation(new Maho\Event\Observer([]));

    expect((new Maho_PageCache_Model_Version())->getUrl($context->getUrlHash()))->toBe(3)
        ->and(Mage::getModel('page_cache/urlIndex')->getByTag('catalog_product_1'))->toBe([]);
});

it('invalidates deleted products when a delete import finishes', function () {
    $context = observerStaticContext('/deleted-product.html');
    Mage::getModel('page_cache/urlIndex')->add($context, ['catalog_product_123'], 1);
    $adapter = new class {
        /** @return list<int> */
        public function getAffectedEntityIds(): array
        {
            return [];
        }

        public function getBehavior(): string
        {
            return Mage_ImportExport_Model_Import::BEHAVIOR_DELETE;
        }

        /** @return array<string, array{entity_id: int}> */
        public function getOldSku(): array
        {
            return ['PC-TEST-1' => ['entity_id' => 123]];
        }
    };

    (new Maho_PageCache_Model_Observer())->invalidateImportedProducts(
        new Maho\Event\Observer(['adapter' => $adapter]),
    );

    expect((new Maho_PageCache_Model_Version())->getUrl($context->getUrlHash()))->toBe(2)
        ->and(Mage::getModel('page_cache/urlIndex')->getByTag('catalog_product_123'))->toBe([]);
});

it('clears static files when the page cache type is refreshed', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $context = observerStaticContext();
    $cache = Mage::getSingleton('page_cache/staticCache');
    $cache->save($context, '<html>category</html>');
    $path = $cache->getFilePath($context);

    (new Maho_PageCache_Model_Observer())->flushOnTypeRefresh(
        new Maho\Event\Observer(['type' => 'page_cache']),
    );

    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeFalse();
});

it('keeps static files when another cache type is refreshed', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $context = observerStaticContext();
    $cache = Mage::getSingleton('page_cache/staticCache');
    $cache->save($context, '<html>category</html>');
    $path = $cache->getFilePath($context);

    (new Maho_PageCache_Model_Observer())->flushOnTypeRefresh(
        new Maho\Event\Observer(['type' => 'layout']),
    );

    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeTrue();
});

it('cleans static files older than the ttl', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/ttl', '3600');
    $context = observerStaticContext();
    $cache = Mage::getSingleton('page_cache/staticCache');
    $cache->save($context, '<html>category</html>');
    $path = (string) $cache->getFilePath($context);
    touch($path, time() - 4000);
    touch($path . '.gz', time() - 4000);

    (new Maho_PageCache_Model_Observer())->cleanupStaticCache();

    expect(file_exists($path))->toBeFalse();
});

it('invalidates every page after a configuration section save', function () {
    $version = new Maho_PageCache_Model_Version();
    $before = $version->getGlobal();

    (new Maho_PageCache_Model_Observer())->invalidateOnConfigSave(new Maho\Event\Observer([]));

    expect($version->getGlobal())->toBeGreaterThan($before);
});

it('sets the no-dispatch flag before layout generation for a matching etag', function () {
    $request = Mage::app()->getRequest();
    $context = new Maho_PageCache_Model_Context();
    $key = new Maho_PageCache_Model_Key();
    $responseService = new Maho_PageCache_Service_Response();

    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'tags' => ['PAGE_CACHE'],
            'created_at' => time(),
            'mode' => 'revalidating',
            'ttl' => 3600,
            'version' => 1,
        ],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        3600,
    );

    $etag = $responseService->getEtag($context, 3600, 1);
    $request->getSymfonyRequest()->headers->set('If-None-Match', $etag);
    Mage::register('page_cache_request_cacheable', true);

    $controller = new class ($request, Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};

    (new Maho_PageCache_Model_Observer())->checkNotModified(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    expect($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeTrue()
        ->and(Mage::app()->getResponse()->getHttpResponseCode())->toBe(304)
        ->and(Mage::app()->getResponse()->getBody())->toBe('');
});
