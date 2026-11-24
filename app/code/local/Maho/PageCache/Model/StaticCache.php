<?php

/**
 * Static HTML export for the local page cache.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_StaticCache
{
    public function isEnabled(): bool
    {
        return $this->helper()->getStaticExportEnabled();
    }

    public function getRoot(): string
    {
        return Mage::getBaseDir('var') . DS . 'page-cache' . DS . 'static';
    }

    public function getFilePath(Maho_PageCache_Model_Context $context): ?string
    {
        if ($context->getParams() !== [] || $context->getMode() !== Maho_PageCache_Helper_Data::MODE_LOCAL) {
            return null;
        }

        $storeCode = $this->getStoreCode($context);
        if ($storeCode === '') {
            return null;
        }

        $relative = $this->getRelativePath($context->getPath());
        if ($relative === null) {
            return null;
        }

        return $this->buildPath($storeCode, $relative);
    }

    public function save(Maho_PageCache_Model_Context $context, string $html): bool
    {
        try {
            if (!$this->isEnabled() || is_link($this->getRoot())) {
                return false;
            }

            $path = $this->getFilePath($context);
            if ($path === null || !$this->writeAtomic($path, $html)) {
                return false;
            }

            if (function_exists('gzencode')) {
                $gz = gzencode($html, 6);
                if ($gz !== false && !$this->writeAtomic($path . '.gz', $gz)) {
                    Mage::log('Page cache static gzip write failed: ' . $path . '.gz', Mage::LOG_WARNING);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Mage::logException($e);
            return false;
        }
    }

    public function removeByUrl(string $url, string $storeCode): void
    {
        try {
            if (is_link($this->getRoot())
                || preg_match('/^[A-Za-z0-9_-]+$/', $storeCode) !== 1
            ) {
                return;
            }

            if ($url === '' || str_contains($url, '?')) {
                return;
            }

            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path)) {
                $path = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $url);
                $path = '/' . ltrim($path, '/');
            }

            $relative = $this->getRelativePath($path);
            if ($relative === null) {
                return;
            }

            $file = $this->buildPath($storeCode, $relative);
            if ($file === null) {
                return;
            }

            $this->removeFile($file);
            $this->removeFile($file . '.gz');
            $this->removeEmptyParents(dirname($file), $this->getRoot() . DS . $storeCode);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function flush(): int
    {
        $root = $this->getRoot();
        if (is_link($root) || !is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || $item->isFile()) {
                    if (@unlink($item->getPathname())) {
                        $deleted++;
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getPathname());
                }
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        return $deleted;
    }

    public function cleanup(int $maxAge): int
    {
        $root = $this->getRoot();
        if (is_link($root) || !is_dir($root)) {
            return 0;
        }

        $cutoff = time() - max(0, $maxAge);
        $deleted = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }
                if ($item->isFile()) {
                    $name = $item->getFilename();
                    if (str_ends_with($name, '.html') || str_ends_with($name, '.gz')) {
                        if ($item->getMTime() < $cutoff && @unlink($item->getPathname())) {
                            $deleted++;
                        }
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getPathname());
                }
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        return $deleted;
    }

    private function getStoreCode(Maho_PageCache_Model_Context $context): string
    {
        try {
            $code = (string) Mage::app()->getStore($context->getStoreId())->getCode();
        } catch (\Throwable) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $code) === 1 ? $code : '';
    }

    private function getRelativePath(string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        if (strlen($path) > 1024 || preg_match('#^[A-Za-z0-9._~/-]+$#', $path) !== 1) {
            return null;
        }

        $path = trim($path, '/');
        if ($path === '') {
            return 'index.html';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return pathinfo($path, PATHINFO_EXTENSION) !== '' ? $path : $path . '/index.html';
    }

    private function buildPath(string $storeCode, string $relative): ?string
    {
        $root = $this->getRoot() . DS . $storeCode;
        $path = $root . DS . str_replace('/', DS, $relative);

        if (str_contains($relative, '..') || !str_starts_with($path, $root . DS)) {
            return null;
        }

        return $path;
    }

    private function writeAtomic(string $path, string $contents): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            Mage::log('Page cache static directory create failed: ' . $directory, Mage::LOG_WARNING);
            return false;
        }

        $temporary = $path . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            Mage::log('Page cache static write failed: ' . $path, Mage::LOG_WARNING);
            return false;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            Mage::log('Page cache static rename failed: ' . $path, Mage::LOG_WARNING);
            return false;
        }

        return true;
    }

    private function removeFile(string $file): void
    {
        if (is_file($file) || is_link($file)) {
            @unlink($file);
        }
    }

    private function removeEmptyParents(string $directory, string $stop): void
    {
        while ($directory !== $stop && str_starts_with($directory, $stop . DS)) {
            if (is_link($directory) || !is_dir($directory) || !@rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    private function helper(): Maho_PageCache_Helper_Data
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');
        return $helper;
    }
}
