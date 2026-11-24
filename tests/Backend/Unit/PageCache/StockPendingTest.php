<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function stockPendingTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stock_pending');
}

beforeEach(function () {
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(stockPendingTable(), '1=1');
});

afterEach(function () {
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(stockPendingTable(), '1=1');
});

it('reports an empty pending queue', function () {
    $summary = Mage::getService('page_cache/stockPending')->getSummary();

    expect($summary)->toBe(['count' => 0, 'oldest_at' => null, 'age' => null]);
});

it('reports the count and oldest pending age', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insertMultiple(stockPendingTable(), [
        ['product_id' => 1, 'created_at' => gmdate('Y-m-d H:i:s', time() - 7200)],
        ['product_id' => 2, 'created_at' => gmdate('Y-m-d H:i:s', time() - 60)],
    ]);
    $summary = Mage::getService('page_cache/stockPending')->getSummary();

    expect($summary['count'])->toBe(2)
        ->and($summary['oldest_at'])->not->toBeNull()
        ->and($summary['age'])->toBeGreaterThanOrEqual(7200);
});

it('decides whether the pending queue is stale', function () {
    $service = Mage::getService('page_cache/stockPending');
    $summary = ['count' => 2, 'oldest_at' => gmdate('Y-m-d H:i:s', time() - 7200), 'age' => 7200];

    expect($service->isStale($summary, 3600))->toBeTrue()
        ->and($service->isStale($summary, 10000))->toBeFalse()
        ->and($service->isStale($summary, 0))->toBeFalse()
        ->and($service->isStale(['count' => 0, 'oldest_at' => null, 'age' => null], 1))->toBeFalse();
});
