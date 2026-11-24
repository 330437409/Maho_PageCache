# 功能规格：页面缓存 tag 索引重构

> 状态：实现完成，待验收
> 关联 FR：FR-05、FR-06、FR-07（行为不变）
> 关联 ADR：ADR-0004、ADR-0016
> 分支：`feat/page-cache-phase2`

## 1. 背景与目标

当前 tag 索引存放在页面缓存里：

- tag key 和 registry 有独立 TTL，和 meta 生命周期不一致。
- 每个 tag 的 URL 列表是 load-modify-save，分类页有 20 个商品就是 20 多次缓存往返。
- registry 是全量数组，并发保存会丢更新。
- #1、#4 的 P0 修复只是止血，索引结构本身仍是瓶颈。

本次重构把失效索引改成：

- 页面缓存 HTML、meta 和 ETag 使用原生 tag-aware cache，按实体 tag 原子失效。
- tag 到 URL 的映射放进 DB 表 `page_cache_url` 和 `page_cache_url_tag`，供 proxy purge、warmup 和静态文件删除使用。
- 删除 `Model/TagIndex.php`、tag key、registry 和 P0 的 cache 索引止血逻辑。

**外部行为不变**：失效范围、purge/warm URL、静态文件删除、304、bypass、store code 和 query 变体语义保持 0.5.1 的行为。

## 2. 范围

### 2.1 范围内

- 新增 `page_cache_url` 和 `page_cache_url_tag` 表。
- HTML、meta、ETag 按实体 tag 打原生 cache tag。
- `Invalidator` 用 `Mage_Core_Model_Cache::clean($tags)` 原子失效。
- DB URL 索引保存 page URL hash、变体 URL、公共路径、store、版本和 tag。
- proxy purge、warmup、静态删除、版本递增和 ETag 删除改用 DB 索引。
- URL 索引清理随 `page_cache_static_cleanup` cron 执行。
- 删除 `TagIndex` 及其测试，新增 `UrlIndex` 测试。
- 模块版本升到 `0.5.2`，新增表由 `./maho migrate` 创建。

### 2.2 范围外

- CDN 按 tag purge 的适配器。
- 多节点广播（FR-19）。
- 新增后台配置、CLI 或 API。
- 改变 session bypass、`Vary`、静态导出或动态块策略。
- #7、#8 的产品和代理配置决定。

## 3. 设计

### 3.1 原生 cache tag

新增 `Key::getCacheTag(string $tag): string`，返回 `PAGE_CACHE_TAG_` 加 sanitized tag，全大写。新增 `Key::getCacheTags(array $tags): list<string>`：

- 过滤空值。
- 过滤 `PAGE_CACHE`、`PAGE_CACHE_HTML`、`PAGE_CACHE_DYNAMIC`。
- 去重。

以下条目同时打上实体 tag：

- local HTML entry。
- local/proxy meta。
- `PC_ETAG_<urlhash>`。

`PAGE_CACHE` 和 `PAGE_CACHE_HTML` 保留，分别用于 cache type 和 HTML 类型清理。

### 3.2 DB 表

`page_cache_url`：

| 列 | 类型 | 说明 |
|---|---|---|
| `url_id` | int unsigned PK autoincrement | URL 行 |
| `url_hash` | char(64) unique | page URL hash，含 store code，不含 query |
| `url` | varchar(2048) | 最近一次变体 URL |
| `urls` | text | JSON 变体 URL 列表，最多 200 |
| `variants_truncated` | smallint unsigned default 0 | 超过上限时为 1 |
| `public_path` | varchar(2048) | 去 store code、去 query 的静态路径 |
| `store_id` | smallint unsigned default 0 | store ID |
| `store_code` | varchar(32) default '' | store code |
| `version` | int unsigned default 1 | 最近 URL 版本 |
| `created_at` | datetime | UTC |
| `updated_at` | datetime | UTC |

索引：unique `url_hash`，`updated_at`。

`page_cache_url_tag`：

| 列 | 类型 | 说明 |
|---|---|---|
| `url_id` | int unsigned | URL 行 |
| `tag` | varchar(128) | 实体 tag |

索引：unique `(url_id, tag)`，`tag`。

### 3.3 UrlIndex

新增 `Model/UrlIndex.php`：

- `add(Context $context, array $tags, int $version): void`
  - 过滤 cache type tag。
  - 按 `$context->getUrlHash()` upsert URL 行。
  - 合并 `$context->getPurgeUrl()` 到 `urls`，上限 200；超限标记 `variants_truncated`。
  - 更新 `public_path`、`store_id`、`store_code`、`version`。
  - 替换该 URL 的 tag 行（delete + insertMultiple）。
  - 单事务；失败只记录日志。
- `getByTag(string $tag): ?array`
  - 返回该 tag 的 URL 行；`urls` 解码。
  - DB 异常返回 `null`，由 Invalidator 触发全量失效。
- `removeTag(string $tag): void`
  - 删除该 tag 的映射；删除没有剩余 tag 的 URL 行。
- `clear(): void`
- `cleanup(int $maxAge): int`
  - 删除 `updated_at` 早于 cutoff 的 URL 行及其 tag 行。

### 3.4 保存流程

- local：`HtmlCache::save()` 保存 HTML/meta 后调用 `UrlIndex::add($context, $entry['tags'], $versions['url'])`。
- proxy/revalidating：`Observer` 保存 meta 后调用 `UrlIndex::add($context, $tags, $urlVersion)`。
- meta 不再保存 `urls`/`variants_truncated`；变体列表只在 DB。
- `Version::storeEtag()` 增加可选 `$tags`，给 ETag key 打实体 tag。

### 3.5 失效流程

`Invalidator::invalidateTags()`：

1. `$cacheTags = Key::getCacheTags($tags)`。
2. `Mage::app()->getCache()->clean($cacheTags)`；失败则全量失效。
3. 对每个 tag 查询 `UrlIndex::getByTag($tag)`：
   - 返回 `null`：全量失效。
   - 返回空：只做原生 clean。
   - 每行：递增 `version`，删除 ETag，删除静态文件，收集变体 URL；截断时全量 purge。
4. proxy 模式把变体 URL 写入 purge 队列；warmup 开启时写入预热队列。
5. `UrlIndex::removeTag($tag)`。

`Invalidator::invalidateAll()` 用 `UrlIndex::clear()` 替换 `TagIndex::flush()`。全量失效靠全局版本让缓存条目不可达，不调用 `cleanType()`，避免清掉统计计数器。

### 3.6 清理

`Observer::cleanupStaticCache()` 在静态清理后调用 `UrlIndex::cleanup($ttl + 3600)`，和 meta TTL 对齐。

### 3.7 删除项

- `Model/TagIndex.php`
- `Key::getTagKey()`
- `TagIndexTest.php`
- meta 的 `urls`/`variants_truncated` 和 `HtmlCache::buildMetaUrls()`

旧缓存里的 `PC_TAG_*`、`PC_TAG_REGISTRY` 不再读取，随 TTL 过期。

## 4. 任务拆分

- [x] `docs/adr/0016-native-tag-aware-cache.md`。
- [x] `sql/schema.php`、`etc/config.xml`：两张表、实体、版本 0.5.2。
- [x] `Model/UrlIndex.php`。
- [x] `Model/Key.php`：`getCacheTag()`、`getCacheTags()`。
- [x] `Model/HtmlCache.php`、`Model/Observer.php`、`Model/Version.php`：原生 tag 和 UrlIndex 接线。
- [x] `Service/Invalidator.php`：原生 clean + DB URL 索引。
- [x] 删除 `Model/TagIndex.php`、`Key::getTagKey()`。
- [x] `Model/Observer.php`：URL 索引清理。
- [x] 测试：UrlIndex、原生 tag、失效、变体、store code、TTL/缺失 meta、静态、304。
- [x] 文档：ARCHITECTURE、API（如需要）、README、CHANGELOG、ADR。
- [ ] SQLite、PostgreSQL、MySQL 交叉验证。

## 4.1 实现记录

- 代码：`Model/UrlIndex.php`、`Model/Key.php`、`Model/HtmlCache.php`、`Model/Observer.php`、`Model/Version.php`、`Service/Invalidator.php`、`sql/schema.php`、`etc/config.xml`。
- 表：`page_cache_url`、`page_cache_url_tag`，模块版本 `0.5.2`。
- 删除：`Model/TagIndex.php`、`Key::getTagKey()`、`TagIndexTest`。
- 测试：173 passed（490 assertions）。新增原生 tag、截断粘性和 DB 异常回退测试。
- 原生 tag：HTML、meta、ETag 均打 `PAGE_CACHE_TAG_<tag>`；基础 tag 不进入实体 tag。
- `variants_truncated` 一旦置位保持到 URL 行被清理，避免截断后漏 purge。
- 全量失效不清 `page_cache` 缓存类型，保留统计计数器；旧条目靠全局版本不可达。
- PHPStan 全仓通过；php-cs-fixer 与 Rector 对模块和测试通过。
- 实机验证：local 模式商品页和分类页 MISS 后可 HIT；保存商品后两页 MISS，首页保持 HIT；URL 索引版本递增，商品 tag 从索引移除。
- 交叉验证待补：SQLite 已通过；PostgreSQL、MySQL 待模块 CI。

## 5. 验收标准

### AC-REF.1 原生 tag

- local HTML、meta、ETag 带实体原生 tag。
- `clean($tags)` 原子失效，不需要 tag key/registry。
- 其他 cache type 的条目不被误清。

### AC-REF.2 URL 索引

- 页面保存后 DB 行包含 page URL hash、变体 URL、公共路径、store、版本和 tag。
- 同一页面多次保存合并变体，上限 200。
- 删除 tag 后没有剩余 tag 的 URL 行被删除。
- 清理删除过期 URL 行和 tag 行。

### AC-REF.3 失效行为不变

- 实体保存后相关页面失效，未相关页面不失效。
- proxy purge 包含所有变体 URL；截断时全量 purge。
- warmup 入队所有变体。
- 静态文件删除保持 0.5.1 行为。
- meta 缺失、tag 缺失、主动全量后不触发额外全量。
- DB 异常时全量失效。

### AC-REF.4 无外部变化

- 现有 PageCache 测试通过；内部 TagIndex 测试替换为 UrlIndex 测试。
- 304 只使用 ETag。
- bypass、store code、query 变体、静态导出、动态块、warmup 行为不变。
- PHPStan、php-cs-fixer、Rector 通过。
- SQLite、PostgreSQL、MySQL 表创建、UrlIndex 查询和失效测试通过。

## 6. 风险

- DB 索引是 proxy/static URL 的来源；DB 写入失败时 native tag 仍清 local/revalidating，但 proxy/static 可能漏清。失败要记录日志，并在查询失败时全量失效。
- `page_cache_url` 表会增长；cleanup 必须随 cron 执行，并有清理测试。
- 原生 tag 需要命名空间，避免清掉其他模块的 `CATALOG_PRODUCT_123` 缓存。
- URL 变体上限 200 后截断；截断触发全量 purge，保持安全。
- 旧缓存 tag key 不再读取，升级后短期命中率可能下降；可接受。

## 7. 验收测试

- 安全网：重构前 175 passed。
- 新增 `UrlIndexTest`：add、变体合并、上限、tag、removeTag、clear、cleanup。
- 失效：原生 clean、DB 行、版本、ETag、静态、purge、warm、截断、DB 异常。
- 回归：完整 PageCache 套件、三种数据库、linters。
- 实机：保存商品/分类后相关页面 miss、无关页面 hit；proxy purge/warm URL 正确；静态文件删除。
