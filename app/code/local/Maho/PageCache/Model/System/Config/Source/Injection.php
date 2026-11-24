<?php

/**
 * Dynamic block injection source for the admin configuration.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_System_Config_Source_Injection
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'ajax',
                'label' => Mage::helper('page_cache')->__('Ajax (works without a reverse proxy)'),
            ],
            [
                'value' => 'esi',
                'label' => Mage::helper('page_cache')->__('ESI (proxy modes only)'),
            ],
        ];
    }
}
