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

it('increments the miss, bypass and revalidated counters', function () {
    $counter = new Maho_PageCache_Model_Counter();

    $counter->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_REVALIDATED);
    $counter->increment(Maho_PageCache_Model_Counter::RESULT_HIT);
    $counter->increment('unknown');

    expect($counter->getToday())->toBe([
        'miss' => 2,
        'bypass' => 1,
        'revalidated' => 1,
        'hit' => 1,
    ]);
});
