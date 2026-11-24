# 接口文档：Maho 页面缓存

> 状态：0.5.2 已交付，第一期和第二期完成；第三期静态导出、统计面板、定时预热和服务器端占位符已交付；0.5.1 cache integrity 修复和 0.5.2 tag 索引重构已交付
> 关联 PRD：`docs/PRD.md`
> 关联架构：`docs/ARCHITECTURE.md`

## 1. 对外接口范围

第一期没有 REST、GraphQL 或 MCP 接口。对外接口只有：

- 后台配置段
- HTTP 响应头和条件请求
- CLI 命令
- purge 事件和 purge 文件

## 2. 后台配置

路径：`System > Configuration > Page Cache`

| 配置路径 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `page_cache/general/enabled` | yes/no | 0 | 总开关 |
| `page_cache/general/mode` | select | `revalidating` | `proxy`、`revalidating` 或 `local` |
| `page_cache/general/ttl` | text | 3600 | 缓存 TTL，秒 |
| `page_cache/general/html_max_size` | text | 2097152 | local HTML 条目上限，字节 |
| `page_cache/proxy/context_mode` | select | `cookie` | `cookie` 依赖代理遵守 `Vary: Cookie`；`default` 只缓存默认 store 或 URL 带 store code 的请求，`___store` cookie 切换 bypass |
| `page_cache/dynamic/injection` | select | `ajax` | 动态块注入方式：`ajax` 或 `esi` |
| `page_cache/dynamic/ttl` | text | 0 | 显式标记可缓存的动态块响应秒数，0 不缓存 |
| `page_cache/dynamic/server_side` | yes/no | 0 | local 命中服务器端重建开关 |
| `page_cache/dynamic/server_types` | text | 空 | 允许重建的 block type 别名，逗号分隔 |
| `page_cache/warmup/enabled` | yes/no | 0 | 调度预热开关 |
| `page_cache/warmup/concurrency` | text | 4 | `page-cache:warm` 和预热队列默认并发 |
| `page_cache/warmup/batch_size` | text | 50 | 单次 cron 最大 URL 数 |
| `page_cache/warmup/delay_ms` | text | 500 | 并发批次之间的等待毫秒数，0 关闭 |
| `page_cache/warmup/max_attempts` | text | 3 | 最大失败次数 |
| `page_cache/warmup/retry_delay` | text | 300 | 重试基础间隔秒，乘以 attempts |
| `page_cache/warmup/queue_limit` | text | 10000 | 预热队列上限 |
| `page_cache/warmup/auth_user` | text | 空 | basic auth 用户 |
| `page_cache/warmup/auth_password` | obscure | 空 | basic auth 密码，加密存储 |
| `page_cache/general/cacheable_routes` | textarea | 见下 | 每行一个 full action name |
| `page_cache/general/allowed_params` | text | `p,limit,order,dir,mode` | 允许的 query 参数，逗号分隔 |
| `page_cache/general/respect_no_cache` | yes/no | 1 | 请求带 `Cache-Control: no-cache` 时 bypass |
| `page_cache/general/debug_headers` | yes/no | 0 | 输出 `X-Maho-Cache-Tags` 和详细 bypass 原因 |
| `page_cache/general/session_skip_routes` | textarea | 同 cacheable_routes | 允许跳过 session 的路由 |
| `page_cache/general/filter_path_marker` | text | 空 | 分层导航路径标记，例如 `shop`；空表示关闭深度检查 |
| `page_cache/general/max_filter_depth` | text | 2 | 分层导航最大深度 |
| `page_cache/general/max_path_segments` | text | 8 | 最大路径段数 |
| `page_cache/static/enabled` | yes/no | 0 | 静态文件导出开关 |
| `page_cache/stats/enabled` | yes/no | 0 | 统计开关 |
| `page_cache/stats/raw_retention_days` | text | 30 | 原始事件保留天数 |
| `page_cache/stats/rollup_retention_days` | text | 365 | 小时聚合保留天数 |
| `page_cache/stats/max_duration_ms` | text | 60000 | 单事件耗时上限 |

默认 `cacheable_routes`：

```text
cms_index_index
cms_page_view
catalog_category_view
catalog_product_view
```

## 3. HTTP 响应头

### 3.1 通用

| 头 | 值 | 说明 |
|---|---|---|
| `Cache-Control` | 见下 | 代理/浏览器缓存策略 |
| `Vary` | `Accept-Encoding, Cookie` | 代理 cache key 维度 |
| `X-Maho-Cache` | `HIT` / `MISS` / `BYPASS` / `REVALIDATED` / `DYNAMIC` | 本次请求结果；`HIT` 仅由 local 模式产生，代理命中由代理回传 |
| `X-Maho-Cache-Mode` | `proxy` / `revalidating` / `local` | 当前模式 |
| `X-Maho-Cache-Context` | hash | 当前 context |
| `X-Maho-Cache-Bypass` | 原因码 | 仅 bypass 时 |
| `X-Maho-Cache-Tags` | 逗号分隔 tag | 仅调试开启时 |
| `X-Maho-Cache-Version` | hash | 仅调试开启时 |
| `X-Maho-Cache-Dynamic` | `server; rendered=N; failed=M` | 服务器端动态块重建，仅调试开启时 |

### 3.2 `proxy` 模式

```text
Cache-Control: public, s-maxage=<ttl>, max-age=0
```

第一次响应为 `X-Maho-Cache: MISS`。代理命中时 Maho 不参与，头由代理返回。

代理必须按 URL 和 `Cookie` 缓存，也就是遵守 `Vary: Accept-Encoding, Cookie`。`X-Maho-Cache-Context` 是调试和审计头，不是代理 key。代理必须不缓存带 session cookie 的 bypass 响应；最稳妥的配置是只按 `maho_session`、store/货币相关 cookie 和 URL 生成 key，或在 `Cookie` 含 `maho_session` 时直接 bypass。代理忽略 `Vary` 时，不同 context 可能共享对象。模块只能设置头，不能强制代理遵守。

### 3.3 `revalidating` 模式

200 响应：

```text
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"<version>"
Last-Modified: <rfc1123>
```

304 响应：

```text
HTTP/1.1 304 Not Modified
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"<version>"
Vary: Accept-Encoding, Cookie
X-Maho-Cache: REVALIDATED
```

304 响应没有 body，不生成 layout。条件请求只使用 `If-None-Match`。`Last-Modified` 仍返回，但 `If-Modified-Since` 不单独触发 304，避免同一秒内内容变化后返回旧内容。

### 3.4 `local` 模式

200 响应：

```text
Cache-Control: private, max-age=0, must-revalidate
Vary: Accept-Encoding, Cookie
ETag: W/"<version>"
Last-Modified: <rfc1123>
X-Maho-Cache: HIT
X-Maho-Cache-Mode: local
```

304 响应：

```text
HTTP/1.1 304 Not Modified
Cache-Control: private, max-age=0, must-revalidate
ETag: W/"<version>"
Vary: Accept-Encoding, Cookie
X-Maho-Cache: HIT
X-Maho-Cache-Mode: local
```

local 命中不生成 layout。条件请求只使用 `If-None-Match`；`If-Modified-Since` 不单独触发 304。响应是 `private`，浏览器每次回源重新验证，命中发生在 Maho 内部。local 模式只缓存匿名默认 context，session cookie 请求 bypass。

缓存 HTML 里的 form key 和 SID 会替换成 `{{PAGE_CACHE_FORM_KEY}}` / `{{PAGE_CACHE_SID}}`，由注入的脚本用动态请求结果替换。没有 JavaScript 的客户端无法提交这些表单。

### 3.5 动态块

layout 里给 block 设置 `page_cache_dynamic=1` 后，`proxy`/`revalidating` 的可缓存页面里的该 block 会变成一条 HTML 注释：

```html
<!--page-cache-dynamic:<hex-token>:<hex-block-name>-->
```

注释在 `<ul>`、`<tbody>`、`<select>` 等父级里也是合法节点，不会把 `<li>`/`<tr>` 结构挤坏。前端脚本用 `TreeWalker` 找注释，解码 token 和 block name，再用返回的 HTML 替换整条注释。

Ajax 请求同一页面 URL：

```text
X-Maho-Page-Cache: dynamic
X-Maho-Page-Cache-Blocks: <token>[,<token>...]
```

响应：

```json
{
  "blocks": {
    "header.cart": "<html>...</html>"
  },
  "form_key": "...",
  "sid": "..."
}
```

响应头 `Cache-Control: private, no-store`、`X-Maho-Cache: DYNAMIC`。token 绑定 block、页面 URL、store、context，默认 1 小时过期；签名错误、过期、跨 URL/store/context 或请求未标记的 block 返回 400。dynamic 请求只允许 `cacheable_routes` 中的路由和 GET，其他路由按正常 bypass 处理；dynamic 请求不缓存，因此不受 session cookie、`Cache-Control: no-cache` 或 AJAX 排除影响。没有 block token 的 dynamic 请求只返回 `form_key`/`sid`，供表单占位符替换。

`local` 模式缓存无 session 时的匿名渲染。local 命中只发生在没有 session cookie 的请求上，因此不会把用户数据写进 HTML 缓存。显式 opt-in 的 leaf block 可以在 local 命中时服务器端重建；其余动态块保留匿名渲染。form key/SID 仍替换为占位符；local 模式的前端脚本只在用户聚焦或提交 POST 表单时请求新的 form key，被动浏览不会建立 session。

local 服务器端占位符：

- `page_cache/dynamic/server_side=1` 且 block type 在 `server_types` 中。
- block 同时设置 `page_cache_dynamic=1` 和 `page_cache_dynamic_server=1`。
- 缓存 HTML 用 `PC_DYNAMIC_SERVER` 注释包住匿名 fallback；entry 保存 block 定义。
- local 命中只重建标记段，token 绑定 URL、store、context 和过期时间；不生成完整 layout。
- token 无效、定义缺失、block 异常或出现 `Set-Cookie` 时保留匿名 fallback。
- static 文件写入前剥离标记，只保留匿名 fallback。

block 标记 `page_cache_dynamic_cacheable=1` 且 `page_cache/dynamic/ttl>0` 时，模块按 block 集合缓存动态响应；命中时跳过 layout，form key/SID 仍从当前 session 生成。key 包含 block 列表、页面 URL、context、customer group、store 和页面版本。

### 3.6 ESI 动态块

`page_cache/dynamic/injection=esi` 且模式为 `proxy`/`revalidating` 时，占位符变成：

```html
<esi:include src="https://example.com/page.html?_page_cache_dynamic=<token>" />
```

代理解析 ESI 后得到 block HTML。该子请求响应 `private, no-store` 和 `X-Maho-Cache: DYNAMIC`，代理不得缓存。`local` 模式始终使用 Ajax。

### 3.7 静态文件导出

开启 `page_cache/static/enabled` 后，`local` 模式会把匿名默认页额外写成：

```text
var/page-cache/static/{store_code}/{public_path}
var/page-cache/static/{store_code}/{public_path}.gz
```

`{public_path}` 对首页是 `index.html`，对 `/category/` 是 `category/index.html`，对 `/product.html` 是 `product.html`。

- 只导出无 `maho_session` cookie、无 query 参数的匿名默认页。
- 带允许参数的变体继续走 PHP/local。
- 路由改写后使用公共路径，例如 `/page-cache-test/` 写成 `page-cache-test/index.html`。
- 静态文件由 nginx 或 Caddy 直接返回，PHP 不启动；Web 服务器示例见 `docs/specs/page-cache-static-export.md`。
- 缓存目录必须 deny 直接访问。
- Web 服务器必须在 `Cookie` 含 `maho_session` 或有 query 参数时回 PHP。
- tag 失效和全量失效会删除静态文件；`page_cache_static_cleanup` cron 按 `page_cache_static_file` 索引删除到期文件，索引为空时回退目录扫描。
- 静态命中不经过 PHP，不计入 PHP 侧计数；Caddy 示例可加 `X-Page-Cache: HIT-static`，nginx 以没有 `X-Maho-Cache` 和 PHP 不启动为准。
- 第一版只支持单节点；多节点共享存储和广播见 FR-19。

## 4. Bypass 原因码

| 原因码 | 说明 |
|---|---|
| `disabled` | 模块关闭 |
| `invalid_mode` | 配置模式非法 |
| `method` | 非 GET |
| `admin` | admin/install/API 区域 |
| `route` | 不在可缓存路由 |
| `session_cookie` | 请求带 Maho session cookie |
| `parameter` | 有未知 query 参数 |
| `path_depth` | 路径段数超过 `max_path_segments` |
| `filter_depth` | 分层导航深度超过 `max_filter_depth` |
| `no_cache` | 请求要求 no-cache |
| `ajax` | AJAX 请求 |
| `preview` | 预览模式 |
| `maintenance` | 维护模式 |
| `context` | `context_mode=default` 时请求用 `___store` cookie 切换 store，或 context 无法安全确定 |
| `response` | 响应不是 200 或包含 `Set-Cookie` |
| `empty_body` | 响应体为空 |
| `html_too_large` | local HTML 超过大小上限 |
| `lock_timeout` | 等待 local 渲染锁超时 |
| `cache_write` | local 缓存写入失败 |
| `version_changed` | local 渲染期间版本发生变化 |
| `dynamic_block_unnamed` | 标记为动态的 block 没有 layout name，页面不缓存 |
| `dynamic_token` | 动态块 token 生成失败，页面不缓存 |

原因码稳定，可用于日志搜索和监控。304 响应计入 `revalidated` 计数。

### 3.8 统计面板

`Reports > Page Cache`，ACL `admin/report/page_cache`。

- 范围：24 小时、7 天、30 天；可按 store 过滤。
- 指标：hit rate、PHP 平均耗时、miss、bypass、trend、top missed URL、最近 flush/purge/warmup。
- 只统计 PHP 侧；静态文件、代理和 CDN 命中不计入。
- 原始事件保留 30 天，小时 rollup 保留 365 天，均可配置。
- admin 前台请求不记录，避免 secret key 进入统计。

### 3.9 定时预热

默认关闭。开启 `page_cache/warmup/enabled` 后：

- tag 失效把受影响 URL 写入 `page_cache_warmup_queue`，按 URL sha256 去重。
- `page_cache_warmup` cron 每分钟消费，批次大小、并发、间隔、重试和队列上限见配置。
- 404、410、3xx、bypass、401、403 和其他永久 4xx 跳过并移出；429、5xx 和网络错误重试，达到 `max_attempts` 后移出。
- 全量失效清空队列，不自动全站预热。
- 请求不发送 session cookie；配置 basic auth 后发送 `Authorization: Basic`。
- 最近一次汇总保留 7 天，`page-cache:status` 读取。

## 5. CLI

### 5.1 `page-cache:status`

```bash
./maho page-cache:status
```

输出：

```text
Mode: revalidating
Enabled: yes
TTL: 3600
Global version: 12
Purge queue: 3
Warmup: enabled, queue=12
Last warmup: warmed=8 skipped=2 failed=1 dropped=0 remaining=12 at 2026-11-24 02:10:00
PHP counters (today): hit=300 miss=120 bypass=45 revalidated=80
# statistics disabled:
PHP counters: unavailable (statistics disabled)
```

### 5.2 `page-cache:warm`

```bash
./maho page-cache:warm --sitemap
./maho page-cache:warm --url=https://example.com/ --url=https://example.com/category.html
./maho page-cache:warm --sitemap --limit=100 --concurrency=4
./maho page-cache:warm --sitemap --dry-run
./maho page-cache:warm --queue
./maho page-cache:warm --queue --dry-run
./maho page-cache:warm --queue --limit=100
```

选项：

| 选项 | 说明 |
|---|---|
| `--sitemap` | 从已生成的 sitemap 读取 URL |
| `--url=URL` | 显式 URL，可重复 |
| `--limit=N` | 最多预热 N 个 URL，默认 100 |
| `--concurrency=N` | 并发数，0 使用配置 |
| `--delay=N` | 批次间隔毫秒，仅手工 sitemap/URL 预热；默认取配置 |
| `--queue` | 消费预热队列；不能和 `--sitemap` 或 `--url` 同时使用 |
| `--dry-run` | 只列出 URL，不发请求；`--queue` 时列出队列中的 URL |

`X-Maho-Cache: BYPASS`、3xx、404/410、401/403 和其他永久 4xx 记为 skipped；429、5xx 和网络错误记为 failed。有 failed 时手工 sitemap/URL 命令返回非零；`--queue` 输出汇总并返回成功，失败行由 cron 继续重试。

### 5.3 `page-cache:purge`

```bash
./maho page-cache:purge --dry-run
./maho page-cache:purge --limit=100
./maho page-cache:purge --tag=catalog_product_123
```

选项：

| 选项 | 说明 |
|---|---|
| `--dry-run` | 只列出 URL，不删除队列 |
| `--limit=N` | 最多处理 N 条，默认 100 |
| `--tag=tag` | 只处理指定 tag 相关的 URL |

处理流程：

1. 读取队列。
2. 去重 URL。
3. 触发 `maho_page_cache_purge_urls` 事件，payload `{urls, tags, mode}`。
4. 同时写 `var/page-cache/purge-<timestamp>.txt`，供外部消费者读取。
5. 成功后删除队列条目，失败保留并记录日志。
6. URL 为 `*` 表示全量 purge，消费者必须清空该模式的全部缓存对象。

purge entry 保留 24 小时，queue index 保留 25 小时。index 不会先于 entry 过期，避免待处理条目失去索引。

计数契约：

- `hit`：local 模式命中并直接返回 HTML。
- `miss`：可缓存的 200 响应，模块已写入 page cache 元数据或 local HTML。
- `bypass`：请求或响应被 bypass，未写入 page cache。
- `revalidated`：条件请求命中，返回 304。
- 代理直接命中的请求不经过 Maho，不计入 PHP 侧计数。
- 计数来自 `page_cache_stat`，需要开启统计；缓存类型清理不会重置当天计数。
- 统计关闭时 `page-cache:status` 显示 counters unavailable。

## 6. Purge 事件

事件名：`maho_page_cache_purge_urls`

payload：

```php
[
    'urls' => ['https://example.com/category.html', ...],
    'tags' => ['catalog_product_123', ...],
    'mode' => 'proxy',
]
```

监听者可以调用 Varnish、Cloudflare、Fastly 或自建脚本。事件不保证顺序，URL 已去重。

## 7. 缓存类型

模块声明缓存类型 `page_cache`，出现在 `System > Cache Management`。

- 清理该类型会清空页面缓存元数据、URL 索引、版本和 purge 队列。
- 关闭该类型等同于关闭模块的缓存读写。
- 缓存类型使用 `PAGE_CACHE` tag。

## 8. 兼容性

- 不新增 REST/GraphQL/MCP 接口。
- 不修改现有 API。
- 不改变 `Mage_Core_Model_Cache` 的公开接口。
- 不新增数据库表。
