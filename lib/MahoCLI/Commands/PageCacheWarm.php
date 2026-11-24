<?php

/**
 * Warm the page cache from sitemaps or an explicit URL list.
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
    name: 'page-cache:warm',
    description: 'Warm the page cache from generated sitemaps or a URL list',
)]
class PageCacheWarm extends BaseMahoCommand
{
    /**
     * @param list<string> $urls
     */
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Read URLs from the generated sitemaps')]
        bool $sitemap = false,
        #[Option(description: 'URL to warm (repeatable)', name: 'url')]
        array $urls = [],
        #[Option(description: 'Maximum number of URLs to warm', name: 'limit')]
        int $limit = 100,
        #[Option(description: 'Concurrent requests, 0 uses the configured value', name: 'concurrency')]
        int $concurrency = 0,
        #[Option(description: 'List the URLs without requesting them', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        if (!$sitemap && $urls === []) {
            $output->writeln('<error>Pass --sitemap, at least one --url, or both.</error>');
            return Command::FAILURE;
        }

        /** @var \Maho_PageCache_Service_Warm $service */
        $service = Mage::getService('page_cache/warm');
        $urls = $service->collectUrls($urls, $sitemap, max(1, $limit));
        if ($urls === []) {
            $output->writeln('<comment>No URLs to warm.</comment>');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($urls as $url) {
                $output->writeln($url);
            }
            $output->writeln(sprintf('<info>Dry run: %d URL(s).</info>', count($urls)));
            return Command::SUCCESS;
        }

        $concurrency = $concurrency > 0 ? $concurrency : Mage::helper('page_cache')->getWarmupConcurrency();
        $result = $service->warm($urls, $concurrency);

        $output->writeln(sprintf(
            '<info>Warmed %d of %d URL(s) with concurrency %d.</info>',
            count($result['warmed']),
            count($urls),
            $concurrency,
        ));
        foreach ($result['skipped'] as $url => $reason) {
            $output->writeln(sprintf('<comment>Skipped %s (%s)</comment>', $url, $reason));
        }
        foreach ($result['failed'] as $url => $reason) {
            $output->writeln(sprintf('<error>Failed %s: %s</error>', $url, $reason));
        }

        return $result['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
