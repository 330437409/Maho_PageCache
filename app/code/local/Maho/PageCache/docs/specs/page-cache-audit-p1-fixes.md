# 修复规格：页面缓存审计 P1

> 状态：实现完成，待发版 0.6.1
> 关联 FR：FR-05、FR-06、FR-17、FR-18（缺陷修复，不新增 FR）
> 目标版本：0.6.1
> 审计来源：Maho_PageCache 0.6.0 外部审计报告

## 1. 背景

外部审计确认了三个 P1：

1. `UrlIndex` 以 URL hash 为唯一记录，同一 URL 的多个 context 会互相覆盖 tag。
2. `page_cache_url` 只保存一份 store/public_path，静态文件失效可能只清掉最后一个 context 的文件。
3. Warmup 只校验 URL scheme，不校验 Host；Basic Auth 会发给任意 URL，存在 SSRF 和凭据泄露风险。

本修复把 URL 索引拆成 URL 级和 context 级，并给 Warm 增加 store origin allowlist。

## 2. URL 与 Context 模型

URL 是资源，Context 是缓存变体。

保留 `page_cache_url` 作为 URL 级记录：

- `url_id`、`url_hash`、`url`、`urls`、`variants_truncated`、`version`、`created_at`、`updated_at`
- `urls` 仍是同一 URL 下所有 context 的 purge URL 并集，用于按 URL 清理和预热。

新增 `page_cache_url_context`：

- `context_id` 主键
- `url_id`
- `context_hash`
- `store_id`
- `store_code`
- `public_path`
- `url`（该 context 的 purge URL）
- `created_at`、`updated_at`
- 唯一索引 `(url_id, context_hash)`
- 索引 `updated_at`

新增 `page_cache_url_context_tag`：

- `context_id`
- `tag`
- 唯一索引 `(context_id, tag)`
- 索引 `tag`

旧 `page_cache_url_tag` 保留兼容：新代码不再写入，但 `getByTag`、`removeTag`、`cleanup`、`clear` 仍处理旧行，升级后旧索引不会立即失效。

## 3. 写入

`UrlIndex::add()`：

1. 规范化 tag。
2. 更新或插入 URL 行，合并 `urls` 变体，保留 URL 版本。
3. 用 `insertOnDuplicate` 更新或插入 context 行，键为 `(url_id, context_hash)`。
4. 对该 context 的 tag 使用 `insertOnDuplicate`，不删除其他 context 的 tag，也不删除本 context 旧 tag。
   - 旧 tag 保留会带来 over-purge，但不会漏清；URL 索引有 TTL 清理。
5. 所有写入在一个事务内完成。
6. 旧 `page_cache_url_tag` 不再写入，避免继续产生 URL 级覆盖。

并发：

- `(url_id, context_hash)` 和 `(context_id, tag)` 唯一索引防止重复。
- `insertOnDuplicate` 避免并发插入唯一键失败。
- URL 的 `urls` 并集仍可能有一致性窗口，但下一次请求会重新合并；tag 不再丢。

## 4. 读取和失效

`UrlIndex::getByTag($tag)` 返回 context 级行：

- 新表：每个匹配 tag 的 context 一行，包含 `url_id`、`url_hash`、`url`、`urls`、`variants_truncated`、`version`、`context_id`、`context_hash`、`store_id`、`store_code`、`public_path`、`context_url`。
- 旧表：每个 URL 一行，`context_id=0`、`context_hash=''`，`store_id/store_code/public_path` 取 URL 行。
- 新表和旧表结果合并，按 `url_id`、`context_id` 排序。

`Invalidator::invalidateTags()`：

- 同一个 `url_id` 只递增一次 URL 版本，避免多 context 重复递增。
- 每个 context 的 `(public_path, store_code)` 去重后删除静态文件。
- 每个 context 的 `context_url` 按其 `store_id` 进入 warm queue。
- `urls` 并集去重后进入 purge queue。
- 任一 context 的 `variants_truncated=1` 时仍回退全量 purge。

`removeTag($tag)`：

1. 删除新 context tag 中该 tag。
2. 删除没有 tag 的 context。
3. 删除旧 URL tag 中该 tag。
4. 删除既没有 context 也没有旧 tag 的 URL。

`cleanup($maxAge)`：

- 按 URL `updated_at` 找过期 URL。
- 删除对应 context tag、context、旧 tag 和 URL。
- 返回删除的 URL 数。

`clear()`：清空新 tag、context、旧 tag、URL。

## 5. Warm URL 策略

新增 `page_cache/urlPolicy` 服务：

- 从所有 store 的 `web/unsecure/base_url` 和 `web/secure/base_url` 生成 `scheme://host:port` allowlist。
- 只接受 http/https、合法 host、合法端口。
- host 规范化：小写、去掉末尾点、拒绝空白/百分号/用户信息。
- 默认端口归一：http 80，https 443。
- `isAllowed(string $url): bool` 只在 origin 命中 allowlist 时返回 true。

应用位置：

- `WarmQueue::enqueue()`：入队前过滤，非 store origin 直接丢弃。
- `Service\Warm::collectUrls()`：显式 URL 和 sitemap URL 过滤。
- `Service\Warm::getSitemapUrls()`：子 sitemap 请求前校验；最终 URL 也用策略过滤。
- `Service\Warm::warm()`：发请求前再次校验；不合法 URL 记为 `skipped`，原因 `disallowed_host`，不发请求。
- Basic Auth 只在 URL 通过策略后发送。

说明：

- store base URL 可能是 localhost 或私网，这是配置内的可信目标，允许。
- 非 store origin 一律拒绝，因此不需要额外 DNS/IP 黑名单。
- `max_redirects=0` 保持，避免重定向绕过。

## 6. 测试

- UrlIndex：
  - 同一 path 不同 query context 的 tag 都保留。
  - 同一 path 不同 store/public_path context 返回两行，store/public_path 正确。
  - 旧 `page_cache_url_tag` 行仍可被 `getByTag` 读到并清理。
  - `removeTag` 只删除目标 tag，不删除其他 context 的 tag。
  - `cleanup` 删除 URL、context 和 tag。
- Invalidator：
  - 两个 context 不同 tag，失效其中一个 tag 后另一个仍可命中索引。
  - 同一 URL 的版本只递增一次。
  - 两个 context 的静态文件都删除。
- UrlPolicy：
  - store base URL 允许；不同 host、不同端口、错误 scheme 拒绝。
  - 默认端口归一；localhost/私网 store host 允许。
- Warm：
  - 队列拒绝 `169.254.169.254`、外部 host。
  - `warm()` 对不合法 URL 不创建请求。
  - 子 sitemap 非 store host 不请求。
  - Basic Auth 只发往合法 URL。
- 回归：现有 PageCache 套件全绿；PHPStan、php-cs-fixer、Rector 通过。

## 6.1 实现记录

- 代码：`Model/UrlIndex.php`、`Service/Invalidator.php`、`Service/UrlPolicy.php`、`Model/WarmQueue.php`、`Service/Warm.php`、`sql/schema.php`、`etc/config.xml`。
- 新表：`page_cache_url_context`、`page_cache_url_context_tag`；旧 `page_cache_url_tag` 保留兼容读取和清理。
- 测试：`UrlIndexTest` 增加 context 隔离、store/public_path、旧表兼容；`InvalidatorTest` 增加 context 变体、版本只递增一次、每 context 静态文件删除；`UrlPolicyTest`、`WarmTest`、`WarmQueueTest` 增加 host allowlist/SSRF 用例。PageCache 套件 237 passed（693 assertions）。
- 静态检查：PHPStan、php-cs-fixer、Rector 通过。
- 实机（CLI）：
  - 同一 `/category` 的 `?p=1` 和 `?p=2` 分别写不同 tag，两个 tag 都能查到，`contexts` 包含两个变体。
  - warm queue 拒绝 `169.254.169.254` 和 `evil.example`，只接受 store origin `localhost:8000`。
- 跨库：待模块仓库 CI 在 SQLite、MySQL、PostgreSQL 上验证。

## 7. 验收标准

- 审计复现脚本中，`?p=1` 的 tag 不再被 `?p=2` 覆盖。
- 同一 URL 多 store 静态文件都能被失效。
- warm queue 不再接受内网 IP 和外部 host。
- 子 sitemap 请求前完成 host 校验。
- 现有 URL purge、warmup、静态导出、local/proxy/revalidating 行为不变。
- 三库 CI 通过。

## 8. 风险

- 新表增加 schema 和清理面；旧表兼容期内数据量会增加。
- 同一 context 的旧 tag 不删除，会带来 over-purge；用 URL cleanup TTL 控制。
- store base URL 不是完整 allowlist 时，合法别名域名会被拒绝；需要在 store 配置中补齐 base URL，或后续加 trusted hosts 配置。
- 旧 URL 索引行没有 context 信息，升级后第一次失效可能只覆盖 URL 级；建议升级后 flush 页面缓存。
