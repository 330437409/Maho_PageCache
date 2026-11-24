<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

class ServerPlaceholderLayoutObserver
{
    public static bool $fired = false;

    public function fire(): void
    {
        self::$fired = true;
    }
}

function serverPlaceholderContext(array $overrides = []): Maho_PageCache_Model_Context
{
    return new Maho_PageCache_Model_Context(array_merge([
        'website_id' => 1,
        'store_id' => 1,
        'currency' => 'USD',
        'customer_group' => 0,
        'tax_country' => 'US',
        'tax_rate' => 0.0,
        'params' => [],
        'scheme' => 'https',
        'host' => 'example.com',
        'path' => '/server-placeholder.html',
        'mode' => 'local',
    ], $overrides));
}

function serverPlaceholderBlock(array $data = []): Mage_Core_Block_Abstract
{
    return Mage::app()->getLayout()->createBlock('core/text', 'server.placeholder.test', array_merge([
        'text' => 'live',
        'page_cache_dynamic' => 1,
        'page_cache_dynamic_server' => 1,
    ], $data));
}

function serverPlaceholderMarker(): array
{
    $context = serverPlaceholderContext();
    $result = Mage::getService('page_cache/dynamic')->createServerMarker(
        serverPlaceholderBlock(),
        '<span>anonymous</span>',
        $context,
        3600,
    );

    return [$context, $result];
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/general/ttl', '3600');
    Mage::app()->getStore()->setConfig('page_cache/general/html_max_size', '2097152');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_side', '1');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_types', 'core/text');
    Mage::app()->getStore()->setConfig('page_cache/static/enabled', '0');

    Mage::app()->getCache()->cleanType('page_cache');
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_server_blocks');

    $response = Mage::app()->getResponse();
    $response->clearHeaders();
    $response->clearBody();
    $response->setHttpResponseCode(200);
});

afterEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_side', '0');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_types', '');
    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_server_blocks');
    Mage::getSingleton('page_cache/staticCache')->flush();
    Mage::app()->getCache()->cleanType('page_cache');
});

it('wraps a marked block and stores its definition', function () {
    [$context, $result] = serverPlaceholderMarker();

    expect($result)->not->toBeNull()
        ->and($result['html'])->toContain('PC_DYNAMIC_SERVER:')
        ->and($result['html'])->toContain('<span>anonymous</span>')
        ->and($result['definition']['type'])->toBe('core/text')
        ->and($result['definition']['name'])->toBe('server.placeholder.test')
        ->and($result['definition']['data']['text'])->toBe('live')
        ->and($context->getUrl())->toBe('https://example.com/server-placeholder.html');
});

it('does not wrap a block that is not opted in', function () {
    $context = serverPlaceholderContext();
    $block = serverPlaceholderBlock(['page_cache_dynamic_server' => 0]);
    $result = Mage::getService('page_cache/dynamic')->createServerMarker(
        $block,
        '<span>anonymous</span>',
        $context,
        3600,
    );

    expect($result)->toBeNull();
});

it('does not wrap a block whose type is not allowed', function () {
    Mage::app()->getStore()->setConfig('page_cache/dynamic/server_types', 'cms/block');
    [$context, $result] = serverPlaceholderMarker();

    expect($result)->toBeNull();
});

it('renders the live block on a local hit', function () {
    [$context, $result] = serverPlaceholderMarker();

    $cache = new Maho_PageCache_Model_HtmlCache();
    $entry = $cache->save(
        $context,
        $result['html'],
        ['PAGE_CACHE'],
        3600,
        [],
        ['global' => 1, 'url' => 1],
        [$result['id'] => $result['definition']],
    );

    (new Maho_PageCache_Model_Backend_Local())->applyHit($context, $entry, false);

    expect(Mage::app()->getResponse()->getBody())->toBe('live')
        ->and(Mage::app()->getResponse()->getBody())->not->toContain('PC_DYNAMIC_SERVER');
});

it('keeps the anonymous html when the token does not match the context', function () {
    [, $result] = serverPlaceholderMarker();
    $otherContext = serverPlaceholderContext(['path' => '/other.html']);
    $html = Mage::getService('page_cache/dynamic')->renderServerBlocks(
        $result['html'],
        ['dynamic_blocks' => [$result['id'] => $result['definition']]],
        $otherContext,
    );

    expect($html)->toBe('<span>anonymous</span>');
});

it('keeps the anonymous html when the definition is missing', function () {
    [$context, $result] = serverPlaceholderMarker();
    $html = Mage::getService('page_cache/dynamic')->renderServerBlocks(
        $result['html'],
        ['dynamic_blocks' => []],
        $context,
    );

    expect($html)->toContain('<span>anonymous</span>')
        ->and($html)->not->toContain('live');
});

it('falls back when the block starts a session', function () {
    [$context, $result] = serverPlaceholderMarker();
    Mage::app()->getResponse()->setHeader('Set-Cookie', 'maho_session=test; Path=/', true);

    $html = Mage::getService('page_cache/dynamic')->renderServerBlocks(
        $result['html'],
        ['dynamic_blocks' => [$result['id'] => $result['definition']]],
        $context,
    );

    $hasCookie = false;
    foreach (Mage::app()->getResponse()->getHeaders() as $header) {
        if (strcasecmp((string) $header['name'], 'Set-Cookie') === 0) {
            $hasCookie = true;
        }
    }

    expect($html)->toContain('<span>anonymous</span>')
        ->and($html)->not->toContain('live')
        ->and($hasCookie)->toBeFalse();
});

it('strips the markers for static export', function () {
    [$context, $result] = serverPlaceholderMarker();

    $stripped = Mage::getService('page_cache/dynamic')->stripServerBlockMarkers($result['html']);

    expect($stripped)->toBe('<span>anonymous</span>')
        ->and($stripped)->not->toContain('PC_DYNAMIC_SERVER');
});

it('collects the block definition during a local render', function () {
    Mage::register('page_cache_request_cacheable', true);
    $block = serverPlaceholderBlock();
    $transport = new Maho\DataObject(['html' => '<span>anonymous</span>']);

    (new Maho_PageCache_Model_Observer())->handleDynamicBlock(
        new Maho\Event\Observer(['block' => $block, 'transport' => $transport]),
    );

    $blocks = Mage::registry('page_cache_server_blocks');

    expect($transport->getHtml())->toContain('PC_DYNAMIC_SERVER:')
        ->and($blocks)->toBeArray()
        ->and($blocks)->toHaveCount(1)
        ->and(array_values($blocks)[0]['type'])->toBe('core/text');
});

it('does not trigger full layout generation events', function () {
    [$context, $result] = serverPlaceholderMarker();
    ServerPlaceholderLayoutObserver::$fired = false;
    $observer = [new ServerPlaceholderLayoutObserver(), 'fire'];
    Mage::addObserver('controller_action_layout_generate_blocks_before', $observer);
    Mage::addObserver('controller_action_layout_generate_blocks_after', $observer);

    Mage::getService('page_cache/dynamic')->renderServerBlocks(
        $result['html'],
        ['dynamic_blocks' => [$result['id'] => $result['definition']]],
        $context,
    );

    expect(ServerPlaceholderLayoutObserver::$fired)->toBeFalse();
});
