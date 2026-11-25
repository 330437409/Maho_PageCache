<?php

/**
 * Show pending, failed and delivered page cache purge outbox rows.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'page-cache:purge:status',
    description: 'Show the page cache purge outbox status',
)]
class PageCachePurgeStatus extends BaseMahoCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Outbox target to inspect', name: 'target')]
        string $target = 'proxy',
        #[Option(description: 'Exit non-zero on stale pending rows or failed rows')]
        bool $check = false,
    ): int {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        $status = Mage::getModel('page_cache/purgeQueue')->getStatus($target);
        $staleAfter = Mage::helper('page_cache')->getPurgeStaleAfter();
        $age = $status['oldest_pending'] === null ? null : max(0, time() - $status['oldest_pending']);
        $stale = $age !== null && $age > $staleAfter;

        $output->writeln('Page Cache Purge Outbox');
        $output->writeln('Target: ' . $target);
        $output->writeln('Pending: ' . $status['pending']);
        $output->writeln('Processing: ' . $status['processing']);
        $output->writeln('Failed: ' . $status['failed']);
        $output->writeln('Delivered: ' . $status['delivered']);
        $output->writeln('Oldest pending: ' . ($age === null ? 'n/a' : $age . 's'));
        $output->writeln('Last success: ' . ($status['last_success'] ?? 'n/a'));
        $output->writeln('Last failure: ' . ($status['last_failure'] ?? 'n/a'));
        if ($status['last_error'] !== null && $status['last_error'] !== '') {
            $output->writeln('Last error: ' . $status['last_error']);
        }
        if ($stale) {
            $output->writeln(sprintf(
                '<comment>Warning: the oldest pending purge is older than %d seconds.</comment>',
                $staleAfter,
            ));
        }

        if ($check && ($stale || $status['failed'] > 0)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
