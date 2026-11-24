<?php

/**
 * Show the page cache configuration and counters.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'page-cache:status',
    description: 'Show the page cache mode, versions, purge and warmup queues, and PHP side counters',
)]
class PageCacheStatus extends BaseMahoCommand
{
    public function __invoke(OutputInterface $output): int
    {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        $helper = Mage::helper('page_cache');
        $version = Mage::getModel('page_cache/version');
        $queue = Mage::getModel('page_cache/purgeQueue');
        $counters = Mage::getModel('page_cache/counter')->getToday();
        $stockPending = Mage::getService('page_cache/stockPending');
        $stock = $stockPending->getSummary();

        $output->writeln('<info>Page Cache</info>');
        $output->writeln('Enabled: ' . ($helper->isEnabled() ? 'yes' : 'no'));
        $output->writeln('Mode: ' . ($helper->getMode() !== '' ? $helper->getMode() : 'invalid'));
        $output->writeln('TTL: ' . $helper->getTtl());
        $output->writeln('Global version: ' . $version->getGlobal());
        $output->writeln('Purge queue: ' . $queue->count());
        $pendingLabel = (string) $stock['count'];
        if ($stock['count'] > 0 && $stock['age'] !== null) {
            $pendingLabel .= ', oldest ' . $this->formatAge($stock['age']);
        }
        $output->writeln('Stock pending: ' . $pendingLabel);
        if ($stockPending->isStale($stock, $helper->getStockPendingWarnAge())) {
            $output->writeln(sprintf(
                '<comment>Stock pending warning: oldest item is %s old. Run ./maho index:reindex cataloginventory_stock.</comment>',
                $this->formatAge((int) $stock['age']),
            ));
        }
        $warmQueue = Mage::getModel('page_cache/warmQueue');
        $lastRun = Mage::getService('page_cache/warm')->getLastRun();
        $output->writeln(sprintf(
            'Warmup: %s, queue=%d',
            $helper->getWarmupEnabled() ? 'enabled' : 'disabled',
            $warmQueue->count(),
        ));
        $output->writeln($lastRun === null ? 'Last warmup: n/a' : sprintf(
            'Last warmup: warmed=%d skipped=%d failed=%d dropped=%d remaining=%d at %s',
            $lastRun['warmed'],
            $lastRun['skipped'],
            $lastRun['failed'],
            $lastRun['dropped'],
            $lastRun['remaining'],
            gmdate('Y-m-d H:i:s', $lastRun['at']),
        ));
        if (Mage::getSingleton('page_cache/stat')->isEnabled()) {
            $output->writeln(sprintf(
                'PHP counters (today): hit=%d miss=%d bypass=%d revalidated=%d',
                $counters['hit'] ?? 0,
                $counters['miss'] ?? 0,
                $counters['bypass'] ?? 0,
                $counters['revalidated'] ?? 0,
            ));
        } else {
            $output->writeln('PHP counters: unavailable (statistics disabled)');
        }

        return Command::SUCCESS;
    }

    private function formatAge(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $minutes %= 60;
        if ($hours < 24) {
            return $hours . 'h ' . $minutes . 'm';
        }

        $days = intdiv($hours, 24);
        return $days . 'd ' . ($hours % 24) . 'h';
    }
}
