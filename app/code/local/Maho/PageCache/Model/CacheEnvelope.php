<?php

/**
 * Validates and decodes the local page cache envelope.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_PageCache
 */

declare(strict_types=1);

class Maho_PageCache_Model_CacheEnvelope
{
    public const int SCHEMA_VERSION = 2;

    private const array ENCODINGS = ['plain', 'gzip'];

    /**
     * @param array<string, mixed> $entry
     * @param array{global: int, url: int} $versions
     */
    public static function isValid(
        array $entry,
        Maho_PageCache_Model_Context $context,
        array $versions,
    ): bool {
        if ((int) ($entry['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || (string) ($entry['mode'] ?? '') !== Maho_PageCache_Helper_Data::MODE_LOCAL
            || !is_string($entry['html'] ?? null)
            || $entry['html'] === ''
            || (string) ($entry['url_hash'] ?? '') !== $context->getUrlHash()
            || (string) ($entry['context_hash'] ?? '') !== $context->getHash()
            || !in_array((string) ($entry['html_encoding'] ?? 'plain'), self::ENCODINGS, true)
        ) {
            return false;
        }

        $entryVersions = $entry['versions'] ?? null;
        return is_array($entryVersions)
            && (int) ($entryVersions['global'] ?? 0) === (int) $versions['global']
            && (int) ($entryVersions['url'] ?? 0) === (int) $versions['url'];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public static function decode(array $entry): ?array
    {
        if (!is_string($entry['html'] ?? null)) {
            return null;
        }

        $encoding = (string) ($entry['html_encoding'] ?? 'plain');
        if ($encoding === 'plain') {
            return $entry['html'] !== '' ? $entry : null;
        }
        if ($encoding !== 'gzip') {
            return null;
        }

        $decoded = function_exists('gzdecode') ? @gzdecode($entry['html']) : false;
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $entry['html'] = $decoded;
        return $entry;
    }
}
