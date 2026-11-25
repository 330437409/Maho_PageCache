<?php

/**
 * Enqueues one full proxy purge after the purge outbox replaces the cache queue.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

if (Mage::helper('page_cache')->isEnabled()
    && in_array(Mage::helper('page_cache')->getMode(), [
        Maho_PageCache_Helper_Data::MODE_PROXY,
        Maho_PageCache_Helper_Data::MODE_REVALIDATING,
    ], true)
) {
    Mage::getSingleton('page_cache/purgeQueue')->enqueueFullPurge();
}

$installer->endSetup();
