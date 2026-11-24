<?php

/**
 * Base grid for one page cache report tab.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

abstract class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract extends Mage_Adminhtml_Block_Widget_Grid
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setFilterVisibility(false);
        $this->setPagerVisibility(false);
        $this->setUseAjax(false);
        $this->setEmptyText($this->__('No records found for this period.'));
    }

    protected function getDays(): int
    {
        $days = (int) $this->getRequest()->getParam('days', 1);
        return in_array($days, [1, 7, 30], true) ? $days : 1;
    }

    protected function getStoreId(): int
    {
        return max(0, (int) $this->getRequest()->getParam('store_id', 0));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function makeCollection(array $rows): Maho_PageCache_Model_Collection
    {
        $collection = new Maho_PageCache_Model_Collection();
        foreach ($rows as $row) {
            $collection->addItem(new \Maho\DataObject($row));
        }

        return $collection;
    }
}
