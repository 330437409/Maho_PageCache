<?php

/**
 * Page cache trend grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Trend extends Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_trend_grid');
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $this->setCollection($this->makeCollection(
            Mage::getSingleton('page_cache/stat')->getTrend($this->getDays(), $this->getStoreId()),
        ));

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $this->addColumn('bucket', [
            'header' => $this->__('Bucket'),
            'index' => 'bucket',
            'type' => 'string',
            'sortable' => false,
        ]);
        foreach (['hit', 'miss', 'bypass', 'revalidated', 'dynamic'] as $column) {
            $this->addColumn($column, [
                'header' => $this->__($column === 'revalidated' ? 'Revalidated' : ucfirst($column)),
                'index' => $column,
                'type' => 'number',
                'sortable' => false,
            ]);
        }

        $this->addExports('Trend');

        return parent::_prepareColumns();
    }
}
