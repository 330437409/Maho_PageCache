<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function tagBudgetContext(): Maho_PageCache_Model_Context
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
        'path' => '/tag-budget.html',
        'mode' => 'proxy',
    ]);
}

/**
 * @param list<string> $tags
 */
function tagBudgetHeader(array $tags): string
{
    $response = Mage::app()->getResponse();
    $response->clearHeaders();
    (new Maho_PageCache_Model_Backend_Proxy())->apply(tagBudgetContext(), 3600, $tags, false);
    foreach ($response->getHeaders() as $header) {
        if (strcasecmp((string) ($header['name'] ?? ''), 'X-Cache-Tags') === 0) {
            return (string) ($header['value'] ?? '');
        }
    }

    return '';
}

beforeEach(function () {
    Mage::app()->getResponse()->clearHeaders();
});

afterEach(function () {
    Mage::app()->getResponse()->clearHeaders();
});

it('leaves tags within the budget unchanged', function () {
    $tags = ['catalog_product_1', 'catalog_category_2', 'cms_page_3'];

    expect(Maho_PageCache_Model_TagBudget::fit($tags))->toBe($tags);
});

it('keeps broad tags first and adds the product overflow tag', function () {
    $tags = [];
    for ($i = 1; $i <= 60; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $fitted = Maho_PageCache_Model_TagBudget::fit($tags);

    expect($fitted)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS)
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT)
        ->and($fitted)->not->toContain($tags[59])
        ->and($fitted[0])->toBe($tags[0]);
});

it('keeps broad tags ahead of product tags', function () {
    $tags = [];
    for ($i = 1; $i <= 40; $i++) {
        $tags[] = 'cms_page_' . $i;
    }
    for ($i = 1; $i <= 40; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $fitted = Maho_PageCache_Model_TagBudget::fit($tags);

    expect($fitted)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS)
        ->and(array_slice($fitted, 0, 40))->toBe(array_slice($tags, 0, 40))
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT)
        ->and($fitted)->not->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW);
});

it('adds both overflow tags when both groups are dropped', function () {
    $tags = [];
    for ($i = 1; $i <= 60; $i++) {
        $tags[] = 'cms_page_' . $i;
    }
    for ($i = 1; $i <= 5; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $fitted = Maho_PageCache_Model_TagBudget::fit($tags);

    expect($fitted)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS)
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW)
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT);
});

it('does not duplicate an existing overflow tag', function () {
    $tags = ['page_cache_overflow_product'];
    for ($i = 1; $i <= 60; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $fitted = Maho_PageCache_Model_TagBudget::fit($tags);

    expect(array_count_values($fitted)[Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT])->toBe(1)
        ->and($fitted)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS);
});

it('respects a smaller budget', function () {
    $fitted = Maho_PageCache_Model_TagBudget::fit([
        'cms_page_1',
        'cms_page_2',
        'cms_page_3',
        'cms_page_4',
        'catalog_product_1',
    ], 3);

    expect($fitted)->toHaveCount(3)
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW)
        ->and($fitted)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT);
});

it('adds overflow tags to a proxy purge payload', function () {
    $tags = Maho_PageCache_Model_TagBudget::withOverflow([
        'catalog_product_1',
        'cms_page_2',
    ]);

    expect($tags)->toBe([
        'catalog_product_1',
        'cms_page_2',
        Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT,
        Maho_PageCache_Model_TagBudget::OVERFLOW,
    ]);
});

it('keeps the proxy header unchanged within the budget', function () {
    expect(tagBudgetHeader(['catalog_product_1', 'catalog_category_2']))
        ->toBe('catalog_product_1,catalog_category_2');
});

it('adds the product overflow tag to an oversized proxy header', function () {
    $tags = [];
    for ($i = 1; $i <= 60; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $header = tagBudgetHeader($tags);
    $headerTags = explode(',', $header);

    expect($headerTags)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS)
        ->and($headerTags)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT)
        ->and($header)->not->toContain('catalog_product_60');
});

it('adds both overflow tags to a mixed oversized proxy header', function () {
    $tags = [];
    for ($i = 1; $i <= 60; $i++) {
        $tags[] = 'cms_page_' . $i;
    }
    for ($i = 1; $i <= 5; $i++) {
        $tags[] = 'catalog_product_' . $i;
    }

    $headerTags = explode(',', tagBudgetHeader($tags));

    expect($headerTags)->toHaveCount(Maho_PageCache_Model_TagBudget::MAX_TAGS)
        ->and($headerTags)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW)
        ->and($headerTags)->toContain(Maho_PageCache_Model_TagBudget::OVERFLOW_PRODUCT);
});
