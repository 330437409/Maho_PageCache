<?php

/**
 * Page cache top missed URL grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_TopMissed extends Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_top_missed_grid');
    }

    #[\Override]
    protected function _prepareCollection()
    {
        // @phpstan-ignore argument.type
        $this->setCollection($this->makeCollection(
            Mage::getSingleton('page_cache/stat')->getTopMissed($this->getDays(), $this->getStoreId()),
        ));

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $this->addColumn('url_path', [
            'header' => $this->__('Path'),
            'index' => 'url_path',
            'type' => 'string',
            'sortable' => false,
        ]);
        $this->addColumn('miss_count', [
            'header' => $this->__('Misses'),
            'index' => 'miss_count',
            'type' => 'number',
            'sortable' => false,
        ]);

        return parent::_prepareColumns();
    }
}
