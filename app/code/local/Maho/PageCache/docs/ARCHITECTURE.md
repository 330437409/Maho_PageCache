# 架构文档：Maho 页面缓存

> 状态：0.7.0 发布准备中：RFC-007 Phase 1 CacheEnvelope 和 PERF-02 压缩配置、Phase 2 Context Index 迁移和旧表清理、Phase 3 Warmup 安全补强、Phase 4 Stats 采样、Dynamic 边界与 Proxy Models 验证、PERF-04 Cookie 规范化文档已实现；0.6.1 FR-21 和审计 P1 修复已实现；Phase 5 本地验证完成，本地三库通过；官方 CI 和 G9 仍开放。0.5.5 已交付，第一期和第二期完成；第三期静态导出、统计面板、定时预热和服务器端占位符已交付；0.5.1 cache integrity 修复、0.5.2 tag 索引重构、0.5.3 P1 加固与性能优化、0.5.4 stock pending 监控和 0.5.5 监控指标导出与后台报表已交付。
> 模块：`Maho_PageCache`
> 关联 PRD：`docs/PRD.md`
> 关联 spec：`docs/specs/page-cache.md`

## 1. 定位与边界

本模块是 Maho 的 HTTP 页面缓存集成层，复用 Maho 现有的 Symfony Cache，不引入新的缓存后端。

第一期只做三件事：

1. 判断一个匿名 GET 是否适合缓存，跳过 session，并设置正确的 HTTP 头。
2. 用 `revalidating` 或 `proxy` 模式让反向代理/CDN 缓存 HTML。
3. 在实体、规则和配置变化时按 tag 或全量失效，并维护 purge 队列。

第一期不缓存 HTML。第二期增加 `local` 模式、Ajax/ESI 动态块和 `page-cache:warm`；服务器端占位符和代理模型留在第三期。

模块不替换 `Mage_Core_Model_Cache`，不引入 `Zend_Cache`，不新增 Composer 依赖，不修改 Maho 核心表。缓存数据使用 Maho 现有的 Symfony Cache（file/redis）；统计和预热队列使用模块自有表。

## 2. 模块结构

目录：

```text
app/code/local/Maho/PageCache/
├── .claude/rules/                  # 模块级 SDD 规则
├── Helper/Data.php                 # 配置读取和稳定原因码
├── Model/
│   ├── Observer.php                # session skip、304、响应头、失效
│   ├── Context.php                 # store/货币/客户组/税/参数 hash
│   ├── Key.php                     # URL/context/版本/HTML key
│   ├── Version.php                 # 全局版本和 URL 版本
│   ├── HtmlCache.php               # local 模式整页 HTML 存储
│   ├── StaticCache.php             # 匿名默认页静态 HTML 导出
│   ├── Stat.php                    # 统计记录、rollup、面板数据
│   ├── Resource.php                # 统计表资源模型
│   ├── UrlIndex.php                # 持久化 tag 到 URL 的索引
│   ├── PurgeQueue.php              # purge 队列
│   ├── WarmQueue.php               # 预热队列，入队、去重、重试和上限
│   ├── Counter.php                 # PHP 侧计数，写统计表
│   ├── Backend/
│   │   ├── Abstract.php            # 模式接口
│   │   ├── Proxy.php               # proxy 模式
│   │   ├── Revalidating.php        # revalidating 模式
│   │   └── Local.php               # local 模式命中、304 和响应头
│   └── System/Config/              # 模式和注入方式选项
├── Block/Adminhtml/Dashboard.php   # 报表容器、过滤器和按钮
├── Block/Adminhtml/Dashboard/      # tab 和四张报表 grid
├── controllers/Adminhtml/PageCacheController.php  # 统计面板控制器
├── Service/
│   ├── Request.php                 # bypass 判定和可缓存路由
│   ├── Response.php                # 头、ETag、tag 收集
│   ├── Dynamic.php                 # 动态块 token、占位符、服务器端重建和前端替换
│   ├── Invalidator.php             # 实体/规则/配置失效
│   ├── Purge.php                   # 队列处理和事件
│   └── Warm.php                    # sitemap/URL 预热、队列消费和退避
├── etc/
│   ├── config.xml                  # 模块、cache type、events
│   └── system.xml                  # System > Configuration > Page Cache
├── docs/                           # PRD、架构、API、ADR、spec
├── CHANGELOG.md
└── README.md

app/etc/modules/Maho_PageCache.xml
lib/MahoCLI/Commands/PageCacheStatus.php
lib/MahoCLI/Commands/PageCachePurge.php
lib/MahoCLI/Commands/PageCacheWarm.php
app/design/adminhtml/default/default/template/maho/pagecache/dashboard.phtml
tests/Backend/Unit/PageCache/*
```

说明：

- 模块放 `app/code/local`，和 `Maho_Translations` 一致。稳定后再考虑上游到 `app/code/core/Maho`。
- CLI 命令放 `lib/MahoCLI/Commands`，因为 Maho 的 CLI 发现器只扫描这个目录。
- 后台只增加一个配置段，不增加菜单、网格或控制器。
- 配置段使用 `page_cache`，不添加 `maho_` 或 `mage_` 前缀。
- 不新增表，第一期所有索引和队列走 Symfony Cache。

## 3. 运行时流程

### 3.1 请求开始

`controller_action_predispatch_session_start` 事件：

1. `Service\Request` 判断请求是否可缓存。
2. 可缓存时设置 `FLAG_NO_START_SESSION`，跳过 session。
3. 不可缓存时保持现有行为，并记录 bypass 原因。

判定顺序（任一不通过即 bypass）：

1. 模块已开启，模式是 `proxy` 或 `revalidating`。
2. 请求方法为 GET。
3. 不是 admin、install、API、checkout、customer account、preview、维护模式。
4. 当前 full action name 在 `page_cache/general/cacheable_routes` 中。
5. 路径段数不超过 `page_cache/general/max_path_segments`；配置了 `filter_path_marker` 时，分层导航深度不超过 `max_filter_depth`。
6. 请求没有 Maho session cookie。
7. query 参数都在允许列表，或为空。
8. 没有 `no-cache` 请求头，或配置允许忽略。
9. 不是 AJAX 请求。
10. context 可以安全确定（store、货币、客户组、税均为默认或可独立缓存）。

这里不读取 `customer/session`、`checkout/session` 或 `core/session`，因为读取本身会启动 session。登录、购物车、消息等状态用 session cookie 是否存在来判断。这是保守策略：有 session cookie 就不缓存。ADR-0019 锁定默认行为；只有 FR-20 的 opt-in local 模式才允许重新评估。

### 3.2 revalidating 304

`controller_action_predispatch` 事件：

1. 读取请求的 `If-None-Match`。`Last-Modified` 只作为响应头，`If-Modified-Since` 不单独触发 304。
2. 如果 `If-None-Match` 存在，计算当前 ETag：
   - 全局版本
   - URL 版本
   - URL hash
   - context hash
   - TTL 时间桶
   - 模式
3. 如果 ETag 匹配，设置 304、清空 body、设置缓存头、设置 `FLAG_NO_DISPATCH = true`。
4. 不生成 layout，不渲染 block。

这里使用版本 token，而不是响应体 hash。原因是 304 必须在渲染前决定，不能先渲染再比较。版本由失效逻辑递增，因此内容变化时 ETag 一定变化。

### 3.3 正常响应

`controller_front_send_response_before` 事件：

1. 如果请求不是本次可缓存的，设置 bypass 头，不写索引。
2. 如果响应不是 200，或 body 为空，不写索引。
3. 收集页面 tag：
   - `core_block_abstract_to_html_after` 中缓存块的 `getCacheTags()`。
   - 本次请求加载的实体，通过 `getCacheIdTags()` 收集。
   - 至少包含 `PAGE_CACHE`。
4. 计算 URL hash、context hash 和 ETag。
5. 写入 URL 元数据：
   - tags
   - 版本
   - 模式
   - 创建时间
   - TTL
6. 给 meta 打实体原生 tag，并写入 `page_cache_url`、`page_cache_url_context` 和 `page_cache_url_context_tag` URL 索引。
7. 设置响应头。
8. 递增 PHP 侧计数。

`local` 模式在写元数据后，还把整页 HTML 写入 `PC_HTML_<hash>`，并同时打 `PAGE_CACHE` 和 `PAGE_CACHE_HTML` tag。

### 3.4 proxy 模式

响应头：

```text
Cache-Control: public, s-maxage=<ttl>, max-age=0
Vary: Accept-Encoding, Cookie
X-Maho-Cache: MISS
X-Maho-Cache-Mode: proxy
X-Maho-Cache-Context: <hash>
X-Cache-Tags: <tag1>,<tag2>
```

代理命中时 PHP 不参与，因此 PHP 侧只统计 miss/bypass。代理命中率由代理或 CDN 自己的监控提供。

`X-Cache-Tags` 有 50 个 tag 的预算。未超限时保持原样；超限时先保留页面自身、分类、CMS 等宽 tag，再保留 `catalog_product_<id>`，被丢掉的组用 overflow tag 代替：商品 tag 被丢用 `page_cache_overflow_product`，其他 tag 被丢用 `page_cache_overflow`。按 tag purge 的消费者必须识别这两个 tag；按 URL purge 不受影响。该预算只作用于 proxy header 和 purge payload，不改本地 native cache tag 或 URL 索引。

tag 失效时：

1. 用 `PAGE_CACHE_TAG_<tag>` 原生 cache tag 原子清理 HTML、meta 和 ETag。
2. 从 `page_cache_url_context_tag` 读出该 tag 的 context 和 URL 行。
3. 递增每个 URL 的版本，删除 ETag，删除静态文件。
4. 把 URL 变体写入 purge 队列和预热队列；变体截断时全量 purge。
5. DB 查询失败时回退全量失效。
6. `page-cache:purge` 处理队列，触发 `maho_page_cache_purge_urls` 事件，或写 purge 文件。

### 3.5 local 模式

请求进入 `controller_action_predispatch` 时：

1. 如果请求可缓存、模式为 `local`，读取 global version、URL version 和 `PC_HTML_V2_<hash>`，普通命中共 3 次 cache backend 读取。
2. 校验 entry 的 `schema_version`、`url_hash`、`context_hash`、`versions`、`mode`；不匹配即 miss。v2 不依赖 meta。
3. 命中时设置 200、body、`Cache-Control: private, max-age=0, must-revalidate`、`ETag`、`X-Maho-Cache: HIT`，设置 `FLAG_NO_DISPATCH`，不生成 layout。
4. `If-None-Match` 命中时返回 304，body 为空。
5. 未命中时用稳定 core named lock `page_cache_html_<hash>` 保护回源，锁名不包含版本。等待的请求在锁释放后重新读缓存；等待超时则正常渲染但不保存，返回 `lock_timeout` bypass。
6. 渲染完成后，`controller_front_send_response_before` 检查版本没有变化，写入 v2 envelope、meta、原生实体 tag 和 URL 索引；版本在渲染期间变化时不保存，返回 `version_changed` bypass。
7. 旧 `PC_HTML_<hash>` entry 只在 v2 envelope 不存在时 fallback 读取，升级后建议 flush 一次。
8. HTML 超过 `page_cache/general/html_max_size` 时不缓存，返回 `html_too_large` bypass。
9. 大于 `page_cache/local/compression_min_bytes`（默认 4096）的 HTML 在缓存中 gzip 存储；`compression_enabled=0` 时存 plain，`compression_level` 默认 6，命中时按 `html_encoding` 解压后再输出。

`local` 响应是 `private`，浏览器每次回源重新验证，命中发生在 Maho 内部。

### 3.6 动态块

block 在 layout 里显式设置 `page_cache_dynamic=1`。渲染时：

1. `core_block_abstract_to_html_after` 只处理标记过的 block。
2. `proxy`/`revalidating` 的可缓存请求把 block HTML 换成签名注释占位符，不把用户数据写进缓存。注释在 `<ul>`、`<tbody>` 等父级里也是合法节点，不会破坏外层结构。
3. `local` 模式缓存无 session 时的匿名渲染；local 命中只面向无 session cookie 的请求。显式 opt-in 的 leaf block 在 local 命中时按下一节做服务器端重建，其余动态块保留匿名渲染。
4. form key/SID 在所有模式都替换为占位符。local 模式的前端脚本只在用户聚焦或提交 POST 表单时请求 form key，避免被动浏览建立 session；proxy 模式在页面加载时请求动态块和 form key。
5. 动态请求（`X-Maho-Page-Cache: dynamic` 或 `_page_cache_dynamic` 参数）只允许 GET 和 `cacheable_routes` 中的路由；其他路由记录 bypass 原因，不进入动态路径。允许的动态请求启动 session，正常生成 layout，只捕获当前页面标记过且被请求的 block。每个客户端在 `page_cache/dynamic/rate_limit` 窗口内超限时返回 429、`Retry-After` 和 `rate_limit` bypass。
6. Ajax 响应返回 `{blocks, form_key, sid}`；ESI 响应返回单个 block 的 HTML。
7. 动态响应 `Cache-Control: private, no-store`、`X-Maho-Cache: DYNAMIC`，代理不得缓存。
8. block 显式标记 `page_cache_dynamic_cacheable=1` 且 `page_cache/dynamic/ttl>0` 时，动态响应按 block 集合写入 `PC_DYNAMIC_<hash>`；key 包含 block 列表、URL、context、customer group、store 和版本。命中时在 layout 前直接返回，form key/SID 仍从当前 session 生成。

占位符 token 是 base64url JSON 加 HMAC，绑定 block、页面 URL、store、context 和过期时间。注释里用 hex 编码 token 和 block name，避免 HTML 注释中的 `--` 和属性转义。缓存页面里的 form key/SID 换成 `{{PAGE_CACHE_FORM_KEY}}` / `{{PAGE_CACHE_SID}}`，由注入的脚本用动态请求结果替换。

local 服务器端占位符：

- 打开 `page_cache/dynamic/server_side`，并在 `page_cache/dynamic/server_types` 列出允许的 block type。
- block 同时设置 `page_cache_dynamic=1` 和 `page_cache_dynamic_server=1`。
- 保存时把匿名 HTML 包在带 v2 token 的 `PC_DYNAMIC_SERVER` 注释中，entry 增加 `dynamic_blocks` 定义（type、name、template、标量 data）。
- local 命中时只对标记段调用 `Mage::app()->getLayout()->createBlock()` 和 `toHtml()`，不生成完整 layout，也不触发整页 layout 生成事件。
- token 校验 URL、store、context 和 exp；失败、定义缺失或 block 抛异常时保留匿名 fallback。
- 重建期间出现 `Set-Cookie` 或 session 启动时丢弃 live HTML 并移除该头，保持被动浏览不建立 session。
- static 写入前剥离标记，只保留匿名 fallback。

代理模型（FR-15）：

- `page_cache/proxy` 服务按 `type + store_id + id` 在请求内复用 product/category/CMS page/CMS block；负结果也缓存。
- 同类型多 ID 使用 collection 批量加载，查询次数不随实体数量增长。
- local 保存时 entry 记录当前 product/category/CMS page 的 ID（`proxy_entities`）；命中时预加载并恢复 `current_product`、`current_category`、`cms_page` 等 registry。
- 动态块可用 `page_cache_proxies` 声明预加载实体；渲染前统一批量加载。
- 代理加载过的实体把 `getCacheTags()` 合并进页面 tag，失效继续走现有链路。
- 不把模型数据写入页面缓存；旧 entry 没有 `proxy_entities` 时仍可命中。

### 3.7 静态文件导出

`local` 模式可选地把匿名默认页额外写成路径型静态文件：

- 根目录 `var/page-cache/static/{store}/{public-path}`；目录式 URL 补 `index.html`，`.html` 文件式 URL 保持原路径；同名 `.gz`。
- 只处理无 session cookie、无 query 参数的匿名默认页。
- 写入顺序：local 元数据/HTML 保存，静态文件写入，最终版本检查；版本变化会同时删除 local 和静态。
- 静态写入失败只记录日志，不影响页面响应。
- tag 失效删除对应静态文件；全量失效清空静态目录；后台刷新 `page_cache` 类型也清空。
- 静态文件写入时在 `page_cache_static_file` 记录相对路径和 `expires_at`；删除时同步删除记录。
- `page_cache_static_cleanup` cron 先按索引删除到期文件；索引为空时回退到一次目录扫描，清理升级前遗留的文件。
- Web 服务器直出时 PHP 不启动；带 `maho_session` 或 query 参数的请求必须回 PHP。
- 静态缓存目录必须 deny 直接访问；第一版只支持单节点。

`Context` 的静态/local key 使用浏览器公共路径：优先 `getOriginalPathInfo()`，再按 `web/url/use_store` 去掉 store code 前缀，避免 URL 改写后把内部路由写进静态路径。

### 3.8 预热

手工 CLI：

- 从已生成的 sitemap、显式 `--url` 或两者合并取 URL。
- 去重、过滤非 HTTP(S)、按 `--limit` 截断。
- 用 Symfony HttpClient 并发请求，默认并发取 `page_cache/warmup/concurrency`，批次之间等待 `page_cache/warmup/delay_ms`。请求前校验 store origin 或 `page_cache/warmup/allowed_hosts`；`max_redirects=0`，不跟随重定向。
- 不发送 cookie；basic auth 从配置读取。
- `X-Maho-Cache: BYPASS`、3xx、404/410、401/403 和其他永久 4xx 记为 skipped；429、5xx 和网络错误记为 failed。
- `--dry-run` 只列出 URL；有 failed 时命令返回非零。

调度预热：

- `page_cache/warmup/enabled=0` 时不入队、不消费。
- tag 失效成功后，受影响 URL 按 store 写入 `page_cache_warmup_queue`；URL 使用 meta 里的绝对 URL，按 sha256 去重。
- 重复入队把 `available_at` 提前到当前时间，不新增行。
- 队列超过 `queue_limit` 时删除最旧行。
- `page_cache_warmup` cron 每分钟消费，用 `core/lock` 保护。
- 每个批次最多 `batch_size` 条，按 `available_at` 和 `queue_id` 排序。
- 成功和永久跳过删除；失败写 `attempts`、`last_error` 和 `available_at = now + retry_delay * attempts`；达到 `max_attempts` 后删除。
- 最近一次汇总存 `PC_WARMUP_LAST_RUN`，保留 7 天；`page-cache:status` 读取它。
- 全量失效清空队列，不自动全站预热。

### 3.9 统计

- `page_cache_stat` 保存原始事件，`page_cache_stat_hourly` 保存小时聚合。
- 记录 hit/miss/bypass/revalidated/dynamic/purge/flush/warmup，字段包括 route、公共路径、store、mode、服务端耗时和原因码。
- 响应后批量写入，失败只记录日志。
- `page_cache/stats/mode` 支持 `off`、`full`、`sampled`。`enabled=0` 时永远关闭；`enabled=1` 且 mode 缺失或非法时按 `full`。`sampled` 在请求级决定一次，同一请求的 HIT/MISS/BYPASS/REVALIDATED/DYNAMIC 共用该决策；`purge`、`flush`、`warmup` 系统事件始终完整记录。
- 面板显示当前模式和采样率；sampled 时标明计数为估算，不是精确总量。
- admin 前台请求不记录，避免 admin secret key 进入统计；flush/purge/warmup 系统事件不带 admin 路径。
- `page_cache_stats_rollup` 每小时聚合，`page_cache_stats_cleanup` 每天清理。
- 一级菜单 `Page Cache` 使用四个报表 tab：Overview、Trend、Top Missed URLs、Recent Events；每个 tab 是标准 `Mage_Adminhtml_Block_Widget_Grid`，读取原始表或 rollup 表。`Warm Now` 处理一个预热队列批次。
- 静态、代理和 CDN 命中不经过 PHP，不计入。

### 3.10 失效

实体保存/删除：

- 通用 observer 监听 `model_save_commit_after` 和 `model_delete_commit_after`。
- 读取 `getCacheIdTags()`；product 使用 `getCacheIdTagsWithCategories()` 追加分类 tag。
- 用原生实体 tag 清理相关页面，并从 URL 索引删除对应 URL。
- stock item 保存时，只有 `is_in_stock` 变化或库存状态自动变化才失效，qty-only 更新不清理页面；可配置子商品同时失效父商品。保存后把 product ID 写入 `page_cache_stock_pending`，`end_process_event_cataloginventory_stock_item_save`、`end_index_events_cataloginventory_stock_item_save`、`after_reindex_process_cataloginventory_stock` 或 `end_index_events_catalog_product_mass_action` 时再次失效并清空 pending，覆盖同步和异步索引。`page_cache_stock_pending_alert` cron 每小时检查最老 pending 年龄，超过 `page_cache/general/stock_pending_warn_age` 时写 `page-cache-stock.log`；`page-cache:status` 显示 pending 数量和 oldest 年龄。
- `catalog_product_attribute_update_after` 批量属性更新按 product tag 失效；超过 30 个商品时回退全量失效，避免管理请求过慢。
- `catalog_product_import_finish_before` 读取导入 adapter 的 affected product IDs，导入结束后统一失效；超过 30 个商品时回退全量失效。

catalog rule：

- 监听 catalog rule 的保存/删除。
- 用 `getMatchingProductIds()` 得到产品 ID，转换为 `catalog_product_<id>`。
- 同时失效这些产品所属分类的 tag。
- `catalogrule_after_apply` 在索引刷新后读取 `product` 或 `affected_product`，再次失效受影响产品，避免页面在索引前回填旧价格。
- 计算失败或结果为空时回退全量失效。

其他规则和配置：

- sales rule、`core_config_data`、主题/design、layout、store 配置：全量失效。
- 后台清缓存、`core_clean_cache`、`adminhtml_cache_flush_all`：全量失效。
- 全量失效递增全局版本，清空 URL 索引和 purge 队列；旧缓存条目因版本不匹配不再命中，统计计数器保留。

## 4. 缓存存储

第一期使用 `Mage_Core_Model_Cache`，声明一个缓存类型：

```xml
<global>
    <cache>
        <types>
            <page_cache>
                <label>Page Cache</label>
                <description>HTTP page cache metadata, URL index and purge queue.</description>
                <tags>PAGE_CACHE</tags>
            </page_cache>
        </types>
    </cache>
</global>
```

缓存 key（都会带 `Mage_Core_Model_Cache` 的前缀）：

| Key | 内容 | TTL |
|---|---|---|
| `PC_META_<urlhash>` | `{url, tags, version, mode, context, created_at, ttl, store_id}` | TTL + 1 小时 |
| `PC_VERSION_GLOBAL` | int | 无 |
| `PC_VERSION_URL_<urlhash>` | int | TTL + 1 小时 |
| `PC_ETAG_<urlhash>` | `{etag, last_modified}` | TTL + 1 小时 |
| `PC_HTML_V2_<hash>` | v2 envelope：`{schema_version, html, html_encoding, url_hash, context_hash, versions, mode, headers, tags, ttl, created_at, etag, dynamic_blocks, proxy_entities}` | TTL |
| `PC_HTML_<hash>` | 旧格式 entry，兼容 fallback | TTL |
| `PC_HTML_INDEX_<urlhash>` | context hash 列表，仅用于观察和后续清理 | TTL + 1 小时 |
| `PC_DYNAMIC_<hash>` | `{blocks: {name: html}}`，仅显式标记 `page_cache_dynamic_cacheable` 的 block | `page_cache/dynamic/ttl` |
| 静态文件 | `var/page-cache/static/{store}/{path}` + `.gz`，匿名默认页 | `page_cache_static_cleanup` |
| `PC_PURGE_<timestamp>_<rand>` | `{urls: []}` | 24 小时 |
| `PC_PURGE_INDEX` | 待处理 purge entry id 列表 | 25 小时 |
| `PC_WARMUP_LAST_RUN` | 最近一次预热汇总 | 7 天 |
| `page_cache_warmup_queue` | 预热队列，`url_hash` 唯一 | 由 cron 和上限控制 |
| `page_cache_url` | URL 级索引：url hash、变体 URL、版本 | 由 URL 索引清理 |
| `page_cache_url_context` | 每个 context 的 store、public path、URL | 由 URL 索引清理 |
| `page_cache_url_context_tag` | context 到实体 tag 的映射 | 由 URL 索引清理 |
| `page_cache_static_file` | 静态文件相对路径、到期时间 | 由静态清理 cron 删除 |
| `page_cache_stock_pending` | 待二次失效的 product ID | 由 stock index end 事件清空 |

注意：

- 实体使用原生 cache tag `PAGE_CACHE_TAG_<tag>`，HTML、meta 和 ETag 都打 tag；`clean($tags)` 由缓存适配器原子执行。
- Local HIT 只读 global version、URL version、v2 envelope；meta 继续供 revalidating、dynamic 和旧 entry fallback 使用。
- URL 索引在 DB，不会因为缓存淘汰而丢失；DB 查询失败时全量失效。
- URL 行只保存资源级信息；每个 context 在 `page_cache_url_context` 单独保存 store、public path、URL 和 tag，避免同一 URL 的多个变体互相覆盖。
- Warmup、子 sitemap 和队列入队只接受 store base URL 的 scheme+host+port；Basic Auth 不会发往非 store origin。
- `page_cache_url` 按 `ttl + 3600` 清理，和 meta TTL 对齐。
- purge entry 24 小时，index 25 小时，避免 index 先过期留下无法处理的 entry。
- PHP 侧计数从 `page_cache_stat` 读取，统计关闭时不计数；缓存类型清理不会重置计数。
- tag 值只允许 `[A-Za-z0-9_:.-]`，写入前过滤，避免 header 注入。
- URL hash 和 context hash 使用 sha256，不保存原始 URL 或 cookie。
- local HTML key 包含 URL、context、URL 版本、全局版本和模式；版本变化后旧 HTML 不再命中。
- `PC_META_<urlhash>` 是 local 命中的前置条件。元数据被淘汰时不命中，避免旧 HTML 复活。

## 5. Context 和缓存键

`Model\Context` 计算：

- website ID、store ID
- 当前货币
- 客户组（无 session 时为 guest）
- 税国家/税率（无 session 时使用默认）
- 允许的 query 参数（排序后）
- scheme + host + path
- 模式
- `page_cache_context_variant` registry（移动主题、A-B、geo-IP 等变体）

`contextHash = sha256(json_encode(context))`。

`Vary`：

```text
Vary: Accept-Encoding, Cookie
```

- 代理必须按 URL 和 `Cookie` 缓存，并遵守 `Vary: Accept-Encoding, Cookie`。部署侧的 Cookie 规范化和 session bypass 示例见 `docs/deployment/README.md`。
- `page_cache/proxy/context_mode` 默认 `cookie`；代理只按 URL 缓存时设为 `default`，此时只缓存默认 store 或 URL 带 store code 的请求，带 `___store` cookie 的切换请求 bypass。
- 移动主题、A-B、geo-IP 等模块必须把当前变体写入 `page_cache_context_variant` registry；proxy/revalidating 遇到非空 variant 直接 bypass，local 用 variant 区分 HTML key。
- 第一期不缓存登录用户、购物车、checkout、customer account，因此客户组固定为 guest。
- 无 session 时可缓存请求的货币取 store 默认货币；切换货币会写入 session，从而带 session cookie 并 bypass。
- 货币和 store 如果通过 cookie/URL 切换，会进入 context；无法确定时 bypass。

## 6. ETag 和 Last-Modified

ETag 由版本 token 生成：

```text
version = sha256(
    globalVersion
    . '|' . urlVersion
    . '|' . urlHash
    . '|' . contextHash
    . '|' . ttlBucket
    . '|' . mode
)
ETag = W/"<version>"
```

- 使用弱 ETag，因为不做 byte-range。
- `ttlBucket = floor(time() / ttl)`，避免永久 ETag。
- 304 只依赖版本，不依赖 body。
- 实体失效时递增 URL 版本，ETag 立即变化。
- 全量失效时递增全局版本，所有 ETag 变化。
- URL 版本缺失时按 1 处理，不返回错误的 304。
- `Last-Modified` 作为 ETag 不可用时的 fallback，使用缓存元数据的 `created_at`。

## 7. Purge 契约

第一期不绑定具体反向代理。`Service\Purge` 提供：

- `page-cache:purge --dry-run`：列出队列中的 URL。
- `page-cache:purge --limit=N`：处理最多 N 条。
- `page-cache:purge --tag=tag`：只处理指定 tag 相关的 URL。
- 事件 `maho_page_cache_purge_urls`，payload `{urls, tags, mode}`。
- 同时写 `var/page-cache/purge-<timestamp>.txt`，每行一个 URL，供外部消费者读取。
- 成功后删除队列条目；失败保留并记录日志。

站点自己的模块可以监听事件，调用 Varnish、Cloudflare、Fastly 或自建脚本。这样核心模块不依赖任何代理 SDK。

## 8. 配置与 CLI

后台配置段：`System > Configuration > Page Cache`。

| 字段 | 说明 | 默认 |
|---|---|---|
| `page_cache/general/enabled` | 总开关 | 0 |
| `page_cache/general/mode` | `proxy`、`revalidating` 或 `local` | `revalidating` |
| `page_cache/general/ttl` | TTL 秒 | 3600 |
| `page_cache/general/html_max_size` | local HTML 条目上限，字节 | 2097152 |
| `page_cache/local/compression_enabled` | local HTML 压缩开关 | 1 |
| `page_cache/local/compression_min_bytes` | 压缩最小字节数 | 4096 |
| `page_cache/local/compression_level` | gzip 级别 1 到 9 | 6 |
| `page_cache/dynamic/injection` | 动态块注入方式 `ajax` 或 `esi` | `ajax` |
| `page_cache/dynamic/ttl` | 显式标记可缓存的动态块响应秒数，0 不缓存 | 0 |
| `page_cache/dynamic/server_side` | local 命中服务器端重建开关 | 0 |
| `page_cache/dynamic/server_types` | 允许重建的 block type，逗号分隔 | 空 |
| `page_cache/warmup/enabled` | 调度预热开关 | 0 |
| `page_cache/warmup/concurrency` | 预热默认并发 | 4 |
| `page_cache/warmup/batch_size` | 单次 cron 最大 URL 数 | 50 |
| `page_cache/warmup/delay_ms` | 批次间隔毫秒 | 500 |
| `page_cache/warmup/max_attempts` | 最大失败次数 | 3 |
| `page_cache/warmup/retry_delay` | 重试基础间隔秒 | 300 |
| `page_cache/warmup/queue_limit` | 预热队列上限 | 10000 |
| `page_cache/warmup/auth_user` | basic auth 用户 | 空 |
| `page_cache/warmup/auth_password` | basic auth 密码，加密 | 空 |
| `page_cache/warmup/allowed_hosts` | store 别名 origin，每行一个 | 空 |
| `page_cache/general/filter_path_marker` | 分层导航路径标记，空表示关闭深度检查 | 空 |
| `page_cache/general/max_filter_depth` | 分层导航最大深度 | 2 |
| `page_cache/general/max_path_segments` | 最大路径段数 | 8 |
| `page_cache/static/enabled` | 静态文件导出开关 | 0 |
| `page_cache/stats/enabled` | 统计开关 | 0 |
| `page_cache/stats/mode` | 统计模式：`off`、`full`、`sampled` | `full` |
| `page_cache/stats/sample_rate` | 采样比例，0 到 1 | 0.05 |
| `page_cache/stats/raw_retention_days` | 原始事件保留天数 | 30 |
| `page_cache/stats/rollup_retention_days` | 小时聚合保留天数 | 365 |
| `page_cache/stats/max_duration_ms` | 单事件耗时上限 | 60000 |
| `page_cache/general/cacheable_routes` | 每行一个 full action name | CMS、分类、商品 |
| `page_cache/general/allowed_params` | 允许的 query 参数 | `p,limit,order,dir,mode` |
| `page_cache/general/respect_no_cache` | 遵守请求的 `no-cache` | 1 |
| `page_cache/general/debug_headers` | 输出详细调试头 | 0 |
| `page_cache/general/session_skip_routes` | 可跳过 session 的路由 | 同 cacheable_routes |

CLI：

```bash
./maho page-cache:status
./maho page-cache:purge --dry-run
./maho page-cache:purge --limit=100
./maho page-cache:purge --tag=catalog_product_123
```

## 9. 安全

- 默认只缓存匿名 GET；任何 session cookie、登录、购物车、checkout、admin、preview、维护模式都 bypass。FR-20 是默认关闭的后续产品方向，见 ADR-0019。
- 不缓存包含 `Set-Cookie` 的响应。
- 不缓存非 200 响应。
- local HTML 只保存匿名默认 context；session cookie 请求 bypass。
- local HTML 有条目大小上限，避免缓存超大页面。
- local HTML 只保存 `Content-Type`、`Content-Language` 和 `Content-Disposition` 响应头，不保存 `Set-Cookie`。
- 动态块只渲染当前页面 layout 里显式标记的 block，token 必须通过 HMAC 和 context 校验。
- 动态响应 `private, no-store`，不进入 local HTML 或代理缓存。
- 预热不发送 session cookie，只请求公开 URL。
- 路径段数和分层导航深度有上限，防止爬虫用 URL 变体撑爆缓存。
- 动态块占位符是 HTML 注释，不破坏 `<ul>`/`<tbody>` 等父级结构。
- 静态文件只导出匿名默认页；Web 服务器必须对 `maho_session` 和 query 参数回 PHP。
- 静态缓存目录必须 deny 直接访问；路径和 store code 有白名单，拒绝目录穿越。
- 响应头中的 tag、context、bypass 原因必须过滤，不能直接回显用户输入。
- purge 文件不能放在 public 目录。
- CLI 命令只读缓存和写 purge 文件，不执行任意 shell 命令。
- 日志不记录完整 URL 的 query 值，只记录 hash 和原因码。
- 缓存 key 不包含 PII。

## 10. 第二期和第三期扩展点

### 第二期

- `Backend\Local` 和 `Model\HtmlCache` 0.2.0 已交付，使用 Symfony Cache 保存 HTML。
- `local` 模式在 `controller_action_predispatch` 检查 key，命中时直接输出 HTML。
- 动态块：
  - Ajax：页面里输出占位符，前端请求同一页面 URL 的 dynamic 响应。
  - ESI：页面里输出 `<esi:include>`，由代理拼接。
  - 白名单：只对明确标记的 block 打孔，默认不打孔。
  - form key 和 SID 使用占位符替换。
- 预热：`page-cache:warm` 读取 sitemap 或 URL 列表，并发请求并填充缓存。

### 第三期

- 服务器端占位符：缓存页面保存 block 定义，命中时只渲染动态块。0.5.0 已交付。
- 代理模型：`page_cache/proxy` 在请求内复用 product/category/CMS 模型，批量加载并恢复页面上下文；不缓存模型数据。0.6.0 开发中。
- 面板：按路由、模式、结果统计 hit/miss/bypass、队列长度和失效次数。0.3.0 已交付。
- 多节点：全局版本存 Redis，purge 事件广播到所有节点。计划中。

## 11. 兼容性和依赖

- PHP 8.5，Maho 26.11+。
- Symfony Cache file/redis。
- 不依赖 Zend_Cache、Zend_Locale、Varien 新代码。
- 不新增 Composer 包。
- 统计和预热队列使用模块自有表，不修改 Maho 核心表结构。
- 不复制 RockSolid、Cm_Diehard 或其他 GPL 代码。

## 12. 测试策略

单元测试：

- Context 计算和 bypass 原因。
- ETag 生成、304 匹配、TTL 时间桶。
- UrlIndex 添加、变体合并、tag 查询、removeTag、清理。
- PurgeQueue 入队、去重、处理、失败保留。
- Header 过滤和调试开关。
- local HTML 存取、大小上限、元数据缺失和版本淘汰。
- local 命中、304、锁超时、版本变化和锁释放。

集成测试：

- session skip 事件在匿名 GET 上设置 flag。
- `If-None-Match` 返回 304 且不生成 layout。
- local 命中设置 `FLAG_NO_DISPATCH` 并直接输出 HTML。
- 动态块 token 校验、占位符替换、Ajax/ESI 响应。
- local 服务器端占位符的保存、命中替换、token 校验、fallback、session 保护和 static 剥离。
- 预热 sitemap 解析、去重、limit、skip 和失败汇总。
- 预热队列入队、去重、上限、claim、重试、锁和 basic auth。
- 静态路径映射、原子写、gzip、TTL 清理、失效删除和目录 deny。
- 统计记录、admin 事件过滤、rollup、cleanup 和面板查询。
- stock 状态变化、qty-only 更新、批量属性更新和阈值回退。
- 路径段数、分层导航深度和注释占位符。
- product/category/CMS 保存触发 tag 清理。
- catalog rule 保存触发产品和分类 tag；其他规则/配置触发全量。
- CLI purge 的 dry-run、limit、tag 和事件。
- 缓存类型在后台 Cache Management 可见。

手工验证：

- `curl -I` 检查 CMS、分类、商品页的头。
- 第二次带 `If-None-Match` 检查 304。
- 保存商品后检查 ETag 变化和 purge 文件。
- 登录、购物车、checkout、admin 检查 bypass。
- 关闭模块后检查响应头不变。
