<?php

/**
 * Statistics mode options.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_System_Config_Source_StatsMode
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('page_cache');
        return [
            ['value' => Maho_PageCache_Model_Stat::MODE_OFF, 'label' => (string) $helper->__('Off')],
            ['value' => Maho_PageCache_Model_Stat::MODE_FULL, 'label' => (string) $helper->__('Full')],
            ['value' => Maho_PageCache_Model_Stat::MODE_SAMPLED, 'label' => (string) $helper->__('Sampled')],
        ];
    }
}
