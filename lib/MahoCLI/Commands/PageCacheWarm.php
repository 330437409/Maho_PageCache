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
    description: 'Warm the page cache from generated sitemaps, a URL list, or the warmup queue',
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
        #[Option(description: 'Read active product and category URLs from URL rewrites')]
        bool $active = false,
        #[Option(description: 'Store ID for --active, 0 uses the current store', name: 'store')]
        int $store = 0,
        #[Option(description: 'URL to warm (repeatable)', name: 'url')]
        array $urls = [],
        #[Option(description: 'Maximum number of URLs to warm', name: 'limit')]
        int $limit = 100,
        #[Option(description: 'Concurrent requests, 0 uses the configured value', name: 'concurrency')]
        int $concurrency = 0,
        #[Option(description: 'List the URLs without requesting them', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Process the warmup queue instead of a sitemap or URL list', name: 'queue')]
        bool $queue = false,
        #[Option(description: 'Delay between request starts in milliseconds (manual warm only)', name: 'delay')]
        ?int $delay = null,
    ): int {
        if (!$this->isModuleActive('Maho_PageCache')) {
            $output->writeln('<error>The Maho_PageCache module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        if ($queue && ($sitemap || $active || $urls !== [])) {
            $output->writeln('<error>Pass --queue without --sitemap, --active or --url.</error>');
            return Command::FAILURE;
        }
        if (!$queue && !$sitemap && !$active && $urls === []) {
            $output->writeln('<error>Pass --queue, --sitemap, --active, at least one --url, or a combination.</error>');
            return Command::FAILURE;
        }

        $helper = Mage::helper('page_cache');

        if ($queue) {
            $limit = max(1, $limit);
            if ($dryRun) {
                $rows = Mage::getSingleton('page_cache/warmQueue')->peek($limit);
                foreach ($rows as $row) {
                    $output->writeln((string) $row['url']);
                }
                $output->writeln(sprintf('<info>Dry run: %d URL(s) in the warmup queue.</info>', count($rows)));
                return Command::SUCCESS;
            }

            $summary = Mage::getService('page_cache/warm')->consumeQueue($limit);
            if ($summary['processed'] === 0) {
                $output->writeln($summary['remaining'] > 0
                    ? sprintf('<comment>No URLs are due now. %d URL(s) remain in the warmup queue.</comment>', $summary['remaining'])
                    : '<comment>No URLs in the warmup queue.</comment>');
                return Command::SUCCESS;
            }
            $output->writeln(sprintf(
                '<info>Processed %d URL(s): warmed=%d skipped=%d failed=%d dropped=%d remaining=%d.</info>',
                $summary['processed'],
                $summary['warmed'],
                $summary['skipped'],
                $summary['failed'],
                $summary['dropped'],
                $summary['remaining'],
            ));
            return Command::SUCCESS;
        }

        /** @var \Maho_PageCache_Service_Warm $service */
        $service = Mage::getService('page_cache/warm');
        $urls = $service->collectUrls($urls, $sitemap, max(1, $limit), $active, $store);
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

        $concurrency = $concurrency > 0 ? $concurrency : $helper->getWarmupConcurrency();
        $delayMs = $delay !== null ? max(0, $delay) : $helper->getWarmupDelayMs();
        $result = $service->warm($urls, $concurrency, $delayMs);

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
