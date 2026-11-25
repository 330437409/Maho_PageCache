<?php

/**
 * Drops the legacy page cache URL tag table after one final invalidation.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

Mage::getService('page_cache/invalidator')->invalidateAll('upgrade_0_7_0');

$installer->getConnection()->dropTable($installer->getTable('page_cache_url_tag'));

$installer->endSetup();
