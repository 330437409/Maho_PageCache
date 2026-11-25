<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function warmupQueueTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue');
}

function warmupQueueClean(): void
{
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(warmupQueueTable(), '1=1');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'https://example.com/');
    Mage::getService('page_cache/urlPolicy')->reset();
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/warmup/queue_limit', '100');
    warmupQueueClean();
});

afterEach(function () {
    warmupQueueClean();
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '0');
});

it('enqueues valid absolute urls once', function () {
    $queue = Mage::getModel('page_cache/warmQueue');

    $inserted = $queue->enqueue([
        'https://example.com/a',
        'https://example.com/a',
        'ftp://example.com/b',
        'https://example.com/c',
    ], 1, 'catalog_product_1');

    $rows = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll(
        Mage::getSingleton('core/resource')->getConnection('core_read')
            ->select()
            ->from(warmupQueueTable())
            ->order('queue_id ASC'),
    );

    expect($inserted)->toBe(2)
        ->and($queue->count())->toBe(2)
        ->and(array_column($rows, 'url'))->toBe(['https://example.com/a', 'https://example.com/c'])
        ->and((int) $rows[0]['store_id'])->toBe(1)
        ->and($rows[0]['reason'])->toBe('catalog_product_1');
});

it('rejects urls outside the store origins', function () {
    $queue = Mage::getModel('page_cache/warmQueue');

    $inserted = $queue->enqueue([
        'http://169.254.169.254/latest/meta-data/',
        'https://evil.example/collect',
        'https://example.com/allowed',
    ], 1, 'test');

    expect($inserted)->toBe(1)
        ->and($queue->count())->toBe(1)
        ->and(array_column($queue->peek(10), 'url'))->toBe(['https://example.com/allowed']);
});

it('does not enqueue when warmup is disabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '0');
    $queue = Mage::getModel('page_cache/warmQueue');

    expect($queue->enqueue(['https://example.com/a'], 1, 'test'))->toBe(0)
        ->and($queue->count())->toBe(0);
});

it('keeps one row per url and moves an existing row to now', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/a'], 1, 'first');

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    $write->update(warmupQueueTable(), ['available_at' => $future], '1=1');

    $queue->enqueue(['https://example.com/a'], 1, 'second');

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow($read->select()->from(warmupQueueTable()));

    expect($queue->count())->toBe(1)
        ->and(strtotime((string) $row['available_at']))->toBeLessThanOrEqual(time())
        ->and($row['reason'])->toBe('second');
});

it('drops the oldest rows when the queue reaches its limit', function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/queue_limit', '2');
    $queue = Mage::getModel('page_cache/warmQueue');

    $queue->enqueue([
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
    ], 1, 'test');

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $urls = $read->fetchCol(
        $read->select()->from(warmupQueueTable(), ['url'])->order('queue_id ASC'),
    );

    expect($queue->count())->toBe(2)
        ->and($urls)->toBe(['https://example.com/2', 'https://example.com/3']);
});

it('claims only due rows in queue order', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue([
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
    ], 1, 'test');

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->update(
        warmupQueueTable(),
        ['available_at' => gmdate('Y-m-d H:i:s', time() + 3600)],
        ['url = ?' => 'https://example.com/2'],
    );

    $rows = $queue->claim(10);

    expect(array_column($rows, 'url'))->toBe(['https://example.com/1', 'https://example.com/3']);
});

it('retries a failed row and drops it after the last attempt', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/a'], 1, 'test');
    $row = $queue->claim(1)[0];

    expect($queue->retry((int) $row['queue_id'], 'HTTP 500', 3, 60))->toBe('retried');

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $after = $read->fetchRow($read->select()->from(warmupQueueTable()));

    expect((int) $after['attempts'])->toBe(1)
        ->and($after['last_error'])->toBe('HTTP 500')
        ->and(strtotime((string) $after['available_at']))->toBeGreaterThan(time());

    expect($queue->retry((int) $row['queue_id'], 'HTTP 500', 2, 60))->toBe('dropped')
        ->and($queue->count())->toBe(0);
});

it('peeks at every row regardless of availability', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/due', 'https://example.com/later'], 1, 'test');

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->update(
        warmupQueueTable(),
        ['available_at' => gmdate('Y-m-d H:i:s', time() + 3600)],
        ['url = ?' => 'https://example.com/later'],
    );

    expect(array_column($queue->peek(10), 'url'))->toBe([
        'https://example.com/due',
        'https://example.com/later',
    ])->and(array_column($queue->claim(10), 'url'))->toBe(['https://example.com/due']);
});

it('clears every row', function () {
    $queue = Mage::getModel('page_cache/warmQueue');
    $queue->enqueue(['https://example.com/a', 'https://example.com/b'], 1, 'test');

    $queue->clear();

    expect($queue->count())->toBe(0);
});
