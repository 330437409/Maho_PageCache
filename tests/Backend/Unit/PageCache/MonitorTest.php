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

function monitorAuditTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/invalidation_log');
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
    $write->delete(monitorAuditTable(), '1=1');
    Mage::getModel('page_cache/purgeQueue')->clear();
});

afterEach(function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(monitorStatTable(), '1=1');
    $write->delete(monitorPendingTable(), '1=1');
    $write->delete(monitorWarmTable(), '1=1');
    $write->delete(monitorAuditTable(), '1=1');
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/purge/max_attempts', '5');
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
        ->and($metrics['purge_failed'])->toBe(0)
        ->and($metrics['warmup_queue'])->toBe(0)
        ->and($metrics['warmup_due'])->toBe(0)
        ->and($metrics['warmup_lock'])->toBeFalse()
        ->and($metrics['invalidation_24h'])->toBe(0);
});

it('formats prometheus metrics', function () {
    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();
    $output = $service->formatPrometheus($metrics);

    expect($output)->toContain('# TYPE maho_page_cache_stock_pending_count gauge')
        ->and($output)->toContain('maho_page_cache_stock_pending_count 0')
        ->and($output)->toContain('maho_page_cache_stock_pending_stale 0')
        ->and($output)->toContain('maho_page_cache_enabled 1')
        ->and($output)->toContain('maho_page_cache_info{mode="local"} 1')
        ->and($output)->toContain('maho_page_cache_purge_failed 0')
        ->and($output)->toContain('maho_page_cache_purge_stale 0')
        ->and($output)->toContain('maho_page_cache_warmup_due 0')
        ->and($output)->toContain('maho_page_cache_warmup_lock 0')
        ->and($output)->toContain('maho_page_cache_invalidation_24h 0');
});

it('formats json and text metrics', function () {
    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();
    $json = json_decode($service->formatJson($metrics), true);
    $text = $service->formatText($metrics);

    expect($json['mode'])->toBe('local')
        ->and($json['stock_pending']['count'])->toBe(0)
        ->and($text)->toContain('mode=local stock_pending=0')
        ->and($text)->toContain('purge_failed=0')
        ->and($text)->toContain('warmup_due=0')
        ->and($service->isAlerting($metrics))->toBeFalse();
});

it('reports purge failures, warmup backlog and invalidation audit counts', function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'https://example.com/');
    Mage::getService('page_cache/urlPolicy')->reset();
    Mage::app()->getStore()->setConfig('page_cache/purge/max_attempts', '1');
    Mage::app()->getStore()->setConfig('page_cache/warmup/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    $purge = Mage::getModel('page_cache/purgeQueue');
    $failedId = $purge->enqueue(['https://example.com/failed'], ['tag_a'], 'proxy');
    $purge->fail($failedId, 'proxy down');
    $pendingId = $purge->enqueue(['https://example.com/pending'], ['tag_b'], 'proxy');
    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        Mage::getSingleton('core/resource')->getTableName('page_cache/purge_outbox'),
        ['created_at' => gmdate('Y-m-d H:i:s', time() - 7200)],
        ['outbox_id = ?' => (int) $pendingId],
    );

    $warm = Mage::getModel('page_cache/warmQueue');
    $insA = $warm->enqueue(['https://example.com/warm-a'], 1, 'test');
    $insB = $warm->enqueue(['https://example.com/warm-b'], 1, 'test');

    $warmRows = $warm->peek(2);
    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        monitorWarmTable(),
        ['available_at' => gmdate('Y-m-d H:i:s', time() + 300)],
        ['queue_id = ?' => (int) $warmRows[1]['queue_id']],
    );

    Mage::getSingleton('page_cache/invalidationLog')->record([
        'type' => 'product',
        'tags' => ['catalog_product_1'],
    ]);
    $lock = Mage::getSingleton('core/lock');
    expect($lock->acquire(Maho_PageCache_Service_Warm::WARMUP_LOCK))->toBeTrue();

    try {
        $metrics = Mage::getService('page_cache/monitor')->getMetrics();
    } finally {
        $lock->release(Maho_PageCache_Service_Warm::WARMUP_LOCK);
    }

    expect($metrics['purge_failed'])->toBe(1)
        ->and($metrics['purge_oldest_seconds'])->toBeGreaterThanOrEqual(7200)
        ->and($metrics['purge_stale'])->toBeTrue()
        ->and($metrics['warmup_pending'])->toBe(2)
        ->and($metrics['warmup_due'])->toBe(1)
        ->and($metrics['warmup_lock'])->toBeTrue()
        ->and($metrics['invalidation_24h'])->toBe(1)
        ->and(Mage::getService('page_cache/monitor')->isAlerting($metrics))->toBeTrue();
});

it('aggregates purge metrics across targets and emits per-target series', function () {
    $queue = Mage::getModel('page_cache/purgeQueue');
    $queue->clear();
    $queue->enqueue(['https://example.com/custom'], ['tag_custom'], 'proxy', 'custom');
    $queue->enqueue(['https://example.com/proxy'], ['tag_proxy'], 'proxy', 'proxy');

    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();
    $prometheus = $service->formatPrometheus($metrics);

    expect($metrics['purge_queue'])->toBe(2)
        ->and($metrics['purge_pending'])->toBe(2)
        ->and($metrics['purge_target_count'])->toBe(2)
        ->and($metrics['purge_targets']['custom']['pending'])->toBe(1)
        ->and($metrics['purge_targets']['proxy']['pending'])->toBe(1)
        ->and($prometheus)->toContain('maho_page_cache_purge_target_pending{target="custom"} 1')
        ->and($prometheus)->toContain('maho_page_cache_purge_target_pending{target="proxy"} 1');
});

it('alerts on failed purge rows for a non-proxy target', function () {
    Mage::app()->getStore()->setConfig('page_cache/purge/max_attempts', '1');
    $queue = Mage::getModel('page_cache/purgeQueue');
    $queue->clear();
    $id = $queue->enqueue(['https://example.com/custom'], ['tag_custom'], 'proxy', 'custom');
    $queue->fail($id, 'custom down');

    $service = Mage::getService('page_cache/monitor');
    $metrics = $service->getMetrics();

    expect($metrics['purge_failed'])->toBe(1)
        ->and($metrics['purge_targets']['custom']['failed'])->toBe(1)
        ->and($service->isAlerting($metrics))->toBeTrue();
});
