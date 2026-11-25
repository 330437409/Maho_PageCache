<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

beforeEach(function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'http://example.com/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'https://example.com/');
    Mage::app()->getStore()->setConfig('page_cache/warmup/allowed_hosts', '');
    Mage::getService('page_cache/urlPolicy')->reset();
});

afterEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/warmup/allowed_hosts', '');
    Mage::getService('page_cache/urlPolicy')->reset();
});

it('allows the configured store origins', function () {
    $policy = Mage::getService('page_cache/urlPolicy');

    expect($policy->isAllowed('https://example.com/category'))->toBeTrue()
        ->and($policy->isAllowed('http://example.com:80/category'))->toBeTrue()
        ->and($policy->isAllowed('https://EXAMPLE.com./category'))->toBeTrue();
});

it('rejects other hosts, ports, schemes and relative urls', function () {
    $policy = Mage::getService('page_cache/urlPolicy');

    expect($policy->isAllowed('https://evil.example/category'))->toBeFalse()
        ->and($policy->isAllowed('https://example.com:8443/category'))->toBeFalse()
        ->and($policy->isAllowed('ftp://example.com/category'))->toBeFalse()
        ->and($policy->isAllowed('/category'))->toBeFalse()
        ->and($policy->isAllowed('//example.com/category'))->toBeFalse();
});

it('does not treat userinfo as the host', function () {
    $policy = Mage::getService('page_cache/urlPolicy');

    expect($policy->isAllowed('https://example.com@evil.example/category'))->toBeFalse();
});

it('allows a configured localhost store', function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'http://localhost:8000/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'http://localhost:8000/');
    $policy = Mage::getService('page_cache/urlPolicy');
    $policy->reset();

    expect($policy->isAllowed('http://localhost:8000/category'))->toBeTrue()
        ->and($policy->isAllowed('http://localhost:9000/category'))->toBeFalse()
        ->and($policy->isAllowed('http://127.0.0.1:8000/category'))->toBeFalse();
});

it('allows explicitly configured alias origins', function () {
    Mage::app()->getStore()->setConfig(
        'page_cache/warmup/allowed_hosts',
        "https://alias.example\nhttps://example.com:8443",
    );
    Mage::getService('page_cache/urlPolicy')->reset();
    $policy = Mage::getService('page_cache/urlPolicy');

    expect($policy->isAllowed('https://alias.example/category'))->toBeTrue()
        ->and($policy->isAllowed('https://example.com:8443/category'))->toBeTrue()
        ->and($policy->isAllowed('http://alias.example/category'))->toBeFalse()
        ->and($policy->isAllowed('https://alias.example:8443/category'))->toBeFalse()
        ->and($policy->isAllowed('https://other.example/category'))->toBeFalse();
});

it('trusts an explicitly configured private origin without resolving DNS', function () {
    Mage::app()->getStore()->setConfig('web/unsecure/base_url', 'http://10.20.30.40/');
    Mage::app()->getStore()->setConfig('web/secure/base_url', 'http://10.20.30.40/');
    Mage::getService('page_cache/urlPolicy')->reset();

    expect(Mage::getService('page_cache/urlPolicy')->isAllowed('http://10.20.30.40/category'))->toBeTrue();
});
