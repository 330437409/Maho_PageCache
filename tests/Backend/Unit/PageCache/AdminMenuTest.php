<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('registers page cache under system between cache and index management', function () {
    $file = dirname(__DIR__, 4) . '/app/code/local/Maho/PageCache/etc/adminhtml.xml';
    $xml = simplexml_load_file($file);

    expect($xml)->not->toBeFalse()
        ->and(isset($xml->menu->page_cache))->toBeFalse()
        ->and((string) $xml->menu->system->children->page_cache->title)->toBe('Page Cache')
        ->and((string) $xml->menu->system->children->page_cache->sort_order)->toBe('91')
        ->and((string) $xml->menu->system->children->page_cache->action)->toBe('adminhtml/page_cache/stats')
        ->and((string) $xml->menu->system->children->page_cache->resource)->toBe('report/page_cache')
        ->and((string) $xml->acl->resources->admin->children->report->children->page_cache->title)->toBe('Page Cache');
});
