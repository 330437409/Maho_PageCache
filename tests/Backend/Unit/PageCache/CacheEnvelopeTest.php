<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function cacheEnvelopeContext(array $overrides = []): Maho_PageCache_Model_Context
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
        'path' => '/envelope.html',
        'mode' => 'local',
    ], $overrides));
}

function cacheEnvelopeEntry(Maho_PageCache_Model_Context $context, array $versions = ['global' => 1, 'url' => 1]): array
{
    return [
        'schema_version' => Maho_PageCache_Model_CacheEnvelope::SCHEMA_VERSION,
        'html' => '<html>envelope</html>',
        'html_encoding' => 'plain',
        'url_hash' => $context->getUrlHash(),
        'context_hash' => $context->getHash(),
        'versions' => $versions,
        'mode' => Maho_PageCache_Helper_Data::MODE_LOCAL,
        'tags' => [],
        'headers' => [],
        'ttl' => 3600,
        'created_at' => time(),
    ];
}

it('accepts a valid envelope for the context and versions', function () {
    $context = cacheEnvelopeContext();
    $entry = cacheEnvelopeEntry($context, ['global' => 4, 'url' => 7]);

    expect(Maho_PageCache_Model_CacheEnvelope::isValid($entry, $context, ['global' => 4, 'url' => 7]))
        ->toBeTrue();
});

it('rejects envelopes with stale versions or mismatched context', function () {
    $context = cacheEnvelopeContext();
    $entry = cacheEnvelopeEntry($context, ['global' => 4, 'url' => 7]);

    expect(Maho_PageCache_Model_CacheEnvelope::isValid($entry, $context, ['global' => 5, 'url' => 7]))
        ->toBeFalse()
        ->and(Maho_PageCache_Model_CacheEnvelope::isValid($entry, $context, ['global' => 4, 'url' => 8]))
        ->toBeFalse()
        ->and(Maho_PageCache_Model_CacheEnvelope::isValid($entry, cacheEnvelopeContext(['path' => '/other.html']), ['global' => 4, 'url' => 7]))
        ->toBeFalse()
        ->and(Maho_PageCache_Model_CacheEnvelope::isValid($entry, cacheEnvelopeContext(['store_id' => 2]), ['global' => 4, 'url' => 7]))
        ->toBeFalse();
});

it('rejects envelopes with an invalid schema or encoding', function () {
    $context = cacheEnvelopeContext();
    $versions = ['global' => 1, 'url' => 1];
    $entry = cacheEnvelopeEntry($context, $versions);

    $badSchema = $entry;
    $badSchema['schema_version'] = 1;
    $badEncoding = $entry;
    $badEncoding['html_encoding'] = 'brotli';
    $badMode = $entry;
    $badMode['mode'] = Maho_PageCache_Helper_Data::MODE_PROXY;

    expect(Maho_PageCache_Model_CacheEnvelope::isValid($badSchema, $context, $versions))->toBeFalse()
        ->and(Maho_PageCache_Model_CacheEnvelope::isValid($badEncoding, $context, $versions))->toBeFalse()
        ->and(Maho_PageCache_Model_CacheEnvelope::isValid($badMode, $context, $versions))->toBeFalse();
});

it('decodes plain and gzip envelopes', function () {
    $context = cacheEnvelopeContext();
    $entry = cacheEnvelopeEntry($context, ['global' => 1, 'url' => 1]);
    $gzip = $entry;
    $gzip['html'] = (string) gzencode('<html>envelope</html>');
    $gzip['html_encoding'] = 'gzip';

    expect(Maho_PageCache_Model_CacheEnvelope::decode($entry)['html'])->toBe('<html>envelope</html>')
        ->and(Maho_PageCache_Model_CacheEnvelope::decode($gzip)['html'])->toBe('<html>envelope</html>');
});

it('returns null when gzip decoding fails', function () {
    $context = cacheEnvelopeContext();
    $entry = cacheEnvelopeEntry($context, ['global' => 1, 'url' => 1]);
    $entry['html'] = 'not gzip';
    $entry['html_encoding'] = 'gzip';

    expect(Maho_PageCache_Model_CacheEnvelope::decode($entry))->toBeNull();
});

it('keeps the lock name stable when versions change', function () {
    $context = cacheEnvelopeContext();
    $cache = new Maho_PageCache_Model_HtmlCache();

    expect($cache->getLockName($context, ['global' => 1, 'url' => 1]))
        ->toBe($cache->getLockName($context, ['global' => 2, 'url' => 3]))
        ->and($cache->getLockName($context))
        ->not->toBe($cache->getLockName(cacheEnvelopeContext(['path' => '/other.html'])));
});
