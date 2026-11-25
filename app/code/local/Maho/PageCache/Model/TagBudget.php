<?php

/**
 * Bounds proxy cache tags and adds overflow tags for dropped groups.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_TagBudget
{
    public const int MAX_TAGS = 50;
    public const string OVERFLOW = 'page_cache_overflow';
    public const string OVERFLOW_PRODUCT = 'page_cache_overflow_product';

    private const string PRODUCT_TAG_PATTERN = '/^catalog_product_\d+$/';
    private const int MIN_TAGS = 3;

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public static function fit(array $tags, int $maxTags = self::MAX_TAGS): array
    {
        $tags = array_values(array_unique(array_filter(
            $tags,
            static fn(mixed $tag): bool => is_string($tag) && $tag !== '',
        )));
        $maxTags = max(self::MIN_TAGS, $maxTags);
        if (count($tags) <= $maxTags) {
            return $tags;
        }

        $others = [];
        $products = [];
        $needed = [];
        foreach ($tags as $tag) {
            if ($tag === self::OVERFLOW || $tag === self::OVERFLOW_PRODUCT) {
                $needed[$tag] = true;
                continue;
            }
            if (self::isProductTag($tag)) {
                $products[] = $tag;
            } else {
                $others[] = $tag;
            }
        }

        $kept = [];
        foreach ($others as $tag) {
            if (count($kept) < $maxTags) {
                $kept[] = $tag;
                continue;
            }
            $needed[self::OVERFLOW] = true;
        }
        foreach ($products as $tag) {
            if (count($kept) < $maxTags) {
                $kept[] = $tag;
                continue;
            }
            $needed[self::OVERFLOW_PRODUCT] = true;
        }

        while ($kept !== [] && count($kept) + count($needed) > $maxTags) {
            $removed = array_pop($kept);
            if (is_string($removed) && self::isProductTag($removed)) {
                $needed[self::OVERFLOW_PRODUCT] = true;
            } else {
                $needed[self::OVERFLOW] = true;
            }
        }

        foreach ([self::OVERFLOW_PRODUCT, self::OVERFLOW] as $overflow) {
            if (isset($needed[$overflow])) {
                $kept[] = $overflow;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public static function withOverflow(array $tags): array
    {
        $result = [];
        $needed = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }
            $result[$tag] = true;
            if ($tag === self::OVERFLOW || $tag === self::OVERFLOW_PRODUCT) {
                continue;
            }
            $needed[self::isProductTag($tag) ? self::OVERFLOW_PRODUCT : self::OVERFLOW] = true;
        }
        foreach (array_keys($needed) as $tag) {
            $result[$tag] = true;
        }

        return array_keys($result);
    }

    private static function isProductTag(string $tag): bool
    {
        return preg_match(self::PRODUCT_TAG_PATTERN, $tag) === 1;
    }
}
