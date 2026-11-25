<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function statsSamplingTable(): string
{
    return Mage::getSingleton('core/resource')->getTableName('page_cache/stat');
}

function statsSamplingClean(): void
{
    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(statsSamplingTable(), '1=1');
}

function statsSamplingCount(): int
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return (int) $read->fetchOne($read->select()->from(statsSamplingTable(), ['COUNT(*)']));
}

function statsSamplingSet(string $mode, float $rate = 0.05): void
{
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/stats/mode', $mode);
    Mage::app()->getStore()->setConfig('page_cache/stats/sample_rate', (string) $rate);
}

function statsSamplingRenderDashboard(): string
{
    $design = Mage::getDesign();
    $area = $design->getArea();
    $package = $design->getPackageName();
    $themes = [];
    foreach (['layout', 'template', 'skin', 'locale', 'default'] as $type) {
        $themes[$type] = $design->getTheme($type);
    }

    try {
        $design->setArea('adminhtml')->setPackageName('default')->setTheme('default');
        $block = Mage::app()->getLayout()->createBlock('page_cache/adminhtml_dashboard');
        $block->setTemplate('maho/pagecache/dashboard.phtml');
        return $block->toHtml();
    } finally {
        $design->setArea($area)->setPackageName($package);
        foreach ($themes as $type => $theme) {
            $design->setTheme($type, $theme);
        }
    }
}

beforeEach(function () {
    statsSamplingClean();
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
});

afterEach(function () {
    statsSamplingClean();
    Mage::app()->getStore()->setConfig('page_cache/stats/enabled', '0');
    Mage::app()->getStore()->setConfig('page_cache/stats/mode', 'full');
    Mage::app()->getStore()->setConfig('page_cache/stats/sample_rate', '0.05');
});

it('does not record in off mode', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_OFF);
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit');
    $stat->record('purge');
    $stat->flush();

    expect(statsSamplingCount())->toBe(0);
});

it('records every request event in full mode', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_FULL);
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('hit');
    $stat->record('miss');
    $stat->record('bypass', null, null, null, 'session_cookie');
    $stat->flush();

    expect(statsSamplingCount())->toBe(3);
});

it('records no request events when the sample rate is zero', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 0.0);
    $stat = new Maho_PageCache_Model_Stat();
    for ($i = 0; $i < 10; $i++) {
        $stat->record('hit');
    }
    $stat->flush();

    expect(statsSamplingCount())->toBe(0);
});

it('records every request event when the sample rate is one', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 1.0);
    $stat = new Maho_PageCache_Model_Stat();
    for ($i = 0; $i < 10; $i++) {
        $stat->record('hit');
    }
    $stat->flush();

    expect(statsSamplingCount())->toBe(10);
});

it('always records system events when sampling is zero', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 0.0);
    $stat = new Maho_PageCache_Model_Stat();
    $stat->record('purge');
    $stat->record('flush');
    $stat->record('warmup');
    $stat->flush();

    expect(statsSamplingCount())->toBe(3);
});

it('maps a missing or invalid mode to full for compatibility', function () {
    statsSamplingSet('');
    expect(Mage::helper('page_cache')->getStatsMode())->toBe(Maho_PageCache_Model_Stat::MODE_FULL);

    statsSamplingSet('invalid');
    expect(Mage::helper('page_cache')->getStatsMode())->toBe(Maho_PageCache_Model_Stat::MODE_FULL);
});

it('clamps the sample rate', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, -1.0);
    expect(Mage::helper('page_cache')->getStatsSampleRate())->toBe(0.0);

    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 2.0);
    expect(Mage::helper('page_cache')->getStatsSampleRate())->toBe(1.0);
});

it('renders the sampled warning on the dashboard template', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 0.05);
    $html = statsSamplingRenderDashboard();

    expect($html)->toContain('Statistics are sampled at 5%')
        ->and($html)->toContain('Sampled 5%');
});

it('renders the overview above the report tabs without a summary grid', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_FULL);
    $html = statsSamplingRenderDashboard();

    expect($html)->toContain('pc-status-grid')
        ->and($html)->toContain('pc-tabs-bar')
        ->and($html)->toContain('id="filter_form"')
        ->and($html)->toContain('Invalidation Log')
        ->and($html)->not->toContain('>Overview<')
        ->and($html)->not->toContain('page_cache_summary_grid')
        ->and($html)->not->toContain('Summary CSV');
});

it('shows the statistics mode and sample rate on the dashboard', function () {
    statsSamplingSet(Maho_PageCache_Model_Stat::MODE_SAMPLED, 0.05);
    $block = Mage::app()->getLayout()->createBlock('page_cache/adminhtml_dashboard');

    expect($block->getStatsMode())->toBe(Maho_PageCache_Model_Stat::MODE_SAMPLED)
        ->and($block->isStatsSampled())->toBeTrue()
        ->and($block->getStatsSampleRatePercent())->toBe('5')
        ->and($block->getStatsModeLabel())->toContain('5%');
});
