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

it('enqueues and reads purge entries', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();

    $id = $queue->enqueue(['https://example.com/a'], ['catalog_product_1'], 'proxy');

    expect($id)->not->toBe('')
        ->and($queue->count())->toBe(1);

    $entries = $queue->getEntries();
    expect($entries)->toHaveKey($id)
        ->and($entries[$id]['urls'])->toBe(['https://example.com/a'])
        ->and($entries[$id]['tags'])->toBe(['catalog_product_1'])
        ->and($entries[$id]['mode'])->toBe('proxy');
});

it('keeps multiple entries and removes them by id', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();

    $first = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $second = $queue->enqueue(['https://example.com/b'], ['tag_b'], 'proxy');

    expect($queue->count())->toBe(2);

    $queue->remove($first);

    expect($queue->count())->toBe(1)
        ->and($queue->getEntries())->toHaveKey($second)
        ->and($queue->getEntries())->not->toHaveKey($first);
});

it('clears the queue', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();

    $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $queue->clear();

    expect($queue->count())->toBe(0)
        ->and($queue->getEntries())->toBe([]);
});

it('enqueues a full purge marker', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();

    $id = $queue->enqueueFullPurge();

    $entries = $queue->getEntries();
    expect($entries[$id]['urls'])->toBe(['*'])
        ->and($entries[$id]['tags'])->toBe(['*']);
});
