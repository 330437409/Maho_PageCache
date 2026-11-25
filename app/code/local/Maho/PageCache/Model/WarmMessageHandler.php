<?php

/**
 * Runs one scheduled warmup batch from the message queue.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_WarmMessageHandler
{
    #[Maho\Config\MessageHandler]
    public function __invoke(Maho_PageCache_Model_WarmMessage $message): void
    {
        Mage::getService('page_cache/warm')->consumeQueue($message->limit);
    }
}
