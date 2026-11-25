<?php

/**
 * Process the page cache purge queue.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'page-cache:purge',
    description: 'Process the page cache purge queue and hand URLs to the reverse proxy',
)]
class PageCachePurge extends BaseMahoCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'List the URLs without writing a file or clearing the queue')]
        bool $dryRun = false,
        #[Option(description: 'Maximum number of queue entries to process', name: 'limit')]
        int $limit = 100,
        #[Option(description: 'Only process entries that contain this tag', name: 'tag')]
        ?string $tag = null,
        #[Option(description: 'Outbox target to process', name: 'target')]
        string $target = 'proxy',
        #[Option(description: 'Reset failed rows for this target before processing', name: 'retry-failed')]
        bool $retryFailed = false,
    ): int {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        $queue = Mage::getModel('page_cache/purgeQueue');
        if ($retryFailed) {
            $retried = $queue->retryFailed($target);
            $output->writeln(sprintf('Reset %d failed outbox row(s) for target %s.', $retried, $target));
        }

        $entries = $dryRun
            ? $queue->getPending($target, $limit, $tag, true)
            : $queue->claim($target, $limit, $tag);

        if ($entries === []) {
            $output->writeln('Nothing to purge.');
            return Command::SUCCESS;
        }

        $result = Mage::getService('page_cache/purge')->process($entries, $dryRun);

        if ($dryRun) {
            $output->writeln(sprintf('Dry run: %d URL(s) in %d queue entr(y/ies).', count($result['urls']), count($entries)));
            foreach ($result['urls'] as $url) {
                $output->writeln('  ' . $url);
            }
            return Command::SUCCESS;
        }

        if ($result['processed'] === 0) {
            $output->writeln('<error>Purge failed. The outbox rows were kept and scheduled for retry; see var/log for details.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Processed %d queue entr(y/ies), %d URL(s), %d failed.',
            $result['processed'],
            count($result['urls']),
            $result['failed'],
        ));
        if ($result['file'] !== null) {
            $output->writeln('Purge file: ' . $result['file']);
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
