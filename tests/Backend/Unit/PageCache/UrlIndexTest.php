<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function urlIndexContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'public_path' => '/category',
        'mode' => 'local',
    ], $overrides));
}

beforeEach(function () {
    Mage::getModel('page_cache/urlIndex')->clear();
});

afterEach(function () {
    Mage::getModel('page_cache/urlIndex')->clear();
});

it('adds a url and its tags', function () {
    $context = urlIndexContext();
    $index = new Maho_PageCache_Model_UrlIndex();

    $index->add($context, ['catalog_product_1', 'PAGE_CACHE'], 1);

    $rows = $index->getByTag('catalog_product_1');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['url'])->toBe($context->getPurgeUrl())
        ->and($rows[0]['public_path'])->toBe('/category')
        ->and($rows[0]['store_code'])->toBe('default')
        ->and((int) $rows[0]['version'])->toBe(1)
        ->and($rows[0]['urls'])->toBe([$context->getPurgeUrl()])
        ->and($index->getByTag('PAGE_CACHE'))->toBe([]);
});

it('merges url variants and caps the list', function () {
    $context = urlIndexContext();
    $index = new Maho_PageCache_Model_UrlIndex();
    $index->add($context, ['catalog_product_1'], 1);

    for ($i = 1; $i <= 200; $i++) {
        $variant = urlIndexContext(['params' => ['p' => (string) $i]]);
        $index->add($variant, ['catalog_product_1'], 1);
    }

    $rows = $index->getByTag('catalog_product_1');
    $urls = $rows[0]['urls'];

    expect($urls)->toHaveCount(200)
        ->and((int) $rows[0]['variants_truncated'])->toBe(1)
        ->and($rows[0]['url'])->toBe($urls[199]);

    $index->add(urlIndexContext(['params' => ['p' => '201']]), ['catalog_product_1'], 1);
    $rows = $index->getByTag('catalog_product_1');

    expect($rows[0]['urls'])->toHaveCount(200)
        ->and((int) $rows[0]['variants_truncated'])->toBe(1);
});

it('keeps a url while another tag still points to it', function () {
    $context = urlIndexContext();
    $index = new Maho_PageCache_Model_UrlIndex();
    $index->add($context, ['catalog_product_1', 'catalog_category_2'], 1);

    $index->removeTag('catalog_product_1');

    expect($index->getByTag('catalog_product_1'))->toBe([])
        ->and($index->getByTag('catalog_category_2'))->toHaveCount(1);

    $index->removeTag('catalog_category_2');

    expect($index->getByTag('catalog_category_2'))->toBe([]);
});

it('clears the index', function () {
    $index = new Maho_PageCache_Model_UrlIndex();
    $index->add(urlIndexContext(), ['catalog_product_1'], 1);

    $index->clear();

    expect($index->getByTag('catalog_product_1'))->toBe([]);
});

it('cleans up rows older than the cutoff', function () {
    $context = urlIndexContext();
    $index = new Maho_PageCache_Model_UrlIndex();
    $index->add($context, ['catalog_product_1'], 1);
    $resource = Mage::getSingleton('core/resource');
    $resource->getConnection('core_write')->update(
        $resource->getTableName('page_cache/url'),
        ['updated_at' => gmdate('Y-m-d H:i:s', time() - 7200)],
        ['url_hash = ?' => $context->getUrlHash()],
    );

    $deleted = $index->cleanup(3600);

    expect($deleted)->toBe(1)
        ->and($index->getByTag('catalog_product_1'))->toBe([]);
});
