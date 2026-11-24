<?php

/**
 * Page cache statistics dashboard controller.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Adminhtml_PageCacheController extends Mage_Adminhtml_Controller_Action
{
    public const string ADMIN_RESOURCE = 'report/page_cache';

    #[\Maho\Config\Route('/admin/page_cache/stats')]
    public function statsAction(): void
    {
        $this->_initAction();
        $this->_addContent($this->getLayout()->createBlock('page_cache/adminhtml_stats'));
        $this->renderLayout();
    }

    protected function _initAction(): static
    {
        $this->loadLayout()
            ->_setActiveMenu('report/page_cache')
            ->_addBreadcrumb(
                Mage::helper('page_cache')->__('Reports'),
                Mage::helper('page_cache')->__('Reports'),
            )
            ->_addBreadcrumb(
                Mage::helper('page_cache')->__('Page Cache'),
                Mage::helper('page_cache')->__('Page Cache'),
            );

        $head = $this->getLayout()->getBlock('head');
        if ($head) {
            $head->setTitle(Mage::helper('page_cache')->__('Page Cache'));
        }

        return $this;
    }
}
