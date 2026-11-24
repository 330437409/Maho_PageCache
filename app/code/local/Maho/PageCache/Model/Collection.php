<?php

/**
 * In-memory collection that satisfies the report grid collection contract.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_Collection extends \Maho\Data\Collection\Db
{
    #[\Override]
    public function load($printQuery = false, $logQuery = false)
    {
        return $this;
    }

    #[\Override]
    public function loadData($printQuery = false, $logQuery = false)
    {
        return $this;
    }

    #[\Override]
    public function getSize()
    {
        return count($this->getItems());
    }
}
