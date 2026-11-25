<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

class LocalHitCountingCache extends Mage_Core_Model_Cache
{
    public int $loads = 0;

    public function __construct(private readonly Mage_Core_Model_Cache $inner) {}

    #[\Override]
    public function load(string $id): mixed
    {
        $this->loads++;
        return $this->inner->load($id);
    }

    #[\Override]
    public function save(mixed $data, string $id, array $tags = [], ?int $lifeTime = null): bool
    {
        return $this->inner->save($data, $id, $tags, $lifeTime);
    }

    #[\Override]
    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }

    #[\Override]
    public function clean(array|string $tags = []): bool
    {
        return $this->inner->clean($tags);
    }
}

function localHitCountContext(array $overrides = []): Maho_PageCache_Model_Context
{
    return new Maho_PageCache_Model_Context(array_merge([
        'website_id' => 1,
        'store_id' => 1,
        'currency' => 'USD',
        'customer_group' => 0,
        'tax_country' => 'US',
        'tax_rate' => 0.0,
        'params' => [],
        'scheme' => 'https',
        'host' => 'example.com',
        'path' => '/read-count.html',
        'mode' => 'local',
    ], $overrides));
}

/**
 * @return array{0: array<string, mixed>|null, 1: int, 2: array{global: int, url: int}}
 */
function localHitCountReadEnvelope(?array $versions = null): array
{
    $context = localHitCountContext();
    $cache = new Maho_PageCache_Model_HtmlCache();
    $storedVersions = $cache->getVersions($context);
    $cache->save($context, '<html>read count</html>', ['catalog_product_1'], 3600, [], $storedVersions);

    $app = Mage::app();
    $property = new ReflectionProperty(Mage_Core_Model_App::class, '_cache');
    $original = $property->getValue($app);
    assert($original instanceof Mage_Core_Model_Cache);
    $counter = new LocalHitCountingCache($original);
    $property->setValue($app, $counter);

    try {
        $result = $cache->loadEnvelope($context, $versions);
    } finally {
        $property->setValue($app, $original);
    }

    return [$result['entry'], $counter->loads, $storedVersions];
}

it('reads the local envelope in three cache operations', function () {
    [$entry, $loads] = localHitCountReadEnvelope();

    expect($entry)->not->toBeNull()
        ->and($entry['html'])->toBe('<html>read count</html>')
        ->and($loads)->toBe(3);
});

it('reads only the envelope when versions are supplied', function () {
    [, , $versions] = localHitCountReadEnvelope();
    [$entry, $loads] = localHitCountReadEnvelope($versions);

    expect($entry)->not->toBeNull()
        ->and($loads)->toBe(1);
});
