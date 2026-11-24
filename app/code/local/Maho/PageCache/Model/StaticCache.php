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

        $relative = $this->getRelativePath($context->getPublicPath());
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

            $html = Mage::getService('page_cache/dynamic')->stripServerBlockMarkers($html);
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

            $this->remember($path);
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

            if ($url === '') {
                return;
            }

            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                $path = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $url);
                $path = (string) parse_url($path, PHP_URL_PATH);
                $path = '/' . ltrim($path, '/');
            }

            $prefix = '/' . $storeCode;
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
            }

            $relative = $this->getRelativePath($path);
            if ($relative === null) {
                return;
            }

            $file = $this->buildPath($storeCode, $relative);
            if ($file === null) {
                return;
            }

            $removed = $this->removeFile($file);
            $removed = $this->removeFile($file . '.gz') && $removed;
            if ($removed) {
                $this->forget($file);
            }
            $this->removeEmptyParents(dirname($file), $this->getRoot() . DS . $storeCode);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function flush(): int
    {
        try {
            $this->write()->delete($this->table(), '1=1');
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

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

        try {
            $read = $this->read();
            $rows = $read->fetchAll(
                $read->select()
                    ->from($this->table(), ['static_id', 'path'])
                    ->where('expires_at < ?', gmdate('Y-m-d H:i:s'))
                    ->limit(1000),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            return $this->cleanupTree($maxAge);
        }

        if ($rows === []) {
            return $this->cleanupTree($maxAge);
        }

        $deletedIds = [];
        $deleted = 0;
        foreach ($rows as $row) {
            $relative = (string) ($row['path'] ?? '');
            $file = $this->fileForRelative($relative);
            if ($file === null) {
                $deletedIds[] = (int) ($row['static_id'] ?? 0);
                continue;
            }

            $removed = $this->removeFile($file);
            $removed = $this->removeFile($file . '.gz') && $removed;
            if (!$removed) {
                continue;
            }

            $deletedIds[] = (int) ($row['static_id'] ?? 0);
            $deleted++;
            $storeCode = explode('/', $relative)[0] ?? '';
            if ($storeCode !== '') {
                $this->removeEmptyParents(dirname($file), $this->getRoot() . DS . $storeCode);
            }
        }

        $deletedIds = array_values(array_filter($deletedIds));
        if ($deletedIds !== []) {
            try {
                $this->write()->delete(
                    $this->table(),
                    $this->write()->quoteInto('static_id IN (?)', $deletedIds),
                );
            } catch (\Throwable $e) {
                Mage::logException($e);
            }
        }

        return $deleted;
    }

    private function cleanupTree(int $maxAge): int
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

    private function remember(string $file): void
    {
        $relative = $this->relativeFromFile($file);
        if ($relative === null) {
            return;
        }

        try {
            $now = gmdate('Y-m-d H:i:s');
            $this->write()->insertOnDuplicate($this->table(), [
                'path_hash' => hash('sha256', $relative),
                'path' => $relative,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->helper()->getTtl() + 300),
                'created_at' => $now,
                'updated_at' => $now,
            ], ['path', 'expires_at', 'updated_at']);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    private function forget(string $file): void
    {
        $relative = $this->relativeFromFile($file);
        if ($relative === null) {
            return;
        }

        try {
            $this->write()->delete($this->table(), ['path_hash = ?' => hash('sha256', $relative)]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    private function relativeFromFile(string $file): ?string
    {
        $root = rtrim(str_replace('\\', '/', $this->getRoot()), '/');
        $file = str_replace('\\', '/', $file);
        if (!str_starts_with($file, $root . '/')) {
            return null;
        }

        $relative = substr($file, strlen($root) + 1);
        return $relative !== '' && !str_contains($relative, '..') ? $relative : null;
    }

    private function fileForRelative(string $relative): ?string
    {
        $root = $this->getRoot();
        $file = $root . DS . str_replace('/', DS, $relative);
        if ($relative === '' || str_contains($relative, '..') || !str_starts_with($file, $root . DS)) {
            return null;
        }

        return $file;
    }

    private function table(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('page_cache/static_file');
    }

    private function read(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function write(): Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
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

    private function removeFile(string $file): bool
    {
        if (!is_file($file) && !is_link($file)) {
            return true;
        }

        return @unlink($file);
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
