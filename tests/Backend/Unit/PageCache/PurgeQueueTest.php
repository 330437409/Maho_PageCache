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
    Mage::getModel('page_cache/purgeQueue')->clear();
});

afterEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getModel('page_cache/purgeQueue')->clear();
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

it('survives a cache flush', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    Mage::app()->getCache()->cleanType('page_cache');

    expect($queue->count())->toBe(1);
});

it('claims a row and moves it to delivered on ack', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $id = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    $claimed = $queue->claim('proxy', 10);

    expect($claimed)->toHaveKey($id)
        ->and($queue->getPending())->toBe([])
        ->and($queue->ack($id))->toBeTrue()
        ->and($queue->getStatus()['delivered'])->toBe(1);
});

it('retries a failed row after its backoff', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $id = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $queue->claim('proxy', 10);

    $queue->fail($id, 'proxy unavailable');

    expect($queue->getStatus()['pending'])->toBe(1)
        ->and($queue->claim('proxy', 10))->toBe([]);

    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        Mage::getSingleton('core/resource')->getTableName('page_cache/purge_outbox'),
        ['available_at' => gmdate('Y-m-d H:i:s', time() - 1)],
        ['outbox_id = ?' => (int) $id],
    );

    expect($queue->claim('proxy', 10))->toHaveKey($id);
});

it('moves a row to failed after the maximum attempts and can retry it', function () {
    Mage::app()->getStore()->setConfig('page_cache/purge/max_attempts', '2');
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $id = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    $queue->fail($id, 'first');
    $queue->fail($id, 'second');

    expect($queue->getStatus()['failed'])->toBe(1)
        ->and($queue->retryFailed())->toBe(1)
        ->and($queue->getStatus()['pending'])->toBe(1);
});

it('recovers a processing row after its lease expires', function () {
    Mage::app()->getStore()->setConfig('page_cache/purge/lease_seconds', '60');
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $id = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $queue->claim('proxy', 10);

    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        Mage::getSingleton('core/resource')->getTableName('page_cache/purge_outbox'),
        ['locked_at' => gmdate('Y-m-d H:i:s', time() - 120)],
        ['outbox_id = ?' => (int) $id],
    );

    expect($queue->claim('proxy', 10))->toHaveKey($id);
});

it('deduplicates pending rows with the same target, mode and payload', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $first = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $second = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    expect($second)->toBe($first)
        ->and($queue->count())->toBe(1);
});

it('keeps targets independent', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $proxy = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy', 'proxy');
    $cdn = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy', 'cdn');

    $queue->claim('proxy', 10);
    $queue->fail($proxy, 'proxy down');

    expect($queue->getStatus('proxy')['pending'])->toBe(1)
        ->and($queue->getStatus('cdn')['pending'])->toBe(1)
        ->and($queue->claim('cdn', 10))->toHaveKey($cdn)
        ->and($queue->ack($cdn))->toBeTrue();
});

it('cleans delivered and failed rows after retention', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $id = $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');
    $queue->ack($id);
    $table = Mage::getSingleton('core/resource')->getTableName('page_cache/purge_outbox');
    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        $table,
        ['updated_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)],
        ['outbox_id = ?' => (int) $id],
    );

    expect($queue->cleanup(7))->toBe(1)
        ->and($queue->getStatus()['delivered'])->toBe(0);
});

it('filters by tag before applying the limit', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $queue->enqueue(['https://example.com/a'], ['tag_other'], 'proxy');
    $queue->enqueue(['https://example.com/b'], ['tag_match'], 'proxy');

    $rows = $queue->getPending('proxy', 1, 'tag_match');

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows)[0]['urls'])->toBe(['https://example.com/b']);
});
