<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('describes one warmup batch and deduplicates pending messages', function () {
    $message = new Maho_PageCache_Model_WarmMessage(25);

    expect($message->limit)->toBe(25)
        ->and($message->getDedupeKey())->toBe('page_cache_warmup');
});

it('passes the message limit to the warmup service', function () {
    $spy = new class extends Maho_PageCache_Service_Warm {
        public ?int $limit = null;

        #[\Override]
        public function consumeQueue(int $limit = 0): array
        {
            $this->limit = $limit;

            return [
                'at' => time(),
                'processed' => 0,
                'warmed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'dropped' => 0,
                'remaining' => 0,
            ];
        }
    };
    $registryKey = '_service/page_cache/warm';
    $original = Mage::registry($registryKey);
    Mage::unregister($registryKey);
    Mage::register($registryKey, $spy, true);

    try {
        (new Maho_PageCache_Model_WarmMessageHandler())->__invoke(new Maho_PageCache_Model_WarmMessage(7));
    } finally {
        Mage::unregister($registryKey);
        if ($original !== null) {
            Mage::register($registryKey, $original, true);
        }
    }

    expect($spy->limit)->toBe(7);
});
