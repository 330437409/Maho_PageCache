<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function pageCacheContextData(array $overrides = []): array
{
    return array_merge([
        'website_id' => 1,
        'store_id' => 1,
        'currency' => 'USD',
        'customer_group' => 0,
        'tax_country' => 'US',
        'tax_rate' => 0.0,
        'params' => ['p' => '2', 'limit' => '12'],
        'scheme' => 'https',
        'host' => 'example.com',
        'path' => '/category.html',
        'mode' => 'revalidating',
    ], $overrides);
}

it('builds a stable context hash from the storefront dimensions', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData());
    $data = $context->getContext();

    expect($data['params'])->toBe(['limit' => '12', 'p' => '2'])
        ->and($context->getUrl())->toBe('https://example.com/category.html')
        ->and($context->getPurgeUrl())->toBe('https://example.com/category.html?limit=12&p=2')
        ->and($context->getHash())->toHaveLength(64);
});

it('changes the hash when a cache dimension changes', function () {
    $first = new Maho_PageCache_Model_Context(pageCacheContextData());
    $second = new Maho_PageCache_Model_Context(pageCacheContextData(['currency' => 'EUR']));

    expect($first->getHash())->not->toBe($second->getHash());
});

it('uses the original public path after a route rewrite', function () {
    $request = Mage::app()->getRequest();
    $setOriginalPath = Closure::bind(
        function (string $path): void {
            $this->_pathInfo = $path;
            $this->_originalPathInfo = $path;
        },
        $request,
        $request::class,
    );
    $setOriginalPath('/page-cache-test/');
    $request->rewritePathInfo('catalog/category/view/id/4');

    $context = new Maho_PageCache_Model_Context();

    expect($context->getPath())->toBe('/page-cache-test/')
        ->and($context->getUrl())->toEndWith('/page-cache-test/');
});

it('does not include the query string in the page url', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData([
        'params' => [],
    ]));

    expect($context->getUrl())->toBe('https://example.com/category.html')
        ->and($context->getPurgeUrl())->toBe('https://example.com/category.html');
});

it('drops query parameters that are not in the allowlist', function () {
    $context = new Maho_PageCache_Model_Context(pageCacheContextData([
        'params' => ['p' => '2', 'utm_source' => 'newsletter'],
    ]));

    expect($context->getParams())->toBe(['p' => '2'])
        ->and($context->getPurgeUrl())->toBe('https://example.com/category.html?p=2');
});
