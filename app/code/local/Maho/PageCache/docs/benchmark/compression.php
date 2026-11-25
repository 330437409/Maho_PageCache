<?php

/**
 * Measure gzip time and size for local page cache HTML samples.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

$iterations = 50;
$warmup = 2;
$files = [];

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--iterations=')) {
        $iterations = max(1, (int) substr($arg, 13));
        continue;
    }
    if (str_starts_with($arg, '--warmup=')) {
        $warmup = max(0, (int) substr($arg, 9));
        continue;
    }
    $files[] = $arg;
}

if ($files === []) {
    fwrite(STDERR, "Usage: php compression.php [--iterations=50] [--warmup=2] file.html [...]\n");
    exit(1);
}

/**
 * @param list<float> $values
 */
function percentile(array $values, float $percentile): float
{
    sort($values);
    $index = (int) ceil((count($values) - 1) * $percentile);
    return $values[$index];
}

$levels = [1, 3, 6];
$result = [
    'php_version' => PHP_VERSION,
    'iterations' => $iterations,
    'warmup' => $warmup,
    'levels' => $levels,
    'samples' => [],
];

foreach ($files as $file) {
    $html = file_get_contents($file);
    if ($html === false || $html === '') {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }

    $sample = [
        'label' => pathinfo($file, PATHINFO_FILENAME),
        'file' => basename($file),
        'html_bytes' => strlen($html),
        'results' => [],
    ];

    foreach ($levels as $level) {
        $encoded = gzencode($html, $level);
        if ($encoded === false) {
            fwrite(STDERR, "gzencode failed for {$file} at level {$level}\n");
            exit(1);
        }

        for ($i = 0; $i < $warmup; $i++) {
            if (gzencode($html, $level) === false) {
                fwrite(STDERR, "gzencode failed for {$file} at level {$level}\n");
                exit(1);
            }
        }

        $times = [];
        $encodedBytes = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $encoded = gzencode($html, $level);
            $times[] = (hrtime(true) - $start) / 1_000_000;
            if ($encoded === false) {
                fwrite(STDERR, "gzencode failed for {$file} at level {$level}\n");
                exit(1);
            }
            $encodedBytes = strlen($encoded);
        }

        for ($i = 0; $i < $warmup; $i++) {
            if (gzdecode($encoded) === false) {
                fwrite(STDERR, "gzdecode failed for {$file} at level {$level}\n");
                exit(1);
            }
        }

        $decodeTimes = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $decoded = gzdecode($encoded);
            $decodeTimes[] = (hrtime(true) - $start) / 1_000_000;
            if ($decoded !== $html) {
                fwrite(STDERR, "gzdecode mismatch for {$file} at level {$level}\n");
                exit(1);
            }
        }

        $sample['results'][] = [
            'level' => $level,
            'gzip_bytes' => $encodedBytes,
            'ratio' => round($encodedBytes / $sample['html_bytes'], 4),
            'median_ms' => round(percentile($times, 0.5), 4),
            'p95_ms' => round(percentile($times, 0.95), 4),
            'decode_median_ms' => round(percentile($decodeTimes, 0.5), 4),
            'decode_p95_ms' => round(percentile($decodeTimes, 0.95), 4),
        ];
    }

    $result['samples'][] = $sample;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
