<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function dynamicTestContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'path' => '/dynamic.html',
        'mode' => 'local',
    ], $overrides));
}

function dynamicHeader(Mage_Core_Controller_Response_Http $response, string $name): ?string
{
    foreach ($response->getHeaders() as $header) {
        if (strcasecmp($header['name'], $name) === 0) {
            return $header['value'];
        }
    }
    return null;
}

beforeEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/general/enabled', '1');
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'local');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/injection', 'ajax');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/ttl', '0');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/rate_limit', '0');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/rate_limit_window', '60');
    Mage::app()->getCache()->cleanType('page_cache');
    Mage::helper('core')->rateLimiter('page_cache_dynamic', 1, 60)->clear();

    $response = Mage::app()->getResponse();
    $response->clearHeaders();
    $response->clearBody();
    $response->setHttpResponseCode(200);

    Mage::unregister('page_cache_request_cacheable');
    Mage::unregister('page_cache_dynamic_request');
    Mage::unregister('page_cache_dynamic_blocks');
    Mage::unregister('page_cache_dynamic_output');
    Mage::unregister('page_cache_dynamic_seen');
    Mage::unregister('page_cache_local_hit');
    Mage::unregister('page_cache_local_bypass_reason');
    Mage::unregister('page_cache_local_lock_name');
    Mage::unregister('page_cache_local_versions');
    Mage::unregister('page_cache_tags');
    Mage::unregister('page_cache_bypass_reason');

    Mage::app()->getRequest()->getSymfonyRequest()->headers->remove('X-Maho-Page-Cache');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->remove('X-Maho-Page-Cache-Blocks');
    Mage::app()->getRequest()->getSymfonyRequest()->query->remove('_page_cache_dynamic');
});

afterEach(function () {
    Mage::app()->getStore()->setConfig('page_cache/dynamic/rate_limit', '0');
    Mage::helper('core')->rateLimiter('page_cache_dynamic', 1, 60)->clear();
    Mage::app()->getRequest()->getSymfonyRequest()->headers->remove('X-Maho-Page-Cache');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->remove('X-Maho-Page-Cache-Blocks');
    Mage::app()->getRequest()->getSymfonyRequest()->query->remove('_page_cache_dynamic');
    Mage::app()->getCache()->cleanType('page_cache');
});

it('creates a signed placeholder token bound to the page context', function () {
    $context = dynamicTestContext();
    $service = new Maho_PageCache_Service_Dynamic();

    $token = $service->createToken('header.cart', $context);
    $placeholder = $service->getPlaceholder('header.cart', $context);
    preg_match('/^<!--page-cache-dynamic:([0-9a-f]+):([0-9a-f]+)-->$/', $placeholder, $parts);
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', $token);

    expect($token)->toContain('.')
        ->and($parts)->toHaveCount(3)
        ->and(hex2bin($parts[1]))->toBe($token)
        ->and(hex2bin($parts[2]))->toBe('header.cart')
        ->and($service->getRequestedBlocks($context))->toBe(['header.cart']);
});

it('rejects expired, tampered and cross context tokens', function () {
    $service = new Maho_PageCache_Service_Dynamic();
    $context = dynamicTestContext();
    $expired = $service->createToken('header.cart', $context, time() - 1);
    $valid = $service->createToken('header.cart', $context);
    [$payload, $signature] = explode('.', $valid, 2);
    $tampered = rtrim(strtr(base64_encode('{"v":1,"block":"header.cart","url":"https://evil.test/","store":1,"context":"x","exp":9999999999}'), '+/', '-_'), '=') . '.' . $signature;

    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', $expired);
    expect(fn() => $service->getRequestedBlocks($context))->toThrow(InvalidArgumentException::class);

    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', $tampered);
    expect(fn() => $service->getRequestedBlocks($context))->toThrow(InvalidArgumentException::class);

    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', $valid);
    expect(fn() => $service->getRequestedBlocks(dynamicTestContext(['store_id' => 2])))->toThrow(InvalidArgumentException::class);
});

it('uses an esi include only in proxy modes', function () {
    $service = new Maho_PageCache_Service_Dynamic();
    Mage::app()->getStore()->setConfig('page_cache/dynamic/injection', 'esi');

    $proxy = $service->getPlaceholder('header.cart', dynamicTestContext(['mode' => 'proxy']));
    $local = $service->getPlaceholder('header.cart', dynamicTestContext(['mode' => 'local']));

    expect($proxy)->toStartWith('<esi:include')
        ->and($proxy)->toContain('_page_cache_dynamic=')
        ->and($local)->toStartWith('<!--page-cache-dynamic:');
});

it('uses a comment placeholder without breaking list or table parents', function () {
    $placeholder = (new Maho_PageCache_Service_Dynamic())->getPlaceholder(
        'line.item',
        dynamicTestContext(['mode' => 'proxy']),
    );
    $list = '<ul><li>one</li>' . $placeholder . '<li>two</li></ul>';
    $table = '<table><tbody><tr><td>one</td></tr>' . $placeholder . '<tr><td>two</td></tr></tbody></table>';

    $listDom = new DOMDocument();
    $listDom->loadHTML($list);
    $tableDom = new DOMDocument();
    $tableDom->loadHTML($table);

    expect($placeholder)->toStartWith('<!--page-cache-dynamic:')
        ->and($listDom->getElementsByTagName('li')->length)->toBe(2)
        ->and($tableDom->getElementsByTagName('td')->length)->toBe(2);
});

it('replaces the real form key and session id with placeholders', function () {
    $service = new Maho_PageCache_Service_Dynamic();
    $formKey = Mage::getSingleton('core/session')->getFormKey();

    $html = '<input name="form_key" value="' . $formKey . '">';

    expect($service->sanitizeHtml($html))->toBe('<input name="form_key" value="{{PAGE_CACHE_FORM_KEY}}">');
});

it('injects the ajax client script in proxy modes', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'proxy');
    $service = new Maho_PageCache_Service_Dynamic();

    $plain = '<html><body>plain</body></html>';
    $dynamic = '<html><body><!--page-cache-dynamic:74:6865616465722e63617274--></body></html>';

    expect($service->injectClientScript($plain))->toBe($plain)
        ->and($service->injectClientScript($dynamic))->toContain('X-Maho-Page-Cache-Blocks')
        ->and($service->injectClientScript($dynamic))->toContain('NodeFilter.SHOW_COMMENT')
        ->and($service->injectClientScript($dynamic))->toContain('{{PAGE_CACHE_FORM_KEY}}');
});

it('injects a lazy form key script in local mode', function () {
    $service = new Maho_PageCache_Service_Dynamic();

    $plain = '<html><body>plain</body></html>';
    $form = '<html><body><form method="post"><input name="form_key" value="{{PAGE_CACHE_FORM_KEY}}"></form></body></html>';

    expect($service->injectClientScript($plain))->toBe($plain)
        ->and($service->injectClientScript($form))->toContain('focusin')
        ->and($service->injectClientScript($form))->not->toContain('X-Maho-Page-Cache-Blocks');
});

it('keeps a marked block in a local page', function () {
    Mage::register('page_cache_request_cacheable', true);
    $block = new Mage_Core_Block_Text();
    $block->setNameInLayout('header.cart');
    $block->setData('page_cache_dynamic', 1);
    $transport = new Maho\DataObject(['html' => '<div>anonymous cart</div>']);

    (new Maho_PageCache_Model_Observer())->handleDynamicBlock(
        new Maho\Event\Observer(['block' => $block, 'transport' => $transport]),
    );

    expect($transport->getHtml())->toBe('<div>anonymous cart</div>');
});

it('replaces a marked block with a placeholder in a proxy page', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/mode', 'proxy');
    Mage::register('page_cache_request_cacheable', true);
    $block = new Mage_Core_Block_Text();
    $block->setNameInLayout('header.cart');
    $block->setData('page_cache_dynamic', 1);
    $transport = new Maho\DataObject(['html' => '<div>private cart</div>']);

    (new Maho_PageCache_Model_Observer())->handleDynamicBlock(
        new Maho\Event\Observer(['block' => $block, 'transport' => $transport]),
    );

    expect($transport->getHtml())->toStartWith('<!--page-cache-dynamic:')
        ->and($transport->getHtml())->not->toContain('private cart');
});

it('leaves an unmarked block unchanged', function () {
    Mage::register('page_cache_request_cacheable', true);
    $block = new Mage_Core_Block_Text();
    $block->setNameInLayout('header.cart');
    $transport = new Maho\DataObject(['html' => '<div>public</div>']);

    (new Maho_PageCache_Model_Observer())->handleDynamicBlock(
        new Maho\Event\Observer(['block' => $block, 'transport' => $transport]),
    );

    expect($transport->getHtml())->toBe('<div>public</div>');
});

it('captures a requested marked block on a dynamic request', function () {
    Mage::register('page_cache_dynamic_request', true);
    Mage::register('page_cache_dynamic_blocks', ['header.cart']);
    $block = new Mage_Core_Block_Text();
    $block->setNameInLayout('header.cart');
    $block->setData('page_cache_dynamic', 1);
    $transport = new Maho\DataObject(['html' => '<div>private cart</div>']);

    (new Maho_PageCache_Model_Observer())->handleDynamicBlock(
        new Maho\Event\Observer(['block' => $block, 'transport' => $transport]),
    );

    expect(Mage::registry('page_cache_dynamic_output'))->toBe(['header.cart' => '<div>private cart</div>'])
        ->and($transport->getHtml())->toBe('<div>private cart</div>');
});

it('does not skip session for a dynamic request', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/cacheable_routes', 'catalog_product_view');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::app()->getRequest()->setRoutingInfo([
        'requested_route' => 'catalog',
        'requested_controller' => 'product',
        'requested_action' => 'view',
    ]);
    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};

    (new Maho_PageCache_Model_Observer())->skipSession(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    expect(Mage::registry('page_cache_dynamic_request'))->toBeTrue()
        ->and($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION))->toBeFalse()
        ->and(Mage::registry('page_cache_request_cacheable'))->toBeNull();
});

it('rate limits dynamic requests', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/cacheable_routes', 'catalog_product_view');
    Mage::app()->getStore()->setConfig('page_cache/dynamic/rate_limit', '1');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::app()->getRequest()->setRoutingInfo([
        'requested_route' => 'catalog',
        'requested_controller' => 'product',
        'requested_action' => 'view',
    ]);
    $observer = new Maho_PageCache_Model_Observer();
    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};

    $observer->skipSession(new Maho\Event\Observer(['controller_action' => $controller]));
    expect(Mage::registry('page_cache_dynamic_request'))->toBeTrue();

    Mage::unregister('page_cache_dynamic_request');
    Mage::unregister('page_cache_dynamic_blocks');
    Mage::unregister('page_cache_bypass_reason');
    Mage::app()->getResponse()->setHttpResponseCode(200);
    $observer->skipSession(new Maho\Event\Observer(['controller_action' => $controller]));

    expect(Mage::registry('page_cache_dynamic_request'))->toBeNull()
        ->and(Mage::registry('page_cache_bypass_reason'))->toBe('rate_limit')
        ->and(Mage::app()->getResponse()->getHttpResponseCode())->toBe(429)
        ->and($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeTrue();
});

it('rejects a dynamic request on a non-cacheable route', function () {
    Mage::app()->getStore()->setConfig('page_cache/general/cacheable_routes', 'catalog_product_view');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::app()->getRequest()->setRoutingInfo([
        'requested_route' => 'checkout',
        'requested_controller' => 'cart',
        'requested_action' => 'add',
    ]);
    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};

    (new Maho_PageCache_Model_Observer())->skipSession(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    expect(Mage::registry('page_cache_dynamic_request'))->toBeNull()
        ->and(Mage::registry('page_cache_bypass_reason'))->toBe('route')
        ->and(Mage::registry('page_cache_request_cacheable'))->toBeNull();
});

it('returns requested dynamic blocks as json', function () {
    $context = new Maho_PageCache_Model_Context();
    $service = new Maho_PageCache_Service_Dynamic();
    $token = $service->createToken('header.cart', $context);

    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', $token);
    Mage::register('page_cache_dynamic_request', true);
    Mage::register('page_cache_dynamic_blocks', ['header.cart']);
    Mage::register('page_cache_dynamic_output', ['header.cart' => '<div>cart</div>']);

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $response = Mage::app()->getResponse();
    $payload = json_decode((string) $response->getBody(), true);

    expect($response->getHttpResponseCode())->toBe(200)
        ->and(dynamicHeader($response, 'Cache-Control'))->toContain('private')
        ->and(dynamicHeader($response, 'Cache-Control'))->toContain('no-store')
        ->and(dynamicHeader($response, 'X-Maho-Cache'))->toBe('DYNAMIC')
        ->and($payload['blocks']['header.cart'])->toBe('<div>cart</div>')
        ->and($payload['form_key'])->not->toBeEmpty();
});

it('returns the raw block html for an esi request', function () {
    $context = new Maho_PageCache_Model_Context();
    $service = new Maho_PageCache_Service_Dynamic();
    $token = $service->createToken('header.cart', $context);

    Mage::app()->getRequest()->getSymfonyRequest()->query->set('_page_cache_dynamic', $token);
    Mage::register('page_cache_dynamic_request', true);
    Mage::register('page_cache_dynamic_blocks', ['header.cart']);
    Mage::register('page_cache_dynamic_output', ['header.cart' => '<div>cart</div>']);

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $response = Mage::app()->getResponse();
    expect($response->getBody())->toBe('<div>cart</div>')
        ->and(dynamicHeader($response, 'Content-Type'))->toContain('text/html')
        ->and(dynamicHeader($response, 'X-Maho-Cache'))->toBe('DYNAMIC');
});

it('rejects an unknown dynamic block token', function () {
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache-Blocks', 'bad.token');
    Mage::register('page_cache_dynamic_request', true);

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    expect(Mage::app()->getResponse()->getHttpResponseCode())->toBe(400)
        ->and(json_decode((string) Mage::app()->getResponse()->getBody(), true)['error'])->toBeTrue();
});

it('caches a dynamic response only when every requested block is cacheable', function () {
    $context = dynamicTestContext();
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => ['PAGE_CACHE'], 'version' => 1, 'ttl' => 3600],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        7200,
    );
    $service = new Maho_PageCache_Service_Dynamic();

    $service->saveResponse($context, ['header.cart'], ['header.cart' => '<div>cart</div>'], 60);

    expect($service->loadResponse($context, ['header.cart']))->toBe(['header.cart' => '<div>cart</div>']);

    (new Maho_PageCache_Model_Version())->incrementUrl($context->getUrlHash());

    expect($service->loadResponse($context, ['header.cart']))->toBeNull();
});

it('does not cache a dynamic response when the ttl is zero', function () {
    $context = dynamicTestContext();
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => ['PAGE_CACHE'], 'version' => 1, 'ttl' => 3600],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        7200,
    );
    $service = new Maho_PageCache_Service_Dynamic();

    $service->saveResponse($context, ['header.cart'], ['header.cart' => '<div>cart</div>'], 0);

    expect($service->loadResponse($context, ['header.cart']))->toBeNull();
});

it('serves a cached dynamic response before dispatch', function () {
    $context = new Maho_PageCache_Model_Context();
    $key = new Maho_PageCache_Model_Key();
    Mage::app()->getCache()->save(
        ['url' => $context->getPurgeUrl(), 'tags' => ['PAGE_CACHE'], 'version' => 1, 'ttl' => 3600],
        $key->getMetaKey($context->getUrlHash()),
        ['PAGE_CACHE'],
        7200,
    );
    (new Maho_PageCache_Service_Dynamic())->saveResponse(
        $context,
        ['header.cart'],
        ['header.cart' => '<div>cached cart</div>'],
        60,
    );

    Mage::app()->getRequest()->getSymfonyRequest()->headers->set('X-Maho-Page-Cache', 'dynamic');
    Mage::register('page_cache_dynamic_request', true);
    Mage::register('page_cache_dynamic_blocks', ['header.cart']);
    $controller = new class (Mage::app()->getRequest(), Mage::app()->getResponse()) extends Mage_Core_Controller_Varien_Action {};

    (new Maho_PageCache_Model_Observer())->serveDynamicHit(
        new Maho\Event\Observer(['controller_action' => $controller]),
    );

    $payload = json_decode((string) Mage::app()->getResponse()->getBody(), true);
    expect($controller->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH))->toBeTrue()
        ->and($payload['blocks']['header.cart'])->toBe('<div>cached cart</div>')
        ->and(dynamicHeader(Mage::app()->getResponse(), 'X-Maho-Cache'))->toBe('DYNAMIC');
});

it('sanitizes and saves a local page with a form key', function () {
    $formKey = Mage::getSingleton('core/session')->getFormKey();
    Mage::register('page_cache_request_cacheable', true);
    Mage::app()->getResponse()->setBody('<html><body><form><input name="form_key" value="' . $formKey . '"></form></body></html>');

    (new Maho_PageCache_Model_Observer())->sendResponse(
        new Maho\Event\Observer(['front' => Mage::app()->getFrontController()]),
    );

    $body = (string) Mage::app()->getResponse()->getBody();
    $entry = (new Maho_PageCache_Model_HtmlCache())->load(new Maho_PageCache_Model_Context());

    expect($body)->not->toContain($formKey)
        ->and($body)->toContain('{{PAGE_CACHE_FORM_KEY}}')
        ->and($body)->toContain('Page cache form key request failed')
        ->and($entry)->not->toBeNull()
        ->and($entry['html'])->not->toContain($formKey)
        ->and($entry['html'])->toContain('{{PAGE_CACHE_FORM_KEY}}');
});
