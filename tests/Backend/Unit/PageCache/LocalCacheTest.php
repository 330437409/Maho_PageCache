<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function localCacheContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'path' => '/local-cache.html',
        'mode' => 'local',
    ], $overrides));
}

function localCacheHeaderValue(Mage_Core_Controller_Response_Http $response, string $name): ?string
{
    foreach ($response->getHeaders() as $header) {
        if (strcasecmp($header['name'], $name) === 0) {
            return $header['value'];
        }
    }
    return null;
}

function localCacheLockName(Maho_PageCache_Model_Context $context, ?array $versions = null): string
{
    return (new Maho_PageCache_Model_HtmlCache())->getLockName($context);
}

function localStatClean(): void
{
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(
        Mage::getSingleton('core/resource')->getTableName('page_cache/stat'),
        '1=1',
    );
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '2097152');
    Mage::app()->getStore()->setConfig('page_cache/general/allowed_params', 'p,limit');
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getSingleton('page_cache/staticCache')->flush();
    localStatClean();

    $response = Mage::app()->getResponse();
    $response->clearHeaders();
    $response->clearBody();
    $response->setHttpResponseCode(200);

    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_local_hit');
    Mage::unregister('page_cache_local_bypass_reason');
    Mage::unregister('page_cache_local_lock_name');
    Mage::unregister('page_cache_local_versions');
    Mage::unregister('page_cache_tags');
    Mage::unregister('page_cache_not_modified');
    Mage::getModel('page_cache/urlIndex')->clear();
});

afterEach(function () {
    $lockName = Mage::registry('page_cache_local_lock_name');
    if (is_string($lockName) && $lockName !== '') {
        Mage::getSingleton('core/lock')->release($lockName);
    }
    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_local_hit');
    Mage::unregister('page_cache_local_bypass_reason');
    Mage::unregister('page_cache_local_lock_name');
    Mage::unregister('page_cache_local_versions');
    Mage::unregister('page_cache_tags');
    Mage::unregister('page_cache_not_modified');
    unset($_GET['p']);
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    localStatClean();
    Mage::app()->getCache()->cleanType('page_cache');
});

it('writes a static file when static export is enabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    Mage::register('page_cache_request_cacheable', true);
    Mage::app()->getResponse()->setBody('<html>static page</html>');

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $context = new Maho_PageCache_Model_Context();
    $path = Mage::getSingleton('page_cache/staticCache')->getFilePath($context);

    expect($path)->not->toBeNull()
        ->and(file_get_contents((string) $path))->toBe('<html>static page</html>');
});

it('does not write a static file for a query parameter', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $_GET['p'] = '2';
    Mage::register('page_cache_request_cacheable', true);
    Mage::app()->getResponse()->setBody('<html>query page</html>');

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $queryless = new Maho_PageCache_Model_Context([
        'website_id' => 1,
        'store_id' => 1,
        'currency' => 'USD',
        'customer_group' => 0,
        'tax_country' => 'US',
        'tax_rate' => 0.0,
        'params' => [],
        'scheme' => 'http',
        'host' => 'localhost',
        'path' => '/',
        'mode' => 'local',
    ]);
    $path = Mage::getSingleton('page_cache/staticCache')->getFilePath($queryless);

    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeFalse();
});

it('removes the static file when the local entry is removed', function () {
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '1');
    $context = new Maho_PageCache_Model_Context();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $versions = $cache->getVersions($context);
    $cache->save($context, '<html>local</html>', [], 3600, [], $versions);
    Mage::getSingleton('page_cache/staticCache')->save($context, '<html>static</html>');
    $path = Mage::getSingleton('page_cache/staticCache')->getFilePath($context);

    $cache->remove($context, $versions);

    expect($path)->not->toBeNull()
        ->and(file_exists((string) $path))->toBeFalse();
});

it('stores and loads a local html entry', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $versions = $cache->getVersions($context);

    $entry = $cache->save(
        $context,
        '<html>cached</html>',
        ['catalog_product_1', 'PAGE_CACHE'],
        3600,
        ['Content-Type' => 'text/html; charset=UTF-8'],
        $versions,
    );

    expect($entry)->not->toBeNull()
        ->and($entry['html'])->toBe('<html>cached</html>')
        ->and($entry['tags'])->toBe(['catalog_product_1', 'PAGE_CACHE'])
        ->and($entry['headers']['Content-Type'])->toBe('text/html; charset=UTF-8')
        ->and($entry['etag'])->toStartWith('W/"')
        ->and($cache->load($context)['html'])->toBe('<html>cached</html>');
});

it('compresses large local html entries', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $versions = $cache->getVersions($context);
    $html = '<html>' . str_repeat('compressible ', 500) . '</html>';

    $entry = $cache->save($context, $html, [], 3600, [], $versions);
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect($entry['html_encoding'])->toBe('gzip')
        ->and($stored['html_encoding'])->toBe('gzip')
        ->and($stored['html'])->not->toBe($html)
        ->and($cache->load($context)['html'])->toBe($html);
});

it('keeps small local html entries uncompressed', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $versions = $cache->getVersions($context);
    $html = '<html>small</html>';

    $entry = $cache->save($context, $html, [], 3600, [], $versions);
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect($entry['html_encoding'])->toBe('plain')
        ->and($stored['html'])->toBe($html);
});

it('indexes the stored context for observation', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $cache->save($context, '<html>indexed</html>', [], 3600, [], $cache->getVersions($context));

    expect($cache->getContexts($context->getUrlHash()))->toContain($context->getHash());
});

it('loads a v2 entry even when the metadata was evicted', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $versions = $cache->getVersions($context);

    $cache->save($context, '<html>old</html>', ['catalog_product_1'], 3600, [], $versions);
    Mage::app()->getCache()->remove($key->getMetaKey($context->getUrlHash()));

    expect($cache->load($context))->not->toBeNull()
        ->and($cache->load($context)['html'])->toBe('<html>old</html>');
});

it('loads a legacy entry when the v2 envelope is absent', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $versions = $cache->getVersions($context);
    $entry = [
        'html' => '<html>legacy</html>',
        'headers' => [],
        'tags' => [],
        'url' => $context->getPurgeUrl(),
        'context' => $context->getHash(),
        'version' => $versions['url'],
        'global_version' => $versions['global'],
        'mode' => 'local',
        'ttl' => 3600,
    ];
    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => [], 'created_at' => time(), 'mode' => 'local', 'ttl' => 3600, 'version' => $versions['url'], 'store_id' => 1],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        3600,
    );
    Mage::app()->getCache()->save(
        $entry,
        $key->getHtmlKey($cache->getHash($context, $versions)),
        ['PAGE_CACHE'],
        3600,
    );

    expect($cache->load($context))->not->toBeNull()
        ->and($cache->load($context)['html'])->toBe('<html>legacy</html>');
});

it('does not serve a v2 entry after the url version changes', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $cache->save($context, '<html>url version</html>', [], 3600, [], $cache->getVersions($context));

    (new Maho_PageCache_Model_Version())->incrementUrl($context->getUrlHash());

    expect($cache->load($context))->toBeNull();
});

it('does not revive html when the url version key was evicted after invalidation', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $urlHash = $context->getUrlHash();
    $versions = $cache->getVersions($context);

    $cache->save($context, '<html>old</html>', ['catalog_product_1'], 3600, [], $versions);
    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);
    Mage::app()->getCache()->remove($key->getVersionUrlKey($urlHash));

    expect($cache->load($context))->toBeNull();
});

it('changes the local key with the url, context, versions and mode', function () {
    $cache = new Maho_PageCache_Model_HtmlCache();
    $context = localCacheContext();

    $base = $cache->getHash($context, ['global' => 1, 'url' => 1]);

    expect($cache->getHash(localCacheContext(['path' => '/other.html']), ['global' => 1, 'url' => 1]))
        ->not->toBe($base)
        ->and($cache->getHash($context, ['global' => 2, 'url' => 1]))->not->toBe($base)
        ->and($cache->getHash($context, ['global' => 1, 'url' => 2]))->not->toBe($base)
        ->and($cache->getHash(localCacheContext(['store_id' => 2]), ['global' => 1, 'url' => 1]))->not->toBe($base)
        ->and($cache->getHash(localCacheContext(['mode' => 'revalidating']), ['global' => 1, 'url' => 1]))->not->toBe($base);
});

it('does not store html above the configured size limit', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '10');
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();

    $entry = $cache->save($context, str_repeat('x', 11), [], 3600, [], $cache->getVersions($context));

    expect($entry)->toBeNull()
        ->and($cache->load($context))->toBeNull();
});

it('stops loading the old html after a tag invalidation', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $versions = $cache->getVersions($context);

    $cache->save($context, '<html>old</html>', ['catalog_product_1'], 3600, [], $versions);

    (new Maho_PageCache_Service_Invalidator())->invalidateTags(['catalog_product_1']);

    expect($cache->load($context))->toBeNull();
});

it('tags local html and meta with the entity cache tag', function () {
    $context = localCacheContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $versions = $cache->getVersions($context);

    expect($cache->save($context, '<html>tagged</html>', ['catalog_product_1', 'PAGE_CACHE'], 3600, [], $versions))
        ->not->toBeNull();

    $htmlKey = $key->getEnvelopeKey($context);
    $metaKey = $key->getMetaKey($context->getUrlHash());
    expect(Mage::app()->getCache()->load($htmlKey))->not->toBeFalse()
        ->and(Mage::app()->getCache()->load($metaKey))->not->toBeFalse();

    Mage::app()->getCache()->clean([$key->getCacheTag('PAGE_CACHE')]);

    expect(Mage::app()->getCache()->load($htmlKey))->not->toBeFalse()
        ->and(Mage::app()->getCache()->load($metaKey))->not->toBeFalse();

    Mage::app()->getCache()->clean([$key->getCacheTag('catalog_product_1')]);

    expect(Mage::app()->getCache()->load($htmlKey))->toBeFalse()
        ->and(Mage::app()->getCache()->load($metaKey))->toBeFalse();
});

it('does not keep a local entry when the url index write fails', function () {
    $cache = new class extends Maho_PageCache_Model_HtmlCache {
        protected function urlIndex(): Maho_PageCache_Model_UrlIndex
        {
            return new class extends Maho_PageCache_Model_UrlIndex {
                #[\Override]
                public function add(Maho_PageCache_Model_Context $context, array $tags, int $version): bool
                {
                    return false;
                }
            };
        }
    };
    $context = localCacheContext();

    $entry = $cache->save(
        $context,
        '<html>index failure</html>',
        ['catalog_product_1'],
        60,
        [],
        $cache->getVersions($context),
    );

    expect($entry)->toBeNull()
        ->and($cache->load($context))->toBeNull();
});

it('serves a local hit before dispatch', function () {
    $context = new Maho_PageCache_Model_Context();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $cache->save(
        $context,
        '<html>local hit</html>',
        ['catalog_product_1', 'PAGE_CACHE'],
        3600,
        ['Content-Type' => 'text/html; charset=UTF-8'],
        $cache->getVersions($context),
    );
    Mage::register('page_cache_request_cacheable', true);

    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};
    (new Maho_PageCache_Model_Observer())->serveLocalHit(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    $response = Mage::app()->getResponse();
    expect($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeTrue()
        ->and($response->getBody())->toBe('<html>local hit</html>')
        ->and($response->getHttpResponseCode())->toBe(200)
        ->and(localCacheHeaderValue($response, 'X-Maho-Cache'))->toBe('HIT')
        ->and(localCacheHeaderValue($response, 'X-Maho-Cache-Mode'))->toBe('local')
        ->and(localCacheHeaderValue($response, 'Cache-Control'))->toContain('private')
        ->and(localCacheHeaderValue($response, 'Cache-Control'))->toContain('max-age=0')
        ->and(localCacheHeaderValue($response, 'Cache-Control'))->toContain('must-revalidate')
        ->and(localCacheHeaderValue($response, 'Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and((new Maho_PageCache_Model_Counter())->getToday()['hit'])->toBe(1);
});

it('returns a local 304 without a body when the etag matches', function () {
    $context = new Maho_PageCache_Model_Context();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $entry = $cache->save($context, '<html>local</html>', [], 3600, [], $cache->getVersions($context));
    Mage::register('page_cache_request_cacheable', true);
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('If-None-Match', (string) $entry['etag']);

    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};
    (new Maho_PageCache_Model_Observer())->serveLocalHit(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    $response = Mage::app()->getResponse();
    expect($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeTrue()
        ->and($response->getHttpResponseCode())->toBe(304)
        ->and($response->getBody())->toBe('')
        ->and(localCacheHeaderValue($response, 'ETag'))->toBe($entry['etag'])
        ->and(localCacheHeaderValue($response, 'X-Maho-Cache'))->toBe('HIT')
        ->and((new Maho_PageCache_Model_Counter())->getToday()['revalidated'])->toBe(1);

    Mage::app()->getRequest()->getSymfonyRequest()->headers->remove('If-None-Match');
});

it('saves the local html entry on a cacheable response', function () {
    Mage::register('page_cache_request_cacheable', true);
    Mage::register('page_cache_tags', ['catalog_product_1' => true]);

    $response = Mage::app()->getResponse();
    $response->setBody('<html>saved later</html>');

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $context = new Maho_PageCache_Model_Context();
    $entry = (new Maho_PageCache_Model_HtmlCache())->load($context);

    expect($entry)->not->toBeNull()
        ->and($entry['html'])->toBe('<html>saved later</html>')
        ->and($entry['tags'])->toContain('catalog_product_1')
        ->and($entry['tags'])->toContain('PAGE_CACHE')
        ->and(localCacheHeaderValue($response, 'X-Maho-Cache'))->toBe('MISS')
        ->and(localCacheHeaderValue($response, 'X-Maho-Cache-Mode'))->toBe('local')
        ->and((new Maho_PageCache_Model_Counter())->getToday()['miss'])->toBe(1);
});

it('does not save a cacheable response above the size limit', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '10');
    Mage::register('page_cache_request_cacheable', true);
    Mage::app()->getResponse()->setBody(str_repeat('x', 11));

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    expect((new Maho_PageCache_Model_HtmlCache())->load(new Maho_PageCache_Model_Context()))->toBeNull()
        ->and(localCacheHeaderValue(Mage::app()->getResponse(), 'X-Maho-Cache'))->toBe('BYPASS')
        ->and(localCacheHeaderValue(Mage::app()->getResponse(), 'X-Maho-Cache-Bypass'))->toBe('html_too_large')
        ->and((new Maho_PageCache_Model_Counter())->getToday()['bypass'])->toBe(1);
});

it('does not save when the version changed during rendering', function () {
    $context = new Maho_PageCache_Model_Context();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $versions = $cache->getVersions($context);

    Mage::register('page_cache_request_cacheable', true);
    Mage::register('page_cache_local_lock_name', localCacheLockName($context, $versions));
    Mage::register('page_cache_local_versions', $versions);
    Mage::getSingleton('core/lock')->acquire((string) Mage::registry('page_cache_local_lock_name'));
    Mage::app()->getResponse()->setBody('<html>stale render</html>');
    (new Maho_PageCache_Model_Version())->incrementGlobal();

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    expect($cache->load($context, $versions))->toBeNull()
        ->and(localCacheHeaderValue(Mage::app()->getResponse(), 'X-Maho-Cache-Bypass'))->toBe('version_changed');
});

it('applies a lock timeout without saving', function () {
    $context = new Maho_PageCache_Model_Context();
    $versions = (new Maho_PageCache_Model_HtmlCache())->getVersions($context);
    $lockName = localCacheLockName($context, $versions);
    Mage::register('page_cache_request_cacheable', true);
    Mage::getSingleton('core/lock')->acquire($lockName);

    $observer = new class extends Maho_PageCache_Model_Observer {
        protected function getLockWaitSeconds(): int
        {
            return 0;
        }
    };
    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};
    $observer->serveLocalHit(new Maho\Event\Observer(['controller_action' => $controller]));

    expect(Mage::registry('page_cache_local_bypass_reason'))->toBe('lock_timeout')
        ->and($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeFalse()
        ->and((new Maho_PageCache_Model_HtmlCache())->load($context))->toBeNull();

    Mage::getSingleton('core/lock')->release($lockName);
});

it('releases the local lock after saving the response', function () {
    $context = new Maho_PageCache_Model_Context();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $versions = $cache->getVersions($context);
    $lockName = localCacheLockName($context, $versions);

    Mage::register('page_cache_request_cacheable', true);
    Mage::register('page_cache_local_lock_name', $lockName);
    Mage::register('page_cache_local_versions', $versions);
    Mage::getSingleton('core/lock')->acquire($lockName);
    Mage::app()->getResponse()->setBody('<html>saved</html>');

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    expect(Mage::getSingleton('core/lock')->isHeld($lockName))->toBeFalse()
        ->and($cache->load($context))->not->toBeNull();
});
