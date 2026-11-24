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
    public function __clone() {}

    #[\Override]
    public function getSize()
    {
        return count($this->_items);
    }

    #[\Override]
    public function getFirstItem()
    {
        return array_first($this->getItems()) ?? new \Maho\DataObject();
    }

    #[\Override]
    public function getItems()
    {
        $items = parent::getItems();
        if ($this->_pageSize) {
            $offset = max(0, ($this->getCurPage() - 1) * $this->_pageSize);
            return array_slice($items, $offset, $this->_pageSize);
        }

        return $items;
    }

    #[\Override]
    public function addFieldToFilter($field, $condition = null)
    {
        if (is_array($field) && $condition === null) {
            foreach ($field as $item) {
                $this->addFieldToFilter($item);
            }
            return $this;
        }

        $this->_items = array_values(array_filter(
            $this->_items,
            fn(\Maho\DataObject $item): bool => $this->matches($item->getData($field), $condition),
        ));
        $this->_totalRecords = null;

        return $this;
    }

    private function matches(mixed $value, mixed $condition): bool
    {
        if (is_array($condition)) {
            if (array_key_exists('like', $condition)) {
                $needle = str_replace(['\\%', '\\_', '%'], ['%', '_', ''], (string) $condition['like']);
                return stripos((string) $value, $needle) !== false;
            }
            if (array_key_exists('eq', $condition)) {
                return (string) $value === (string) $condition['eq'];
            }
            if (array_key_exists('neq', $condition)) {
                return (string) $value !== (string) $condition['neq'];
            }
            if (array_key_exists('in', $condition) && is_array($condition['in'])) {
                return in_array((string) $value, array_map(strval(...), $condition['in']), true);
            }
            if (array_key_exists('nin', $condition) && is_array($condition['nin'])) {
                return !in_array((string) $value, array_map(strval(...), $condition['nin']), true);
            }
            if (array_key_exists('from', $condition) || array_key_exists('to', $condition)) {
                $numeric = is_numeric($value);
                $from = $condition['from'] ?? null;
                $to = $condition['to'] ?? null;
                if ($numeric) {
                    return ($from === null || (float) $value >= (float) $from)
                        && ($to === null || (float) $value <= (float) $to);
                }
                return ($from === null || (string) $value >= (string) $from)
                    && ($to === null || (string) $value <= (string) $to);
            }
            if (array_key_exists('null', $condition)) {
                return $condition['null'] ? $value === null : $value !== null;
            }
            return true;
        }

        return (string) $value === (string) $condition;
    }
}
