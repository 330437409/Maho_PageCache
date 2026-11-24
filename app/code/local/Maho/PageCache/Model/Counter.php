<?php

/**
 * Best-effort PHP-side page cache counters.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Counter
{
    public const RESULT_MISS = 'miss';
    public const RESULT_HIT = 'hit';
    public const RESULT_BYPASS = 'bypass';
    public const RESULT_REVALIDATED = 'revalidated';

    public const RESULTS = [
        self::RESULT_MISS,
        self::RESULT_BYPASS,
        self::RESULT_REVALIDATED,
        self::RESULT_HIT,
    ];

    public function increment(string $result, ?string $reason = null): void
    {
        if (!in_array($result, self::RESULTS, true)) {
            return;
        }

        try {
            Mage::getSingleton('page_cache/stat')->record($result, null, null, null, $reason);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * @return array<string, int>
     */
    /**
     * @return array<string, int>
     */
    public function getToday(): array
    {
        return Mage::getSingleton('page_cache/stat')->getTodayCounts();
    }
}
