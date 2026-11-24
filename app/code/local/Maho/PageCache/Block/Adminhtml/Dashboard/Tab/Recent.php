<?php

/**
 * Page cache recent events grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Recent extends Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_recent_grid');
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $this->setCollection($this->makeCollection(
            Mage::getSingleton('page_cache/stat')->getRecentEvents($this->getDays()),
        ));

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $this->addColumn('created_at', [
            'header' => $this->__('Time'),
            'index' => 'created_at',
            'type' => 'string',
            'sortable' => false,
        ]);
        foreach (['event_type' => 'Type', 'route' => 'Route', 'reason' => 'Reason'] as $index => $label) {
            $this->addColumn($index, [
                'header' => $this->__($label),
                'index' => $index,
                'type' => 'string',
                'sortable' => false,
            ]);
        }

        $this->addExports('Recent');

        return parent::_prepareColumns();
    }
}
