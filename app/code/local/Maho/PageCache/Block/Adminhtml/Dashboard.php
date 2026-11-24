<?php

/**
 * Page cache dashboard container with standard admin header and actions.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Widget_Container
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->_headerText = Mage::helper('page_cache')->__('Page Cache');
        $this->setTemplate('maho/pagecache/dashboard.phtml');
        if (Mage::getSingleton('admin/session')->isAllowed('admin/system/config/page_cache')) {
            $this->addButton('configuration', [
                'label' => Mage::helper('page_cache')->__('Configuration'),
                'onclick' => 'setLocation(\'' . $this->getConfigUrl() . '\')',
                'class' => 'd-btn',
            ]);
        }
    }

    public function getStatsHtml(): string
    {
        if (!$this->getChild('stats')) {
            $this->setChild(
                'stats',
                $this->getLayout()->createBlock('page_cache/adminhtml_stats'),
            );
        }

        return $this->getChildHtml('stats');
    }

    public function getWarmUrl(): string
    {
        return $this->getUrl('*/*/warm');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'page_cache']);
    }

    public function isWarmupEnabled(): bool
    {
        return Mage::helper('page_cache')->getWarmupEnabled();
    }

    public function getWarmupQueueCount(): int
    {
        return Mage::getModel('page_cache/warmQueue')->count();
    }

    public function getWarmupBatchSize(): int
    {
        return Mage::helper('page_cache')->getWarmupBatchSize();
    }
}
