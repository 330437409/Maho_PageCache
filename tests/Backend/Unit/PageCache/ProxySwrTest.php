<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

beforeEach(function () {
    $store = Mage::app()->getStore();
    $store->setConfig('page_cache/proxy/swr', '0');
    Mage::app()->getCache()->cleanType('page_cache');

    $response = Mage::app()->getResponse();
    $response->clearHeaders();
    $response->clearBody();
    $response->setHttpResponseCode(200);
});

afterEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '0');
    Mage::app()->getCache()->cleanType('page_cache');
});

function proxySwrContext(string $mode = 'proxy'): Maho_PageCache_Model_Context
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
        'path' => '/category',
        'mode' => $mode,
    ]);
}

function proxySwrApply(string $mode = 'proxy'): string
{
    (new Maho_PageCache_Service_Response())->apply(proxySwrContext($mode), 3600, [], false);

    foreach (Mage::app()->getResponse()->getHeaders() as $header) {
        if (strcasecmp($header['name'], 'Cache-Control') === 0) {
            return (string) $header['value'];
        }
    }

    return '';
}

it('adds stale-while-revalidate to proxy responses when configured', function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '60');

    $cacheControl = proxySwrApply();

    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('s-maxage=3600')
        ->and($cacheControl)->toContain('max-age=0')
        ->and($cacheControl)->toContain('stale-while-revalidate=60');
});

it('omits stale-while-revalidate when the proxy value is zero', function () {
    expect(proxySwrApply())->not->toContain('stale-while-revalidate');
});

it('parses proxy swr as a bounded integer', function (string $value, int $expected) {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', $value);

    expect(Mage::helper('page_cache')->getProxySwr())->toBe($expected);
})->with([
    'empty' => ['', 0],
    'zero' => ['0', 0],
    'valid' => ['60', 60],
    'negative' => ['-10', 0],
    'text' => ['abc', 0],
    'trailing text' => ['12abc', 0],
    'decimal' => ['1.5', 0],
    'spaces' => [' 30 ', 30],
    'over max' => ['86401', 86400],
]);

it('does not add stale-while-revalidate to local responses', function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '60');

    $cacheControl = proxySwrApply('local');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->not->toContain('stale-while-revalidate');
});

it('does not add stale-while-revalidate to revalidating responses', function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '60');

    $cacheControl = proxySwrApply('revalidating');

    expect($cacheControl)->toContain('must-revalidate')
        ->and($cacheControl)->not->toContain('stale-while-revalidate');
});

it('uses the new proxy value on the next response without a cache flush', function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '30');
    expect(proxySwrApply())->toContain('stale-while-revalidate=30');

    Mage::app()->getResponse()->clearHeaders();
    Mage::app()->getStore()->setConfig('page_cache/proxy/swr', '90');
    expect(proxySwrApply())->toContain('stale-while-revalidate=90');
});
