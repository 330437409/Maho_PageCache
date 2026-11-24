<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function statTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stat');
}

function statHourlyTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stat_hourly');
}

class StatTestFailingWrite extends Maho_PageCache_Model_Stat
{
    #[\Override]
    protected function write(): Maho\Db\Adapter\AdapterInterface
    {
        throw new RuntimeException('database is down');
    }
}

function statClean(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(statTable(), '1=1');
    $write->delete(statHourlyTable(), '1=1');
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/stats/raw_retention_days', '30');
    Mage::app()->getStore()->setConfig('page_cache/stats/rollup_retention_days', '365');
    Mage::app()->getStore()->setConfig('page_cache/stats/max_duration_ms', '60000');
    statClean();
});

afterEach(function () {
    statClean();
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
});

it('records and flushes events', function () {
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit', 'catalog_product_view', '/product.html', 12);
    $stat->record('miss', 'catalog_category_view', '/category.html', 20, null);
    $stat->flush();

    $rows = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll(
        Mage::getSingleton('core/resource')->getConnection('core_read')->select()->from(statTable()),
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['event_type'])->toBe('hit')
        ->and($rows[0]['route'])->toBe('catalog_product_view')
        ->and($rows[0]['url_path'])->toBe('/product.html')
        ->and((int) $rows[0]['duration_ms'])->toBe(12)
        ->and($rows[0]['hour_bucket'])->not->toBe('');
});

it('does not record when statistics are disabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit');
    $stat->flush();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    expect((int) $read->fetchOne($read->select()->from(statTable(), ['COUNT(*)'])))->toBe(0);
});

it('ignores unknown event types', function () {
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('unknown');
    $stat->flush();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    expect((int) $read->fetchOne($read->select()->from(statTable(), ['COUNT(*)'])))->toBe(0);
});

it('drops admin frontend events but keeps system events without an admin path', function () {
    Mage::app()->setCurrentStore(0);
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit', 'catalog_product_view', '/admin/secret');
    $stat->record('flush', null, '/admin/secret', null, 'config');
    $stat->flush();
    Mage::app()->setCurrentStore(1);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $rows = $read->fetchAll($read->select()->from(statTable()));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['event_type'])->toBe('flush')
        ->and($rows[0]['route'])->toBeNull()
        ->and($rows[0]['url_path'])->toBeNull();
});

it('strips the query and caps unrealistic durations', function () {
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit', 'catalog_product_view', '/product.html?utm_source=x', 999999);
    $stat->flush();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow($read->select()->from(statTable()));

    expect($row['url_path'])->toBe('/product.html')
        ->and((int) $row['duration_ms'])->toBe(60000);
});

it('rolls up old buckets and leaves the current bucket', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $old = gmdate('Y-m-d H:00:00', time() - 7200);
    $current = gmdate('Y-m-d H:00:00');
    $write->insertMultiple(statTable(), [
        ['event_type' => 'hit', 'route' => 'catalog_product_view', 'url_path' => '/p.html', 'store_id' => 1, 'mode' => 'local', 'duration_ms' => 10, 'reason' => null, 'hour_bucket' => $old, 'created_at' => $old],
        ['event_type' => 'miss', 'route' => 'catalog_product_view', 'url_path' => '/p.html', 'store_id' => 1, 'mode' => 'local', 'duration_ms' => 20, 'reason' => null, 'hour_bucket' => $old, 'created_at' => $old],
        ['event_type' => 'hit', 'route' => 'catalog_category_view', 'url_path' => '/c.html', 'store_id' => 1, 'mode' => 'local', 'duration_ms' => 30, 'reason' => null, 'hour_bucket' => $current, 'created_at' => $current],
    ]);

    $processed = (new Maho_PageCache_Model_Stat())->rollup();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $rollup = $read->fetchAll($read->select()->from(statHourlyTable()));
    $raw = $read->fetchAll($read->select()->from(statTable()));

    expect($processed)->toBeGreaterThan(0)
        ->and($rollup)->toHaveCount(2)
        ->and($raw)->toHaveCount(1);

    $processedAgain = (new Maho_PageCache_Model_Stat())->rollup();
    $rollupAgain = $read->fetchAll($read->select()->from(statHourlyTable()));

    expect($processedAgain)->toBe(0)
        ->and($rollupAgain)->toHaveCount(2);
});

it('cleans old raw and rollup rows', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
    $new = gmdate('Y-m-d H:i:s');
    $write->insertMultiple(statTable(), [
        ['event_type' => 'hit', 'route' => null, 'url_path' => null, 'store_id' => 1, 'mode' => null, 'duration_ms' => null, 'reason' => null, 'hour_bucket' => $old, 'created_at' => $old],
        ['event_type' => 'hit', 'route' => null, 'url_path' => null, 'store_id' => 1, 'mode' => null, 'duration_ms' => null, 'reason' => null, 'hour_bucket' => $new, 'created_at' => $new],
    ]);
    $write->insertMultiple(statHourlyTable(), [
        ['bucket' => gmdate('Y-m-d H:00:00', time() - 400 * 86400), 'store_id' => 1, 'event_type' => 'hit', 'route' => '', 'event_count' => 1, 'duration_sum' => 0, 'duration_count' => 0],
        ['bucket' => gmdate('Y-m-d H:00:00'), 'store_id' => 1, 'event_type' => 'hit', 'route' => '', 'event_count' => 1, 'duration_sum' => 0, 'duration_count' => 0],
    ]);

    (new Maho_PageCache_Model_Stat())->cleanup();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    expect((int) $read->fetchOne($read->select()->from(statTable(), ['COUNT(*)'])))->toBe(1)
        ->and((int) $read->fetchOne($read->select()->from(statHourlyTable(), ['COUNT(*)'])))->toBe(1);
});

it('counts only the events inside the selected range', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $threeDaysAgo = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
    $write->insert(statTable(), [
        'event_type' => 'hit',
        'route' => 'catalog_product_view',
        'url_path' => '/p.html',
        'store_id' => 1,
        'mode' => 'local',
        'duration_ms' => 10,
        'reason' => null,
        'hour_bucket' => substr($threeDaysAgo, 0, 13) . ':00:00',
        'created_at' => $threeDaysAgo,
    ]);

    $stat = new Maho_PageCache_Model_Stat();
    $weekTrend = $stat->getTrend(7, 1);

    expect($stat->getSummary(1, 1)['hit'])->toBe(0)
        ->and($stat->getSummary(7, 1)['hit'])->toBe(1)
        ->and($weekTrend)->toHaveCount(1)
        ->and($weekTrend[0]['bucket'])->toBe(substr($threeDaysAgo, 0, 10))
        ->and($weekTrend[0]['hit'])->toBe(1);
});

it('filters the summary and the trend by store', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $row = [
        'event_type' => 'hit',
        'route' => 'cms_index_index',
        'url_path' => '/',
        'mode' => 'local',
        'duration_ms' => 10,
        'reason' => null,
        'hour_bucket' => gmdate('Y-m-d H:00:00'),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
    $write->insert(statTable(), ['store_id' => 1] + $row);
    $write->insert(statTable(), ['store_id' => 2] + $row);

    $stat = new Maho_PageCache_Model_Stat();

    expect($stat->getSummary(7, 1)['hit'])->toBe(1)
        ->and($stat->getSummary(7, 0)['hit'])->toBe(2)
        ->and($stat->getTrend(7, 1)[0]['hit'])->toBe(1);
});

it('records the counter results with their reason', function () {
    $counter = Mage::getModel('page_cache/counter');
    $counter->increment('hit');
    $counter->increment('miss');
    $counter->increment('bypass', 'session_cookie');
    $counter->increment('revalidated');
    Mage::getSingleton('page_cache/stat')->flush();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $rows = $read->fetchAll($read->select()->from(statTable())->order('stat_id ASC'));

    expect(array_column($rows, 'event_type'))->toBe(['hit', 'miss', 'bypass', 'revalidated'])
        ->and($rows[2]['reason'])->toBe('session_cookie');
});

it('does not throw when the database write fails', function () {
    $stat = new StatTestFailingWrite();
    $stat->record('hit', 'cms_index_index', '/', 5);

    $failed = false;
    try {
        $stat->flush();
    } catch (\Throwable) {
        $failed = true;
    }

    expect($failed)->toBeFalse();
});

it('returns summary, trend and top missed paths', function () {
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit', 'catalog_product_view', '/p.html', 10);
    $stat->record('miss', 'catalog_product_view', '/p.html', 30);
    $stat->record('miss', 'catalog_product_view', '/p.html', 50);
    $stat->record('bypass', 'catalog_category_view', '/c.html', null, 'session_cookie');
    $stat->flush();

    $summary = $stat->getSummary(1, 1);
    $week = $stat->getSummary(7, 1);
    $trend = $stat->getTrend(1, 1);
    $top = $stat->getTopMissed(1, 1);

    expect($week['hit'])->toBe(1)
        ->and($week['miss'])->toBe(2)
        ->and($summary['hit'])->toBe(1)
        ->and($summary['miss'])->toBe(2)
        ->and($summary['bypass'])->toBe(1)
        ->and($summary['hit_rate'])->toBe(33.3)
        ->and($summary['avg_duration_ms'])->toBe(30.0)
        ->and($trend)->not->toBeEmpty()
        ->and($top[0]['url_path'])->toBe('/p.html')
        ->and((int) $top[0]['miss_count'])->toBe(2);
});
