<?php

/**
 * Request-scoped loader for the entities that a dynamic block renders.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Proxy
{
    private const array TYPES = ['product', 'category', 'cms_page', 'cms_block'];
    private const int MAX_REFERENCES = 200;

    /** @var array<string, Mage_Core_Model_Abstract|null> */
    private array $entities = [];

    /** @var array<string, true> */
    private array $tags = [];

    public function getProduct(?int $id = null): ?Mage_Catalog_Model_Product
    {
        if ($id === null) {
            $current = Mage::registry('current_product') ?: Mage::registry('product');
            if ($current instanceof Mage_Catalog_Model_Product && (int) $current->getId() > 0) {
                $this->remember('product', $current);
                return $current;
            }

            return null;
        }

        $entity = $this->getEntities('product', [$id])[$id] ?? null;
        return $entity instanceof Mage_Catalog_Model_Product ? $entity : null;
    }

    /**
     * @param array<array-key, int|string> $ids
     * @return array<int, Mage_Catalog_Model_Product>
     */
    public function getProducts(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $entities = $this->getEntities('product', $ids);
        $result = [];
        foreach ($ids as $id) {
            if (($entities[$id] ?? null) instanceof Mage_Catalog_Model_Product) {
                $result[$id] = $entities[$id];
            }
        }

        return $result;
    }

    public function getCategory(?int $id = null): ?Mage_Catalog_Model_Category
    {
        if ($id === null) {
            $current = Mage::registry('current_category');
            if ($current instanceof Mage_Catalog_Model_Category && (int) $current->getId() > 0) {
                $this->remember('category', $current);
                return $current;
            }

            return null;
        }

        $entity = $this->getEntities('category', [$id])[$id] ?? null;
        return $entity instanceof Mage_Catalog_Model_Category ? $entity : null;
    }

    /**
     * @param array<array-key, int|string> $ids
     * @return array<int, Mage_Catalog_Model_Category>
     */
    public function getCategories(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $entities = $this->getEntities('category', $ids);
        $result = [];
        foreach ($ids as $id) {
            if (($entities[$id] ?? null) instanceof Mage_Catalog_Model_Category) {
                $result[$id] = $entities[$id];
            }
        }

        return $result;
    }

    public function getCmsPage(?int $id = null): ?Mage_Cms_Model_Page
    {
        if ($id === null) {
            $current = Mage::registry('cms_page') ?: Mage::getSingleton('cms/page');
            if ($current instanceof Mage_Cms_Model_Page && (int) $current->getId() > 0) {
                $this->remember('cms_page', $current);
                return $current;
            }

            return null;
        }

        $entity = $this->getEntities('cms_page', [$id])[$id] ?? null;
        return $entity instanceof Mage_Cms_Model_Page ? $entity : null;
    }

    /**
     * @param array<array-key, int|string> $ids
     * @return array<int, Mage_Cms_Model_Page>
     */
    public function getCmsPages(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $entities = $this->getEntities('cms_page', $ids);
        $result = [];
        foreach ($ids as $id) {
            if (($entities[$id] ?? null) instanceof Mage_Cms_Model_Page) {
                $result[$id] = $entities[$id];
            }
        }

        return $result;
    }

    public function getCmsBlock(?int $id = null): ?Mage_Cms_Model_Block
    {
        if ($id === null) {
            return null;
        }

        $entity = $this->getEntities('cms_block', [$id])[$id] ?? null;
        return $entity instanceof Mage_Cms_Model_Block ? $entity : null;
    }

    /**
     * @param array<array-key, int|string> $ids
     * @return array<int, Mage_Cms_Model_Block>
     */
    public function getCmsBlocks(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $entities = $this->getEntities('cms_block', $ids);
        $result = [];
        foreach ($ids as $id) {
            if (($entities[$id] ?? null) instanceof Mage_Cms_Model_Block) {
                $result[$id] = $entities[$id];
            }
        }

        return $result;
    }

    /**
     * @param string|array<string, int|string|array<array-key, int|string>> $references
     */
    public function preload(string|array $references): void
    {
        $parsed = $this->parseReferences($references);
        foreach ($parsed as $type => $ids) {
            $missing = [];
            $storeId = $this->storeId();
            foreach ($ids as $id) {
                if (!array_key_exists($this->key($type, $storeId, $id), $this->entities)) {
                    $missing[] = $id;
                }
            }
            if ($missing !== []) {
                $this->loadBatch($type, $missing, $storeId);
            }
        }
    }

    /**
     * @return array<string, list<int>>
     */
    public function capturePageContext(): array
    {
        $context = [];
        $product = Mage::registry('current_product') ?: Mage::registry('product');
        if ($product instanceof Mage_Catalog_Model_Product && (int) $product->getId() > 0) {
            $context['product'][] = (int) $product->getId();
        }

        $category = Mage::registry('current_category');
        if ($category instanceof Mage_Catalog_Model_Category && (int) $category->getId() > 0) {
            $context['category'][] = (int) $category->getId();
        }

        $page = Mage::getSingleton('cms/page');
        if ($page instanceof Mage_Cms_Model_Page && (int) $page->getId() > 0) {
            $context['cms_page'][] = (int) $page->getId();
        }

        return $context;
    }

    /**
     * @param array<string, list<int>> $context
     */
    public function restorePageContext(array $context): void
    {
        $this->preload($context);
        $storeId = $this->storeId();

        $productId = (int) ($context['product'][0] ?? 0);
        if ($productId > 0) {
            $product = $this->entities[$this->key('product', $storeId, $productId)] ?? null;
            if ($product instanceof Mage_Catalog_Model_Product) {
                Mage::unregister('product');
                Mage::register('product', $product);
                Mage::unregister('current_product');
                Mage::register('current_product', $product);
            }
        }

        $categoryId = (int) ($context['category'][0] ?? 0);
        if ($categoryId > 0) {
            $category = $this->entities[$this->key('category', $storeId, $categoryId)] ?? null;
            if ($category instanceof Mage_Catalog_Model_Category) {
                Mage::unregister('current_category');
                Mage::register('current_category', $category);
                $path = (string) $category->getPath();
                if ($path !== '') {
                    Mage::unregister('current_entity_key');
                    Mage::register('current_entity_key', $path);
                }
            }
        }

        $pageId = (int) ($context['cms_page'][0] ?? 0);
        if ($pageId > 0) {
            $page = $this->entities[$this->key('cms_page', $storeId, $pageId)] ?? null;
            if ($page instanceof Mage_Cms_Model_Page) {
                Mage::unregister('cms_page');
                Mage::register('cms_page', $page);
                $singleton = Mage::getSingleton('cms/page');
                if ($singleton instanceof Mage_Cms_Model_Page && !$singleton->getId()) {
                    $singleton->setData($page->getData());
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    public function reset(): void
    {
        $this->entities = [];
        $this->tags = [];
    }

    /**
     * @param string|array<string, int|string|array<array-key, int|string>> $references
     * @return array<string, list<int>>
     */
    private function parseReferences(string|array $references): array
    {
        if (is_string($references)) {
            $references = $this->parseReferenceString($references);
        }

        $parsed = [];
        $count = 0;
        foreach ($references as $type => $ids) {
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                continue;
            }
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    continue;
                }
                $id = (int) $id;
                if ($id <= 0 || isset($parsed[$type][$id])) {
                    continue;
                }
                $parsed[$type][$id] = true;
                $count++;
                if ($count >= self::MAX_REFERENCES) {
                    Mage::log(
                        'Page cache proxy reference limit reached; extra entities were ignored.',
                        Mage::LOG_WARNING,
                    );
                    break 2;
                }
            }
        }

        $result = [];
        foreach ($parsed as $type => $ids) {
            $result[$type] = array_keys($ids);
        }

        return $result;
    }

    /**
     * @return array<string, list<int>>
     */
    private function parseReferenceString(string $references): array
    {
        $parsed = [];
        foreach (explode(',', $references) as $reference) {
            $parts = explode(':', trim($reference), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$type, $id] = $parts;
            $type = strtolower(trim($type));
            $id = (int) trim($id);
            if (in_array($type, self::TYPES, true) && $id > 0) {
                $parsed[$type][] = $id;
            }
        }

        return $parsed;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Mage_Core_Model_Abstract>
     */
    private function getEntities(string $type, array $ids): array
    {
        $storeId = $this->storeId();
        $missing = [];
        foreach ($ids as $id) {
            if (!array_key_exists($this->key($type, $storeId, $id), $this->entities)) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $this->loadBatch($type, $missing, $storeId);
        }

        $result = [];
        foreach ($ids as $id) {
            $entity = $this->entities[$this->key($type, $storeId, $id)] ?? null;
            if ($entity instanceof Mage_Core_Model_Abstract) {
                $result[$id] = $entity;
            }
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     */
    private function loadBatch(string $type, array $ids, int $storeId): void
    {
        try {
            $entities = match ($type) {
                'product' => $this->loadProducts($ids, $storeId),
                'category' => $this->loadCategories($ids, $storeId),
                'cms_page' => $this->loadCmsPages($ids, $storeId),
                'cms_block' => $this->loadCmsBlocks($ids, $storeId),
                default => [],
            };
        } catch (\Throwable $e) {
            Mage::logException($e);
            $entities = [];
        }

        foreach ($ids as $id) {
            $key = $this->key($type, $storeId, $id);
            $this->entities[$key] ??= null;
        }

        foreach ($entities as $entity) {
            $this->remember($type, $entity, $storeId);
        }
    }

    /**
     * @param list<int> $ids
     * @return list<Mage_Catalog_Model_Product>
     */
    private function loadProducts(array $ids, int $storeId): array
    {
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addStoreFilter($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect('*')
            ->addUrlRewrite();
        $collection->load();
        $collection->addCategoryIds();

        $products = [];
        foreach ($collection as $product) {
            if ($product instanceof Mage_Catalog_Model_Product) {
                $product->setStoreId($storeId);
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @param list<int> $ids
     * @return list<Mage_Catalog_Model_Category>
     */
    private function loadCategories(array $ids, int $storeId): array
    {
        $collection = Mage::getModel('catalog/category')->getCollection()
            ->addIdFilter($ids)
            ->addAttributeToSelect('*')
            ->addUrlRewriteToResult();
        $collection->load();

        $categories = [];
        foreach ($collection as $category) {
            if ($category instanceof Mage_Catalog_Model_Category) {
                $category->setStoreId($storeId);
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * @param list<int> $ids
     * @return list<Mage_Cms_Model_Page>
     */
    private function loadCmsPages(array $ids, int $storeId): array
    {
        $collection = Mage::getModel('cms/page')->getCollection()
            ->addFieldToFilter('main_table.page_id', ['in' => $ids])
            ->addStoreFilter($storeId);
        $collection->load();

        $pages = [];
        foreach ($collection as $page) {
            if ($page instanceof Mage_Cms_Model_Page) {
                $page->setStoreId($storeId);
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * @param list<int> $ids
     * @return list<Mage_Cms_Model_Block>
     */
    private function loadCmsBlocks(array $ids, int $storeId): array
    {
        $collection = Mage::getModel('cms/block')->getCollection()
            ->addFieldToFilter('main_table.block_id', ['in' => $ids])
            ->addStoreFilter($storeId);
        $collection->load();

        $blocks = [];
        foreach ($collection as $block) {
            if ($block instanceof Mage_Cms_Model_Block) {
                $block->setStoreId($storeId);
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function remember(string $type, Mage_Core_Model_Abstract $entity, ?int $storeId = null): void
    {
        $id = (int) $entity->getId();
        if ($id <= 0) {
            return;
        }

        $storeId ??= $this->storeId();
        $this->entities[$this->key($type, $storeId, $id)] = $entity;

        try {
            $tags = $entity->getCacheTags();
        } catch (\Throwable $e) {
            Mage::logException($e);
            return;
        }
        if (!is_array($tags)) {
            return;
        }
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $this->tags[$tag] = true;
            }
        }
    }

    /**
     * @param array<array-key, int|string> $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    private function key(string $type, int $storeId, int $id): string
    {
        return $type . ':' . $storeId . ':' . $id;
    }

    private function storeId(): int
    {
        return (int) Mage::app()->getStore()->getId();
    }
}
