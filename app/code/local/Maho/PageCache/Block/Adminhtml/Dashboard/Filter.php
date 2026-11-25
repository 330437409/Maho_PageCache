<?php

/**
 * Range and store filter of the page cache report tabs.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Filter extends Mage_Adminhtml_Block_Template
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('maho/pagecache/filter.phtml');
    }

    public function getDashboard(): Maho_PageCache_Block_Adminhtml_Dashboard
    {
        $dashboard = $this->getParentBlock();
        if (!$dashboard instanceof Maho_PageCache_Block_Adminhtml_Dashboard) {
            throw new RuntimeException('The page cache filter needs the dashboard block as its parent.');
        }

        return $dashboard;
    }
}
