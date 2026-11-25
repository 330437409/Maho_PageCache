<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function invalidationLogTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/invalidation_log');
}

function invalidationLogClean(): void
{
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(invalidationLogTable(), '1=1');
}

beforeEach(function () {
    invalidationLogClean();
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '0');
});

afterEach(function () {
    invalidationLogClean();
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/audit/sample_tags', '20');
    Mage::app()->getStore()->setConfig('page_cache/audit/retention_days', '30');
});

it('records an invalidation row when audit is enabled', function () {
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    $model = new Maho_PageCache_Model_InvalidationLog();
    $model->record([
        'area' => 'admin',
        'type' => 'product',
        'reason' => 'catalog_product_1',
        'tags' => ['catalog_product_1'],
        'affected_urls' => 3,
        'purge_queued' => true,
        'warm_queued' => true,
        'store_id' => 1,
        'mode' => 'proxy',
    ]);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow($read->select()->from(invalidationLogTable()));

    expect($row['area'])->toBe('admin')
        ->and($row['invalidation_type'])->toBe('product')
        ->and($row['reason'])->toBe('catalog_product_1')
        ->and((int) $row['tag_count'])->toBe(1)
        ->and($row['sample_tags'])->toBe('["catalog_product_1"]')
        ->and((int) $row['affected_urls'])->toBe(3)
        ->and((int) $row['purge_queued'])->toBe(1)
        ->and((int) $row['warm_queued'])->toBe(1)
        ->and((int) $row['store_id'])->toBe(1)
        ->and($row['mode'])->toBe('proxy');
});

it('stores only the configured tag sample and always stores the total count', function () {
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/audit/sample_tags', '2');
    $model = new Maho_PageCache_Model_InvalidationLog();
    $model->record([
        'type' => 'tag',
        'reason' => 'tags',
        'tags' => ['a', 'b', 'c', 'd'],
        'affected_urls' => 0,
    ]);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow($read->select()->from(invalidationLogTable()));

    expect((int) $row['tag_count'])->toBe(4)
        ->and(json_decode((string) $row['sample_tags'], true))->toBe(['a', 'b']);
});

it('does not record when audit is disabled', function () {
    $model = new Maho_PageCache_Model_InvalidationLog();
    $model->record(['type' => 'tag', 'tags' => ['a']]);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    expect((int) $read->fetchOne($read->select()->from(invalidationLogTable(), ['COUNT(*)'])))->toBe(0);
});

it('returns recent rows and deletes them', function () {
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    $model = new Maho_PageCache_Model_InvalidationLog();
    $model->record(['type' => 'tag', 'reason' => 'a', 'tags' => ['a']]);
    $model->record(['type' => 'full', 'reason' => 'full']);

    $rows = $model->getRecent(1);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['invalidation_type'])->toBe('full')
        ->and($rows[0]['sample_tags'])->toBe('');

    expect($model->delete([(int) $rows[0]['log_id']]))->toBe(1)
        ->and($model->getRecent(1))->toHaveCount(1);
});

it('cleans rows older than the retention window', function () {
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    $model = new Maho_PageCache_Model_InvalidationLog();
    $model->record(['type' => 'tag', 'tags' => ['a']]);
    Mage::getSingleton('core/resource')->getConnection('core_write')->update(
        invalidationLogTable(),
        ['created_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)],
        '1=1',
    );

    expect($model->cleanup(30))->toBe(1)
        ->and($model->getRecent(30))->toBe([]);
});
