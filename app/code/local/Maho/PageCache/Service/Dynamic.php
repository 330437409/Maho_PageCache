<?php

/**
 * Dynamic block placeholders, tokens and client side replacement.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Dynamic
{
    public const HEADER_REQUEST = 'X-Maho-Page-Cache';
    public const HEADER_BLOCKS = 'X-Maho-Page-Cache-Blocks';
    public const PARAM_ESI = '_page_cache_dynamic';
    public const TOKEN_TTL = 3600;
    public const PLACEHOLDER_PREFIX = 'page-cache-dynamic:';
    public const SERVER_PLACEHOLDER = 'PC_DYNAMIC_SERVER:';

    public function isRequest(): bool
    {
        $request = Mage::app()->getRequest();
        if (strtolower((string) $request->getHeader(self::HEADER_REQUEST)) === 'dynamic') {
            return true;
        }
        return $request->getParam(self::PARAM_ESI) !== null;
    }

    public function isEsi(): bool
    {
        return Mage::app()->getRequest()->getParam(self::PARAM_ESI) !== null;
    }

    /**
     * @return list<string>
     */
    public function getTokens(): array
    {
        if ($this->isEsi()) {
            $token = (string) Mage::app()->getRequest()->getParam(self::PARAM_ESI);
            return $token !== '' ? [$token] : [];
        }

        $header = (string) Mage::app()->getRequest()->getHeader(self::HEADER_BLOCKS);
        $tokens = array_map(trim(...), explode(',', $header));
        return array_values(array_filter($tokens, static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    public function getRequestedBlocks(Maho_PageCache_Model_Context $context): array
    {
        $tokens = $this->getTokens();
        if ($this->isEsi() && count($tokens) !== 1) {
            throw new \InvalidArgumentException('Invalid page cache block token.');
        }

        $blocks = [];
        foreach ($tokens as $token) {
            $payload = $this->verifyToken($token);
            if ($payload === null
                || (string) ($payload['url'] ?? '') !== $context->getCanonicalUrl()
                || (int) ($payload['store'] ?? 0) !== $context->getStoreId()
                || (string) ($payload['context'] ?? '') !== $context->getHash()
            ) {
                throw new \InvalidArgumentException('Invalid page cache block token.');
            }
            $block = (string) ($payload['block'] ?? '');
            if ($block === '') {
                throw new \InvalidArgumentException('Invalid page cache block token.');
            }
            $blocks[] = $block;
        }

        return array_values(array_unique($blocks));
    }

    public function getPlaceholder(
        string $block,
        Maho_PageCache_Model_Context $context,
        ?int $ttl = null,
    ): string {
        $expires = time() + max(self::TOKEN_TTL, max(0, (int) $ttl) + 300);
        $token = $this->createToken($block, $context, $expires);

        if ($this->helper()->getDynamicInjection() === 'esi'
            && $context->getMode() !== Maho_PageCache_Helper_Data::MODE_LOCAL
        ) {
            $params = $context->getParams();
            $params[self::PARAM_ESI] = $token;
            $url = $context->getCanonicalUrl() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            return '<esi:include src="' . $this->escape($url) . '" />';
        }

        return '<!--' . self::PLACEHOLDER_PREFIX
            . bin2hex($token) . ':' . bin2hex($block) . '-->';
    }

    public function createToken(string $block, Maho_PageCache_Model_Context $context, ?int $expires = null): string
    {
        $payload = [
            'v' => 1,
            'block' => $block,
            'url' => $context->getCanonicalUrl(),
            'store' => $context->getStoreId(),
            'context' => $context->getHash(),
            'exp' => $expires ?? time() + self::TOKEN_TTL,
        ];
        $encoded = $this->base64UrlEncode(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $signature = hash_hmac('sha256', $encoded, Mage::getEncryptionKeyAsHex(), true);
        return $encoded . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * @return array{html: string, id: string, definition: array<string, mixed>}|null
     */
    public function createServerMarker(
        Mage_Core_Block_Abstract $block,
        string $html,
        Maho_PageCache_Model_Context $context,
        int $ttl,
    ): ?array {
        $helper = $this->helper();
        if (!$helper->getDynamicServerSide() || !$block->getData('page_cache_dynamic_server')) {
            return null;
        }

        $type = (string) $block->getType();
        $name = (string) $block->getNameInLayout();
        $template = (string) $block->getTemplate();
        if ($type === '' || $name === '' || !in_array($type, $helper->getDynamicServerTypes(), true)) {
            return null;
        }

        $id = substr(hash('sha256', $name . '|' . $type . '|' . $template), 0, 16);
        $token = $this->createServerToken($id, $context, time() + max(60, $ttl) + 300);
        $html = '<!--' . self::SERVER_PLACEHOLDER . $token . '-->'
            . $html
            . '<!--/' . self::SERVER_PLACEHOLDER . $id . '-->';

        return [
            'html' => $html,
            'id' => $id,
            'definition' => [
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'template' => $template,
                'data' => $this->scalarData($block->getData()),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function renderServerBlocks(
        string $html,
        array $entry,
        Maho_PageCache_Model_Context $context,
    ): string {
        $blocks = $entry['dynamic_blocks'] ?? [];
        if (!Mage::helper('page_cache')->getDynamicServerSide()
            || !is_array($blocks)
            || $blocks === []
            || !str_contains($html, self::SERVER_PLACEHOLDER)
        ) {
            return $html;
        }

        try {
            $proxy = Mage::getService('page_cache/proxy');
            $pageContext = $entry['proxy_entities'] ?? [];
            $proxy->restorePageContext(is_array($pageContext) ? $pageContext : []);

            $references = [];
            foreach ($blocks as $definition) {
                $data = is_array($definition) ? ($definition['data'] ?? null) : null;
                if (is_array($data)
                    && isset($data['page_cache_proxies'])
                    && is_string($data['page_cache_proxies'])
                ) {
                    $references[] = $data['page_cache_proxies'];
                }
            }
            if ($references !== []) {
                $proxy->preload(implode(',', $references));
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        $rendered = 0;
        $failed = 0;
        $result = preg_replace_callback(
            $this->serverPattern(),
            function (array $matches) use ($blocks, $context, &$rendered, &$failed): string {
                $anonymous = $matches[2];
                $id = $matches[3];
                $payload = $this->verifyServerToken($matches[1]);
                if ($payload === null
                    || (string) ($payload['server'] ?? '') !== $id
                    || (string) ($payload['url'] ?? '') !== $context->getCanonicalUrl()
                    || (int) ($payload['store'] ?? 0) !== $context->getStoreId()
                    || (string) ($payload['context'] ?? '') !== $context->getHash()
                    || !isset($blocks[$id])
                    || !is_array($blocks[$id])
                ) {
                    $failed++;
                    return $anonymous;
                }

                try {
                    $definition = $blocks[$id];
                    $block = Mage::app()->getLayout()->createBlock(
                        (string) ($definition['type'] ?? ''),
                        (string) ($definition['name'] ?? ''),
                        is_array($definition['data'] ?? null) ? $definition['data'] : [],
                    );
                    if (!$block instanceof Mage_Core_Block_Abstract) {
                        $failed++;
                        return $anonymous;
                    }
                    $template = (string) ($definition['template'] ?? '');
                    if ($template !== '') {
                        $block->setTemplate($template);
                    }
                    $live = (string) $block->toHtml();
                    if ($live === '') {
                        $failed++;
                        return $anonymous;
                    }
                    $rendered++;
                    return $live;
                } catch (\Throwable $e) {
                    Mage::logException($e);
                    $failed++;
                    return $anonymous;
                }
            },
            $html,
        );

        if (!is_string($result)) {
            return $html;
        }

        if ($this->hasSetCookie()) {
            $this->clearSetCookie();
            Mage::log(
                'Page cache server-side dynamic block started a session; falling back to anonymous HTML.',
                Mage::LOG_WARNING,
            );
            return $html;
        }

        if ($this->helper()->isDebugHeaders()) {
            Mage::app()->getResponse()->setHeader(
                'X-Maho-Cache-Dynamic',
                sprintf('server; rendered=%d; failed=%d', $rendered, $failed),
                true,
            );
        }

        return $result;
    }

    public function stripServerBlockMarkers(string $html): string
    {
        if (!str_contains($html, self::SERVER_PLACEHOLDER)) {
            return $html;
        }

        $stripped = preg_replace($this->serverPattern(), '$2', $html);
        return is_string($stripped) ? $stripped : $html;
    }

    private function serverPattern(): string
    {
        $prefix = preg_quote(self::SERVER_PLACEHOLDER, '#');
        return '#<!--' . $prefix . '([A-Za-z0-9._-]+)-->(.*?)<!--/' . $prefix . '([a-f0-9]{16})-->#s';
    }

    private function createServerToken(string $id, Maho_PageCache_Model_Context $context, int $expires): string
    {
        $payload = [
            'v' => 2,
            'server' => $id,
            'url' => $context->getCanonicalUrl(),
            'store' => $context->getStoreId(),
            'context' => $context->getHash(),
            'exp' => $expires,
        ];
        $encoded = $this->base64UrlEncode(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $signature = hash_hmac('sha256', $encoded, Mage::getEncryptionKeyAsHex(), true);
        return $encoded . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifyServerToken(string $value): ?array
    {
        $parts = explode('.', $value);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encoded, Mage::getEncryptionKeyAsHex(), true),
        );
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 2
            || !is_string($payload['server'] ?? null)
            || (int) ($payload['exp'] ?? 0) <= time()
        ) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function scalarData(array $data): array
    {
        $exclude = [
            'layout',
            'type',
            'name_in_layout',
            'template',
            'page_cache_dynamic',
            'page_cache_dynamic_server',
            'page_cache_dynamic_cacheable',
        ];
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || in_array($key, $exclude, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
                continue;
            }
            if (is_array($value) && $this->isScalarArray($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     */
    private function isScalarArray(array $value): bool
    {
        return array_all($value, static fn(mixed $item): bool => is_scalar($item) || $item === null);
    }

    private function hasSetCookie(): bool
    {
        foreach (Mage::app()->getResponse()->getHeaders() as $header) {
            if (strcasecmp((string) $header['name'], 'Set-Cookie') === 0) {
                return true;
            }
        }
        return false;
    }

    private function clearSetCookie(): void
    {
        Mage::app()->getResponse()->clearHeader('Set-Cookie');
    }

    public function sanitizeHtml(string $html): string
    {
        $sid = $this->getSid();
        if ($sid !== '') {
            $html = str_replace($sid, '{{PAGE_CACHE_SID}}', $html);
        }

        $formKey = $this->getFormKey();
        if ($formKey !== '') {
            $html = str_replace($formKey, '{{PAGE_CACHE_FORM_KEY}}', $html);
        }

        return $html;
    }

    public function injectClientScript(string $html): string
    {
        $hasFormToken = str_contains($html, '{{PAGE_CACHE_FORM_KEY}}')
            || str_contains($html, '{{PAGE_CACHE_SID}}');
        $local = $this->helper()->getMode() === Maho_PageCache_Helper_Data::MODE_LOCAL;

        if ($local) {
            if (!$hasFormToken) {
                return $html;
            }
            $script = '<script>' . $this->getLazyFormScript() . '</script>';
        } else {
            if (!$hasFormToken && !str_contains($html, self::PLACEHOLDER_PREFIX)) {
                return $html;
            }
            $script = '<script>' . $this->getClientScript() . '</script>';
        }

        $position = strripos($html, '</body>');
        if ($position === false) {
            return $html . $script;
        }
        return substr($html, 0, $position) . $script . substr($html, $position);
    }

    /**
     * @param list<string> $blocks
     * @return array<string, string>|null
     */
    public function loadResponse(Maho_PageCache_Model_Context $context, array $blocks): ?array
    {
        if ($blocks === [] || !$this->hasMeta($context)) {
            return null;
        }

        $entry = Mage::app()->getCache()->load($this->getResponseCacheKey($context, $blocks));
        if (!is_array($entry) || !isset($entry['blocks']) || !is_array($entry['blocks'])) {
            return null;
        }

        $result = [];
        foreach ($blocks as $block) {
            if (!isset($entry['blocks'][$block]) || !is_string($entry['blocks'][$block])) {
                return null;
            }
            $result[$block] = $entry['blocks'][$block];
        }
        return $result;
    }

    /**
     * @param list<string> $blocks
     * @param array<string, string> $blockHtml
     */
    public function saveResponse(Maho_PageCache_Model_Context $context, array $blocks, array $blockHtml, int $ttl): void
    {
        if ($ttl <= 0 || $blocks === [] || !$this->hasMeta($context)) {
            return;
        }

        $stored = [];
        foreach ($blocks as $block) {
            if (!isset($blockHtml[$block])) {
                return;
            }
            $stored[$block] = $blockHtml[$block];
        }

        Mage::app()->getCache()->save(
            ['blocks' => $stored],
            $this->getResponseCacheKey($context, $blocks),
            ['PAGE_CACHE', 'PAGE_CACHE_DYNAMIC'],
            $ttl,
        );
    }

    public function getFormKey(): string
    {
        return (string) Mage::getSingleton('core/session')->getFormKey();
    }

    public function getSid(): string
    {
        $sid = Mage::getSingleton('core/session')->getSessionId();
        return is_string($sid) ? $sid : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifyToken(string $value): ?array
    {
        $parts = explode('.', $value);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encoded, Mage::getEncryptionKeyAsHex(), true),
        );
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 1
            || (int) ($payload['exp'] ?? 0) <= time()
        ) {
            return null;
        }
        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getLazyFormScript(): string
    {
        return <<<'JS'
        (function () {
            'use strict';
            var formToken = '{{PAGE_CACHE_FORM_KEY}}';
            var sidToken = '{{PAGE_CACHE_SID}}';
            var request = null;
            var submitting = false;
            function needsToken() {
                var inputs = document.querySelectorAll('input[name="form_key"]');
                for (var i = 0; i < inputs.length; i++) {
                    if ((inputs[i].value || '').indexOf(formToken) !== -1) {
                        return true;
                    }
                }
                var elements = document.querySelectorAll('[href], [action]');
                for (var j = 0; j < elements.length; j++) {
                    var attributes = elements[j].attributes;
                    for (var k = 0; k < attributes.length; k++) {
                        if (attributes[k].value.indexOf(formToken) !== -1
                            || attributes[k].value.indexOf(sidToken) !== -1) {
                            return true;
                        }
                    }
                }
                return false;
            }
            function replaceValue(token, value) {
                if (!value) {
                    return;
                }
                var elements = document.querySelectorAll('*');
                for (var i = 0; i < elements.length; i++) {
                    var attributes = elements[i].attributes;
                    for (var j = 0; j < attributes.length; j++) {
                        if (attributes[j].value.indexOf(token) !== -1) {
                            attributes[j].value = attributes[j].value.split(token).join(value);
                        }
                    }
                }
                var walker = document.createTreeWalker(document.documentElement, NodeFilter.SHOW_TEXT);
                var nodes = [];
                while (walker.nextNode()) {
                    nodes.push(walker.currentNode);
                }
                nodes.forEach(function (node) {
                    if (node.nodeValue.indexOf(token) !== -1) {
                        node.nodeValue = node.nodeValue.split(token).join(value);
                    }
                });
            }
            function load() {
                if (request) {
                    return request;
                }
                request = mahoFetch(window.location.href, {
                    headers: {'X-Maho-Page-Cache': 'dynamic'},
                    loaderArea: false
                }).then(function (data) {
                    replaceValue(formToken, data.form_key);
                    replaceValue(sidToken, data.sid);
                    if (typeof window.FORM_KEY !== 'undefined' && data.form_key) {
                        window.FORM_KEY = data.form_key;
                    }
                    if (window.minicart && data.form_key) {
                        window.minicart.formKey = data.form_key;
                    }
                    return data;
                }).catch(function (error) {
                    request = null;
                    console.error('Page cache form key request failed', error);
                    throw error;
                });
                return request;
            }
            document.addEventListener('focusin', function (event) {
                var form = event.target && event.target.closest ? event.target.closest('form') : null;
                if (form && form.method && form.method.toLowerCase() === 'post') {
                    load().catch(function () {});
                }
            }, true);
            document.addEventListener('click', function (event) {
                var target = event.target;
                var control = target && target.closest
                    ? target.closest('button, input[type="submit"], input[type="image"]')
                    : null;
                var form = control && control.form ? control.form : null;
                if (!control || !form || !needsToken()) {
                    return;
                }
                event.preventDefault();
                event.stopImmediatePropagation();
                if (control.getAttribute('data-page-cache-retry') === '1') {
                    control.removeAttribute('data-page-cache-retry');
                    return;
                }
                control.setAttribute('data-page-cache-retry', '1');
                load().then(function () {
                    control.removeAttribute('data-page-cache-retry');
                    if (!needsToken()) {
                        control.click();
                    }
                }).catch(function () {
                    control.removeAttribute('data-page-cache-retry');
                });
            }, true);
            document.addEventListener('submit', function (event) {
                var form = event.target;
                if (!needsToken() || !form || !form.method || form.method.toLowerCase() !== 'post') {
                    return;
                }
                event.preventDefault();
                if (submitting) {
                    return;
                }
                submitting = true;
                load().then(function () {
                    submitting = false;
                    if (needsToken()) {
                        return;
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }).catch(function () {
                    submitting = false;
                });
            }, true);
        })();
        JS;
    }

    private function getClientScript(): string
    {
        return <<<'JS'
        (function () {
            'use strict';
            function fromHex(hex) {
                var value = '';
                for (var i = 0; i + 1 < hex.length; i += 2) {
                    value += String.fromCharCode(parseInt(hex.substr(i, 2), 16));
                }
                return value;
            }
            function findPlaceholders(root) {
                var walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
                var found = [];
                while (walker.nextNode()) {
                    var node = walker.currentNode;
                    var value = node.nodeValue || '';
                    if (value.indexOf('page-cache-dynamic:') !== 0) {
                        continue;
                    }
                    var parts = value.split(':');
                    if (parts.length !== 3) {
                        continue;
                    }
                    var token = fromHex(parts[1]);
                    var name = fromHex(parts[2]);
                    if (token && name) {
                        found.push({node: node, token: token, name: name});
                    }
                }
                return found;
            }
            var placeholders = findPlaceholders(document.body || document.documentElement);
            var hasFormKey = document.documentElement.innerHTML.indexOf('{{PAGE_CACHE_FORM_KEY}}') !== -1;
            var hasSid = document.documentElement.innerHTML.indexOf('{{PAGE_CACHE_SID}}') !== -1;
            if (!placeholders.length && !hasFormKey && !hasSid) {
                return;
            }
            var tokens = [];
            placeholders.forEach(function (placeholder) {
                if (tokens.indexOf(placeholder.token) === -1) {
                    tokens.push(placeholder.token);
                }
            });
            var ready = false;
            function guard(event) {
                if (!ready) {
                    event.preventDefault();
                }
            }
            document.addEventListener('submit', guard, true);
            function replaceValue(root, token, value) {
                if (!value) {
                    return;
                }
                var elements = root.querySelectorAll('*');
                for (var i = 0; i < elements.length; i++) {
                    var attributes = elements[i].attributes;
                    for (var j = 0; j < attributes.length; j++) {
                        if (attributes[j].value.indexOf(token) !== -1) {
                            attributes[j].value = attributes[j].value.split(token).join(value);
                        }
                    }
                }
                var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                var nodes = [];
                while (walker.nextNode()) {
                    nodes.push(walker.currentNode);
                }
                nodes.forEach(function (node) {
                    if (node.nodeValue.indexOf(token) !== -1) {
                        node.nodeValue = node.nodeValue.split(token).join(value);
                    }
                });
            }
            var headers = {'X-Maho-Page-Cache': 'dynamic'};
            if (tokens.length) {
                headers['X-Maho-Page-Cache-Blocks'] = tokens.join(',');
            }
            mahoFetch(window.location.href, {headers: headers, loaderArea: false})
                .then(function (data) {
                    placeholders.forEach(function (placeholder) {
                        var html = data.blocks && data.blocks[placeholder.name]
                            ? data.blocks[placeholder.name]
                            : '';
                        var template = document.createElement('template');
                        template.innerHTML = html;
                        if (placeholder.node.parentNode) {
                            placeholder.node.parentNode.replaceChild(template.content, placeholder.node);
                        }
                    });
                    if (data.form_key) {
                        replaceValue(document, '{{PAGE_CACHE_FORM_KEY}}', data.form_key);
                        if (typeof window.FORM_KEY !== 'undefined') {
                            window.FORM_KEY = data.form_key;
                        }
                        if (window.minicart) {
                            window.minicart.formKey = data.form_key;
                        }
                    }
                    if (data.sid) {
                        replaceValue(document, '{{PAGE_CACHE_SID}}', data.sid);
                    }
                })
                .catch(function (error) {
                    console.error('Page cache dynamic block request failed', error);
                })
                .finally(function () {
                    ready = true;
                    document.removeEventListener('submit', guard, true);
                });
        })();
        JS;
    }

    /**
     * @param list<string> $blocks
     */
    private function getResponseCacheKey(Maho_PageCache_Model_Context $context, array $blocks): string
    {
        $blocks = array_values(array_unique($blocks));
        sort($blocks);
        $versions = Mage::getSingleton('page_cache/htmlCache')->getVersions($context);

        return 'PC_DYNAMIC_' . hash(
            'sha256',
            implode(',', $blocks)
            . '|' . $context->getUrl()
            . '|' . $context->getHash()
            . '|' . $this->getCustomerGroupId($context)
            . '|' . $context->getStoreId()
            . '|' . $versions['url']
            . '|' . $versions['global'],
        );
    }

    private function getCustomerGroupId(Maho_PageCache_Model_Context $context): int
    {
        try {
            $groupId = (int) Mage::getSingleton('customer/session')->getCustomerGroupId();
        } catch (\Throwable) {
            $groupId = 0;
        }
        return $groupId > 0 ? $groupId : $context->getCustomerGroupId();
    }

    private function hasMeta(Maho_PageCache_Model_Context $context): bool
    {
        $meta = Mage::app()->getCache()->load(
            Mage::getSingleton('page_cache/key')->getMetaKey($context->getUrlHash()),
        );
        return is_array($meta) && isset($meta['version']);
    }

    private function helper(): Maho_PageCache_Helper_Data
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');
        return $helper;
    }
}
