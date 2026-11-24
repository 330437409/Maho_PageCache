# 功能规格：Maho 页面缓存第一期

> 状态：已交付@v0.2.0
> 关联 PRD：FR-01 到 FR-10
> 关联 ADR：ADR-0001、ADR-0002、ADR-0003、ADR-0004、ADR-0005、ADR-0006、ADR-0007、ADR-0008
> 分支：`feat/page-cache`

## 1. 背景与目标

Maho 没有内置 HTTP 页面缓存。第一期给匿名 GET 提供一个安全的缓存集成层：

- `proxy` 模式：反向代理/CDN 缓存 HTML，Maho 设置头和维护 purge 队列。
- `revalidating` 模式：反向代理/CDN 缓存 HTML，但每次请求回源做 ETag/Last-Modified 校验，304 表示未变。
- 匿名 GET 跳过 session。
- 实体、规则和配置变化时按 tag 或全量失效。
- 提供最小后台配置、CLI 和调试头。

第一期不缓存 HTML，不实现动态块打孔和代理模型。第二期和第三期按 PRD 规划。

## 2. 需求

### 2.1 范围内

- 模块声明、配置段和缓存类型。
- 可缓存请求判定和 bypass 原因码。
- 匿名 GET session skip。
- context hash、`Vary`、`Cache-Control`、`X-Maho-Cache-*`。
- `revalidating` 的 ETag/Last-Modified、304 和渲染前短路。
- `proxy` 的 `X-Cache-Tags`、tag 到 URL 索引、purge 队列。
- 实体 tag 失效、catalog rule 细粒度失效、其他规则/配置全量失效。
- `page-cache:status`、`page-cache:purge`。
- PHP 侧计数。
- 单元测试、集成测试、手工 curl 验证、文档同步。

### 2.2 范围外

- `local` 模式、整页 HTML 缓存、动态块 Ajax/ESI、缓存预热。
- 服务器端占位符、product/category/CMS 代理模型。
- 具体反向代理的 purge 适配器。
- 后台网格、命中率面板、按路由统计。
- 新数据库表、新 Composer 依赖。
- 缓存 API、admin、checkout、customer account。

## 3. 设计

### 3.1 模块和文件

```text
app/code/local/Maho/PageCache/
├── Helper/Data.php
├── Model/
│   ├── Observer.php
│   ├── Context.php
│   ├── Key.php
│   ├── Version.php
│   ├── TagIndex.php
│   ├── PurgeQueue.php
│   ├── Counter.php
│   └── Backend/
│       ├── Abstract.php
│       ├── Proxy.php
│       └── Revalidating.php
├── Service/
│   ├── Request.php
│   ├── Response.php
│   ├── Invalidator.php
│   └── Purge.php
├── etc/config.xml
├── etc/system.xml
├── docs/
├── CHANGELOG.md
└── README.md

app/etc/modules/Maho_PageCache.xml
lib/MahoCLI/Commands/PageCacheStatus.php
lib/MahoCLI/Commands/PageCachePurge.php
tests/Backend/Unit/PageCache/*
tests/Backend/Integration/PageCache/*
```

### 3.2 缓存类型

在 `etc/config.xml` 声明：

```xml
<global>
    <cache>
        <types>
            <page_cache>
                <label>Page Cache</label>
                <description>HTTP page cache metadata, tag index and purge queue.</description>
                <tags>PAGE_CACHE</tags>
            </page_cache>
        </types>
    </cache>
</global>
```

使用 `Mage::app()->getCache()`，不实例化新的缓存后端，不读取 `diehard_cache`。

### 3.3 请求判定

`Service\Request` 的判定顺序：

1. `page_cache/general/enabled` 为真。
2. 模式是 `proxy` 或 `revalidating`。
3. 方法为 GET。
4. 不是 admin、install、API、checkout、customer account、preview、维护模式。
5. full action name 在 `cacheable_routes`。
6. 没有 Maho session cookie。
7. query 参数都在 `allowed_params`，或为空。
8. 没有 `Cache-Control: no-cache`，或 `respect_no_cache` 为假。
9. 不是 AJAX。
10. context 可以安全确定。

返回 `true` 或一个稳定的 bypass 原因码。

### 3.4 Session skip

监听 `controller_action_predispatch_session_start`：

- 可缓存请求：设置 `FLAG_NO_START_SESSION`。
- 不可缓存请求：不设置，记录原因。
- 不读取 `customer/session`、`checkout/session`、`core/session`。

### 3.5 Context

`Model\Context` 计算：

```php
[
    'website_id' => int,
    'store_id' => int,
    'currency' => string,
    'customer_group' => int, // 无 session 时为 guest
    'tax_country' => string,
    'tax_rate' => float,
    'params' => array, // 允许的 query 参数，已排序
    'scheme' => string,
    'host' => string,
    'path' => string,
    'mode' => string,
]
```

`contextHash = hash('sha256', json_encode($context))`。

无 session 时可缓存请求的货币取 store 默认货币；切换货币会写入 session，从而带 session cookie 并 bypass。

### 3.6 HTTP 头

`proxy`：

```text
Cache-Control: public, s-maxage=<ttl>, max-age=0
Vary: Accept-Encoding, Cookie
X-Maho-Cache: MISS
X-Maho-Cache-Mode: proxy
X-Maho-Cache-Context: <hash>
X-Cache-Tags: <tags>
```

`revalidating` 200：

```text
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"<version>"
Last-Modified: <rfc1123>
Vary: Accept-Encoding, Cookie
X-Maho-Cache: MISS
X-Maho-Cache-Mode: revalidating
X-Maho-Cache-Context: <hash>
```

`revalidating` 304：

```text
HTTP/1.1 304 Not Modified
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"<version>"
Vary: Accept-Encoding, Cookie
X-Maho-Cache: REVALIDATED
```

调试关闭时不输出 `X-Maho-Cache-Tags`、`X-Maho-Cache-Version` 和详细 bypass 原因，只输出结果和原因码。

### 3.7 ETag

```text
version = hash('sha256',
    globalVersion
    . '|' . urlVersion
    . '|' . urlHash
    . '|' . contextHash
    . '|' . floor(time() / ttl)
    . '|' . mode
)
ETag = 'W/"' . version . '"'
```

- `globalVersion` 来自 `PC_VERSION_GLOBAL`，缺失时为 1。
- `urlVersion` 来自 `PC_VERSION_URL_<urlhash>`，缺失时为 1。
- 304 判断只读缓存，不生成 layout。
- `If-None-Match` 支持逗号分隔的多个 ETag。
- `Last-Modified` 作为 fallback。

### 3.8 304 短路

监听 `controller_action_predispatch`：

1. 只在 `revalidating` 模式执行。
2. 读取 `If-None-Match`。`Last-Modified` 仅作为响应头；`If-Modified-Since` 不单独触发 304。
3. 匹配时：
   - `$response->setHttpResponseCode(304)`
   - `$response->clearBody()`
   - 设置缓存头
   - `$controller->setFlag('', FLAG_NO_DISPATCH, true)`
4. 不生成 layout，不渲染 block。

### 3.9 响应和 tag 收集

监听 `controller_front_send_response_before`：

- 只处理本次可缓存请求。
- 只处理 200 且 body 非空、没有 `Set-Cookie` 的响应。
- 收集 tag：
  - `core_block_abstract_to_html_after` 中 `getCacheTags()`。
  - 本次请求的实体 tag，来自 `getCacheIdTags()`。
  - `PAGE_CACHE`。
- 写入 `PC_META_<urlhash>`、`PC_VERSION_URL_<urlhash>`、`PC_ETAG_<urlhash>`。
- 更新 `PC_TAG_<tag>` 索引。
- 设置响应头。
- 递增计数。

### 3.10 失效

监听：

- `model_save_commit_after`
- `model_delete_commit_after`
- `core_clean_cache`
- `adminhtml_cache_flush_all`

逻辑：

| 对象 | 失效 |
|---|---|
| product | `getCacheIdTagsWithCategories()` |
| category | `getCacheIdTags()` |
| CMS page/block | `getCacheIdTags()` |
| catalog rule | `getMatchingProductIds()` → 产品 tag + 分类 tag |
| sales rule | 全量 |
| `Mage_Core_Model_Config_Data` | 全量 |
| 其他模型 | `getCacheIdTags()` 如果有 |
| 索引丢失/异常 | 全量 |

全量失效：

1. 递增 `PC_VERSION_GLOBAL`。
2. 清空 `page_cache` 缓存类型。
3. 清空 purge 队列。

tag 失效：

1. 读取 `PC_TAG_<tag>`。
2. 对每个 URL hash，递增 `PC_VERSION_URL_<hash>`。
3. 将 URL 写入 purge 队列。
4. 删除 `PC_TAG_<tag>`。

### 3.11 Purge

`Model\PurgeQueue`：

- 入队：`PC_PURGE_<timestamp>_<rand>`，值为 `{urls, tags, mode}`。
- 去重：同一 URL 在队列中只保留一份。
- 处理：按时间顺序读取，触发 `maho_page_cache_purge_urls`。
- 同时写 `var/page-cache/purge-<timestamp>.txt`。
- 成功删除，失败保留并记录 `var/log/page-cache.log`。

### 3.12 CLI

`PageCacheStatus`：

- 输出模式、开关、TTL、全局版本、队列长度、当天 PHP 计数。

`PageCachePurge`：

- `--dry-run`、`--limit`、`--tag`。
- 只处理 `proxy` 模式队列；`revalidating` 模式版本失效不需要 purge。
- 不执行 shell 命令。

### 3.13 计数

`Model\Counter`：

- key：`PC_STAT_<Ymd>_<result>`。
- 结果：`miss`、`bypass`、`revalidated`、`not_modified`。
- 使用缓存读改写，允许近似值；不阻塞请求。
- 代理命中不统计，文档说明。

### 3.14 配置

`etc/system.xml` 增加 `page_cache` section，字段见 API 文档。

### 3.15 安全

- 默认关闭。
- 默认只缓存 CMS、分类、商品。
- 有 session cookie、登录、购物车、checkout、admin、preview、维护模式全部 bypass。
- header 值过滤 `[A-Za-z0-9_:.,-]`。
- purge 文件放 `var/page-cache`，权限 0750。
- 不记录完整 query 值。

## 4. 任务拆分

- [x] 创建模块声明 `app/etc/modules/Maho_PageCache.xml`
- [x] 创建 `etc/config.xml`、`etc/system.xml` 和 `page_cache` 缓存类型
- [x] 添加 `sql/pagecache_setup/install-0.1.0.php`，默认启用 `page_cache` 缓存类型
- [x] 实现 `Helper/Data.php` 配置读取和原因码
- [x] 实现 `Model/Context.php`、`Model/Key.php`、`Model/Version.php`
- [x] 实现 `Service/Request.php` 判定和 bypass
- [x] 实现 `controller_action_predispatch_session_start` session skip
- [x] 实现 `Service/Response.php` 头、ETag、tag 收集
- [x] 实现 `Model/Backend/Revalidating.php` 和 304 短路
- [x] 实现 `Model/Backend/Proxy.php`、`Model/TagIndex.php`、`Model/PurgeQueue.php`
- [x] 实现 `Service/Invalidator.php` 实体/规则/配置失效
- [x] 实现 `Service/Purge.php` 和 `maho_page_cache_purge_urls` 事件
- [x] 实现 `Model/Counter.php`
- [x] 实现 `PageCacheStatus`、`PageCachePurge` CLI
- [x] 写单元测试：Context、Key、Version、TagIndex、PurgeQueue、Header
- [x] 写单元和集成测试：session skip、304、tag 失效、规则/配置失效、CLI
- [x] 手工 curl 验证 CMS 页；本地库没有分类/商品数据，分类/商品路由由策略单元测试覆盖
- [x] 更新 README、CHANGELOG、API、ARCHITECTURE、PRD 状态
- [x] 运行 php-cs-fixer、PHPStan、Rector 和相关测试
- [ ] 用户确认后提交

## 5. 验收标准

### AC-01 模块和配置

- 模块在 `app/etc/modules` 声明，`codePool=local`，依赖 `Mage_Core`。
- 后台出现 `System > Configuration > Page Cache`。
- `page_cache` 出现在 Cache Management。
- 关闭模块时响应头不变，不跳过 session。

### AC-02 Session skip

- 无 session cookie 的可缓存 GET 不返回 `Set-Cookie` 会话 cookie。
- 有 session cookie 的请求 bypass，原因 `session_cookie`。
- POST、admin、checkout、customer account、preview、维护模式 bypass。
- 跳过 session 不读取 customer/checkout/core session。

### AC-03 头

- `proxy` 和 `revalidating` 返回对应 `Cache-Control`。
- 两种模式返回 `Vary: Accept-Encoding, Cookie`。
- `X-Maho-Cache` 值正确。
- 调试关闭时不输出 tag 和详细原因。

### AC-04 Revalidating 304

- 第一次 200 带 ETag。
- 第二次带 `If-None-Match` 返回 304，body 为空。
- 保存实体后相同 ETag 返回 200 和新 ETag。
- 无元数据或版本丢失时返回 200，不返回错误 304。

### AC-05 Proxy 和 purge

- 第一次响应带 `X-Cache-Tags`。
- 实体保存写入 purge 队列。
- `page-cache:purge --dry-run` 列出 URL。
- `page-cache:purge` 触发事件或写文件。
- 成功后队列清空，失败保留。

### AC-06 实体失效

- product/category/CMS page/block 保存或删除清理对应 tag。
- product 保存清理所属分类 tag。
- 重复保存不产生重复队列条目。

### AC-07 规则/配置失效

- catalog rule 保存/删除按 `getMatchingProductIds()` 细粒度失效。
- sales rule、config data、主题/layout、后台清缓存全量失效。
- 无法计算时回退全量。

### AC-08 Bypass

- 所有原因码都有测试。
- bypass 响应不写索引、不写队列。
- 原因码不含 PII。

### AC-09 CLI 和计数

- `page-cache:status` 输出模式、TTL、版本、队列和计数。
- `page-cache:purge` 支持 `--dry-run`、`--limit`、`--tag`。
- 计数不阻塞请求，代理命中不统计的说明存在。

### AC-10 验证门

- 新增测试全部通过。
- 相关 PHPStan、php-cs-fixer、Rector 通过。
- `curl` 验证至少一个已有数据页的 200、304、bypass、purge；分类/商品页有数据时复验。
- 保存实体验证失效。
- 不新增表、不新增 Composer 依赖。

## 6. 验证记录

- 单元测试：`vendor/bin/pest tests/Backend/Unit/PageCache`，57 passed。
- PHPStan：`php vendor/bin/phpstan analyze app/code/local/Maho/PageCache lib/MahoCLI/Commands/PageCacheStatus.php lib/MahoCLI/Commands/PageCachePurge.php --memory-limit=2G`，No errors。
- php-cs-fixer：修改文件 dry-run，0 个需要修复。
- Rector：修改文件 dry-run，已应用 typed constants 和 first-class callable 变更。
- 手工 `curl http://localhost:8000/`：`X-Maho-Cache: MISS`、`ETag`、`Vary: Accept-Encoding, Cookie`，无 session cookie。
- 手工 `curl -H 'If-None-Match: <etag>'`：304，body 0 字节。
- 手工 session cookie、未知 query、no-cache：`X-Maho-Cache: BYPASS` 和对应原因码。
- 手工保存 CMS page 2：旧 ETag 返回 200 和新 ETag。
- 手工 `proxy` 模式：`Cache-Control: public, s-maxage=3600` 和 `X-Cache-Tags`；保存 CMS page 后 `page-cache:purge --dry-run` 列出 URL，`page-cache:purge` 写 purge 文件并清空队列。
- 不同 store 的 context 测试：`X-Maho-Cache-Context` 不同；代理 key 使用 `Vary: Accept-Encoding, Cookie`，context 头只用于调试。
- 全量失效测试：连续两次全量失效，全局版本严格递增；proxy 模式写入 `*` 全量 purge 标记。
- 版本键淘汰测试：元数据保留 `version`，删除 `PC_VERSION_URL_*` 后旧 ETag 返回 200，不返回错误 304；手工 curl 复验通过。
- tag/debug/计数测试：proxy 的 `X-Cache-Tags`、debug 开关、tag 字符过滤、`X-Maho-Cache-Bypass` 原因码和 miss/bypass/revalidated 计数均有断言。
- 条件请求测试：只使用 `If-None-Match`；命中时设置 `FLAG_NO_DISPATCH`，304 在 layout 生成前短路。
- purge 测试：`Context::getPurgeUrl()` 只保留白名单 query 参数；`writePurgeFile` 失败时队列保留，成功后移除。
- 目标代理没有在本机运行；模块侧的 `X-Maho-Cache-Context` 和 `Vary` 已由测试覆盖。目标代理必须遵守 `Vary: Accept-Encoding, Cookie`，并处理 purge 文件中的 `*` 全量标记；否则不同 context 可能共享对象，或旧对象无法全量 purge。
- 本地库没有分类/商品数据，分类/商品页未做手工验证；`Service\Request` 和 `Service\Response` 的单元测试覆盖相同代码路径。

## 7. 风险与待定

### 需要用户确认的假设

1. **代理支持自定义 Vary**：如果目标代理不支持 `Vary: Cookie`，第一期只缓存默认 context，非默认 context bypass。
2. **计数近似**：PHP 侧计数使用缓存读改写，不保证严格原子；代理命中不统计。
3. **catalog rule 同步计算**：保存时调用 `getMatchingProductIds()`，大店铺可能变慢；第一期接受，第二期可异步化。
4. **session skip 路由**：默认只覆盖 CMS、分类、商品页；其他路由需要显式配置。
5. **首次 POST**：跳过 session 的页面 form key 为空，第一次 POST 可能失败；文档说明，不额外注入 form key。
6. **purge 输出**：第一期没有具体代理适配器，默认写文件或触发事件。

### 其他风险

- tag 索引被缓存淘汰时全量失效，命中率下降。
- 多 store、多货币、客户组、税组合会降低命中率。
- 代理不支持 Vary 时，非默认 context 无法缓存。
- Cm_Diehard/RockSolid 只作参考，不复制 GPL 代码。
