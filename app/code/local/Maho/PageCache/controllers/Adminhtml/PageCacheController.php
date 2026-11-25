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
        $this->_addContent($this->getLayout()->createBlock('page_cache/adminhtml_dashboard'));
        $this->renderLayout();
    }

    #[\Maho\Config\Route('/admin/page_cache/exportSummaryCsv')]
    public function exportSummaryCsvAction(): void
    {
        $this->exportGrid('summary', 'csv');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportSummaryExcel')]
    public function exportSummaryExcelAction(): void
    {
        $this->exportGrid('summary', 'excel');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportTrendCsv')]
    public function exportTrendCsvAction(): void
    {
        $this->exportGrid('trend', 'csv');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportTrendExcel')]
    public function exportTrendExcelAction(): void
    {
        $this->exportGrid('trend', 'excel');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportTopMissedCsv')]
    public function exportTopMissedCsvAction(): void
    {
        $this->exportGrid('top_missed', 'csv');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportTopMissedExcel')]
    public function exportTopMissedExcelAction(): void
    {
        $this->exportGrid('top_missed', 'excel');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportRecentCsv')]
    public function exportRecentCsvAction(): void
    {
        $this->exportGrid('recent', 'csv');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportRecentExcel')]
    public function exportRecentExcelAction(): void
    {
        $this->exportGrid('recent', 'excel');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportInvalidationsCsv')]
    public function exportInvalidationsCsvAction(): void
    {
        $this->exportGrid('invalidations', 'csv');
    }

    #[\Maho\Config\Route('/admin/page_cache/exportInvalidationsExcel')]
    public function exportInvalidationsExcelAction(): void
    {
        $this->exportGrid('invalidations', 'excel');
    }

    #[\Maho\Config\Route('/admin/page_cache/massDeleteInvalidations', methods: ['POST'])]
    public function massDeleteInvalidationsAction(): void
    {
        $ids = (array) $this->getRequest()->getParam('log_ids', []);
        $deleted = Mage::getSingleton('page_cache/invalidationLog')->delete($ids);
        Mage::getSingleton('adminhtml/session')->addSuccess(
            Mage::helper('page_cache')->__('Deleted %d invalidation record(s).', $deleted),
        );
        $this->_redirect('*/*/stats');
    }

    private function exportGrid(string $key, string $format): void
    {
        $aliases = [
            'summary' => 'page_cache/adminhtml_dashboard_tab_summary',
            'trend' => 'page_cache/adminhtml_dashboard_tab_trend',
            'top_missed' => 'page_cache/adminhtml_dashboard_tab_topMissed',
            'recent' => 'page_cache/adminhtml_dashboard_tab_recent',
            'invalidations' => 'page_cache/adminhtml_dashboard_tab_invalidations',
        ];
        if (!isset($aliases[$key])) {
            $this->_forward('noroute');
            return;
        }

        $grid = $this->getLayout()->createBlock($aliases[$key]);
        if (!$grid instanceof Mage_Adminhtml_Block_Widget_Grid) {
            $this->_forward('noroute');
            return;
        }

        $fileName = 'page_cache_' . $key . ($format === 'excel' ? '.xml' : '.csv');
        if ($format === 'excel') {
            $this->_prepareDownloadResponse(...$grid->getExcelFile($fileName, -1));
            return;
        }

        $this->_prepareDownloadResponse(...$grid->getCsvFile($fileName, -1));
    }

    #[\Maho\Config\Route('/admin/page_cache/warm', methods: ['POST'])]
    public function warmAction(): void
    {
        $helper = Mage::helper('page_cache');
        try {
            if (!$helper->getWarmupEnabled()) {
                Mage::getSingleton('adminhtml/session')->addNotice(
                    $helper->__('Scheduled warmup is disabled. Enable it before warming the queue.'),
                );
            } else {
                \Maho\Queue\QueueManager::dispatch(
                    new Maho_PageCache_Model_WarmMessage($helper->getWarmupBatchSize()),
                );
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    $helper->__('Warmup queued. The queue worker will process one batch.'),
                );
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError(
                $helper->__('Warmup could not be queued. Check the logs and try again.'),
            );
        }

        $this->_redirect('*/*/stats');
    }

    protected function _initAction(): static
    {
        $this->loadLayout()
            ->_setActiveMenu('page_cache')
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
