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
    Mage::getModel('page_cache/purgeQueue')->clear();
});

afterEach(function () {
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getModel('page_cache/purgeQueue')->clear();
});

it('returns URLs on a dry run and keeps the queue', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    $result = (new Maho_PageCache_Service_Purge())->process($queue->getEntries(), true);

    expect($result['urls'])->toBe(['https://example.com/a'])
        ->and($result['processed'])->toBe(0)
        ->and($queue->count())->toBe(1);
});

it('keeps the queue when writing the purge file fails', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    $service = new class extends Maho_PageCache_Service_Purge {
        protected function writePurgeFile(array $urls, array $tags): string
        {
            throw new RuntimeException('purge file write failed');
        }
    };

    $result = $service->process($queue->getEntries());

    expect($result['processed'])->toBe(0)
        ->and($queue->count())->toBe(1);
});

it('removes the queue after a successful hand-off', function () {
    $queue = new Maho_PageCache_Model_PurgeQueue();
    $queue->enqueue(['https://example.com/a'], ['tag_a'], 'proxy');

    $service = new class extends Maho_PageCache_Service_Purge {
        protected function writePurgeFile(array $urls, array $tags): string
        {
            return '/tmp/page-cache-purge-test.txt';
        }
    };

    $result = $service->process($queue->getEntries());

    expect($result['processed'])->toBe(1)
        ->and($queue->count())->toBe(0);
});
