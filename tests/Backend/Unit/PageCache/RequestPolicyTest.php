<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function pageCacheRequestInput(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'mode' => 'revalidating',
        'method' => 'GET',
        'is_admin' => false,
        'route' => 'catalog_product_view',
        'cacheable_routes' => ['catalog_product_view'],
        'session_cookie' => false,
        'params' => [],
        'allowed_params' => ['p', 'limit'],
        'path' => '/category.html',
        'filter_path_marker' => '',
        'max_filter_depth' => 2,
        'max_path_segments' => 8,
        'respect_no_cache' => true,
        'no_cache' => false,
        'ajax' => false,
        'preview' => false,
        'maintenance' => false,
        'context_safe' => true,
    ], $overrides);
}

it('allows an anonymous cacheable GET', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput()))->toBeNull();
});

it('returns a stable bypass reason', function (array $overrides, string $reason) {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput($overrides)))->toBe($reason);
})->with([
    'disabled' => [['enabled' => false], 'disabled'],
    'invalid mode' => [['mode' => 'unknown'], 'invalid_mode'],
    'post' => [['method' => 'POST'], 'method'],
    'admin' => [['is_admin' => true], 'admin'],
    'route' => [['route' => 'cms_index_index'], 'route'],
    'session cookie' => [['session_cookie' => true], 'session_cookie'],
    'unknown parameter' => [['params' => ['utm_source' => 'newsletter']], 'parameter'],
    'path depth' => [['path' => '/a/b/c/d/e/f/g/h/i.html'], 'path_depth'],
    'filter depth' => [[
        'path' => '/shoes/shop/nike--adidas--puma.html',
        'filter_path_marker' => 'shop',
    ], 'filter_depth'],
    'no cache' => [['no_cache' => true], 'no_cache'],
    'ajax' => [['ajax' => true], 'ajax'],
    'preview' => [['preview' => true], 'preview'],
    'maintenance' => [['maintenance' => true], 'maintenance'],
    'unsafe context' => [['context_safe' => false], 'context'],
]);

it('allows local mode', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput(['mode' => 'local'])))->toBeNull();
});

it('allows a layered navigation path within the filter depth', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput([
        'path' => '/shoes/shop/nike--adidas.html',
        'filter_path_marker' => 'shop',
    ])))->toBeNull();
});

it('matches the filter marker as a whole path segment', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput([
        'path' => '/shopping/a--b--c--d.html',
        'filter_path_marker' => 'shop',
    ])))->toBeNull();
});

it('ignores the filter depth when no marker is configured', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput([
        'path' => '/shoes/shop/nike--adidas--puma--reebok.html',
        'filter_path_marker' => '',
    ])))->toBeNull();
});

it('allows known query parameters', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput([
        'params' => ['p' => '2', 'limit' => '12'],
    ])))->toBeNull();
});

it('bypasses allowed parameters whose value is an array', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->evaluate(pageCacheRequestInput([
        'params' => ['limit' => ['12', '24']],
    ])))->toBe('parameter');
});

it('treats legacy session cookies as session cookies', function () {
    $request = new Mage_Core_Controller_Request_Http(
        Symfony\Component\HttpFoundation\Request::create('/', 'GET', [], ['frontend' => 'legacy-session']),
    );
    $action = new class ($request, new Mage_Core_Controller_Response_Http()) extends Mage_Core_Controller_Varien_Action {};

    try {
        $input = (new Maho_PageCache_Service_Request())->getInput($action);
    } finally {
        Mage::app()->getFrontController()->setAction(null);
    }

    expect($input['session_cookie'])->toBeTrue();
});

it('skips session only on configured session routes', function () {
    $service = new Maho_PageCache_Service_Request();

    expect($service->canSkipSession(pageCacheRequestInput()))->toBeTrue()
        ->and($service->canSkipSession(pageCacheRequestInput([
            'session_skip_routes' => ['cms_page_view'],
        ])))->toBeFalse()
        ->and($service->canSkipSession(pageCacheRequestInput([
            'session_cookie' => true,
        ])))->toBeFalse();
});
