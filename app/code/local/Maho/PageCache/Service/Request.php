<?php

/**
 * Cacheability and bypass policy for storefront requests.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Request
{
    /**
     * @param array<string, mixed> $input
     */
    public function evaluate(array $input): ?string
    {
        if (empty($input['enabled'])) {
            return 'disabled';
        }
        if (!in_array($input['mode'] ?? '', [
            Maho_PageCache_Helper_Data::MODE_PROXY,
            Maho_PageCache_Helper_Data::MODE_REVALIDATING,
            Maho_PageCache_Helper_Data::MODE_LOCAL,
        ], true)) {
            return 'invalid_mode';
        }
        if (($input['method'] ?? '') !== 'GET') {
            return 'method';
        }
        if (!empty($input['is_admin'])) {
            return 'admin';
        }

        $cacheableRoutes = $input['cacheable_routes'] ?? [];
        if (!in_array($input['route'] ?? '', $cacheableRoutes, true)) {
            return 'route';
        }

        $path = (string) ($input['path'] ?? '');
        $maxSegments = (int) ($input['max_path_segments'] ?? 0);
        if ($maxSegments > 0 && count($this->getPathSegments($path)) > $maxSegments) {
            return 'path_depth';
        }

        $marker = trim((string) ($input['filter_path_marker'] ?? ''), '/');
        if ($marker !== ''
            && $this->getFilterDepth($path, $marker) > max(0, (int) ($input['max_filter_depth'] ?? 0))
        ) {
            return 'filter_depth';
        }

        if (!empty($input['session_cookie'])) {
            return 'session_cookie';
        }

        $params = $input['params'] ?? [];
        $allowedParams = $input['allowed_params'] ?? [];
        if (is_array($params) && is_array($allowedParams)) {
            $unknown = array_diff(array_keys($params), $allowedParams);
            if ($unknown !== []) {
                return 'parameter';
            }
        }

        if (!empty($input['respect_no_cache']) && !empty($input['no_cache'])) {
            return 'no_cache';
        }
        if (!empty($input['ajax'])) {
            return 'ajax';
        }
        if (!empty($input['preview'])) {
            return 'preview';
        }
        if (!empty($input['maintenance'])) {
            return 'maintenance';
        }
        if (empty($input['context_safe'])) {
            return 'context';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function canSkipSession(array $input): bool
    {
        if ($this->evaluate($input) !== null) {
            return false;
        }
        $routes = $input['session_skip_routes'] ?? $input['cacheable_routes'] ?? [];
        return in_array($input['route'] ?? '', $routes, true);
    }

    public function getBypassReason(Mage_Core_Controller_Varien_Action $controller): ?string
    {
        return $this->evaluate($this->getInput($controller));
    }

    public function isCacheable(Mage_Core_Controller_Varien_Action $controller): bool
    {
        return $this->getBypassReason($controller) === null;
    }

    public function getContext(): Maho_PageCache_Model_Context
    {
        return Mage::getModel('page_cache/context');
    }

    /**
     * @return list<string>
     */
    private function getPathSegments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
    }

    private function getFilterDepth(string $path, string $marker): int
    {
        $needle = '/' . $marker . '/';
        $haystack = '/' . trim($path, '/') . '/';
        $position = strpos($haystack, $needle);
        if ($position === false) {
            return 0;
        }

        $tail = ltrim(substr($haystack, $position + strlen($needle)), '/');
        $tail = (string) preg_replace('/\.[a-z0-9]+$/i', '', $tail);
        $depth = 0;
        foreach (explode('/', $tail) as $segment) {
            if ($segment !== '') {
                $depth += substr_count($segment, '--') + 1;
            }
        }
        return $depth;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(Mage_Core_Controller_Varien_Action $controller): array
    {
        $request = $controller->getRequest();
        $helper = Mage::helper('page_cache');
        $sessionName = Mage_Core_Controller_Front_Action::SESSION_NAMESPACE;
        $query = $request->getQuery();
        $query = is_array($query) ? $query : [];
        $preview = (bool) $request->getParam('preview') || (bool) Mage::registry('page_cache_preview');

        return [
            'enabled' => $helper->isEnabled(),
            'mode' => $helper->getMode(),
            'method' => (string) $request->getMethod(),
            'is_admin' => Mage::app()->getStore()->isAdmin(),
            'route' => (string) $controller->getFullActionName(),
            'cacheable_routes' => $helper->getCacheableRoutes(),
            'path' => (string) $request->getPathInfo(),
            'filter_path_marker' => $helper->getFilterPathMarker(),
            'max_filter_depth' => $helper->getMaxFilterDepth(),
            'max_path_segments' => $helper->getMaxPathSegments(),
            'session_skip_routes' => $helper->getSessionSkipRoutes(),
            'session_cookie' => (bool) $request->getCookie($sessionName)
                || (bool) $request->getCookie($sessionName . '_cid'),
            'params' => $query,
            'allowed_params' => $helper->getAllowedParams(),
            'respect_no_cache' => $helper->respectNoCache(),
            'no_cache' => stripos((string) $request->getHeader('Cache-Control'), 'no-cache') !== false,
            'ajax' => strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest',
            'preview' => $preview,
            'maintenance' => file_exists(Mage::getBaseDir() . '/maintenance.flag'),
            'context_safe' => true,
        ];
    }
}
