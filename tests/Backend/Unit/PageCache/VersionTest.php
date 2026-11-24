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

it('starts the global and url versions at one', function () {
    $version = new Maho_PageCache_Model_Version();

    expect($version->getGlobal())->toBe(1)
        ->and($version->getUrl('abc'))->toBe(1);
});

it('increments the global and url versions', function () {
    $version = new Maho_PageCache_Model_Version();

    $global = $version->incrementGlobal();
    $url = $version->incrementUrl('abc');

    expect($global)->toBeGreaterThan(1)
        ->and($version->getGlobal())->toBe($global)
        ->and($url)->toBe(2)
        ->and($version->getUrl('abc'))->toBe(2);
});

it('builds a stable etag and changes it with each dimension', function () {
    $version = new Maho_PageCache_Model_Version();

    $first = $version->getEtag('abc', 'context', 3600, 'revalidating');
    $same = $version->getEtag('abc', 'context', 3600, 'revalidating');

    expect($first)->toBe($same)
        ->and($first)->toStartWith('W/"')
        ->and($version->getEtag('abc', 'other', 3600, 'revalidating'))->not->toBe($first)
        ->and($version->getEtag('abc', 'context', 7200, 'revalidating'))->not->toBe($first)
        ->and($version->getEtag('abc', 'context', 3600, 'proxy'))->not->toBe($first);

    $version->incrementGlobal();
    expect($version->getEtag('abc', 'context', 3600, 'revalidating'))->not->toBe($first);

    $version->incrementUrl('abc');
    expect($version->getEtag('abc', 'context', 3600, 'revalidating'))->not->toBe($version->getEtag('abc', 'other', 3600, 'revalidating'));
});

it('matches weak, strong and comma separated if-none-match values', function () {
    $version = new Maho_PageCache_Model_Version();

    expect($version->isNotModified('W/"abc"', 'W/"abc"'))->toBeTrue()
        ->and($version->isNotModified('"abc"', 'W/"abc"'))->toBeTrue()
        ->and($version->isNotModified('W/"def", W/"abc"', 'W/"abc"'))->toBeTrue()
        ->and($version->isNotModified('W/"def"', 'W/"abc"'))->toBeFalse()
        ->and($version->isNotModified('*', 'W/"abc"'))->toBeTrue()
        ->and($version->isNotModified('', 'W/"abc"'))->toBeFalse();
});

it('stores and reads the last modified timestamp', function () {
    $version = new Maho_PageCache_Model_Version();

    $version->storeEtag('abc', 'W/"abc"', 1234567890);

    expect($version->getLastModified('abc'))->toBe(1234567890)
        ->and($version->getLastModified('missing'))->toBe(0);
});
