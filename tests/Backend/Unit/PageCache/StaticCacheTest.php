<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function staticCacheContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'path' => '/category',
        'mode' => 'local',
    ], $overrides));
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getSingleton('page_cache/staticCache')->flush();
});

afterEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::app()->getCache()->cleanType('page_cache');
});

it('maps page urls to static file paths', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $root = $cache->getRoot() . DS . 'default' . DS;

    expect($cache->getFilePath(staticCacheContext(['path' => '/'])))->toBe($root . 'index.html')
        ->and($cache->getFilePath(staticCacheContext(['path' => '/category'])))->toBe($root . 'category' . DS . 'index.html')
        ->and($cache->getFilePath(staticCacheContext(['path' => '/category/'])))->toBe($root . 'category' . DS . 'index.html')
        ->and($cache->getFilePath(staticCacheContext(['path' => '/product.html'])))->toBe($root . 'product.html')
        ->and($cache->getFilePath(staticCacheContext(['path' => '/cms/page'])))->toBe($root . 'cms' . DS . 'page' . DS . 'index.html');
});

it('does not export urls with query parameters', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext(['params' => ['p' => '2']]);

    expect($cache->getFilePath($context))->toBeNull()
        ->and($cache->save($context, '<html>category</html>'))->toBeFalse();
});

it('rejects unsafe paths', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $paths = [
        '/../secret',
        '/a/./b',
        '/a//b',
        '/café',
        '/a%2Fb',
        '/' . str_repeat('a', 1100),
    ];

    foreach ($paths as $path) {
        expect($cache->getFilePath(staticCacheContext(['path' => $path])))->toBeNull();
    }
});

it('writes plain and gzip files atomically', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext();
    $path = $cache->getFilePath($context);

    expect($cache->save($context, '<html>category</html>'))->toBeTrue()
        ->and($path)->not->toBeNull()
        ->and(file_get_contents($path))->toBe('<html>category</html>')
        ->and(is_file($path . '.gz'))->toBeTrue()
        ->and(gzdecode((string) file_get_contents($path . '.gz')))->toBe('<html>category</html>')
        ->and(glob(dirname($path) . '/*.tmp'))->toBe([]);
});

it('does not write when static export is disabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext();

    expect($cache->save($context, '<html>category</html>'))->toBeFalse()
        ->and(file_exists((string) $cache->getFilePath($context)))->toBeFalse();
});

it('removes a static file by url', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext();
    $path = (string) $cache->getFilePath($context);
    $cache->save($context, '<html>category</html>');

    $cache->removeByUrl('https://example.com/category', 'default');

    expect(file_exists($path))->toBeFalse()
        ->and(file_exists($path . '.gz'))->toBeFalse();
});

it('removes the default static file for a query variant', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext();
    $path = (string) $cache->getFilePath($context);
    $cache->save($context, '<html>category</html>');

    $cache->removeByUrl('https://example.com/category?p=2', 'default');

    expect(file_exists($path))->toBeFalse();
});

it('removes a static file for a store-code url', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext(['path' => '/default/category', 'public_path' => '/category']);
    $path = (string) $cache->getFilePath($context);
    $cache->save($context, '<html>category</html>');

    $cache->removeByUrl('https://example.com/default/category?p=2', 'default');

    expect(file_exists($path))->toBeFalse();
});

it('does not remove a static file without a store code', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $context = staticCacheContext();
    $path = (string) $cache->getFilePath($context);
    $cache->save($context, '<html>category</html>');

    $cache->removeByUrl('https://example.com/category', '');

    expect(file_exists($path))->toBeTrue();
});

it('flushes every static file but keeps the root', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $cache->save(staticCacheContext(['path' => '/']), '<html>home</html>');
    $cache->save(staticCacheContext(['path' => '/category']), '<html>category</html>');

    $cache->flush();

    expect(is_dir($cache->getRoot()))->toBeTrue()
        ->and(glob($cache->getRoot() . '/*'))->toBe([]);
});

it('cleans files older than the max age and keeps fresh files', function () {
    $cache = Mage::getSingleton('page_cache/staticCache');
    $oldContext = staticCacheContext(['path' => '/old']);
    $freshContext = staticCacheContext(['path' => '/fresh']);
    $oldPath = (string) $cache->getFilePath($oldContext);
    $freshPath = (string) $cache->getFilePath($freshContext);

    $cache->save($oldContext, '<html>old</html>');
    $cache->save($freshContext, '<html>fresh</html>');
    touch($oldPath, time() - 4000);
    touch($oldPath . '.gz', time() - 4000);

    expect($cache->cleanup(3600))->toBeGreaterThan(0)
        ->and(file_exists($oldPath))->toBeFalse()
        ->and(file_exists($freshPath))->toBeTrue();
});
