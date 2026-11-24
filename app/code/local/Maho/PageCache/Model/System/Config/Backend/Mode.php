<?php

/**
 * Validates the configured page cache mode.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_System_Config_Backend_Mode extends Mage_Core_Model_Config_Data
{
    public function isValidMode(string $value): bool
    {
        return in_array($value, [
            Maho_PageCache_Helper_Data::MODE_PROXY,
            Maho_PageCache_Helper_Data::MODE_REVALIDATING,
            Maho_PageCache_Helper_Data::MODE_LOCAL,
        ], true);
    }

    #[\Override]
    protected function _beforeSave()
    {
        if (!$this->isValidMode((string) $this->getValue())) {
            Mage::throwException(
                Mage::helper('page_cache')->__('Invalid page cache mode. Choose Proxy, Revalidating or Local.'),
            );
        }
        return parent::_beforeSave();
    }
}
