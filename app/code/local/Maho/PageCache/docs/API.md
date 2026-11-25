# 接口文档：Maho 页面缓存

> 状态：0.9.2 已发布@v0.9.2（dist）：页面缓存菜单移到 System，后台改为顶部状态卡、tab 栏右侧范围和商店筛选、Invalidation Log，并删除重复的摘要 grid 与导出。 0.9.1 已发布@v0.9.1（dist）：监控指标补强、全量预热 runbook，以及统计 rollup、活跃 URL、purge 指标、legacy session cookie、catalog rule、Warm Now 异步和 gzip/动态块加固。 0.9.0 已发布@v0.9.0（dist）：FR-24 预热滑动窗口、活跃商品/分类 URL 源、后台 warmup 进度和锁状态。0.8.0 已发布@v0.8.0（dist）：FR-22 purge DB outbox、per-target ack/重试、`page-cache:purge:status` 和 stale 告警；FR-23 失效审计表、后台 Invalidations tab、导出和 retention；Static Export 生产配置和 nginx/Caddy benchmark 已完成。0.7.0 已发布@v0.7.0（dist）：RFC-007 Phase 1 CacheEnvelope 和 PERF-02 压缩配置、Phase 2 Context Index 迁移和旧表清理、Phase 3 Warmup 安全补强、Phase 4 Stats 采样、Dynamic 边界与 Proxy Models 验证、PERF-04 Cookie 规范化文档已实现；0.6.1 FR-21 和审计 P1 修复已实现；Phase 5 完成，dist 三库 CI 通过；G9 原 Local HIT 目标由 Static Export 替代并达成。0.5.5 已交付，第一期和第二期完成；第三期静态导出、统计面板、定时预热和服务器端占位符已交付；0.5.1 cache integrity 修复、0.5.2 tag 索引重构、0.5.3 P1 加固与性能优化、0.5.4 stock pending 监控和 0.5.5 监控指标导出与后台面板已交付。
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
| `page_cache/local/compression_enabled` | yes/no | 1 | local HTML 压缩开关 |
| `page_cache/local/compression_min_bytes` | text | 4096 | 小于该值的页面不压缩 |
| `page_cache/local/compression_level` | text | 6 | gzip 级别 1 到 9 |
| `page_cache/general/stock_pending_warn_age` | text | 3600 | stock pending 年龄告警阈值秒数，0 关闭 |
| `page_cache/proxy/context_mode` | select | `cookie` | `cookie` 依赖代理遵守 `Vary: Cookie`；`default` 只缓存默认 store 或 URL 带 store code 的请求，`___store` cookie 切换 bypass |
| `page_cache/audit/enabled` | yes/no | 0 | 失效审计开关 |
| `page_cache/audit/retention_days` | text | 30 | 审计保留天数 |
| `page_cache/audit/sample_tags` | text | 20 | 每条记录保存的 tag 样本数 |
| `page_cache/purge/max_attempts` | text | 5 | purge 最大投递次数 |
| `page_cache/purge/retry_delay` | text | 60 | 退避基数秒 |
| `page_cache/purge/stale_after` | text | 3600 | pending 年龄告警阈值秒 |
| `page_cache/purge/retention_days` | text | 7 | delivered/failed 保留天数 |
| `page_cache/purge/lease_seconds` | text | 300 | processing lease 秒 |
| `page_cache/dynamic/injection` | select | `ajax` | 动态块注入方式：`ajax` 或 `esi` |
| `page_cache/dynamic/ttl` | text | 0 | 显式标记可缓存的动态块响应秒数，0 不缓存 |
| `page_cache/dynamic/server_side` | yes/no | 0 | local 命中服务器端重建开关 |
| `page_cache/dynamic/server_types` | text | 空 | 允许重建的 block type 别名，逗号分隔 |
| `page_cache/dynamic/rate_limit` | text | 120 | 每个客户端在窗口内允许的动态请求数，0 关闭 |
| `page_cache/dynamic/rate_limit_window` | text | 60 | 动态请求限流窗口秒数 |
| `page_cache/warmup/enabled` | yes/no | 0 | 调度预热开关 |
| `page_cache/warmup/concurrency` | text | 4 | `page-cache:warm` 和预热队列默认并发 |
| `page_cache/warmup/batch_size` | text | 50 | 单次 cron 最大 URL 数 |
| `page_cache/warmup/delay_ms` | text | 500 | 请求启动间隔毫秒数，0 关闭；无 ext-curl 的 Symfony 回退路径按批次间隔 |
| `page_cache/warmup/max_attempts` | text | 3 | 最大失败次数 |
| `page_cache/warmup/retry_delay` | text | 300 | 重试基础间隔秒，乘以 attempts |
| `page_cache/warmup/queue_limit` | text | 10000 | 预热队列上限 |
| `page_cache/warmup/auth_user` | text | 空 | basic auth 用户 |
| `page_cache/warmup/auth_password` | obscure | 空 | basic auth 密码，加密存储 |
| `page_cache/warmup/allowed_hosts` | textarea | 空 | store 别名 origin，每行一个，scheme/host/port 必须完全匹配 |
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
| `page_cache/stats/mode` | select | `full` | `off`、`full`、`sampled`；`enabled=0` 时永远关闭 |
| `page_cache/stats/sample_rate` | text | 0.05 | 采样比例，0 到 1 |
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

代理必须按 URL 和 `Cookie` 缓存，也就是遵守 `Vary: Accept-Encoding, Cookie`。`X-Maho-Cache-Context` 是调试和审计头，不是代理 key。

`X-Cache-Tags` 最多 50 个 tag。超过预算时先保留页面自身、分类、CMS 等宽 tag，再保留商品 tag；被丢掉的组用 overflow tag 代替：

- 商品 tag `catalog_product_<id>` 被丢：`page_cache_overflow_product`
- 其他 tag 被丢：`page_cache_overflow`

按 tag purge 的消费者必须同时处理这两个 overflow tag；按 URL purge 的消费者不受影响。代理必须不缓存带 session cookie 的 bypass 响应；最稳妥的配置是只按 `maho_session`、store/货币相关 cookie 和 URL 生成 key，或在 `Cookie` 含 `maho_session` 时直接 bypass。代理忽略 `Vary` 时，不同 context 可能共享对象。模块只能设置头，不能强制代理遵守。nginx 和 Varnish 的 Cookie 规范化示例见 `docs/deployment/README.md`。

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

响应头 `Cache-Control: private, no-store`、`X-Maho-Cache: DYNAMIC`。token 绑定 block、页面 URL、store、context，过期时间为 `max(3600, 页面 TTL + 300)`；页面 URL 使用 store base URL 的 scheme/host，避免 TLS 终止在代理后出现 http/https 不一致。签名错误、过期、跨 URL/store/context 或请求未标记的 block 返回 400。dynamic 请求只允许 `cacheable_routes` 中的路由和 GET，其他路由按正常 bypass 处理；dynamic 请求不缓存，因此不受 session cookie、`Cache-Control: no-cache` 或 AJAX 排除影响。超过 `page_cache/dynamic/rate_limit` 时返回 429、`Retry-After` 和 `rate_limit` bypass。没有 block token 的 dynamic 请求只返回 `form_key`/`sid`，供表单占位符替换。

`local` 模式缓存无 session 时的匿名渲染。local 命中只发生在没有 session cookie 的请求上，因此不会把用户数据写进 HTML 缓存。显式 opt-in 的 leaf block 可以在 local 命中时服务器端重建；其余动态块保留匿名渲染。form key/SID 仍替换为占位符；local 模式的前端脚本只在用户聚焦或提交 POST 表单时请求新的 form key，被动浏览不会建立 session。

local 服务器端占位符：

- `page_cache/dynamic/server_side=1` 且 block type 在 `server_types` 中。
- block 同时设置 `page_cache_dynamic=1` 和 `page_cache_dynamic_server=1`。
- 缓存 HTML 用 `PC_DYNAMIC_SERVER` 注释包住匿名 fallback；entry 保存 block 定义。
- local 命中只重建标记段，token 绑定 URL、store、context 和过期时间；不生成完整 layout。
- token 无效、定义缺失、block 异常或出现 `Set-Cookie` 时保留匿名 fallback。
- static 文件写入前剥离标记，只保留匿名 fallback。

代理模型（FR-15）：

```php
$proxy = Mage::getService('page_cache/proxy');

$product = $proxy->getProduct($productId);      // ?Mage_Catalog_Model_Product
$products = $proxy->getProducts($productIds);   // [id => product]
$category = $proxy->getCategory($categoryId);
$page = $proxy->getCmsPage($pageId);
$block = $proxy->getCmsBlock($blockId);
```

- 同一请求内按 `type + store_id + id` 复用实例；重复调用不再查询。
- 单个 getter 传 `null` 时返回当前页面上下文实体；没有上下文时返回 `null`。
- 动态块用 `page_cache_proxies="product:1,product:2,category:3"` 声明预加载实体，渲染前统一批量加载。
- 代理加载的实体 tag 合并进页面 tag；模型数据不写入页面缓存。
- 引用类型只允许 `product`、`category`、`cms_page`、`cms_block`；非法值和超过 200 个的引用被忽略。

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

- 只导出无 session cookie、无 `store` cookie 且无 query 参数的匿名默认页。
- 带允许参数的变体继续走 PHP/local。
- 路由改写后使用公共路径，例如 `/page-cache-test/` 写成 `page-cache-test/index.html`。
- 静态文件由 nginx 或 Caddy 直接返回，PHP 不启动；完整配置见 `docs/deployment/static-export.md`。
- 缓存目录必须 deny 直接访问。
- Web 服务器必须在 `Cookie` 含 `maho_session`、`store` 或有 query 参数时回 PHP；`___store` 是 query，由参数规则拦截。
- 多 store 按 `store` cookie 的值映射到对应静态目录，未知 store 回 PHP。
- tag 失效和全量失效会删除静态文件；`page_cache_static_cleanup` cron 按 `page_cache_static_file` 索引删除到期文件，索引为空时回退目录扫描。
- 静态命中不经过 PHP，不计入 PHP 侧计数；nginx 和 Caddy 配置都用 `X-Page-Cache: HIT-static` 标记。
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
| `rate_limit` | dynamic 请求超过客户端限流 |
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

`System > Page Cache`（位于 Cache Management 下方、Index Management 上方），ACL 保留 `admin/report/page_cache`。页面提供 `Warm Now`（投递一个预热队列批次任务）和 `Configuration` 按钮。顶部状态卡显示 hit rate、命中/未命中/绕过、PHP 平均耗时、统计保留和预热队列；统计与日志内容使用 Trend、Top Missed URLs、Recent Events、Invalidation Log 四个标准后台 tab，每个 tab 是一张 `Mage_Adminhtml_Block_Widget_Grid`，带分页、列过滤和 CSV/Excel XML 导出。`Invalidation Log` 记录 area、cron job、类型、reason、tag 总数和样本、影响 URL 数、purge/warm 入队、store 和 mode，支持批量删除。

- 范围：24 小时、7 天、30 天；可按 store 过滤。
- 指标：hit rate、PHP 平均耗时、miss、bypass、trend、top missed URL、最近 flush/purge/warmup。
- 只统计 PHP 侧；静态文件、代理和 CDN 命中不计入。
- 原始事件保留 30 天，小时 rollup 保留 365 天，均可配置。
- `page_cache/stats/mode=sampled` 时按请求采样，面板显示采样率并标明计数为估算；`purge`、`flush`、`warmup` 仍完整记录。
- admin 前台请求不记录，避免 secret key 进入统计。

### 3.9 定时预热

默认关闭。后台 `Warmup` 配置组只控制调度开关、队列和请求参数，不提供“立即预热”按钮；立即预热使用 `./maho page-cache:warm`。开启 `page_cache/warmup/enabled` 后：

- 只请求 store base URL 或 `page_cache/warmup/allowed_hosts` 中显式列出的 origin；非信任 origin 不入队、不请求，也不发送 basic auth。
- tag 失效把受影响 URL 写入 `page_cache_warmup_queue`，按 URL sha256 去重。
- `page_cache_warmup` cron 每分钟消费，批次大小、并发、间隔、重试和队列上限见配置。
- 404、410、3xx、bypass、401、403 和其他永久 4xx 跳过并移出；429、5xx 和网络错误重试，达到 `max_attempts` 后移出。
- 全量失效清空队列，不自动全站预热。
- 请求不发送 session cookie；配置 basic auth 后发送 `Authorization: Basic`。
- 只接受 store base URL 的 scheme+host+port；入队、发请求和子 sitemap 请求前都会校验，非 store origin 不请求。
- URL 索引按 context 保存 tag、store 和 public path；同一 URL 的 `?p=1` 和 `?p=2` 不会互相覆盖。
- 最近一次汇总保留 7 天，`page-cache:status` 读取。

### 3.10 Stock pending 监控

- stock 状态变化写入 `page_cache_stock_pending`，实时和 manual 索引结束后清空。
- `page_cache_stock_pending_alert` cron 每小时第 20 分检查最老 pending 年龄。
- 超过 `page_cache/general/stock_pending_warn_age` 时写 `var/log/page-cache-stock.log`，提示执行 `./maho index:reindex cataloginventory_stock`。
- `page-cache:status` 同时显示 pending 数量和 oldest 年龄；阈值为 0 关闭告警。

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
Stock pending: 2, oldest: 2h 5m
Stock pending warning: oldest item is 2h 5m old. Run ./maho index:reindex cataloginventory_stock.
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
./maho page-cache:warm --active --store=1 --limit=100
./maho page-cache:warm --sitemap --dry-run
./maho page-cache:warm --queue
./maho page-cache:warm --queue --dry-run
./maho page-cache:warm --queue --limit=100
```

选项：

| 选项 | 说明 |
|---|---|
| `--sitemap` | 从已生成的 sitemap 读取 URL |
| `--active` | 从商品/分类 URL 重写读取活跃页面 |
| `--store=N` | `--active` 使用的 store ID，0 表示当前 store |
| `--url=URL` | 显式 URL，可重复 |
| `--limit=N` | 最多预热 N 个 URL，默认 100 |
| `--concurrency=N` | 并发数，0 使用配置 |
| `--delay=N` | 请求启动间隔毫秒，仅手工预热；默认取配置。有 ext-curl 时完成一个请求立即补下一个，无 ext-curl 回退按批次间隔 |
| `--queue` | 消费预热队列；不能和 `--sitemap`、`--active` 或 `--url` 同时使用 |
| `--dry-run` | 只列出 URL，不发请求；`--queue` 时列出队列中的 URL |

`X-Maho-Cache: BYPASS`、3xx、404/410、401/403 和其他永久 4xx 记为 skipped；429、5xx 和网络错误记为 failed。有 failed 时手工 sitemap/URL/active 命令返回非零；`--queue` 输出汇总并返回成功，失败行由 cron 继续重试。

`--active` 只读取当前 store 下 enabled 且可见的商品、is_active 分类，以及 `options` 为空的系统 URL 重写；目录式路径自动补尾斜杠。基址使用 store 的 link URL，因此会保留 store code 并使用 secure frontend 的 HTTPS 地址；收集失败返回非零。

### 5.3 `page-cache:purge`

```bash
./maho page-cache:purge --dry-run
./maho page-cache:purge --limit=100
./maho page-cache:purge --tag=catalog_product_123
```

选项：

| 选项 | 说明 |
|---|---|
| `--dry-run` | 只列出 pending URL，不 claim |
| `--limit=N` | 最多 claim N 条，默认 100 |
| `--tag=tag` | 只处理指定 tag 相关的 URL |
| `--target=proxy` | 选择 outbox target |
| `--retry-failed` | 先把 failed 行重置为 pending |

处理流程：

1. 从 `page_cache_purge_outbox` claim pending 行，写 `processing` 和 lease。
2. 去重 URL。
3. 触发 `maho_page_cache_purge_urls` 事件，payload `{urls, tags, mode}`。
4. 同时写 `var/page-cache/purge-<timestamp>.txt`，供外部消费者读取。
5. 成功后 ack，状态变 `delivered`。
6. 失败后 `attempts++`，按指数退避回到 `pending`；达到上限变 `failed`。
7. lease 过期或进程崩溃后，下一次 claim 会恢复行。
8. URL 为 `*` 表示全量 purge，消费者必须清空该模式的全部缓存对象。
9. 按 tag purge 时，商品 tag 会附带 `page_cache_overflow_product`，非商品 tag 会附带 `page_cache_overflow`；消费者按 tag 清理时必须处理这两个 tag。

delivered/failed 行按 `page_cache/purge/retention_days` 清理；pending 不受 retention 影响。

### 5.3.1 `page-cache:purge:status`

```bash
./maho page-cache:purge:status
./maho page-cache:purge:status --target=proxy --check
```

显示 pending、processing、failed、delivered、oldest pending、最近成功/失败和最近错误。`--check` 在存在 stale pending 或 failed 时返回退出码 1。

计数契约：

- `hit`：local 模式命中并直接返回 HTML。
- `miss`：可缓存的 200 响应，模块已写入 page cache 元数据或 local HTML。
- `bypass`：请求或响应被 bypass，未写入 page cache。
- `revalidated`：条件请求命中，返回 304。
- 代理直接命中的请求不经过 Maho，不计入 PHP 侧计数。
- 计数来自 `page_cache_stat`，需要开启统计；缓存类型清理不会重置当天计数。
- `page_cache_context_variant` registry 由移动主题、A-B、geo-IP 等模块设置；proxy/revalidating 遇到非空 variant 会 bypass，local 会把它计入 context key。
- 统计关闭时 `page-cache:status` 显示 counters unavailable。

### 5.4 `page-cache:monitor`

```bash
./maho page-cache:monitor --format=prometheus
./maho page-cache:monitor --format=json
./maho page-cache:monitor --format=text --check
```

选项：

| 选项 | 说明 |
|---|---|
| `--format=prometheus` | Prometheus 文本格式，默认 |
| `--format=json` | JSON，适合 Zabbix、Nagios、脚本 |
| `--format=text` | 一行 key=value，适合简单探针 |
| `--check` | stock pending 超过告警阈值、任一 target 的 purge 行超过 stale 阈值或存在 failed purge 行时返回退出码 1 |

指标：

- `maho_page_cache_stock_pending_count`
- `maho_page_cache_stock_pending_oldest_seconds`
- `maho_page_cache_stock_pending_stale`
- `maho_page_cache_stock_pending_warn_age_seconds`
- `maho_page_cache_purge_queue` / `purge_pending`（所有 target 的 pending outbox 行数）
- `maho_page_cache_purge_processing`
- `maho_page_cache_purge_failed`（所有 target）
- `maho_page_cache_purge_oldest_seconds`（所有 target）
- `maho_page_cache_purge_stale`
- `maho_page_cache_purge_target_count`
- `maho_page_cache_purge_target_pending{target="..."}` / `_failed` / `_oldest_seconds`（每个 target 的明细）
- `maho_page_cache_warmup_queue` / `warmup_pending`
- `maho_page_cache_warmup_due`
- `maho_page_cache_warmup_oldest_seconds`
- `maho_page_cache_warmup_lock`
- `maho_page_cache_invalidation_24h`
- `maho_page_cache_hit_today` / `miss` / `bypass` / `revalidated`
- `maho_page_cache_enabled` / `stats_enabled` / `warmup_enabled`

计数来自 `page_cache_stat`；统计关闭时 counter 为 0，`stats_enabled` 为 0。`invalidation_24h` 统计审计表最近 24 小时的行数，审计关闭时不增加。purge 聚合指标统计所有 outbox target；`page-cache:purge:status` 仍按 `--target` 查看单个 target。

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

监听者可以调用 Varnish、Cloudflare、Fastly 或自建脚本。事件不保证顺序，URL 已去重。事件/文件 hand-off 成功后模块 ack outbox 行；hand-off 失败会退避重试。ack 只表示 hand-off 成功，代理侧仍应保证幂等。

## 7. 缓存类型

模块声明缓存类型 `page_cache`，出现在 `System > Cache Management`。

- 清理该类型会清空页面缓存元数据、URL 索引和版本；purge outbox 在 DB 中，不随缓存清理丢失。
- 关闭该类型等同于关闭模块的缓存读写。
- 缓存类型使用 `PAGE_CACHE` tag。

## 8. 兼容性

- 不新增 REST/GraphQL/MCP 接口。
- 不修改现有 API。
- 不改变 `Mage_Core_Model_Cache` 的公开接口。
- 不新增数据库表。
