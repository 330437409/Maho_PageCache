<?php

/**
 * Page cache statistics tables.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Maho\Db\Schema;

return function (SchemaEditor $schema): void {
    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_stat')
            ->addColumn(Schema::column('stat_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('event_type', Types::STRING, length: 16))
            ->addColumn(Schema::column('route', Types::STRING, length: 128, notNull: false))
            ->addColumn(Schema::column('url_path', Types::STRING, length: 512, notNull: false))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('mode', Types::STRING, length: 16, notNull: false))
            ->addColumn(Schema::column('duration_ms', Types::INTEGER, unsigned: true, notNull: false))
            ->addColumn(Schema::column('reason', Types::STRING, length: 64, notNull: false))
            ->addColumn(Schema::column('hour_bucket', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('stat_id')->create(),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('created_at'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('event_type', 'created_at'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('store_id', 'created_at'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('route', 'created_at'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('hour_bucket'))
            ->setComment('Page Cache Statistics')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_stat_hourly')
            ->addColumn(Schema::column('bucket', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('event_type', Types::STRING, length: 16))
            ->addColumn(Schema::column('route', Types::STRING, length: 128, default: ''))
            ->addColumn(Schema::column('event_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('duration_sum', Types::BIGINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('duration_count', Types::INTEGER, unsigned: true, default: 0))
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('bucket', 'store_id', 'event_type', 'route'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('bucket'))
            ->setComment('Page Cache Hourly Statistics')
            ->create(),
    );
};
