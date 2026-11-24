# ADR-0017：proxy context 模式和 P1 缓存加固

## 状态

已接受，0.5.3。

## 背景

ADR-0008 用 `Vary: Cookie` 作为 proxy/revalidating 的隔离契约，`X-Maho-Cache-Context` 只用于调试。`Request::getInput()` 仍把 `context_safe` 写死为 `true`，`context` bypass 是死代码。

同时有三个正确性窗口：

- dynamic 请求在 `skipSession` 中看到请求头就提前返回，不检查路由是否可缓存。
- catalog rule 在索引刷新前失效，页面可能在索引刷新前回填旧价格。
- 商品导入和 stock index 更新缺少索引后的再次失效。

## 决策

- 新增 `page_cache/proxy/context_mode`：
  - `cookie`（默认）：代理遵守 `Vary: Cookie`，所有匿名 context 可缓存。
  - `default`：只缓存默认 store 或 URL 带 store code 的请求；带 `___store` cookie 的切换请求 `context` bypass。
- dynamic 请求只允许 GET 和 `cacheable_routes` 中的路由；dynamic 响应不缓存，因此跳过 session cookie、`Cache-Control: no-cache` 和 AJAX 排除。
- `catalogrule_after_apply` 在索引刷新后按 `product` 或 `affected_product` 再次失效。
- `catalog_product_import_finish_before` 按 adapter 的 affected product IDs 统一失效。
- stock item 保存后把 product ID 写入 `page_cache_stock_pending`，在 `end_process_event_cataloginventory_stock_item_save` 或 `end_index_events_cataloginventory_stock_item_save` 时再次失效并清空。
- dynamic token 的过期时间跟随页面 TTL，URL 使用 store base URL 的 scheme/host。
- dynamic 请求按客户端限流，超限返回 429。
- 移动主题、A-B、geo-IP 通过 `page_cache_context_variant` 进入 local context；proxy 遇到非空 variant 时 bypass。
- 受影响商品超过 30 个时沿用全量失效。

## 理由

- `context_mode` 把 ADR-0008 的降级从文档变成可执行的运行时策略。
- dynamic 请求不产生缓存对象，不需要 proxy context 和 session 条件，但仍不能绕过可缓存路由边界。
- 失效发生在索引之后，避免页面在索引刷新前后回填旧数据。

## 后果

- 默认 `cookie` 不改变现有 proxy 行为。
- `default` 会降低多 store 命中率，只用于 URL-only 代理。
- stock index 事件由 Maho 索引器触发；pending 表现在跨请求保留，manual/异步索引也会在 end 事件清空。

## 备选方案

- **删除 `context_safe`，只保留文档**：无法给 URL-only 代理提供运行时降级，不采用。
- **dynamic 请求完全走普通 bypass**：会因 session cookie、AJAX 头导致正常动态块失效，不采用。
- **只在 catalog rule 保存时失效**：保留索引前回填窗口，不采用。
