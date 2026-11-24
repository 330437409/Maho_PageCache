<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'proxy');
    Mage::app()->getStore()->setConfig('page_cache/general/cacheable_routes', 'catalog_product_view');
    Mage::app()->getStore()->setConfig('page_cache/proxy/context_mode', 'default');
    Mage::app()->getRequest()->setRoutingInfo([
        'requested_route' => 'catalog',
        'requested_controller' => 'product',
        'requested_action' => 'view',
    ]);
    Mage::app()->getRequest()->getSymfonyRequest()->cookies->remove('___store');
    Mage::unregister('page_cache_context_variant');
    Mage::app()->getCache()->cleanType('page_cache');
});

afterEach(function () {
    Mage::app()->getRequest()->getSymfonyRequest()->cookies->remove('___store');
    Mage::app()->getStore()->setConfig('page_cache/proxy/context_mode', 'cookie');
    Mage::app()->getCache()->cleanType('page_cache');
});

function proxyContextController(): Mage_Core_Controller_Varien_Action
{
    return new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};
}

function staticCacheVariantContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'path' => '/category',
        'mode' => 'local',
    ], $overrides));
}

it('allows the default store context for a url-only proxy', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBeNull();
});

it('bypasses a store cookie for a url-only proxy', function () {
    Mage::app()->getRequest()->getSymfonyRequest()->cookies->set('___store', 'default');
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBe('context');
});

it('allows a store cookie in local mode', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getRequest()->getSymfonyRequest()->cookies->set('___store', 'default');
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBeNull();
});

it('bypasses a proxy request with a context variant', function () {
    Mage::register('page_cache_context_variant', 'mobile');
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBe('context');
});

it('partitions local context by variant', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    $base = staticCacheVariantContext();
    Mage::register('page_cache_context_variant', 'mobile');
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBeNull()
        ->and(staticCacheVariantContext(['variant' => 'mobile'])->getHash())->not->toBe($base->getHash());
});

it('allows a store cookie when the proxy honors cookie vary', function () {
    Mage::app()->getStore()->setConfig('page_cache/proxy/context_mode', 'cookie');
    Mage::app()->getRequest()->getSymfonyRequest()->cookies->set('___store', 'default');
    $service = new Maho_PageCache_Service_Request();

    expect($service->getBypassReason(proxyContextController()))->toBeNull();
});
