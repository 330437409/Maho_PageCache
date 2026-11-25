#!/usr/bin/env node

/**
 * Small HTTP benchmark for the page cache baseline.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

import http from 'node:http';
import https from 'node:https';

const args = process.argv.slice(2);
const options = {
    label: 'run',
    url: 'http://localhost:8000/',
    requests: 300,
    concurrency: 10,
    rounds: 5,
    warmup: 2,
    headers: {},
};

for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    const next = args[i + 1];
    switch (arg) {
        case '--label': options.label = next; i++; break;
        case '--url': options.url = next; i++; break;
        case '--requests': options.requests = Number(next); i++; break;
        case '--concurrency': options.concurrency = Number(next); i++; break;
        case '--rounds': options.rounds = Number(next); i++; break;
        case '--warmup': options.warmup = Number(next); i++; break;
        case '--header': {
            const index = String(next).indexOf(':');
            if (index > 0) {
                options.headers[String(next).slice(0, index).trim()] = String(next).slice(index + 1).trim();
            }
            i++;
            break;
        }
        case '--help':
            console.log('Usage: node run.mjs --label NAME --url URL [--requests N] [--concurrency N] [--rounds N] [--warmup N] [--header "Name: value"]');
            process.exit(0);
            break;
        default:
            throw new Error(`Unknown argument: ${arg}`);
    }
}

const target = new URL(options.url);
const transport = target.protocol === 'https:' ? https : http;
const agent = new transport.Agent({
    keepAlive: true,
    maxSockets: Math.max(1, options.concurrency),
});

function request() {
    return new Promise((resolve) => {
        const started = process.hrtime.bigint();
        const req = transport.request(target, {
            method: 'GET',
            agent,
            headers: {
                Accept: 'text/html,application/xhtml+xml',
                Connection: 'keep-alive',
                ...options.headers,
            },
        }, (res) => {
            const chunks = [];
            res.on('data', (chunk) => chunks.push(chunk));
            res.on('end', () => {
                const elapsedMs = Number(process.hrtime.bigint() - started) / 1e6;
                resolve({
                    status: res.statusCode ?? 0,
                    latency: elapsedMs,
                    cache: String(res.headers['x-maho-cache'] ?? ''),
                    proxy: String(res.headers['x-varnish-cache'] ?? res.headers['x-cache'] ?? ''),
                    cacheControl: String(res.headers['cache-control'] ?? ''),
                    etag: String(res.headers['etag'] ?? ''),
                    bytes: Buffer.concat(chunks).length,
                });
            });
        });
        req.on('error', (error) => {
            const elapsedMs = Number(process.hrtime.bigint() - started) / 1e6;
            resolve({
                status: 0,
                latency: elapsedMs,
                cache: '',
                cacheControl: '',
                etag: '',
                bytes: 0,
                error: error.message,
            });
        });
        req.end();
    });
}

function percentile(sorted, p) {
    if (sorted.length === 0) return 0;
    const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
    return sorted[index];
}

async function runRound(round) {
    for (let i = 0; i < options.warmup; i++) {
        await request();
    }

    const results = [];
    let next = 0;
    const started = process.hrtime.bigint();

    async function worker() {
        while (true) {
            const index = next++;
            if (index >= options.requests) return;
            results.push(await request());
        }
    }

    await Promise.all(Array.from({ length: Math.max(1, options.concurrency) }, worker));
    const durationSeconds = Number(process.hrtime.bigint() - started) / 1e9;
    const latencies = results.map((result) => result.latency).sort((a, b) => a - b);
    const statuses = {};
    const cacheHeaders = {};
    const proxyHeaders = {};
    for (const result of results) {
        statuses[result.status] = (statuses[result.status] ?? 0) + 1;
        const cache = result.cache || 'none';
        cacheHeaders[cache] = (cacheHeaders[cache] ?? 0) + 1;
        const proxy = result.proxy || 'none';
        proxyHeaders[proxy] = (proxyHeaders[proxy] ?? 0) + 1;
    }

    return {
        round,
        requests: results.length,
        duration_seconds: Number(durationSeconds.toFixed(4)),
        rps: Number((results.length / durationSeconds).toFixed(2)),
        latency_ms: {
            min: Number((latencies[0] ?? 0).toFixed(3)),
            p50: Number(percentile(latencies, 50).toFixed(3)),
            p95: Number(percentile(latencies, 95).toFixed(3)),
            p99: Number(percentile(latencies, 99).toFixed(3)),
            max: Number((latencies[latencies.length - 1] ?? 0).toFixed(3)),
        },
        statuses,
        cache_headers: cacheHeaders,
        proxy_headers: proxyHeaders,
        errors: results.filter((result) => result.error).length,
    };
}

const rounds = [];
for (let round = 1; round <= Math.max(1, options.rounds); round++) {
    rounds.push(await runRound(round));
}

const median = (values) => {
    const sorted = [...values].sort((a, b) => a - b);
    return sorted[Math.floor(sorted.length / 2)];
};

const summary = {
    label: options.label,
    url: options.url,
    requests_per_round: options.requests,
    concurrency: options.concurrency,
    warmup_requests: options.warmup,
    rounds,
    median: {
        rps: Number(median(rounds.map((round) => round.rps)).toFixed(2)),
        p50_ms: Number(median(rounds.map((round) => round.latency_ms.p50)).toFixed(3)),
        p95_ms: Number(median(rounds.map((round) => round.latency_ms.p95)).toFixed(3)),
        p99_ms: Number(median(rounds.map((round) => round.latency_ms.p99)).toFixed(3)),
    },
};

console.log(JSON.stringify(summary, null, 2));
agent.destroy();
