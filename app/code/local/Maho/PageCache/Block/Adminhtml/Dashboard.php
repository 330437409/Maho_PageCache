<?php

/**
 * Page cache dashboard container with standard admin header, filters and report grids.
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
                'class' => 'scalable',
            ]);
        }
    }

    #[\Override]
    public function getHeaderCssClass()
    {
        return 'icon-head head-page-cache';
    }

    public function getTabsHtml(): string
    {
        if (!$this->getChild('tabs')) {
            $this->setChild(
                'tabs',
                $this->getLayout()->createBlock('page_cache/adminhtml_dashboard_tabs'),
            );
        }

        return $this->getChildHtml('tabs');
    }

    public function getWarmUrl(): string
    {
        return $this->getUrl('*/*/warm');
    }

    public function getStatsUrl(): string
    {
        return $this->getUrl('*/*/stats');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'page_cache']);
    }

    public function getDays(): int
    {
        $days = (int) $this->getRequest()->getParam('days', 1);
        return in_array($days, [1, 7, 30], true) ? $days : 1;
    }

    public function getStoreId(): int
    {
        return max(0, (int) $this->getRequest()->getParam('store_id', 0));
    }

    /**
     * @return array<int, string>
     */
    public function getRangeOptions(): array
    {
        return [
            1 => (string) $this->__('Last 24 hours'),
            7 => (string) $this->__('Last 7 days'),
            30 => (string) $this->__('Last 30 days'),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getStores(): array
    {
        $stores = [['value' => 0, 'label' => (string) $this->__('All Stores')]];
        foreach (Mage::app()->getStores() as $store) {
            $stores[] = [
                'value' => (int) $store->getId(),
                'label' => (string) $store->getName(),
            ];
        }
        return $stores;
    }

    public function isWarmupEnabled(): bool
    {
        return Mage::helper('page_cache')->getWarmupEnabled();
    }

    public function getStatsMode(): string
    {
        return Mage::helper('page_cache')->getStatsMode();
    }

    public function getStatsModeLabel(): string
    {
        return match ($this->getStatsMode()) {
            Maho_PageCache_Model_Stat::MODE_FULL => (string) $this->__('Full'),
            Maho_PageCache_Model_Stat::MODE_SAMPLED => (string) $this->__('Sampled %s', $this->getStatsSampleRatePercent() . '%'),
            default => (string) $this->__('Off'),
        };
    }

    public function isStatsSampled(): bool
    {
        return $this->getStatsMode() === Maho_PageCache_Model_Stat::MODE_SAMPLED;
    }

    public function getStatsSampleRatePercent(): string
    {
        $percent = Mage::helper('page_cache')->getStatsSampleRate() * 100;
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    public function isStatsEnabled(): bool
    {
        return $this->getStatsMode() !== Maho_PageCache_Model_Stat::MODE_OFF;
    }

    public function getRawRetentionDays(): int
    {
        return Mage::helper('page_cache')->getStatsRawRetentionDays();
    }

    public function getRollupRetentionDays(): int
    {
        return Mage::helper('page_cache')->getStatsRollupRetentionDays();
    }

    public function getLastRollupBucket(): ?string
    {
        return Mage::getSingleton('page_cache/stat')->getLastRollupBucket();
    }

    public function getWarmupBatchSize(): int
    {
        return Mage::helper('page_cache')->getWarmupBatchSize();
    }
}
