<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function monitorStatTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stat');
}

function monitorPendingTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stock_pending');
}

function monitorWarmTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/warmup_queue');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/general/stock_pending_warn_age', '3600');
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(monitorStatTable(), '1=1');
    $write->delete(monitorPendingTable(), '1=1');
    $write->delete(monitorWarmTable(), '1=1');
    Mage::getModel('page_cache/purgeQueue')->clear();
});

afterEach(function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(monitorStatTable(), '1=1');
    $write->delete(monitorPendingTable(), '1=1');
    $write->delete(monitorWarmTable(), '1=1');
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    Mage::getModel('page_cache/purgeQueue')->clear();
});

it('collects stock pending and counter metrics', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(monitorPendingTable(), [
        'product_id' => 1,
        'created_at' => gmdate('Y-m-d H:i:s', time() - 7200),
    ]);
    $write->insertMultiple(monitorStatTable(), [
        [
            'event_type' => 'hit',
            'store_id' => 1,
            'mode' => 'local',
            'hour_bucket' => gmdate('Y-m-d H:00:00'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ],
        [
            'event_type' => 'miss',
            'store_id' => 1,
            'mode' => 'local',
            'hour_bucket' => gmdate('Y-m-d H:00:00'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

    $metrics = Mage::getService('page_cache/monitor')->getMetrics();

    expect($metrics['enabled'])->toBeTrue()
        ->and($metrics['mode'])->toBe('local')
        ->and($metrics['stock_pending']['count'])->toBe(1)
        ->and($metrics['stock_pending']['age'])->toBeGreaterThanOrEqual(7200)
        ->and($metrics['stock_pending_stale'])->toBeTrue()
        ->and($metrics['stats_enabled'])->toBeTrue()
        ->and($metrics['counters']['hit'])->toBe(1)
        ->and($metrics['counters']['miss'])->toBe(1)
        ->and($metrics['purge_queue'])->toBe(0)
        ->and($metrics['warmup_queue'])->toBe(0);
});

it('formats prometheus metrics', function () {
    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();
    $output = $service->formatPrometheus($metrics);

    expect($output)->toContain('# TYPE maho_page_cache_stock_pending_count gauge')
        ->and($output)->toContain('maho_page_cache_stock_pending_count 0')
        ->and($output)->toContain('maho_page_cache_stock_pending_stale 0')
        ->and($output)->toContain('maho_page_cache_enabled 1')
        ->and($output)->toContain('maho_page_cache_info{mode="local"} 1');
});

it('formats json and text metrics', function () {
    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();
    $json = json_decode($service->formatJson($metrics), true);
    $text = $service->formatText($metrics);

    expect($json['mode'])->toBe('local')
        ->and($json['stock_pending']['count'])->toBe(0)
        ->and($text)->toContain('mode=local stock_pending=0')
        ->and($service->isAlerting($metrics))->toBeFalse();
});
