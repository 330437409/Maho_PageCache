# 功能规格：P1 剩余正确性项

> 状态：已交付@v0.5.3
> 关联 ADR：ADR-0008、ADR-0017
> 分支：`feat/page-cache-phase2`

## 1. 范围

- dynamic token 过期时间跟随页面 TTL，URL 使用 store base URL 的 scheme/host。
- dynamic 请求按客户端限流，超限返回 429。
- 静态导出文档要求 `___store` cookie 回 PHP，更新 nginx/Caddy 示例。
- 移动主题、A-B、geo-IP 通过 `page_cache_context_variant` 进入 context 或 bypass。
- stock 二次失效使用 `page_cache_stock_pending`，覆盖异步索引。

## 2. 设计

### 2.1 Dynamic token 和 URL

- `getPlaceholder()` 接收页面 TTL，过期时间为 `max(3600, ttl + 300)`。
- token 和 ESI URL 使用 `Context::getCanonicalUrl()`：store base URL 的 scheme/host + 请求 path。
- token 校验使用同一个 canonical URL。

### 2.2 Dynamic 限流

- `page_cache/dynamic/rate_limit` 默认 120，`rate_limit_window` 默认 60 秒。
- 使用 core `rateLimiter('page_cache_dynamic', limit, window)`，按客户端计数。
- 超限设置 429、`Retry-After`、`FLAG_NO_DISPATCH`，记录 `rate_limit` bypass。
- 限流为 0 时关闭。

### 2.3 Context variant

- `Context` 增加 `variant`，来源 `Mage::registry('page_cache_context_variant')`。
- local 模式把 variant 计入 context hash。
- proxy/revalidating 遇到非空 variant 返回 `context` bypass，因为 `Vary: Cookie` 无法覆盖模块自定义维度。
- 移动主题、A-B、geo-IP 模块负责写入该 registry。

### 2.4 Stock pending

- 表 `page_cache_stock_pending(product_id PK, created_at)`。
- stock item 状态变化时 upsert product ID。
- `end_process_event_cataloginventory_stock_item_save`、`end_index_events_cataloginventory_stock_item_save`、`after_reindex_process_cataloginventory_stock` 和 `end_index_events_catalog_product_mass_action` 读取 pending，调用 `invalidateProductIds()`，成功后清空对应行。
- DB 写失败时回退进程内 registry，至少同一请求能 flush。

## 3. 验收标准

- 大 TTL 页面的 token 不会在页面过期前失效。
- token/ESI URL 使用 store base URL 的 scheme/host。
- 超过 dynamic 限流返回 429 和 `Retry-After`，不注册 dynamic request。
- static 文档和示例包含 `___store` 回 PHP。
- proxy 遇到 context variant bypass；local 的 variant 进入 context key。
- stock 保存写 pending 表；实时事件、manual 完整重索引和 mass-action end 后二次失效并清空表。
- 现有测试、PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL 建表和相关测试通过。

## 4. 实现记录

- 代码：`Model/Context.php`、`Service/Dynamic.php`、`Service/Request.php`、`Model/Observer.php`、`Helper/Data.php`、`etc/config.xml`、`etc/system.xml`、`sql/schema.php`。
- 测试：`DynamicBlockTest`、`ProxyContextTest`、`ObserverTest`、`ContextTest`。
- 测试结果：192 passed（533 assertions）。
- 交叉验证：模块 CI run 45359859827 在 SQLite、MySQL、PostgreSQL 三个任务上全部通过（2026-11-24）。
