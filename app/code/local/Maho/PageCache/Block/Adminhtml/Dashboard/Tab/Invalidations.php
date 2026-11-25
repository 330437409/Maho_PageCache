<?php

/**
 * Page cache invalidation audit grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Invalidations extends Maho_PageCache_Block_Adminhtml_Dashboard_Tab_Abstract
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_invalidations_grid');
        if (!Mage::helper('page_cache')->getAuditEnabled()) {
            $this->setEmptyText($this->__('Invalidation audit is disabled. Enable it under System > Configuration > Page Cache > Invalidation Audit.'));
        }
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $this->setCollection($this->makeCollection(
            Mage::getSingleton('page_cache/invalidationLog')->getRecent($this->getDays()),
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
        ]);
        $this->addColumn('area', [
            'header' => $this->__('Area'),
            'index' => 'area',
            'type' => 'string',
        ]);
        $this->addColumn('cron_job', [
            'header' => $this->__('Cron Job'),
            'index' => 'cron_job',
            'type' => 'string',
        ]);
        $this->addColumn('invalidation_type', [
            'header' => $this->__('Type'),
            'index' => 'invalidation_type',
            'type' => 'string',
        ]);
        $this->addColumn('reason', [
            'header' => $this->__('Reason'),
            'index' => 'reason',
            'type' => 'string',
        ]);
        $this->addColumn('tag_count', [
            'header' => $this->__('Tags'),
            'index' => 'tag_count',
            'type' => 'number',
        ]);
        $this->addColumn('sample_tags', [
            'header' => $this->__('Tag Sample'),
            'index' => 'sample_tags',
            'type' => 'string',
        ]);
        $this->addColumn('affected_urls', [
            'header' => $this->__('URLs'),
            'index' => 'affected_urls',
            'type' => 'number',
        ]);
        $this->addColumn('purge_queued', [
            'header' => $this->__('Purge'),
            'index' => 'purge_queued',
            'type' => 'number',
        ]);
        $this->addColumn('warm_queued', [
            'header' => $this->__('Warm'),
            'index' => 'warm_queued',
            'type' => 'number',
        ]);
        $this->addColumn('store_id', [
            'header' => $this->__('Store'),
            'index' => 'store_id',
            'type' => 'number',
        ]);
        $this->addColumn('mode', [
            'header' => $this->__('Mode'),
            'index' => 'mode',
            'type' => 'string',
        ]);

        $this->addExports('Invalidations');

        return parent::_prepareColumns();
    }

    #[\Override]
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('log_id');
        $this->getMassactionBlock()->setFormFieldName('log_ids');
        $this->getMassactionBlock()->addItem('delete', [
            'label' => $this->__('Delete'),
            'url' => $this->getUrl('*/*/massDeleteInvalidations'),
            'confirm' => $this->__('Delete the selected invalidation records?'),
        ]);

        return parent::_prepareMassaction();
    }
}
