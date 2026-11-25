# FPM benchmark environment

The FPM baseline uses the prebuilt `webdevops/php-nginx:8.5` image. It ships PHP 8.5.13 with
`intl`, `gd`, `soap`, `zip`, `pdo_sqlite`, `opcache`, `ftp` and `redis`, plus nginx and php-fpm.
No apt build is required.

## Start

```bash
cd app/code/local/Maho/PageCache/docs/benchmark/fpm
MAHO_ROOT=/home/ubuntu/maho docker compose up -d
```

The app answers on `http://localhost:8082`. Send `Host: localhost:8000` so the app sees the
store URL configured in `local.xml`:

```bash
curl -H 'Host: localhost:8000' http://localhost:8082/page-cache-test-product/
```

`fpm-pool.conf` raises `pm.max_children` from the image default of 5 to 20 for the concurrency 10
benchmark. It is benchmark-only.

## Redis

For B3, point `app/etc/local.xml` at the Redis service:

```xml
<global>
    <cache>
        <backend>redis</backend>
        <backend_options>
            <dsn>redis://redis:6379/0</dsn>
        </backend_options>
    </cache>
</global>
```

Restart the `app` service, flush Redis with
`docker compose exec redis redis-cli flushall`, warm the page, and run the same benchmark.
Restore `local.xml` after the run.

## Baseline

The measured results and caveats are in `../0.6.1-baseline.md`.
