# 功能规格：Maho 页面缓存定时预热

> 状态：已交付@v0.4.0
> 关联 PRD：FR-18
> 关联 ADR：ADR-0014
> 分支：`feat/page-cache-phase2`（继续）

## 1. 背景与目标

第二期的 `page-cache:warm` 只能手工执行，支持 sitemap、显式 URL、limit 和 concurrency。页面失效后不会自动补缓存，也没有断点续跑、限速、basic auth 和失败重试。

本功能在手工 CLI 之上增加一个默认关闭的调度预热：

- tag/URL 失效后，把受影响 URL 写入预热队列。
- cron 按批次、并发和间隔消费队列。
- 队列有上限，失败按次数重试后移出。
- 支持 basic auth、失败汇总和手工补跑。
- 预热请求走正常 HTTP 路径，不绕过 Web 服务器和 session 检查。

预热只处理之前被页面缓存记录过的 URL。全量失效不自动预热全站，避免缓存风暴。

## 2. 需求

### 2.1 范围内

- 新增预热队列表 `page_cache_warmup_queue`。
- tag/URL 失效成功后入队受影响 URL，按 URL 去重。
- 全量失效清空预热队列，不自动全站预热。
- cron 每分钟消费队列，受锁保护。
- 批次大小、并发、批次间隔、重试次数、重试间隔、队列上限可配置。
- 404、redirect、bypass、永久 4xx 跳过并移出队列。
- 429、5xx、网络错误重试，超过次数后移出并汇总。
- 支持 HTTP basic auth。
- `page-cache:status` 显示队列长度和最近一次预热汇总。
- `page-cache:warm --queue` 手工消费队列，`--dry-run` 只列出。
- 手工 `--sitemap` / `--url` 预热继续可用。

### 2.2 范围外

- 多节点共享锁和广播（FR-19）。
- 全量 sitemap 自动预热。
- 预热优先级、按 store 配额或独立队列。
- 预热结果的可视化图表（统计面板已有 warmup 事件）。
- 预热失败重试的持久历史表。
- 反向代理或 CDN 侧的主动刷新。

## 3. 设计

### 3.1 数据表

新增 `page_cache_warmup_queue`：

| 列 | 类型 | 说明 |
|---|---|---|
| `queue_id` | int unsigned, PK, auto increment | 队列 ID |
| `url_hash` | char(64) | `sha256(url)`，唯一键 |
| `url` | varchar(2048) | 绝对 URL，含 store code 和允许的 query |
| `store_id` | smallint unsigned, default 0 | store ID，仅用于统计和排查 |
| `reason` | varchar(64), null | 触发失效的 tag，最多 3 个，截断 |
| `attempts` | smallint unsigned, default 0 | 已失败次数 |
| `available_at` | datetime | 下次可处理时间，UTC |
| `last_error` | varchar(255), null | 最近一次失败原因 |
| `created_at` | datetime | UTC |
| `updated_at` | datetime | UTC |

索引和约束：

- unique `url_hash`
- `(available_at, queue_id)`
- `(store_id)`
- `(created_at)`

表通过 `sql/schema.php` 声明式创建，模块版本升到 `0.4.0`。在 `etc/config.xml` 增加 `page_cache_resource/warmup_queue` 表映射。

### 3.2 入队

`Model/WarmQueue`：

- `enqueue(list<string> $urls, int $storeId, string $reason): int`
- `claim(int $limit): list<array<string, mixed>>`
- `peek(int $limit): list<array<string, mixed>>`
- `remove(int $queueId): void`
- `retry(array $row, string $error, int $maxAttempts, int $retryDelay): string`
- `count(): int`
- `clear(): void`

入队规则：

- `page_cache/warmup/enabled=0` 时不入队，直接返回 0。
- 只接受 `http://` 或 `https://` 开头的绝对 URL，长度不超过 2048。
- 同一 URL 已存在时，把 `available_at` 提前到当前时间，不新增行。
- 同一个 URL 重复入队不重复计数。
- 入队后如果行数超过 `queue_limit`，删除最旧的 `queue_id` 行，保留最新 URL，并记录被淘汰数量。
- 数据库异常只记录日志，不影响页面保存或失效流程。

`Service/Invalidator::invalidateTags()` 在 meta 更新和静态文件删除后入队：

- URL 取 `$meta['url']`，已经是绝对 URL。
- store 取 `$meta['store_id'] ?? 0`。
- reason 取当前 tag 列表前 3 个，逗号分隔，截断 64。
- 不改变现有 proxy purge 队列行为。
- `invalidateAll()` 清空预热队列，不自动全站入队。

meta 写入补 `store_id`：

- `Model/HtmlCache` 的 local meta。
- `Model/Observer` 的 proxy/revalidating meta。

旧 meta 没有 `store_id` 时按 0 处理，不阻塞失效。

### 3.3 消费

cron：`page_cache_warmup`，`* * * * *`，默认关闭。

`Service/Warm::consumeQueue(int $limit = 0): array`：

1. 读取配置；`enabled=0` 时返回空汇总。
2. 用 `core/lock` 获取 `page_cache_warmup_queue` 锁，拿不到直接返回。
3. `claim()` 取最多 `batch_size` 行（手工 `--queue --limit` 可覆盖）。
4. 没有行时返回空汇总。
5. 调用 `warm()`，并发和间隔来自配置。
6. 按 URL 映射回队列行：
   - `warmed`：删除。
   - `skipped`：删除，计入 skipped。
   - `failed`：调用 `retry()`。
     - 未到 `max_attempts`：`attempts+1`，`available_at = now + retry_delay * attempts`，写入 `last_error`。
     - 到 `max_attempts`：删除，计入 dropped。
7. 写入最近一次汇总到缓存，保留 7 天，通过 `Service/Warm::getLastRun()` 读取。
8. 写一行日志，包含 processed/warmed/skipped/failed/dropped/remaining。本批次的 HTTP 结果已由 `warm()` 记录为 `warmup` 统计事件，不重复记录。
9. 释放锁并返回汇总。

汇总结构：

```php
[
    'at' => time(),
    'processed' => 12,
    'warmed' => 8,
    'skipped' => 2,
    'failed' => 1,
    'dropped' => 1,
    'remaining' => 34,
]
```

锁是节点本地的文件锁。多节点同时运行 cron 时每个节点可能各处理一批，队列行不会重复删除，但可能重复请求。完整的多节点协调留给 FR-19。

### 3.4 HTTP 结果分类

`Service/Warm::warm()` 的结果分类调整为：

| 响应 | 结果 | 队列处理 |
|---|---|---|
| 200，`X-Maho-Cache` 不是 `BYPASS` | warmed | 删除 |
| 200，`X-Maho-Cache: BYPASS` | skipped: bypass | 删除 |
| 3xx | skipped: redirect | 删除 |
| 404、410 | skipped: not_found | 删除 |
| 401、403 | skipped: forbidden | 删除，记录警告 |
| 429、5xx | failed | 重试 |
| 其他 4xx | skipped: http_<code> | 删除 |
| 网络异常 | failed | 重试 |

401/403 通常是 basic auth 或访问控制配置错误，重试不会自愈，因此跳过并记录警告。429 和 5xx 可能恢复，进入重试。

### 3.5 限速和 basic auth

`Service/Warm::warm()` 增加可选参数：

```php
public function warm(array $urls, int $concurrency, int $delayMs = 0): array
```

- 每处理完一个并发批次，等待 `delay_ms`。并发 4、间隔 500ms 时约 8 请求/秒。
- 默认 `delay_ms=500`，可配置为 0 关闭。
- 不发送 `Cache-Control: no-cache`。
- 不发送 session cookie，HTTP 客户端不保留 cookie。
- `auth_user` 非空时发送 `Authorization: Basic base64(user:password)`。
- basic auth 密码使用加密配置字段，文档要求只在 HTTPS 环境使用。

### 3.6 配置

| 路径 | 默认 | 说明 |
|---|---|---|
| `page_cache/warmup/enabled` | 0 | 定时预热总开关 |
| `page_cache/warmup/batch_size` | 50 | 每次 cron 处理的最大 URL 数 |
| `page_cache/warmup/concurrency` | 4 | 并发请求数，沿用现有字段 |
| `page_cache/warmup/delay_ms` | 500 | 批次之间的等待毫秒数 |
| `page_cache/warmup/max_attempts` | 3 | 最大失败次数 |
| `page_cache/warmup/retry_delay` | 300 | 重试基础间隔秒数，乘以 attempts |
| `page_cache/warmup/queue_limit` | 10000 | 队列硬上限 |
| `page_cache/warmup/auth_user` | 空 | basic auth 用户名 |
| `page_cache/warmup/auth_password` | 空 | basic auth 密码，加密存储 |

Helper 增加对应 getter，非法值回退默认值。后台放在 `System > Configuration > Page Cache > Warmup`，默认作用域，auth 字段只显示在 default。

### 3.7 状态和失败汇总

`page-cache:status` 增加：

```text
Warmup: enabled, queue=12, last: warmed=8 skipped=2 failed=1 dropped=0 at 2026-11-24 02:10:00
```

- `enabled=0` 时显示 `Warmup: disabled, queue=12`。
- 没有最近汇总时显示 `last: n/a`。
- 最近汇总来自 `Service/Warm::getLastRun()`，存缓存，缓存清理后返回 n/a，不影响队列长度显示。

`page-cache:warm` 增加：

- `--queue`：消费预热队列，最多 `--limit` 条，使用同一 `consumeQueue()`。
- `--dry-run --queue`：列出待处理 URL，不请求。
- `--delay`：覆盖 `delay_ms`，仅对手工 sitemap/URL 预热生效。
- `--queue` 不能和 `--sitemap` / `--url` 同时使用。

失败汇总同时写入日志：每次 cron 一行，包含 processed/warmed/skipped/failed/dropped/remaining 和失败 URL 的前 3 个。

## 4. 任务拆分

- [ ] `sql/schema.php`：新增 `page_cache_warmup_queue`。
- [ ] `etc/config.xml`：表映射、warmup 默认配置、版本 0.4.0。
- [ ] `etc/system.xml`：Warmup 配置字段。
- [ ] `Helper/Data.php`：warmup 配置 getter。
- [ ] `Model/WarmQueue.php`：入队、去重、上限、claim、retry、count、clear、last run。
- [ ] `Model/HtmlCache.php`、`Model/Observer.php`：meta 补 `store_id`。
- [ ] `Service/Invalidator.php`：tag 失效后入队，全量失效清空。
- [ ] `Service/Warm.php`：延迟、basic auth、结果分类、`consumeQueue()`、最近汇总。
- [ ] `Model/Observer.php`：`page_cache_warmup` cron。
- [ ] `lib/MahoCLI/Commands/PageCacheWarm.php`：`--queue`、`--delay`，dry-run 支持队列。
- [ ] `lib/MahoCLI/Commands/PageCacheStatus.php`：队列长度和最近汇总。
- [ ] 语言包：新增后台和 CLI 字符串。
- [ ] 测试：队列、入队、消费、重试、上限、锁、auth、结果分类、跨库。
- [ ] 文档：PRD、ARCHITECTURE、API、README、CHANGELOG。

## 4.1 实现记录

- 代码：`sql/schema.php`、`Model/WarmQueue.php`、`Model/HtmlCache.php`、`Model/Observer.php`、`Model/Key.php`、`Service/Invalidator.php`、`Service/Warm.php`、`Helper/Data.php`、`etc/config.xml`、`etc/system.xml`、CLI 的 `PageCacheWarm.php` 和 `PageCacheStatus.php`。
- 表：`page_cache_warmup_queue`，通过声明式 `sql/schema.php` 创建，模块版本 `0.4.0`。
- 配置：默认关闭，batch 50、concurrency 4、delay 500ms、max attempts 3、retry delay 300s、queue limit 10000、basic auth 用户和加密密码。
- 测试：153 passed（419 assertions），SQLite、PostgreSQL 18、MySQL 8.0 各一次全新安装后全量 PageCache 套件通过。
- 实机（SQLite，local 模式）：
  - `catalog_category_4` 失效后队列出现 `http://localhost:8000/page-cache-test/`，store 1，reason `catalog_category_4`。
  - `page-cache:warm --queue` 后 warmed=1、队列清空，随后请求返回 `X-Maho-Cache: HIT`。
  - 404 URL 跳过并移出队列；网络失败的 URL 保留 attempts=1，`available_at` 延后，立即重跑时 processed=0。
  - basic auth 用加密配置和本地 401/200 端点验证：正确凭据 warmed=1，去掉凭据后 skipped=forbidden。
  - 500 端点配合 `max_attempts=1` 验证 failed=1、dropped=1、队列清空。
  - 静态导出开启时，失效删除 `index.html` 和 `.gz`，预热后重新生成。
  - `cron:run page_cache_warmup` 正常消费队列。
- 验收后清理：预热关闭、队列 0 行、统计 0 行、静态导出关闭、临时 HTTP 端点已停止。

## 5. 验收标准

### AC-18.1 入队

- 开启预热后，保存 product/category/CMS 页面使相关 tag 失效，受影响绝对 URL 进入队列一次。
- 同一 URL 重复失效不产生重复行，`available_at` 提前到当前时间。
- 预热关闭时不入队。
- 全量失效清空队列，不自动全站入队。
- 入队失败不影响页面保存和缓存失效。

### AC-18.2 消费与重试

- cron 每次处理不超过 `batch_size` 条，按 `available_at` 和 `queue_id` 顺序。
- 200 非 bypass、bypass、redirect、404、410、401、403 和其他永久 4xx 移出队列。
- 429、5xx 和网络错误按 `retry_delay * attempts` 重试。
- 达到 `max_attempts` 后移出队列并计入 dropped。
- 队列超过 `queue_limit` 时淘汰最旧行。
- 同一节点并发触发 cron 时只有一个消费者，锁生效。
- 空队列不产生 warmup 统计事件。

### AC-18.3 限速与认证

- `delay_ms` 生效，批次之间等待。
- 配置 basic auth 后请求带 `Authorization: Basic` 头。
- 请求不带 session cookie，不带 `Cache-Control: no-cache`。
- auth 密码在配置中加密存储。

### AC-18.4 状态与手工补跑

- `page-cache:status` 显示预热开关、队列长度和最近汇总。
- `page-cache:warm --queue --dry-run` 列出待处理 URL，不改队列。
- `page-cache:warm --queue` 消费队列并输出汇总。
- `page-cache:warm --sitemap` 和 `--url` 行为保持兼容。

### AC-18.5 验证门

- 新增单元测试和集成测试通过。
- SQLite、PostgreSQL、MySQL 至少覆盖表创建、入队、claim、重试和队列上限。
- PHPStan、php-cs-fixer、Rector 通过。
- 实机：local 模式下失效一个商品或分类，cron 预热后页面命中，队列清空。
- 实机：404 URL 被跳过，5xx 重试到上限后移出。
- 实机：开启静态导出时，预热后静态文件存在或 Web 服务器直接返回。
- 不新增 Composer 依赖，不修改 Maho 核心表。

## 6. 风险与待定

- 预热请求会消耗 Web 进程和数据库资源，默认关闭并限速。
- 队列上限采用淘汰最旧行，可能丢掉较早的失效 URL；日志记录淘汰数量。
- 401/403 跳过可能掩盖 basic auth 配置错误；日志记录警告和 URL。
- 最近汇总存缓存，缓存清理后丢失；统计面板的 warmup 事件保留历史。
- 多节点锁不共享，可能重复预热；完整协调留给 FR-19。
- 预热只覆盖之前被缓存记录过的 URL，不覆盖从未访问的页面；全量覆盖继续用 sitemap CLI。

## 7. 已确认的选择

1. 队列用专用表，不用缓存。理由是重试、上限、重启恢复和跨库查询更稳。
2. 全量失效只清空队列，不自动全站预热。全站预热继续手工 `--sitemap`。
3. 默认值：关闭、batch 50、concurrency 4、delay 500ms、max attempts 3、retry delay 300s、queue limit 10000。
4. 401/403 跳过，429/5xx 重试。
5. basic auth 用全局配置，CLI 不增加密码参数，避免出现在进程列表。
6. 增加 `page-cache:warm --queue`，方便手工补跑和实机验收。
