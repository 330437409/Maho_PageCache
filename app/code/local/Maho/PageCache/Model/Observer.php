<?php

/**
 * Page cache event observers.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Observer
{
    #[\Maho\Config\Observer('controller_action_predispatch_session_start')]
    public function skipSession(Maho\Event\Observer $observer): void
    {
        $controller = $observer->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        try {
            Mage::unregister('page_cache_dynamic_request');
            Mage::unregister('page_cache_dynamic_blocks');
            Mage::unregister('page_cache_dynamic_seen');
            Mage::unregister('page_cache_dynamic_output');
            Mage::unregister('page_cache_dynamic_cacheable');
            Mage::unregister('page_cache_dynamic_hit');
            Mage::unregister('page_cache_bypass_reason');
            Mage::unregister('page_cache_request_cacheable');
            Mage::unregister('page_cache_session_skipped');
            Mage::unregister('page_cache_local_hit');
            Mage::unregister('page_cache_local_bypass_reason');
            Mage::unregister('page_cache_not_modified');
            Mage::unregister('page_cache_tags');
            Mage::unregister('page_cache_server_blocks');
            $dynamic = Mage::getService('page_cache/dynamic');
            if ($dynamic->isRequest()) {
                Mage::register('page_cache_dynamic_request', true);
                try {
                    Mage::register(
                        'page_cache_dynamic_blocks',
                        $dynamic->getRequestedBlocks(Mage::getModel('page_cache/context')),
                    );
                } catch (\InvalidArgumentException) {
                    Mage::register('page_cache_dynamic_blocks', []);
                }
                return;
            }

            $service = Mage::getService('page_cache/request');
            $input = $service->getInput($controller);
            $reason = $service->evaluate($input);

            if ($reason !== null) {
                Mage::register('page_cache_bypass_reason', $reason);
                return;
            }

            Mage::register('page_cache_request_cacheable', true);
            $canSkip = $service->canSkipSession($input);
            if ($canSkip) {
                $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true);
                Mage::register('page_cache_session_skipped', true);
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\Observer('controller_action_predispatch')]
    public function checkNotModified(Maho\Event\Observer $observer): void
    {
        if (!Mage::registry('page_cache_request_cacheable')) {
            return;
        }

        $helper = Mage::helper('page_cache');
        if (!$helper->isEnabled() || $helper->getMode() !== Maho_PageCache_Helper_Data::MODE_REVALIDATING) {
            return;
        }

        $controller = $observer->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        $request = $controller->getRequest();
        $ifNoneMatch = (string) $request->getHeader('If-None-Match');
        if ($ifNoneMatch === '') {
            return;
        }

        try {
            $context = Mage::getModel('page_cache/context');
            $service = Mage::getService('page_cache/response');
            $ttl = $helper->getTtl();
            if (!$service->isNotModified($context, $ttl, $ifNoneMatch)) {
                return;
            }

            $urlVersion = $service->getMetaVersion($context);
            if ($urlVersion === null) {
                return;
            }
            $service->applyNotModified(
                $context,
                $service->getEtag($context, $ttl, $urlVersion),
                $service->getLastModified($context),
                $helper->isDebugHeaders(),
            );
            $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
            Mage::register('page_cache_not_modified', true);
            Mage::getModel('page_cache/counter')->increment(Maho_PageCache_Model_Counter::RESULT_REVALIDATED);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\Observer('controller_action_predispatch')]
    public function serveLocalHit(Maho\Event\Observer $observer): void
    {
        if (!Mage::registry('page_cache_request_cacheable')) {
            return;
        }

        $helper = Mage::helper('page_cache');
        if (!$helper->isEnabled() || $helper->getMode() !== Maho_PageCache_Helper_Data::MODE_LOCAL) {
            return;
        }

        $controller = $observer->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        if (Mage::registry('page_cache_local_lock_name')) {
            return;
        }

        $lockName = null;
        try {
            $context = Mage::getModel('page_cache/context');
            $cache = Mage::getSingleton('page_cache/htmlCache');
            $entry = $cache->load($context);
            if ($entry !== null) {
                $this->serveLocalEntry($controller, $context, $entry, $helper);
                return;
            }

            $versions = $cache->getVersions($context);
            $lockName = $cache->getLockName($context, $versions);
            $lock = Mage::getSingleton('core/lock');
            if (!$lock->acquire($lockName) && !$lock->acquireWithin($lockName, $this->getLockWaitSeconds())) {
                Mage::register('page_cache_local_bypass_reason', 'lock_timeout');
                return;
            }

            $entry = $cache->load($context, $versions);
            if ($entry !== null) {
                $lock->release($lockName);
                $lockName = null;
                $this->serveLocalEntry($controller, $context, $entry, $helper);
                return;
            }

            Mage::register('page_cache_local_lock_name', $lockName);
            Mage::register('page_cache_local_versions', $versions);
            $lockName = null;
        } catch (\Throwable $e) {
            Mage::logException($e);
        } finally {
            if (is_string($lockName) && $lockName !== '') {
                Mage::getSingleton('core/lock')->release($lockName);
            }
        }
    }

    #[\Maho\Config\Observer('core_block_abstract_to_html_after')]
    public function collectBlockTags(Maho\Event\Observer $observer): void
    {
        if (!Mage::registry('page_cache_request_cacheable')) {
            return;
        }

        $block = $observer->getBlock();
        if (!$block instanceof Mage_Core_Block_Abstract) {
            return;
        }
        if ($block->getData('page_cache_dynamic')
            && Mage::helper('page_cache')->getMode() !== Maho_PageCache_Helper_Data::MODE_LOCAL
        ) {
            return;
        }

        try {
            $tags = $block->getCacheTags();
        } catch (\Throwable $e) {
            Mage::logException($e);
            return;
        }
        if (!is_array($tags)) {
            return;
        }

        $current = Mage::registry('page_cache_tags');
        if (!is_array($current)) {
            $current = [];
        }
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $current[$tag] = true;
            }
        }
        Mage::unregister('page_cache_tags');
        Mage::register('page_cache_tags', $current);
    }

    #[\Maho\Config\Observer('controller_action_predispatch')]
    public function serveDynamicHit(Maho\Event\Observer $observer): void
    {
        if (!Mage::registry('page_cache_dynamic_request')) {
            return;
        }

        $controller = $observer->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        $blocks = Mage::registry('page_cache_dynamic_blocks');
        if (!is_array($blocks) || $blocks === []) {
            return;
        }

        $helper = Mage::helper('page_cache');
        if (!$helper->isEnabled()) {
            return;
        }

        try {
            $context = Mage::getModel('page_cache/context');
            $cached = Mage::getService('page_cache/dynamic')->loadResponse($context, $blocks);
            if ($cached === null) {
                return;
            }

            $this->writeDynamicResponse($helper, $cached);
            $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
            Mage::register('page_cache_dynamic_hit', true);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\Observer('core_block_abstract_to_html_after')]
    public function handleDynamicBlock(Maho\Event\Observer $observer): void
    {
        $block = $observer->getBlock();
        $transport = $observer->getTransport();
        if (!$block instanceof Mage_Core_Block_Abstract
            || !$transport instanceof \Maho\DataObject
            || !$block->getData('page_cache_dynamic')
        ) {
            return;
        }

        $name = (string) $block->getNameInLayout();
        if (Mage::registry('page_cache_dynamic_request')) {
            if ($name === '') {
                return;
            }

            $seen = Mage::registry('page_cache_dynamic_seen');
            $seen = is_array($seen) ? $seen : [];
            $seen[$name] = true;
            Mage::unregister('page_cache_dynamic_seen');
            Mage::register('page_cache_dynamic_seen', $seen);

            $cacheable = Mage::registry('page_cache_dynamic_cacheable');
            $cacheable = is_array($cacheable) ? $cacheable : [];
            $cacheable[$name] = (bool) $block->getData('page_cache_dynamic_cacheable');
            Mage::unregister('page_cache_dynamic_cacheable');
            Mage::register('page_cache_dynamic_cacheable', $cacheable);

            $requested = Mage::registry('page_cache_dynamic_blocks');
            if (is_array($requested) && in_array($name, $requested, true)) {
                $output = Mage::registry('page_cache_dynamic_output');
                $output = is_array($output) ? $output : [];
                $output[$name] = (string) $transport->getHtml();
                Mage::unregister('page_cache_dynamic_output');
                Mage::register('page_cache_dynamic_output', $output);
            }
            return;
        }

        if (!Mage::registry('page_cache_request_cacheable')) {
            return;
        }

        if (Mage::helper('page_cache')->getMode() === Maho_PageCache_Helper_Data::MODE_LOCAL) {
            if (!Mage::helper('page_cache')->getDynamicServerSide()
                || !$block->getData('page_cache_dynamic_server')
            ) {
                return;
            }

            try {
                $marker = Mage::getService('page_cache/dynamic')->createServerMarker(
                    $block,
                    (string) $transport->getHtml(),
                    Mage::getModel('page_cache/context'),
                    Mage::helper('page_cache')->getTtl(),
                );
                if ($marker !== null) {
                    $transport->setHtml($marker['html']);
                    $blocks = Mage::registry('page_cache_server_blocks');
                    $blocks = is_array($blocks) ? $blocks : [];
                    $blocks[$marker['id']] = $marker['definition'];
                    Mage::unregister('page_cache_server_blocks');
                    Mage::register('page_cache_server_blocks', $blocks);
                }
            } catch (\Throwable $e) {
                Mage::logException($e);
            }
            return;
        }

        if ($name === '') {
            $transport->setHtml('');
            Mage::unregister('page_cache_request_cacheable');
            Mage::register('page_cache_bypass_reason', 'dynamic_block_unnamed');
            return;
        }

        try {
            $transport->setHtml(
                Mage::getService('page_cache/dynamic')->getPlaceholder(
                    $name,
                    Mage::getModel('page_cache/context'),
                ),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            $transport->setHtml('');
            Mage::unregister('page_cache_request_cacheable');
            Mage::register('page_cache_bypass_reason', 'dynamic_token');
        }
    }

    #[\Maho\Config\Observer('controller_front_send_response_before')]
    public function sendResponse(Maho\Event\Observer $observer): void
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');

        try {
            if (!$helper->isEnabled()) {
                return;
            }

            if (Mage::registry('page_cache_dynamic_hit')) {
                return;
            }

            if (Mage::registry('page_cache_dynamic_request')) {
                $this->sendDynamicResponse($helper);
                return;
            }

            if (Mage::registry('page_cache_local_hit') || Mage::registry('page_cache_not_modified')) {
                return;
            }

            $service = Mage::getService('page_cache/response');
            if (!Mage::registry('page_cache_request_cacheable')) {
                $reason = (string) (Mage::registry('page_cache_bypass_reason')
                    ?: (Mage::app()->getRequest()->getMethod() !== 'GET' ? 'method' : 'unknown'));
                $service->applyBypass($reason, $helper->isDebugHeaders());
                Mage::getModel('page_cache/counter')->increment(
                    Maho_PageCache_Model_Counter::RESULT_BYPASS,
                    $reason,
                );
                return;
            }

            $front = $observer->getFront();
            if (!$front instanceof Mage_Core_Controller_Varien_Front) {
                return;
            }
            $response = $front->getResponse();
            $body = (string) $response->getBody();
            if ($response->getHttpResponseCode() !== 200 || $response->hasHeader('Set-Cookie')) {
                $service->applyBypass('response', $helper->isDebugHeaders());
                Mage::getModel('page_cache/counter')->increment(
                    Maho_PageCache_Model_Counter::RESULT_BYPASS,
                    'response',
                );
                return;
            }

            if ($body === '') {
                $service->applyBypass('empty_body', $helper->isDebugHeaders());
                Mage::getModel('page_cache/counter')->increment(
                    Maho_PageCache_Model_Counter::RESULT_BYPASS,
                    'empty_body',
                );
                return;
            }

            $dynamic = Mage::getService('page_cache/dynamic');
            $sanitized = $dynamic->sanitizeHtml($body);
            if ($sanitized !== $body) {
                $body = $sanitized;
                $response->setBody($body);
            }
            $withScript = $dynamic->injectClientScript($body);
            if ($withScript !== $body) {
                $body = $withScript;
                $response->setBody($body);
            }

            $context = Mage::getModel('page_cache/context');
            $tags = array_keys(Mage::registry('page_cache_tags') ?: []);
            $tags[] = 'PAGE_CACHE';
            $tags = array_values(array_unique($tags));
            $ttl = $helper->getTtl();

            if ($helper->getMode() === Maho_PageCache_Helper_Data::MODE_LOCAL) {
                $this->saveLocalResponse($context, $body, $tags, $ttl, $helper, $service);
                return;
            }

            $urlHash = $context->getUrlHash();
            $key = Mage::getModel('page_cache/key');
            $version = Mage::getSingleton('page_cache/version');
            $existingMeta = Mage::app()->getCache()->load($key->getMetaKey($urlHash));
            $urlVersion = max(
                is_array($existingMeta) && isset($existingMeta['version']) ? (int) $existingMeta['version'] : 1,
                $version->getUrl($urlHash),
            );
            $variants = Mage::getSingleton('page_cache/htmlCache')->buildMetaUrls(
                $urlHash,
                $context->getPurgeUrl(),
            );

            Mage::app()->getCache()->save(
                [
                    'url' => $context->getPurgeUrl(),
                    'urls' => $variants['urls'],
                    'variants_truncated' => $variants['truncated'],
                    'tags' => $tags,
                    'created_at' => time(),
                    'mode' => $context->getMode(),
                    'ttl' => $ttl,
                    'version' => $urlVersion,
                    'store_id' => $context->getStoreId(),
                ],
                $key->getMetaKey($urlHash),
                ['PAGE_CACHE'],
                $ttl + 3600,
            );
            Mage::getSingleton('page_cache/tagIndex')->add($urlHash, $tags);
            $service->apply($context, $ttl, $tags, $helper->isDebugHeaders(), $urlVersion);
            Mage::getModel('page_cache/counter')->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
        } catch (\Throwable $e) {
            Mage::logException($e);
        } finally {
            $this->releaseLocalLock();
        }
    }

    private function sendDynamicResponse(Maho_PageCache_Helper_Data $helper): void
    {
        $response = Mage::app()->getResponse();
        $dynamic = Mage::getService('page_cache/dynamic');
        $esi = $dynamic->isEsi();

        try {
            $context = Mage::getModel('page_cache/context');
            $blocks = $dynamic->getRequestedBlocks($context);
            $seen = Mage::registry('page_cache_dynamic_seen');
            $seen = is_array($seen) ? $seen : [];
            $output = Mage::registry('page_cache_dynamic_output');
            $output = is_array($output) ? $output : [];
            $seen += array_fill_keys(array_keys($output), true);

            foreach ($blocks as $block) {
                if (!isset($seen[$block])) {
                    throw new \InvalidArgumentException('Invalid page cache block token.');
                }
            }

            $blockHtml = [];
            foreach ($blocks as $block) {
                $blockHtml[$block] = (string) ($output[$block] ?? '');
            }

            if ($helper->getDynamicTtl() > 0 && $blockHtml !== []) {
                $cacheable = Mage::registry('page_cache_dynamic_cacheable');
                $cacheable = is_array($cacheable) ? $cacheable : [];
                $canCache = true;
                foreach ($blocks as $block) {
                    $canCache = $canCache && !empty($cacheable[$block]);
                }
                if ($canCache) {
                    $dynamic->saveResponse($context, $blocks, $blockHtml, $helper->getDynamicTtl());
                }
            }

            $this->writeDynamicResponse($helper, $blockHtml);
        } catch (\InvalidArgumentException) {
            $response->setHttpResponseCode(400);
            $response->setHeader('Cache-Control', 'private, no-store', true);
            $response->setHeader('X-Maho-Cache', 'DYNAMIC', true);
            $response->setHeader('X-Maho-Cache-Mode', $helper->getMode() ?: 'disabled', true);
            $response->setHeader(
                'Content-Type',
                $esi ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8',
                true,
            );
            $response->setBody(
                $esi
                    ? 'Invalid page cache block token.'
                    : (string) json_encode(['error' => true, 'message' => 'Invalid page cache block token.']),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            $response->setHttpResponseCode(500);
            $response->setHeader('Cache-Control', 'private, no-store', true);
            $response->setHeader('X-Maho-Cache', 'DYNAMIC', true);
            $response->setHeader('X-Maho-Cache-Mode', $helper->getMode() ?: 'disabled', true);
            $response->setHeader(
                'Content-Type',
                $esi ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8',
                true,
            );
            $response->setBody(
                $esi
                    ? 'Page cache dynamic block request failed.'
                    : (string) json_encode(['error' => true, 'message' => 'Page cache dynamic block request failed.']),
            );
        }
    }

    /**
     * @param array<string, string> $blockHtml
     */
    private function writeDynamicResponse(Maho_PageCache_Helper_Data $helper, array $blockHtml): void
    {
        $response = Mage::app()->getResponse();
        $dynamic = Mage::getService('page_cache/dynamic');
        $esi = $dynamic->isEsi();
        $response->setHttpResponseCode(200);
        $response->setHeader('Cache-Control', 'private, no-store', true);
        $response->setHeader('X-Maho-Cache', 'DYNAMIC', true);
        $response->setHeader('X-Maho-Cache-Mode', $helper->getMode() ?: 'disabled', true);
        Mage::getSingleton('page_cache/stat')->record(Maho_PageCache_Model_Stat::EVENT_DYNAMIC);

        if ($esi) {
            $block = array_key_first($blockHtml);
            $response->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
            $response->setBody($block === null ? '' : $blockHtml[$block]);
            return;
        }

        $payload = ['blocks' => (object) $blockHtml];
        $formKey = $dynamic->getFormKey();
        if ($formKey !== '') {
            $payload['form_key'] = $formKey;
        }
        $sid = $dynamic->getSid();
        if ($sid !== '') {
            $payload['sid'] = $sid;
        }

        $response->setHeader('Content-Type', 'application/json; charset=UTF-8', true);
        $response->setBody((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<string> $tags
     */
    private function saveLocalResponse(
        Maho_PageCache_Model_Context $context,
        string $body,
        array $tags,
        int $ttl,
        Maho_PageCache_Helper_Data $helper,
        Maho_PageCache_Service_Response $service,
    ): void {
        $counter = Mage::getModel('page_cache/counter');
        $debug = $helper->isDebugHeaders();
        $reason = Mage::registry('page_cache_local_bypass_reason');
        if (is_string($reason) && $reason !== '') {
            $service->applyBypass($reason, $debug);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS, $reason);
            return;
        }

        $cache = Mage::getSingleton('page_cache/htmlCache');
        $versions = Mage::registry('page_cache_local_versions');
        if (!is_array($versions) || !isset($versions['global'], $versions['url'])) {
            $versions = $cache->getVersions($context);
        }
        $versions = ['global' => (int) $versions['global'], 'url' => (int) $versions['url']];

        $current = $cache->getVersions($context);
        if ($current !== $versions) {
            $service->applyBypass('version_changed', $debug);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS, 'version_changed');
            return;
        }

        if ($cache->isTooLarge($body)) {
            $service->applyBypass('html_too_large', $debug);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS, 'html_too_large');
            return;
        }

        $dynamicBlocks = Mage::registry('page_cache_server_blocks');
        $entry = $cache->save(
            $context,
            $body,
            $tags,
            $ttl,
            $cache->collectHeaders(Mage::app()->getResponse()),
            $versions,
            is_array($dynamicBlocks) ? $dynamicBlocks : [],
        );
        if ($entry === null) {
            $service->applyBypass('cache_write', $debug);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS, 'cache_write');
            return;
        }

        Mage::getSingleton('page_cache/staticCache')->save($context, $body);

        if ($cache->getVersions($context) !== $versions) {
            $cache->remove($context, $versions);
            $service->applyBypass('version_changed', $debug);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_BYPASS, 'version_changed');
            return;
        }

        $service->applyLocalMiss($context, $ttl, $tags, $debug, $entry, $versions['url']);
        $counter->increment(Maho_PageCache_Model_Counter::RESULT_MISS);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function serveLocalEntry(
        Mage_Core_Controller_Varien_Action $controller,
        Maho_PageCache_Model_Context $context,
        array $entry,
        Maho_PageCache_Helper_Data $helper,
    ): void {
        $service = Mage::getService('page_cache/response');
        $request = $controller->getRequest();
        $debug = $helper->isDebugHeaders();
        $counter = Mage::getModel('page_cache/counter');

        if ($service->isLocalNotModified(
            $context,
            $entry,
            (string) $request->getHeader('If-None-Match'),
        )) {
            $service->applyLocalHitNotModified($context, $entry, $debug);
            $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
            Mage::register('page_cache_not_modified', true);
            Mage::register('page_cache_local_hit', true);
            $counter->increment(Maho_PageCache_Model_Counter::RESULT_REVALIDATED);
            return;
        }

        $service->applyLocalHit($context, $entry, $debug);
        $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
        Mage::register('page_cache_local_hit', true);
        $counter->increment(Maho_PageCache_Model_Counter::RESULT_HIT);
    }

    private function releaseLocalLock(): void
    {
        $lockName = Mage::registry('page_cache_local_lock_name');
        if (is_string($lockName) && $lockName !== '') {
            Mage::getSingleton('core/lock')->release($lockName);
            Mage::unregister('page_cache_local_lock_name');
        }
    }

    protected function getLockWaitSeconds(): int
    {
        return 5;
    }

    #[\Maho\Config\Observer('model_save_commit_after', id: 'page_cache_invalidate_save')]
    public function invalidateOnSave(Maho\Event\Observer $observer): void
    {
        $this->invalidateObject($observer->getObject());
    }

    #[\Maho\Config\Observer('cataloginventory_stock_item_save_after', id: 'page_cache_invalidate_stock')]
    public function invalidateOnStockSave(Maho\Event\Observer $observer): void
    {
        if (!Mage::helper('page_cache')->isEnabled()) {
            return;
        }

        $item = $observer->getItem();
        if (!$item instanceof Mage_CatalogInventory_Model_Stock_Item) {
            return;
        }

        try {
            Mage::getService('page_cache/invalidator')->invalidateStockItem($item);
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->invalidateAll();
        }
    }

    #[\Maho\Config\Observer('catalog_product_attribute_update_after', id: 'page_cache_invalidate_attribute_update')]
    public function invalidateOnAttributeUpdate(Maho\Event\Observer $observer): void
    {
        if (!Mage::helper('page_cache')->isEnabled()) {
            return;
        }

        $productIds = $observer->getProductIds();
        if (!is_array($productIds) || $productIds === []) {
            return;
        }

        try {
            Mage::getService('page_cache/invalidator')->invalidateProductIds($productIds);
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->invalidateAll();
        }
    }

    #[\Maho\Config\Observer('model_delete_commit_after', id: 'page_cache_invalidate_delete')]
    public function invalidateOnDelete(Maho\Event\Observer $observer): void
    {
        $this->invalidateObject($observer->getObject());
    }

    #[\Maho\Config\Observer('core_clean_cache', id: 'page_cache_clean_cache')]
    #[\Maho\Config\Observer('adminhtml_cache_flush_all', id: 'page_cache_flush_all')]
    public function flushAll(Maho\Event\Observer $observer): void
    {
        $this->invalidateAll('cache_clean');
    }

    #[\Maho\Config\Observer('admin_system_config_section_save_after', id: 'page_cache_config_save')]
    public function invalidateOnConfigSave(Maho\Event\Observer $observer): void
    {
        $this->invalidateAll('config');
    }

    #[\Maho\Config\Observer('adminhtml_cache_refresh_type', id: 'page_cache_refresh_type')]
    public function flushOnTypeRefresh(Maho\Event\Observer $observer): void
    {
        if ((string) $observer->getType() !== 'page_cache') {
            return;
        }

        $this->invalidateAll('cache_type_refresh');
    }

    #[\Maho\Config\Observer('controller_front_send_response_after', id: 'page_cache_stats_flush')]
    public function flushStats(): void
    {
        try {
            Mage::getSingleton('page_cache/stat')->flush();
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\CronJob('page_cache_static_cleanup', schedule: '*/5 * * * *')]
    public function cleanupStaticCache(): void
    {
        try {
            Mage::getSingleton('page_cache/staticCache')->cleanup(
                Mage::helper('page_cache')->getTtl() + 300,
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\CronJob('page_cache_stats_rollup', schedule: '5 * * * *')]
    public function rollupStats(): void
    {
        try {
            Mage::getSingleton('page_cache/stat')->rollup();
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\CronJob('page_cache_stats_cleanup', schedule: '15 3 * * *')]
    public function cleanupStats(): void
    {
        try {
            Mage::getSingleton('page_cache/stat')->cleanup();
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    #[\Maho\Config\CronJob('page_cache_warmup', schedule: '* * * * *')]
    public function warmQueue(): void
    {
        try {
            Mage::getService('page_cache/warm')->consumeQueue();
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    private function invalidateObject(mixed $object): void
    {
        if (!$object instanceof Mage_Core_Model_Abstract || !Mage::helper('page_cache')->isEnabled()) {
            return;
        }

        try {
            Mage::getService('page_cache/invalidator')->invalidateEntity($object);
        } catch (\Throwable $e) {
            Mage::logException($e);
            try {
                Mage::getService('page_cache/invalidator')->invalidateAll('error');
            } catch (\Throwable $inner) {
                Mage::logException($inner);
            }
        }
    }

    private function invalidateAll(string $reason = 'full'): void
    {
        try {
            Mage::getService('page_cache/invalidator')->invalidateAll($reason);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }
}
