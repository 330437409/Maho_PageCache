<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function pageCacheHeaderValue(Mage_Core_Controller_Response_Http $response, string $name): ?string
{
    foreach ($response->getHeaders() as $header) {
        if (strcasecmp($header['name'], $name) === 0) {
            return $header['value'];
        }
    }
    return null;
}

function pageCacheResponse(): Mage_Core_Controller_Response_Http
{
    return Mage::app()->getResponse();
}

function pageCacheSaveMeta(Maho_PageCache_Model_Context $context, int $version = 1, int $ttl = 3600): void
{
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        [
            'url' => $context->getPurgeUrl(),
            'tags' => ['PAGE_CACHE'],
            'created_at' => time(),
            'mode' => $context->getMode(),
            'ttl' => $ttl,
            'version' => $version,
        ],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        $ttl + 3600,
    );
}

beforeEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
    $response = pageCacheResponse();
    $response->clearHeaders();
    $response->clearBody();
    $response->setHttpResponseCode(200);
});

afterEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
});

it('sets proxy headers and cache tags', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'proxy']));
    $service = new Maho_PageCache_Service_Response();

    $service->apply($context, 3600, ['catalog_product_1'], true);

    $response = pageCacheResponse();
    $cacheControl = (string) pageCacheHeaderValue($response, 'Cache-Control');
    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('s-maxage=3600')
        ->and($cacheControl)->toContain('max-age=0')
        ->and(pageCacheHeaderValue($response, 'Vary'))->toBe('Accept-Encoding, Cookie')
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache'))->toBe('MISS')
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache-Mode'))->toBe('proxy')
        ->and(pageCacheHeaderValue($response, 'X-Cache-Tags'))->toContain('catalog_product_1');
});

it('sets revalidating headers with a weak etag', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();

    $service->apply($context, 3600, ['catalog_product_1'], false);

    $response = pageCacheResponse();
    $cacheControl = (string) pageCacheHeaderValue($response, 'Cache-Control');
    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('max-age=0')
        ->and($cacheControl)->toContain('must-revalidate')
        ->and(pageCacheHeaderValue($response, 'ETag'))->toStartWith('W/"')
        ->and(pageCacheHeaderValue($response, 'Vary'))->toBe('Accept-Encoding, Cookie')
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache'))->toBe('MISS')
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache-Mode'))->toBe('revalidating')
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache-Tags'))->toBeNull()
        ->and(pageCacheHeaderValue($response, 'X-Maho-Cache-Version'))->toBeNull();
});

it('sets different context headers for different stores', function () {
    $service = new Maho_PageCache_Service_Response();
    $first = new Maho_PageCache_Model_Context(pageCacheContextData(['store_id' => 1, 'mode' => 'proxy']));
    $second = new Maho_PageCache_Model_Context(pageCacheContextData(['store_id' => 2, 'mode' => 'proxy']));

    $service->apply($first, 3600, [], false);
    $firstContext = pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Context');

    pageCacheResponse()->clearHeaders();
    $service->apply($second, 3600, [], false);
    $secondContext = pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Context');

    expect($firstContext)->not->toBe($secondContext)
        ->and(pageCacheHeaderValue(pageCacheResponse(), 'Vary'))->toContain('Cookie');
});

it('omits debug tag headers when debug is off and includes them when on', function () {
    $service = new Maho_PageCache_Service_Response();
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'proxy']));

    $service->apply($context, 3600, ['catalog_product_1'], false);
    expect(pageCacheHeaderValue(pageCacheResponse(), 'X-Cache-Tags'))->toContain('catalog_product_1')
        ->and(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Tags'))->toBeNull();

    pageCacheResponse()->clearHeaders();
    $service->apply($context, 3600, ['catalog_product_1'], true);
    expect(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Tags'))->toContain('catalog_product_1')
        ->and(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Version'))->not->toBeNull();
});

it('filters invalid tag header characters', function () {
    $service = new Maho_PageCache_Service_Response();
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'proxy']));

    $service->apply($context, 3600, ["catalog_product_1\nX-Injected: 1"], false);

    $tags = (string) pageCacheHeaderValue(pageCacheResponse(), 'X-Cache-Tags');
    expect($tags)->not->toContain("\n")
        ->and($tags)->not->toContain(' ');
});

it('sets bypass headers with the reason code', function () {
    $service = new Maho_PageCache_Service_Response();

    $service->applyBypass('session_cookie', false);

    expect(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache'))->toBe('BYPASS')
        ->and(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache-Bypass'))->toBe('session_cookie');
});

it('returns 304 when the etag still matches', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();

    pageCacheSaveMeta($context);
    $service->apply($context, 3600, [], false);
    $etag = (string) pageCacheHeaderValue(pageCacheResponse(), 'ETag');

    expect($service->isNotModified($context, 3600, $etag, ''))->toBeTrue();

    $service->applyNotModified($context, $etag, time(), false);
    expect(pageCacheResponse()->getHttpResponseCode())->toBe(304)
        ->and(pageCacheResponse()->getBody())->toBe('')
        ->and(pageCacheHeaderValue(pageCacheResponse(), 'X-Maho-Cache'))->toBe('REVALIDATED');
});

it('does not return 304 when the stored url version changed', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();
    $version = new Maho_PageCache_Model_Version();

    pageCacheSaveMeta($context, 1);
    $service->apply($context, 3600, [], false);
    $etag = (string) pageCacheHeaderValue(pageCacheResponse(), 'ETag');

    pageCacheSaveMeta($context, 2);
    $version->setUrl($context->getUrlHash(), 2);

    expect($service->isNotModified($context, 3600, $etag, ''))->toBeFalse();
});

it('does not return 304 when the url version key is evicted after invalidation', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();
    $key = new Maho_PageCache_Model_Key();

    pageCacheSaveMeta($context, 1);
    $service->apply($context, 3600, [], false);
    $oldEtag = (string) pageCacheHeaderValue(pageCacheResponse(), 'ETag');

    pageCacheSaveMeta($context, 2);
    Mage::app()->getCache()->remove($key->getVersionUrlKey($context->getUrlHash()));

    expect($service->isNotModified($context, 3600, $oldEtag, ''))->toBeFalse();
});

it('does not return 304 without page metadata', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();

    $service->apply($context, 3600, [], false);
    $etag = (string) pageCacheHeaderValue(pageCacheResponse(), 'ETag');

    expect($service->isNotModified($context, 3600, $etag, ''))->toBeFalse();
});

it('gives If-None-Match priority over If-Modified-Since', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();

    pageCacheSaveMeta($context);
    $service->apply($context, 3600, [], false);
    $lastModified = $service->getLastModified($context);

    expect($service->isNotModified($context, 3600, 'W/"not-the-current-etag"', gmdate('D, d M Y H:i:s', $lastModified + 60) . ' GMT'))->toBeFalse();
});

it('uses If-Modified-Since when If-None-Match is absent', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData(['mode' => 'revalidating']));
    $service = new Maho_PageCache_Service_Response();

    pageCacheSaveMeta($context);
    $service->apply($context, 3600, [], false);
    $lastModified = $service->getLastModified($context);

    expect($service->isNotModified($context, 3600, '', gmdate('D, d M Y H:i:s', $lastModified + 60) . ' GMT'))->toBeTrue()
        ->and($service->isNotModified($context, 3600, '', gmdate('D, d M Y H:i:s', $lastModified - 60) . ' GMT'))->toBeFalse();
});
