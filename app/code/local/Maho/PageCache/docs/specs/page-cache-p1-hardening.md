# 功能规格：P1 缓存加固

> 状态：实现完成，待发版 0.5.3
> 关联 ADR：ADR-0008、ADR-0017
> 分支：`feat/page-cache-phase2`

## 1. 背景

审核确认 P0 cache integrity 已由 0.5.1 和 0.5.2 解决。P1 剩余项中，先处理共享缓存安全边界和失效顺序：

- `context_safe` 恒为 true，`context` bypass 是死代码。
- dynamic 请求可以绕过 cacheable route 检查。
- catalog rule、stock index 和商品导入存在索引前后回填窗口。

## 2. 范围

- `page_cache/proxy/context_mode`：`cookie`（默认）和 `default`。
- dynamic 请求限制为 GET + `cacheable_routes`。
- `catalogrule_after_apply` 后再次失效受影响商品。
- `catalog_product_import_finish_before` 后失效 affected product IDs。
- stock item 保存后在 stock index 事件再次失效。
- 不改变 `Vary: Cookie`，不改变 session cookie bypass，不新增 rate limit。

## 3. 设计

### 3.1 Proxy context

`Request::isContextSafe()`：

- local 模式和 `cookie` 模式返回 true。
- `default` 模式要求默认 store，或请求 URL 带 store code。
- 带 `___store` cookie 的请求返回 false，`evaluate()` 返回 `context`。
- 其他 context 维度仍由 URL 和 session cookie 覆盖。

### 3.2 Dynamic 请求

`skipSession()` 先计算输入，再判断 dynamic：

- `evaluateDynamic()` 检查 enabled、mode、GET、admin、cacheable route、路径深度、过滤深度、参数、preview、maintenance。
- 不检查 session cookie、`no-cache` 和 AJAX。
- 失败时记录 bypass 原因，不注册 dynamic request。

### 3.3 失效顺序

- catalog rule：保存时按 `getMatchingProductIds()` 失效；`catalogrule_after_apply` 后按 `product` 或 `affected_product` 再失效。
- import：`catalog_product_import_finish_before` 读取 `getAffectedEntityIds()`。
- stock：保存后记录 product ID；stock index 的 end 事件再次失效。
- 超过 30 个商品回退全量失效。

## 4. 验收标准

- `context_mode=cookie`：带 `___store` cookie 的请求仍可缓存。
- `context_mode=default`：带 `___store` cookie 的请求返回 `context`；默认 store 或无 cookie 的请求可缓存。
- dynamic 请求：cacheable route 通过；checkout 等非 cacheable route 返回 `route`，不注册 dynamic request。
- catalog rule apply 后：受影响商品 URL 版本递增，tag 删除。
- import finish 后：affected product URL 版本递增，tag 删除。
- stock index end 后：保存前回填的页面再次失效。
- 现有 PageCache 测试、PHPStan、php-cs-fixer、Rector 通过。

## 5. 实现记录

- 代码：`Service/Request.php`、`Model/Observer.php`、`Service/Invalidator.php`、`Helper/Data.php`、`etc/config.xml`、`etc/system.xml`、`Model/System/Config/Source/Proxy/Context.php`。
- 测试：`RequestPolicyTest`、`DynamicBlockTest`、`ObserverTest`、`ProxyContextTest`。
- 测试结果：184 passed（508 assertions）。
- PHPStan 全仓通过；php-cs-fixer 与 Rector 对模块和测试通过。
- 交叉验证：模块 CI run 45353222522 在 SQLite、MySQL、PostgreSQL 三个任务上全部通过（2026-11-24）。
- 剩余：manual/异步 stock index 仍需要持久化 pending 队列；本轮只覆盖实时索引事件。
