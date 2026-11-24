<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

beforeEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
});

afterEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
});

it('adds urls to a tag and reads them back', function () {
    $index = new Maho_PageCache_Model_TagIndex();

    $index->add('hash1', ['catalog_product_1', 'PAGE_CACHE']);
    $index->add('hash2', ['catalog_product_1']);

    expect($index->getUrls('catalog_product_1'))->toBe(['hash1', 'hash2']);
});

it('returns false for a missing tag index', function () {
    $index = new Maho_PageCache_Model_TagIndex();

    expect($index->getUrls('catalog_product_1'))->toBeFalse();
});

it('removes a tag index', function () {
    $index = new Maho_PageCache_Model_TagIndex();

    $index->add('hash1', ['catalog_product_1']);
    $index->remove('catalog_product_1');

    expect($index->getUrls('catalog_product_1'))->toBeFalse();
});

it('flushes every tag index', function () {
    $index = new Maho_PageCache_Model_TagIndex();

    $index->add('hash1', ['catalog_product_1']);
    $index->flush();

    expect($index->getUrls('catalog_product_1'))->toBeFalse();
});

it('treats a missing registry as potentially lost', function () {
    $index = new Maho_PageCache_Model_TagIndex();

    Mage::app()->getCache()->cleanType('page_cache');

    expect($index->isKnown('catalog_product_1'))->toBeTrue();
});
