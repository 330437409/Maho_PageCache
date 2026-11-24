<?php

/**
 * Seeds the page cache type in the cache management options.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

$installer->getConnection()->insertOnDuplicate(
    $installer->getTable('core/cache_option'),
    ['code' => 'page_cache', 'value' => 1],
    ['value'],
);

$installer->endSetup();
