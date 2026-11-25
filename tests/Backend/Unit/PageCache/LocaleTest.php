<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function pageCacheLocaleStrings(string $locale): array
{
    $file = dirname(__DIR__, 4) . '/app/locale/' . $locale . '/Maho_PageCache.csv';
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open ' . $file);
    }

    try {
        $strings = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($row) >= 2) {
                $strings[$row[0]] = $row[1];
            }
        }
        return $strings;
    } finally {
        fclose($handle);
    }
}

it('has a Chinese translation for every page cache string', function () {
    $english = pageCacheLocaleStrings('en_US');
    $chinese = pageCacheLocaleStrings('zh_CN');

    $missing = array_keys(array_diff_key($english, $chinese));
    $empty = array_keys(array_filter($chinese, static fn(string $value): bool => trim($value) === ''));

    expect($missing)->toBe([])
        ->and($empty)->toBe([]);
});
