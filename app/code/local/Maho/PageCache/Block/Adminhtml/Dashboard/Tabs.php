<?php

/**
 * Page cache report tabs.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Block_Adminhtml_Dashboard_Tabs extends Mage_Adminhtml_Block_Widget_Tabs
{
    #[\Override]
    protected function _construct()
    {
        parent::_construct();
        $this->setId('page_cache_tabs');
        $this->setDestElementId('page_cache_tab_content');
        $this->setTemplate('widget/tabshoriz.phtml');
    }

    #[\Override]
    protected function _beforeToHtml()
    {
        $this->addTab('summary', [
            'label' => $this->__('Overview'),
            'content' => $this->getLayout()
                ->createBlock('page_cache/adminhtml_dashboard_tab_summary')
                ->toHtml(),
        ]);
        $this->addTab('trend', [
            'label' => $this->__('Trend'),
            'content' => $this->getLayout()
                ->createBlock('page_cache/adminhtml_dashboard_tab_trend')
                ->toHtml(),
        ]);
        $this->addTab('top_missed', [
            'label' => $this->__('Top Missed URLs'),
            'content' => $this->getLayout()
                ->createBlock('page_cache/adminhtml_dashboard_tab_topMissed')
                ->toHtml(),
        ]);
        $this->addTab('recent', [
            'label' => $this->__('Recent Events'),
            'content' => $this->getLayout()
                ->createBlock('page_cache/adminhtml_dashboard_tab_recent')
                ->toHtml(),
        ]);

        return parent::_beforeToHtml();
    }
}
