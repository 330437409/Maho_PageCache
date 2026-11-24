<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function counterStatTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stat');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(counterStatTable(), '1=1');
});

afterEach(function () {
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(counterStatTable(), '1=1');
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    Mage::app()->getCache()->cleanType('page_cache');
});

it('increments the miss, bypass and revalidated counters', function () {
    $counter = new Maho_PageCache_Model_Counter();

    $counter->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_REVALIDATED);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_HIT);
    $counter->increment('unknown');

    expect($counter->getToday())->toBe([
        'hit' => 1,
        'miss' => 2,
        'bypass' => 1,
        'revalidated' => 1,
    ]);
});
