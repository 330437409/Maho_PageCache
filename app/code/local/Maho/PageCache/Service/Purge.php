<?php

/**
 * Purge queue processing and reverse proxy hand-off.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Purge
{
    /**
     * @param array<string, array{urls: list<string>, tags: list<string>, mode: string, created_at: int}> $entries
     * @return array{urls: list<string>, tags: list<string>, processed: int, dry_run: bool, file: string|null}
     */
    public function process(array $entries, bool $dryRun = false): array
    {
        $urls = [];
        $tags = [];
        $modes = [];
        foreach ($entries as $entry) {
            foreach ($entry['urls'] as $url) {
                $urls[$url] = true;
            }
            foreach ($entry['tags'] as $tag) {
                $tags[$tag] = true;
            }
            $modes[$entry['mode']] = true;
        }

        $urls = array_keys($urls);
        $tags = array_keys($tags);
        sort($urls, SORT_STRING);
        sort($tags, SORT_STRING);

        if ($dryRun) {
            return [
                'urls' => $urls,
                'tags' => $tags,
                'processed' => 0,
                'dry_run' => true,
                'file' => null,
            ];
        }

        try {
            Mage::dispatchEvent('maho_page_cache_purge_urls', [
                'urls' => $urls,
                'tags' => $tags,
                'mode' => (string) array_key_first($modes),
            ]);
            $file = $this->writePurgeFile($urls, $tags);
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [
                'urls' => $urls,
                'tags' => $tags,
                'processed' => 0,
                'dry_run' => false,
                'file' => null,
            ];
        }

        $queue = Mage::getSingleton('page_cache/purgeQueue');
        $removed = 0;
        foreach (array_keys($entries) as $id) {
            try {
                $queue->remove($id);
                $removed++;
            } catch (\Throwable $e) {
                Mage::logException($e);
            }
        }

        if ($removed > 0) {
            Mage::getSingleton('page_cache/stat')->record(
                Maho_PageCache_Model_Stat::EVENT_PURGE,
                null,
                null,
                null,
                $tags !== [] ? implode(',', array_slice($tags, 0, 3)) : '*',
            );
            Mage::getSingleton('page_cache/stat')->flush();
        }

        return [
            'urls' => $urls,
            'tags' => $tags,
            'processed' => $removed,
            'dry_run' => false,
            'file' => $file,
        ];
    }

    /**
     * @param list<string> $urls
     * @param list<string> $tags
     */
    protected function writePurgeFile(array $urls, array $tags): string
    {
        $dir = Mage::getBaseDir() . '/var/page-cache';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create page cache purge directory: ' . $dir);
        }

        $file = $dir . '/purge-' . date('YmdHis') . '-' . random_int(1000, 9999) . '.txt';
        $content = "# mode: proxy\n# tags: " . implode(',', $tags) . "\n" . implode("\n", $urls) . "\n";
        if (file_put_contents($file, $content) === false) {
            throw new \RuntimeException('Could not write page cache purge file: ' . $file);
        }
        @chmod($file, 0640);

        return $file;
    }
}
