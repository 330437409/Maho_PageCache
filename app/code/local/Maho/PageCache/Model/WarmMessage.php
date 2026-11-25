<?php

/**
 * Queue message that runs one scheduled warmup batch.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

final readonly class Maho_PageCache_Model_WarmMessage
{
    public function __construct(
        public int $limit = 0,
    ) {}

    public function getDedupeKey(): string
    {
        return 'page_cache_warmup';
    }
}
