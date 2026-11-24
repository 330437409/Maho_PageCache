<?php

/**
 * Page cache statistics dashboard block.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Stats extends Mage_Adminhtml_Block_Template
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('maho/pagecache/stats.phtml');
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
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return Mage::getSingleton('page_cache/stat')->getSummary($this->getDays(), $this->getStoreId());
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function getTrend(): array
    {
        return Mage::getSingleton('page_cache/stat')->getTrend($this->getDays(), $this->getStoreId());
    }

    /**
     * @return list<array{url_path: string, miss_count: int}>
     */
    public function getTopMissed(): array
    {
        return Mage::getSingleton('page_cache/stat')->getTopMissed($this->getDays(), $this->getStoreId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentEvents(): array
    {
        return Mage::getSingleton('page_cache/stat')->getRecentEvents($this->getDays());
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

    public function getStatsUrl(): string
    {
        return $this->getUrl('*/*/stats');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'page_cache']);
    }

    public function isStatsEnabled(): bool
    {
        return Mage::helper('page_cache')->getStatsEnabled();
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
}
