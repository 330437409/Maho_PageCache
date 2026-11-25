<?php

/**
 * Declarative schema for page cache statistics, queues, URL index and static file index.
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

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_warmup_queue')
            ->addColumn(Schema::column('queue_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('url_hash', Types::STRING, length: 64))
            ->addColumn(Schema::column('url', Types::STRING, length: 2048))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('reason', Types::STRING, length: 64, notNull: false))
            ->addColumn(Schema::column('attempts', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('available_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('last_error', Types::STRING, length: 255, notNull: false))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('queue_id')->create(),
            )
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('url_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('available_at', 'queue_id'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('store_id'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('created_at'))
            ->setComment('Page Cache Warmup Queue')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_url')
            ->addColumn(Schema::column('url_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('url_hash', Types::STRING, length: 64))
            ->addColumn(Schema::column('url', Types::STRING, length: 2048))
            ->addColumn(Schema::column('urls', Types::TEXT))
            ->addColumn(Schema::column('variants_truncated', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('public_path', Types::STRING, length: 2048))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('store_code', Types::STRING, length: 32, default: ''))
            ->addColumn(Schema::column('version', Types::INTEGER, unsigned: true, default: 1))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('url_id')->create(),
            )
            ->addIndex(
                Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('url_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('updated_at'))
            ->setComment('Page Cache URL Index')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_url_context')
            ->addColumn(Schema::column('context_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('url_id', Types::INTEGER, unsigned: true))
            ->addColumn(Schema::column('context_hash', Types::STRING, length: 64))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('store_code', Types::STRING, length: 32, default: ''))
            ->addColumn(Schema::column('public_path', Types::STRING, length: 2048))
            ->addColumn(Schema::column('url', Types::STRING, length: 2048))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('context_id')->create(),
            )
            ->addIndex(
                Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('url_id', 'context_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('updated_at'))
            ->setComment('Page Cache URL Context Index')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_url_context_tag')
            ->addColumn(Schema::column('context_id', Types::INTEGER, unsigned: true))
            ->addColumn(Schema::column('tag', Types::STRING, length: 128))
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('context_id', 'tag'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('tag'))
            ->setComment('Page Cache URL Context Tag Index')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_static_file')
            ->addColumn(Schema::column('static_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('path_hash', Types::STRING, length: 64))
            ->addColumn(Schema::column('path', Types::STRING, length: 2048))
            ->addColumn(Schema::column('expires_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('static_id')->create(),
            )
            ->addIndex(
                Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('path_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('expires_at'))
            ->setComment('Page Cache Static File Index')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('page_cache_stock_pending')
            ->addColumn(Schema::column('product_id', Types::INTEGER, unsigned: true))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('product_id')->create(),
            )
            ->setComment('Page Cache Pending Stock Invalidation')
            ->create(),
    );
};
