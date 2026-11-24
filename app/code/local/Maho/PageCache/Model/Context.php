<?php

/**
 * Cache context for the storefront request.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Context
{
    private array $data;

    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(?array $data = null)
    {
        $this->data = ($data === null || $data === []) ? $this->collect() : $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        $data = $this->data;
        $data['params'] = $this->getParams();
        return $data;
    }

    public function getHash(): string
    {
        return hash('sha256', (string) json_encode($this->getContext(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function getUrl(): string
    {
        return $this->getScheme() . '://' . $this->getHost() . $this->getPath();
    }

    public function getPurgeUrl(): string
    {
        $url = $this->getUrl();
        $params = $this->getParams();
        if ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    public function getUrlHash(): string
    {
        return Mage::getModel('page_cache/key')->getUrlHash($this->getUrl());
    }

    public function getStoreId(): int
    {
        return (int) ($this->data['store_id'] ?? 0);
    }

    public function getWebsiteId(): int
    {
        return (int) ($this->data['website_id'] ?? 0);
    }

    public function getCurrencyCode(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    public function getCustomerGroupId(): int
    {
        return (int) ($this->data['customer_group'] ?? 0);
    }

    public function getTaxCountry(): string
    {
        return (string) ($this->data['tax_country'] ?? '');
    }

    public function getTaxRate(): float
    {
        return (float) ($this->data['tax_rate'] ?? 0.0);
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        $params = $this->data['params'] ?? [];
        if (!is_array($params)) {
            return [];
        }
        $allowed = Mage::helper('page_cache')->getAllowedParams();
        $params = array_filter(
            $params,
            static fn(mixed $value, string $key): bool => is_scalar($value) && in_array($key, $allowed, true),
            ARRAY_FILTER_USE_BOTH,
        );
        $params = array_map(static fn(mixed $value): string => (string) $value, $params);
        ksort($params, SORT_STRING);
        return $params;
    }

    public function getScheme(): string
    {
        return (string) ($this->data['scheme'] ?? 'http');
    }

    public function getHost(): string
    {
        return (string) ($this->data['host'] ?? '');
    }

    public function getPath(): string
    {
        $path = (string) ($this->data['path'] ?? '/');
        return $path !== '' ? $path : '/';
    }

    public function getPublicPath(): string
    {
        $path = (string) ($this->data['public_path'] ?? $this->getPath());
        return $path !== '' ? $path : '/';
    }

    public function getMode(): string
    {
        return (string) ($this->data['mode'] ?? '');
    }

    private function getRequestPath(Mage_Core_Controller_Request_Http $request): string
    {
        $path = (string) ($request->getOriginalPathInfo() ?: $request->getPathInfo());
        return $path !== '' ? $path : '/';
    }

    private function collectPublicPath(Mage_Core_Controller_Request_Http $request): string
    {
        $path = $this->getRequestPath($request);
        if (Mage::getStoreConfigFlag(Mage_Core_Model_Store::XML_PATH_STORE_IN_URL)) {
            $storeCode = (string) $request->getStoreCodeFromPath();
            $prefix = '/' . $storeCode;
            if ($storeCode !== '' && $path === $prefix) {
                $path = '/';
            } elseif ($storeCode !== '' && str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
            }
        }
        return $path !== '' ? $path : '/';
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $request = Mage::app()->getRequest();
        $store = Mage::app()->getStore();
        $allowedParams = Mage::helper('page_cache')->getAllowedParams();
        $query = $request->getQuery();
        $params = [];
        if (is_array($query)) {
            foreach ($query as $key => $value) {
                if (in_array((string) $key, $allowedParams, true) && is_scalar($value)) {
                    $params[(string) $key] = (string) $value;
                }
            }
        }

        return [
            'website_id' => (int) $store->getWebsiteId(),
            'store_id' => (int) $store->getId(),
            'currency' => (string) $store->getDefaultCurrencyCode(),
            'customer_group' => Mage_Customer_Model_Group::NOT_LOGGED_IN_ID,
            'tax_country' => (string) Mage::getStoreConfig('general/country/default', $store->getId()),
            'tax_rate' => 0.0,
            'params' => $params,
            'scheme' => (string) $request->getScheme(),
            'host' => (string) $request->getHttpHost(false),
            'path' => $this->getRequestPath($request),
            'public_path' => $this->collectPublicPath($request),
            'mode' => Mage::helper('page_cache')->getMode(),
        ];
    }
}
