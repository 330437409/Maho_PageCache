<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function compressionContext(string $path = '/compression.html'): Maho_PageCache_Model_Context
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

function compressionHtml(int $repeat = 500): string
{
    return '<html>' . str_repeat('compressible content ', $repeat) . '</html>';
}

class CompressionFailingHtmlCache extends Maho_PageCache_Model_HtmlCache
{
    #[\Override]
    protected function compress(string $html): string|false
    {
        return false;
    }
}

beforeEach(function () {
    $store = Mage::app()->getStore();
    $store->setConfig('page_cache/general/enabled', '1');
    $store->setConfig('page_cache/general/mode', 'local');
    $store->setConfig('page_cache/general/html_max_size', '2097152');
    $store->setConfig('page_cache/local/compression_enabled', '1');
    $store->setConfig('page_cache/local/compression_min_bytes', '4096');
    $store->setConfig('page_cache/local/compression_level', '6');
    $store->setConfig('page_cache/stats/enabled', '0');
    Mage::app()->getCache()->cleanType('page_cache');
});

afterEach(function () {
    $store = Mage::app()->getStore();
    $store->setConfig('page_cache/local/compression_enabled', '1');
    $store->setConfig('page_cache/local/compression_min_bytes', '4096');
    $store->setConfig('page_cache/local/compression_level', '6');
    $store->setConfig('page_cache/general/html_max_size', '2097152');
    Mage::app()->getCache()->cleanType('page_cache');
});

it('compresses a large local page with the configured level', function () {
    $context = compressionContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $html = compressionHtml();

    $cache->save($context, $html, [], 3600, [], $cache->getVersions($context));
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect($stored['html_encoding'])->toBe('gzip')
        ->and(strlen($stored['html']))->toBeLessThan(strlen($html))
        ->and($cache->load($context)['html'])->toBe($html);
});

it('stores plain html when compression is disabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/local/compression_enabled', '0');
    $context = compressionContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $html = compressionHtml();

    $cache->save($context, $html, [], 3600, [], $cache->getVersions($context));
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect($stored['html_encoding'])->toBe('plain')
        ->and($stored['html'])->toBe($html)
        ->and($cache->load($context)['html'])->toBe($html);
});

it('keeps html below the configured minimum plain', function () {
    Mage::app()->getStore()->setConfig('page_cache/local/compression_min_bytes', '100000');
    $context = compressionContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $html = compressionHtml();

    $cache->save($context, $html, [], 3600, [], $cache->getVersions($context));
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect(strlen($html))->toBeLessThan(100000)
        ->and($stored['html_encoding'])->toBe('plain')
        ->and($stored['html'])->toBe($html);
});

it('uses the configured gzip level', function () {
    $cache = new Maho_PageCache_Model_HtmlCache();
    $html = compressionHtml(20000);
    $store = Mage::app()->getStore();

    $key = new Maho_PageCache_Model_Key();

    $store->setConfig('page_cache/local/compression_level', '1');
    $fastContext = compressionContext('/compression-fast.html');
    $cache->save($fastContext, $html, [], 3600, [], $cache->getVersions($fastContext));
    $fast = Mage::app()->getCache()->load($key->getEnvelopeKey($fastContext));

    $store->setConfig('page_cache/local/compression_level', '9');
    $smallContext = compressionContext('/compression-small.html');
    $cache->save($smallContext, $html, [], 3600, [], $cache->getVersions($smallContext));
    $small = Mage::app()->getCache()->load($key->getEnvelopeKey($smallContext));

    expect($fast['html_encoding'])->toBe('gzip')
        ->and($small['html_encoding'])->toBe('gzip')
        ->and(strlen($small['html']))->toBeLessThanOrEqual(strlen($fast['html']));
});

it('bypasses an oversized page before compressing it', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '1000');
    $context = compressionContext();
    $cache = new Maho_PageCache_Model_HtmlCache();

    expect($cache->save($context, compressionHtml(), [], 3600, [], $cache->getVersions($context)))
        ->toBeNull()
        ->and($cache->load($context))->toBeNull();
});

it('falls back to plain html when compression fails', function () {
    $context = compressionContext();
    $cache = new CompressionFailingHtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $html = compressionHtml();

    $cache->save($context, $html, [], 3600, [], $cache->getVersions($context));
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));

    expect($stored['html_encoding'])->toBe('plain')
        ->and($stored['html'])->toBe($html)
        ->and($cache->load($context)['html'])->toBe($html);
});

it('returns null when stored gzip cannot be decoded', function () {
    $context = compressionContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $key = new Maho_PageCache_Model_Key();
    $html = compressionHtml();

    $cache->save($context, $html, [], 3600, [], $cache->getVersions($context));
    $stored = Mage::app()->getCache()->load($key->getEnvelopeKey($context));
    $stored['html'] = 'not gzip';
    $stored['html_encoding'] = 'gzip';
    Mage::app()->getCache()->save($stored, $key->getEnvelopeKey($context), ['PAGE_CACHE'], 3600);

    expect($cache->load($context))->toBeNull();
});

it('clamps invalid compression settings to safe defaults', function () {
    $store = Mage::app()->getStore();

    $store->setConfig('page_cache/local/compression_min_bytes', '0');
    $store->setConfig('page_cache/local/compression_level', '0');
    expect(Mage::helper('page_cache')->getCompressionMinBytes())->toBe(4096)
        ->and(Mage::helper('page_cache')->getCompressionLevel())->toBe(6);

    $store->setConfig('page_cache/local/compression_level', '99');
    expect(Mage::helper('page_cache')->getCompressionLevel())->toBe(6);
});
