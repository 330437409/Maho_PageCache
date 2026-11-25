<?php

/**
 * Print page cache metrics for external monitoring.
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
    name: 'page-cache:monitor',
    description: 'Print page cache metrics for external monitoring',
)]
class PageCacheMonitor extends BaseMahoCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Output format: prometheus, json or text', name: 'format')]
        string $format = 'prometheus',
        #[Option(description: 'Exit non-zero on stale stock pending, stale purge rows or failed purge rows')]
        bool $check = false,
    ): int {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        $service = Mage::getService('page_cache/monitor');
        $metrics = $service->getMetrics();

        $output->write(match ($format) {
            'json' => $service->formatJson($metrics),
            'text' => $service->formatText($metrics),
            default => $service->formatPrometheus($metrics),
        });

        return $check && $service->isAlerting($metrics) ? Command::FAILURE : Command::SUCCESS;
    }
}
