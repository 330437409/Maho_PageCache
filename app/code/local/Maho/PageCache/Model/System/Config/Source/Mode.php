<?php

/**
 * Page cache mode source for the admin configuration.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_System_Config_Source_Mode
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Maho_PageCache_Helper_Data::MODE_REVALIDATING,
                'label' => Mage::helper('page_cache')->__('Revalidating (ETag / 304)'),
            ],
            [
                'value' => Maho_PageCache_Helper_Data::MODE_PROXY,
                'label' => Mage::helper('page_cache')->__('Proxy (reverse proxy or CDN)'),
            ],
            [
                'value' => Maho_PageCache_Helper_Data::MODE_LOCAL,
                'label' => Mage::helper('page_cache')->__('Local (full page HTML in Maho cache)'),
            ],
        ];
    }
}
