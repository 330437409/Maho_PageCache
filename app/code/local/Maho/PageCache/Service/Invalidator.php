<?php

/**
 * Entity, rule and configuration invalidation for the page cache.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Service_Invalidator
{
    public const MAX_TAGGED_ENTITIES = 30;

    public function invalidateEntity(Mage_Core_Model_Abstract $model): void
    {
        if ($model instanceof Mage_CatalogInventory_Model_Stock_Item) {
            return;
        }

        if ($model instanceof Mage_SalesRule_Model_Rule) {
            $this->invalidateAll('sales_rule');
            return;
        }

        if ($model instanceof Mage_CatalogRule_Model_Rule) {
            $this->invalidateCatalogRule($model);
            return;
        }

        if ($model instanceof Mage_Catalog_Model_Product) {
            $tags = $model->getCacheIdTagsWithCategories();
        } else {
            $tags = $model->getCacheIdTags();
        }

        if (is_array($tags) && $tags !== []) {
            $this->invalidateTags($tags);
        }
    }

    public function invalidateStockItem(Mage_CatalogInventory_Model_Stock_Item $item): void
    {
        $productId = (int) $item->getProductId();
        if ($productId <= 0) {
            return;
        }
        if (!$item->getStockStatusChangedAuto() && !$item->dataHasChangedFor('is_in_stock')) {
            return;
        }

        $productIds = [$productId];
        try {
            $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
                ->getParentIdsByChild($productId);
            $productIds = array_merge($productIds, array_map(intval(...), (array) $parentIds));
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        $this->invalidateProductIds($productIds);
    }

    /**
     * @param list<int|string> $productIds
     */
    public function invalidateProductIds(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(
            array_map(intval(...), $productIds),
            static fn(int $productId): bool => $productId > 0,
        )));
        if ($productIds === []) {
            return;
        }
        if (count($productIds) > self::MAX_TAGGED_ENTITIES) {
            $this->invalidateAll('mass_update');
            return;
        }

        $this->invalidateTags(array_map(
            static fn(int $productId): string => 'catalog_product_' . $productId,
            $productIds,
        ));
    }

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags, static fn(mixed $tag): bool => is_string($tag) && $tag !== '')));
        if ($tags === []) {
            return;
        }

        $urls = [];
        $warmUrls = [];
        foreach ($tags as $tag) {
            $urlHashes = $this->tagIndex()->getUrls($tag);
            if ($urlHashes === false) {
                if ($this->tagIndex()->isKnown($tag)) {
                    $this->invalidateAll('tag_fallback');
                    return;
                }
                continue;
            }

            foreach ($urlHashes as $urlHash) {
                $meta = Mage::app()->getCache()->load($this->key()->getMetaKey($urlHash));
                if (!is_array($meta) || empty($meta['url'])) {
                    $this->invalidateAll('tag_fallback');
                    return;
                }
                $newVersion = max(
                    (int) ($meta['version'] ?? 1) + 1,
                    $this->version()->getUrl($urlHash) + 1,
                );
                $meta['version'] = $newVersion;
                $ttl = isset($meta['ttl']) ? (int) $meta['ttl'] + 3600 : null;
                if (!Mage::app()->getCache()->save($meta, $this->key()->getMetaKey($urlHash), ['PAGE_CACHE'], $ttl)) {
                    $this->invalidateAll('tag_fallback');
                    return;
                }
                $this->version()->setUrl($urlHash, $newVersion);
                $this->staticCache()->removeByUrl(
                    (string) $meta['url'],
                    (string) ($meta['store_code'] ?? ''),
                );
                $urls[] = (string) $meta['url'];
                $warmUrls[(int) ($meta['store_id'] ?? 0)][] = (string) $meta['url'];
            }
            $this->tagIndex()->remove($tag);
        }

        if ($urls !== [] && $this->helper()->getMode() === Maho_PageCache_Helper_Data::MODE_PROXY) {
            $this->queue()->enqueue($urls, $tags, Maho_PageCache_Helper_Data::MODE_PROXY);
        }

        if ($warmUrls !== [] && $this->helper()->getWarmupEnabled()) {
            $reason = implode(',', array_slice($tags, 0, 3));
            foreach ($warmUrls as $storeId => $storeUrls) {
                $this->warmQueue()->enqueue($storeUrls, (int) $storeId, $reason);
            }
        }
    }

    public function invalidateCatalogRule(Mage_CatalogRule_Model_Rule $rule): void
    {
        try {
            $productIds = $rule->getMatchingProductIds();
        } catch (\Throwable $e) {
            Mage::logException($e);
            $this->invalidateAll('catalog_rule_fallback');
            return;
        }

        if (!is_array($productIds) || $productIds === []) {
            $this->invalidateAll('catalog_rule_empty');
            return;
        }

        $productIds = array_values(array_filter(
            array_map(intval(...), array_keys($productIds)),
            static fn(int $productId): bool => $productId > 0,
        ));
        if ($productIds === []) {
            $this->invalidateAll('catalog_rule_empty');
            return;
        }

        $tags = [];
        foreach ($productIds as $productId) {
            $tags[] = 'catalog_product_' . $productId;
        }
        foreach ($this->getCategoryIdsForProducts($productIds) as $categoryId) {
            $tags[] = 'catalog_category_' . $categoryId;
        }
        $this->invalidateTags($tags);
    }

    public function invalidateAll(string $reason = 'full'): void
    {
        $nextGlobalVersion = max($this->version()->getGlobal() + 1, time());
        try {
            $this->tagIndex()->flush();
            $this->staticCache()->flush();
        } finally {
            $this->version()->setGlobal($nextGlobalVersion);
        }
        $this->queue()->clear();
        $this->warmQueue()->clear();

        if ($this->helper()->getMode() === Maho_PageCache_Helper_Data::MODE_PROXY) {
            $this->queue()->enqueueFullPurge();
        }

        Mage::getSingleton('page_cache/stat')->record(
            Maho_PageCache_Model_Stat::EVENT_FLUSH,
            null,
            null,
            null,
            $reason,
        );
        Mage::getSingleton('page_cache/stat')->flush();
    }

    /**
     * @param list<int> $productIds
     * @return list<int>
     */
    protected function getCategoryIdsForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $select = $read->select()
            ->distinct()
            ->from($resource->getTableName('catalog/category_product'), ['category_id'])
            ->where('product_id IN (?)', $productIds);

        return array_values(array_map(intval(...), $read->fetchCol($select)));
    }

    private function tagIndex(): Maho_PageCache_Model_TagIndex
    {
        return Mage::getSingleton('page_cache/tagIndex');
    }

    private function version(): Maho_PageCache_Model_Version
    {
        return Mage::getSingleton('page_cache/version');
    }

    private function queue(): Maho_PageCache_Model_PurgeQueue
    {
        return Mage::getSingleton('page_cache/purgeQueue');
    }

    private function staticCache(): Maho_PageCache_Model_StaticCache
    {
        return Mage::getSingleton('page_cache/staticCache');
    }

    private function warmQueue(): Maho_PageCache_Model_WarmQueue
    {
        return Mage::getSingleton('page_cache/warmQueue');
    }

    private function key(): Maho_PageCache_Model_Key
    {
        return Mage::getSingleton('page_cache/key');
    }

    private function helper(): Maho_PageCache_Helper_Data
    {
        /** @var Maho_PageCache_Helper_Data $helper */
        $helper = Mage::helper('page_cache');
        return $helper;
    }
}
