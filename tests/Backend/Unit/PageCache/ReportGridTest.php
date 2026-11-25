<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('paginates an in-memory report collection', function () {
    $collection = new Maho_PageCache_Model_Collection();
    for ($i = 1; $i <= 25; $i++) {
        $collection->addItem(new \Maho\DataObject(['id' => $i, 'name' => 'row-' . $i]));
    }

    $collection->setPageSize(10);
    $collection->setCurPage(2);

    expect($collection->getSize())->toBe(25)
        ->and($collection->getItems())->toHaveCount(10)
        ->and($collection->getFirstItem()->getId())->toBe(11);
});

it('filters an in-memory report collection with like and number ranges', function () {
    $collection = new Maho_PageCache_Model_Collection();
    foreach ([
        ['name' => 'Alpha', 'count' => 2],
        ['name' => 'Beta', 'count' => 8],
        ['name' => 'Gamma', 'count' => 15],
    ] as $row) {
        $collection->addItem(new \Maho\DataObject($row));
    }

    $collection->addFieldToFilter('name', ['like' => '%et%']);
    expect($collection->getSize())->toBe(1)
        ->and($collection->getFirstItem()->getName())->toBe('Beta');

    $collection->addFieldToFilter('count', ['from' => 5, 'to' => 10]);
    expect($collection->getSize())->toBe(1)
        ->and($collection->getFirstItem()->getName())->toBe('Beta');
});

it('renders the invalidation audit grid', function () {
    Mage::app()->getStore()->setConfig('page_cache/audit/enabled', '1');
    Mage::getSingleton('page_cache/invalidationLog')->record([
        'area' => 'admin',
        'type' => 'product',
        'reason' => 'catalog_product_1',
        'tags' => ['catalog_product_1'],
        'affected_urls' => 2,
        'purge_queued' => true,
        'mode' => 'proxy',
    ]);
    $design = Mage::getDesign();
    $area = $design->getArea();
    $package = $design->getPackageName();
    $themes = [];
    foreach (['layout', 'template', 'skin', 'locale', 'default'] as $type) {
        $themes[$type] = $design->getTheme($type);
    }

    try {
        $design->setArea('adminhtml')->setPackageName('default')->setTheme('default');
        $html = Mage::app()->getLayout()
            ->createBlock('page_cache/adminhtml_dashboard_tab_invalidations')
            ->toHtml();
    } finally {
        $design->setArea($area)->setPackageName($package);
        foreach ($themes as $type => $theme) {
            $design->setTheme($type, $theme);
        }
    }

    expect($html)->toContain('Tag Sample')
        ->and($html)->toContain('catalog_product_1');
});
