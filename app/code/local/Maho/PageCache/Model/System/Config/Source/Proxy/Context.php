<?php

/**
 * Proxy context mode source for the admin configuration.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_System_Config_Source_Proxy_Context
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Maho_PageCache_Helper_Data::PROXY_CONTEXT_COOKIE,
                'label' => Mage::helper('page_cache')->__('Cookie (proxy honors Vary: Cookie)'),
            ],
            [
                'value' => Maho_PageCache_Helper_Data::PROXY_CONTEXT_DEFAULT,
                'label' => Mage::helper('page_cache')->__('Default context only (URL-only proxy)'),
            ],
        ];
    }
}
