# Static Export 生产部署

> 状态：0.8.x 生产配置，nginx 1.31 和 Caddy 2 已验证
> 关联：[静态导出规格](../specs/page-cache-static-export.md)、[FR-17](../PRD.md)、[基准](../benchmark/0.8.0-static-export.md)

Static Export 把 local 模式的匿名默认页写成 Web 服务器可直接返回的文件。命中时 PHP 不启动，适用于商品页、分类页和 CMS 页。

## 1. 前置条件

1. `page_cache/general/mode=local`，`page_cache/static/enabled=1`。
2. 运行 `./maho migrate`，确认 `page_cache_static_file` 表存在。
3. `page_cache_static_cleanup` cron 每 5 分钟运行。
4. 静态目录 `var/page-cache/static` 在 Maho 根目录下，不在 `public/` 下。
5. Web 服务器配置能访问该目录，且外部不能直接访问 `/var/page-cache/...`。

生成：

```bash
./maho config:set -s default -i 0 page_cache/general/mode local
./maho config:set -s default -i 0 page_cache/static/enabled 1
./maho cache:flush
curl -sS -o /dev/null http://localhost:8000/page-cache-test-product/
find var/page-cache/static -type f | sort
```

期望：

```text
var/page-cache/static/default/page-cache-test-product/index.html
var/page-cache/static/default/page-cache-test-product/index.html.gz
```

## 2. nginx

完整配置见 [`static/nginx.conf`](static/nginx.conf)。关键点：

- `root` 是 Maho 根目录，不是 `public/`。
- `map $cookie_store` 按 store code 选静态目录；未知 store 回 PHP。
- `map $http_cookie` 拦截 `maho_session`、`maho_admin_session`、旧版 `frontend`/`om_frontend`/`adminhtml`。
- `map $args` 拦截所有 query 参数，包括 `___store`。
- `map $request_method` 只允许 GET/HEAD。
- `try_files` 只尝试静态树，不尝试目录本身。
- `gzip_static on` 返回同目录 `.gz`。
- `add_header Cache-Control`、`Vary`、`X-Page-Cache` 只在静态响应出现；PHP 响应通过 `$upstream_addr` 判断，不会重复加头。
- `location ^~ /var/page-cache/` 返回 403。
- `@handler` 换成实际的 PHP-FPM 或 upstream。

多 store：

```nginx
map $cookie_store $pc_static_root {
    default       /__page_cache_static_off__;
    ""            /var/page-cache/static/default;
    "~^default$"  /var/page-cache/static/default;
    "~^second$"   /var/page-cache/static/second;
}
```

每个 store code 一行。未知或未列出的 store 自动回 PHP。

## 3. Caddy

完整配置见 [`static/Caddyfile`](static/Caddyfile)。关键点：

- `handle /var/page-cache/*` 必须放在静态 matcher 之前。
- `@static` 只匹配 GET/HEAD、无 session cookie、无 `store` cookie、无 query。
- `file` matcher 使用 `try_files`，`rewrite * {http.matchers.file.relative}` 让 `file_server` 返回命中的文件。
- `precompressed gzip` 返回 `.gz`。
- 单 store 配置下，任何 `store` cookie 都回 PHP。多 store 应为每个 store 增加独立 site block 和静态路径，或使用能按 cookie 映射 root 的 Caddy 配置。
- `reverse_proxy` 换成实际的 PHP-FPM 或 upstream。

## 4. Bypass 矩阵

| 请求 | 结果 |
|---|---|
| 无 Cookie、无 query、GET/HEAD | 静态文件，`X-Page-Cache: HIT-static`，无 `X-Maho-Cache` |
| `maho_session`、`maho_admin_session`、`frontend`、`om_frontend`、`adminhtml` | PHP，`X-Maho-Cache: BYPASS` |
| `store` cookie 且 store 在 map 中 | 对应 store 的静态文件 |
| `store` cookie 未知 | PHP |
| `___store=...` 或其他 query | PHP |
| POST/PUT/DELETE | PHP |
| 静态文件不存在 | PHP |
| 直接访问 `/var/page-cache/...` | 403 |

## 5. 失效、TTL 和清理

- tag 失效、全量失效、local 版本变化都会删除对应 `.html` 和 `.gz`。
- 删除后下一次请求回 PHP，重新生成静态文件。
- `page_cache_static_cleanup` 按 `page_cache_static_file` 索引删除 `ttl + 300` 之前的文件；索引为空时扫描目录。
- 静态导出关闭后仍运行 cleanup，清理遗留文件。
- 静态文件不经过 PHP，不返回 304；浏览器每次回源，命中在 Web 服务器。

## 6. 性能目标

参考环境：SQLite、file cache、PHP built-in server 的 B2 Local HIT 为 `237.91 RPS / P95 64.494 ms`。

| 配置 | RPS | P50 | P95 | P99 |
|---|---:|---:|---:|---:|
| nginx static | 5492.83 | 1.601 ms | 3.258 ms | 4.582 ms |
| Caddy static | 4850.68 | 1.868 ms | 3.281 ms | 4.236 ms |

目标：

- Static Export P95 至少比 B2 Local HIT 低 80%。
- RPS 至少是 B2 Local HIT 的 5 倍。

当前两套配置都通过。完整数据和复现命令见 [`0.8.0-static-export.md`](../benchmark/0.8.0-static-export.md)。

## 7. 验证清单

```bash
# 1. 静态命中
curl -sS -D - -o /dev/null http://shop.example.com/page-cache-test-product/ \
  | grep -iE 'HTTP/|X-Page-Cache|X-Maho-Cache|Cache-Control|Vary'

# 2. session 回 PHP
curl -sS -H 'Cookie: maho_session=test' -D - -o /dev/null \
  http://shop.example.com/page-cache-test-product/ | grep -i X-Maho-Cache

# 3. query 回 PHP
curl -sS -D - -o /dev/null 'http://shop.example.com/page-cache-test-product/?p=2' \
  | grep -i X-Maho-Cache

# 4. 未知 store 回 PHP
curl -sS -H 'Cookie: store=unknown' -D - -o /dev/null \
  http://shop.example.com/page-cache-test-product/ | grep -i X-Maho-Cache

# 5. 目录不可直接访问
curl -sS -o /dev/null -w '%{http_code}\n' \
  http://shop.example.com/var/page-cache/static/default/page-cache-test-product/index.html

# 6. gzip
curl -sS -H 'Accept-Encoding: gzip' -D - -o /dev/null \
  http://shop.example.com/page-cache-test-product/ | grep -i Content-Encoding
```

期望：1 为 `HIT-static` 且无 `X-Maho-Cache`；2、3、4 有 `X-Maho-Cache`；5 为 403；6 为 `gzip`。

## 8. 回滚

1. `page_cache/static/enabled=0`。
2. `./maho cache:flush` 或 `./maho page-cache:status` 后清理静态目录。
3. 移除 Web 服务器的静态 `try_files`/`file` 分支，恢复全量回 PHP。
4. 确认首页、分类、商品、CMS 页面返回 200，且没有旧的静态文件被继续服务。

静态文件只是输出副本，回滚不需要改数据库 schema；local 元数据和 URL 索引仍是权威来源。
