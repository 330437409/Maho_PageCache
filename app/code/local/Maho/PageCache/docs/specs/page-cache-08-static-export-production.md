# 功能规格：Maho_PageCache 0.8.x Static Export 生产化

> 状态：已实现，待发版
> 关联 PRD：FR-17
> 关联 RFC：RFC-007 G9 替代目标
> 目标版本：0.8.x

## 1. 背景

0.3.0 已实现 Static Export，但部署配置只存在于旧规格里，且示例只覆盖部分 Cookie：

- 示例检查 `___store` cookie，实际 Maho 的 store cookie 是 `store`。
- 没有按 store code 映射静态目录。
- nginx 示例没有条件化 `Cache-Control`/`Vary`，容易给 PHP 响应加重复头。
- 没有在真实 nginx/Caddy 上复验 session、store、query、method、gzip 和目录 deny。
- 没有 Static Export 的 benchmark 目标。

本任务把 Static Export 变成可部署的生产配置，并用真实代理验证。

## 2. 范围

### 2.1 范围内

- `docs/deployment/static/nginx.conf` 和 `docs/deployment/static/Caddyfile` 可复制配置。
- session、admin session、旧版 session cookie、store cookie、query、method 的 bypass。
- 按 store code 映射静态目录；未知 store 回 PHP。
- 静态目录 deny、gzip_static/precompressed gzip。
- 条件化静态响应头，避免给 PHP 响应加重复头。
- nginx 1.31 和 Caddy 2 实机验证。
- Static Export benchmark 和相对 P95/RPS 目标。
- 回滚步骤。

### 2.2 范围外

- 多节点共享静态存储。
- CDN 自动 purge。
- 静态文件 minify、Brotli、图片优化。
- 允许参数变体的静态文件。
- 登录用户或 session 用户的静态化。

## 3. 配置

### 3.1 nginx

核心：

```nginx
map $cookie_store $pc_static_root {
    default      /__page_cache_static_off__;
    ""           /var/page-cache/static/default;
    "~^default$" /var/page-cache/static/default;
}
```

- `store` cookie 值是 store code。
- 每个 store 一行；未知 store 走 sentinel，回 PHP。
- session cookie 和 query 由独立 map 拦截。
- GET/HEAD 才允许静态。
- `try_files` 只尝试静态树，回退 `@handler`。
- `$upstream_addr` 为空时才加 `Cache-Control`、`Vary`、`X-Page-Cache`，避免污染 PHP 响应。

### 3.2 Caddy

- `@static` 只匹配 GET/HEAD，无 session/store cookie，无 query。
- `file` matcher + `try_files`。
- `rewrite * {http.matchers.file.relative}` + `file_server`。
- `precompressed gzip`。
- 单 store 下任何 `store` cookie 回 PHP；多 store 用独立 site block。

## 4. 安全

- 静态目录必须在 Maho 根目录下，不在 `public/`，并 deny 直接访问。
- 静态文件只来自无 session、无 query、已知 store 的匿名请求。
- `store` cookie 必须映射到该 store 的静态目录，不能回落到默认 store。
- 静态文件只包含匿名 fallback，form key 和 SID 仍是占位符。
- 写入和删除由 PHP 侧负责，Web 服务器只读。
- 回滚时先关闭静态导出，再恢复全量回 PHP。

## 5. 性能目标

参考 B2 Local HIT `237.91 RPS / P95 64.494 ms`：

- Static Export P95 至少低 80%。
- RPS 至少是 B2 的 5 倍。

实测：

| 配置 | RPS | P95 |
|---|---:|---:|
| nginx static | 5492.83 | 3.258 ms |
| Caddy static | 4850.68 | 3.281 ms |

目标通过。数据见 `docs/benchmark/0.8.0-static-export.md`。

## 6. 任务拆分

- [x] nginx 配置和真实代理验证。
- [x] Caddy 配置和真实代理验证。
- [x] session/admin/legacy/store/query/method bypass 验证。
- [x] gzip、目录 deny、缺失文件回 PHP 验证。
- [x] Static Export benchmark 原始数据。
- [x] 部署文档、基准文档、回滚步骤。
- [x] README、API、ARCHITECTURE、PRD 更新。
- [ ] 0.8.x 发版。

## 7. 验收

- nginx 和 Caddy 静态命中无 `X-Maho-Cache`，有 `X-Page-Cache: HIT-static`。
- `maho_session`、`maho_admin_session`、`frontend`、`om_frontend`、`adminhtml` 回 PHP。
- `store` cookie 映射到对应 store；未知 store 回 PHP。
- 任何 query 和 POST 回 PHP。
- 目录直接访问 403。
- gzip 返回 `Content-Encoding: gzip`。
- 静态文件缺失时回 PHP。
- 保存商品后静态文件删除，下一次请求重建。
- benchmark 达标。
- 回滚步骤可执行。

## 8. 风险

- 多 store 配置需要为每个 store 增加 map 行；漏配会回 PHP，不会串店。
- Caddy 单 store 配置对任何 `store` cookie 都回 PHP，命中率会低一些，但安全。
- 静态文件删除后 Web 服务器可能有极短的 open file 窗口；旧请求返回已打开的文件，属于正常缓存语义。
- 静态文件不返回 304，浏览器每次回源；命中点在 Web 服务器。
- 静态目录权限和 deny 配错会暴露匿名 HTML；部署检查必须包含 403 验证。
