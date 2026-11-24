<?php

/**
 * Page cache overview grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Summary extends Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_summary_grid');
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $summary = Mage::getSingleton('page_cache/stat')->getSummary($this->getDays(), $this->getStoreId());
        $duration = $summary['avg_duration_ms'] === null
            ? $this->__('n/a')
            : number_format((float) $summary['avg_duration_ms'], 1) . ' ms';

        // @phpstan-ignore argument.type
        $this->setCollection($this->makeCollection([
            ['metric' => $this->__('Hit rate'), 'value' => number_format((float) $summary['hit_rate'], 1) . '%'],
            ['metric' => $this->__('PHP duration'), 'value' => $duration],
            ['metric' => $this->__('Hit'), 'value' => (int) $summary['hit']],
            ['metric' => $this->__('Miss'), 'value' => (int) $summary['miss']],
            ['metric' => $this->__('Bypass'), 'value' => (int) $summary['bypass']],
            ['metric' => $this->__('Revalidated'), 'value' => (int) $summary['revalidated']],
            ['metric' => $this->__('Dynamic'), 'value' => (int) $summary['dynamic']],
            ['metric' => $this->__('Purge events'), 'value' => (int) $summary['purge']],
            ['metric' => $this->__('Flush events'), 'value' => (int) $summary['flush']],
            ['metric' => $this->__('Warmup events'), 'value' => (int) $summary['warmup']],
        ]));

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $this->addColumn('metric', [
            'header' => $this->__('Metric'),
            'index' => 'metric',
            'type' => 'string',
            'sortable' => false,
        ]);
        $this->addColumn('value', [
            'header' => $this->__('Value'),
            'index' => 'value',
            'type' => 'string',
            'sortable' => false,
        ]);

        return parent::_prepareColumns();
    }
}
