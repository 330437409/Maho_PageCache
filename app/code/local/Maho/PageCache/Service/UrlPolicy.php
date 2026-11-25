<?php

/**
 * Store origin allowlist for warmup and sitemap requests.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_UrlPolicy
{
    /** @var list<string>|null */
    private ?array $allowedOrigins = null;

    public function isAllowed(string $url): bool
    {
        $origin = $this->origin($url);
        return $origin !== null && in_array($origin, $this->getAllowedOrigins(), true);
    }

    /**
     * @return list<string>
     */
    public function getAllowedOrigins(): array
    {
        if ($this->allowedOrigins !== null) {
            return $this->allowedOrigins;
        }

        $origins = [];
        foreach (Mage::app()->getStores() as $store) {
            foreach ([false, true] as $secure) {
                $origin = $this->origin((string) $store->getBaseUrl(
                    Mage_Core_Model_Store::URL_TYPE_WEB,
                    $secure,
                ));
                if ($origin !== null) {
                    $origins[$origin] = true;
                }
            }
        }

        foreach (Mage::helper('page_cache')->getWarmupAllowedHosts() as $url) {
            $origin = $this->origin($url);
            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        return $this->allowedOrigins = array_keys($origins);
    }

    public function reset(): void
    {
        $this->allowedOrigins = null;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = rtrim(strtolower((string) $parts['host']), '.');
        if ($host === '' || preg_match('/[\s%\/@]/', $host) === 1) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            return null;
        }
        $port ??= $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ':' . $port;
    }
}
