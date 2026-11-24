# 功能规格：Maho 页面缓存统计面板

> 状态：已交付@v0.3.0；0.5.5 菜单移至一级 Page Cache，内容改为四个标准报表 grid（分页、列过滤、CSV/Excel XML 导出），并新增 Warm Now
> 关联 PRD：FR-16
> 关联 ADR：ADR-0013
> 分支：`feat/page-cache-stats`（待创建）

## 1. 背景与目标

第二期的 PHP 侧计数存在 `page_cache` 缓存类型里，只有当天 hit/miss/bypass/revalidated 几个数字。缓存清理会重置，也没有按路由、按 store、平均耗时、top missed URL 或 flush 历史。

本功能增加一个默认关闭的持久统计：

- 数据库记录页面缓存事件。
- 按小时 rollup，保留历史。
- 后台面板显示 hit rate、平均耗时、趋势、top missed URL 和最近 flush/purge。
- 现有缓存计数器保留为轻量 fallback。

第一版只统计经过 PHP 的事件。静态文件直出、反向代理和 CDN 命中不经过 PHP，不计入。

## 2. 需求

### 2.1 范围内

- 新增原始事件表和小时 rollup 表。
- 记录 hit、miss、bypass、revalidated、dynamic、purge、flush、warmup。
- 记录 route、公共 URL path、store、mode、服务端耗时、原因码。
- 批量写入，失败不阻塞页面。
- 小时 rollup cron 和保留期清理 cron。
- 后台统计面板，支持 24 小时、7 天、30 天。
- hit rate、平均耗时、事件趋势、top missed URL、最近 flush/purge。
- 后台 ACL 和菜单入口。
- 配置开关、原始数据保留天数、rollup 保留天数。

### 2.2 范围外

- 静态文件、反向代理、CDN 命中统计。
- 浏览器上报 TTFB（第一版用服务端耗时）。
- 客户、订单、IP、User-Agent、cookie、query string 等 PII。
- 实时流式面板或 WebSocket。
- 多节点聚合（留到 FR-19）。
- 外部 Prometheus/Grafana 适配器。

## 3. 设计

### 3.1 数据表

新增两张表。

#### `page_cache_stat`

原始事件表，保留最近数据：

| 列 | 类型 | 说明 |
|---|---|---|
| `stat_id` | int unsigned, PK, auto increment | 事件 ID |
| `event_type` | varchar(16) | hit / miss / bypass / revalidated / dynamic / purge / flush / warmup |
| `route` | varchar(128), null | full action name，例如 catalog_product_view |
| `url_path` | varchar(512), null | 公共路径，不含 query、cookie 或客户数据 |
| `store_id` | smallint unsigned | store ID |
| `mode` | varchar(16), null | local / proxy / revalidating |
| `duration_ms` | int unsigned, null | 服务端处理耗时，毫秒 |
| `reason` | varchar(64), null | bypass 原因、flush/purge 原因 |
| `hour_bucket` | datetime | UTC 小时桶，写入时计算 |
| `created_at` | datetime | UTC 时间 |

索引：

- `created_at`
- `(event_type, created_at)`
- `(store_id, created_at)`
- `(route, created_at)`
- `(hour_bucket)`

#### `page_cache_stat_hourly`

小时 rollup 表，保留历史：

| 列 | 类型 | 说明 |
|---|---|---|
| `bucket` | datetime | UTC 小时 |
| `store_id` | smallint unsigned | store ID |
| `event_type` | varchar(16) | 事件类型 |
| `route` | varchar(128), not null default '' | full action name 或空 |
| `event_count` | int unsigned | 次数 |
| `duration_sum` | bigint unsigned | 耗时总和 |
| `duration_count` | int unsigned | 有耗时的次数 |

唯一键：`(bucket, store_id, event_type, route)`。

表通过 setup upgrade script 创建，不改核心表。

### 3.2 事件记录

新增 `Model/Stat.php`：

- `record(string $eventType, ?string $route, ?string $urlPath, ?int $durationMs, ?string $reason): void`
- 事件先写入当前请求的内存缓冲。
- `controller_front_send_response_after` 时一次 `insertMultiple` 写入数据库。
- CLI、cron、purge 等没有前端响应的流程显式调用 `flush()`。
- 写入失败只记录日志，不影响页面；失败时清空缓冲，避免重复报错。
- `event_type` 不在白名单时忽略。
- `duration_ms` 上限保护：超过上限截断到上限，负值丢弃。
- `url_path` 只保存公共路径，截断 512，不含 query、cookie、customer id、IP、User-Agent。

记录点：

- `Model/Counter::increment()`：hit/miss/bypass/revalidated，带原因码。
- `Service/Dynamic` 或 `Observer::sendDynamicResponse()`：dynamic。
- `Service/Purge::process()`：purge，reason 记录 tag 或 `*`。
- `Service/Invalidator::invalidateAll()`：flush，reason 由调用方传入（config、cache_clean、admin_flush、rule、stock_fallback）。
- `Service/Warm::warm()`：warmup，reason 记录 warmed/skipped/failed 数量摘要。

第一版不记录静态文件直出、代理直出和 CDN 命中。

### 3.3 服务端耗时

- 从 `$_SERVER['REQUEST_TIME_FLOAT']` 到记录点的耗时，单位毫秒。
- 只对 hit/miss/revalidated/dynamic 记录；bypass 也记录，便于分析。
- 上限默认 60000ms，超过截断到上限。
- 面板标注“PHP 服务端耗时”，不冒充浏览器 TTFB。

### 3.4 rollup 和清理

Cron：

```text
page_cache_stats_rollup   5 * * * *
page_cache_stats_cleanup  15 3 * * *
```

rollup：

- 聚合 `hour_bucket < 当前小时` 的原始事件，按 bucket、store、event_type、route。
- 写入或累加 `page_cache_stat_hourly`，重复运行不重复计数。
- 聚合成功后删除已处理的原始行。
- 使用查询构造器，兼容 MySQL、MariaDB、PostgreSQL、SQLite。

cleanup：

- 删除超过 `raw_retention_days` 的原始行。
- 删除超过 `rollup_retention_days` 的 rollup 行。
- 删除失败只记录日志。

### 3.5 后台面板

菜单：`Reports > Page Cache`。

ACL：`admin/report/page_cache`。

路由：

```text
/admin/page_cache/stats
/admin/page_cache/stats/index
```

面板显示：

- 时间范围：24 小时 / 7 天 / 30 天。
- Store 过滤：全部或单个 store。
- 顶部卡片：hit rate、PHP 平均耗时、miss 数、bypass 数。
- 趋势表或简单柱状数据：24 小时按小时分组，7 天和 30 天按天分组，包含 hit、miss、bypass、revalidated。
- Top missed URL：最近时间范围内 miss 最多的路径。
- 最近 flush/purge：时间、类型、route、reason。
- 当前配置：统计开关、保留天数、rollup 状态。

第一版使用服务端渲染的 admin block + phtml 模板，daisyUI 类；不做图表库依赖。

数据来源：

- 24 小时、7 天、30 天：原始表；原始数据保留 30 天，覆盖面板范围。
- 超过 30 天的范围（暂未开放）：rollup 表；rollup 保留 365 天。
- 静态/代理/CDN 命中不显示，面板文案说明“仅 PHP 侧”。

### 3.6 配置

| 路径 | 默认 | 说明 |
|---|---|---|
| `page_cache/stats/enabled` | 0 | 统计总开关 |
| `page_cache/stats/raw_retention_days` | 30 | 原始事件保留天数 |
| `page_cache/stats/rollup_retention_days` | 365 | 小时聚合保留天数 |
| `page_cache/stats/max_duration_ms` | 60000 | 单事件耗时上限，超过丢弃 |

后台 `System > Configuration > Page Cache > Statistics`。

### 3.7 安全与隐私

- 默认关闭，开启后只记录聚合所需的字段。
- 不记录 query string、cookie、session、customer id、email、IP、User-Agent。
- `url_path` 截断 512，只保存公共路径。
- 后台面板必须有 ACL。
- 统计写入失败不影响页面。
- 表按保留期清理，避免无限增长。
- rollup/cleanup 使用参数化查询和查询构造器。

## 4. 任务拆分

- [x] `sql/schema.php`：创建两张表和索引。
- [x] `Model/Stat.php`：缓冲、记录、flush、rollup、cleanup。
- [x] `Model/Counter.php`：记录 PHP 侧事件和原因码。
- [x] `Model/Observer.php`：响应后 flush、rollup/cleanup cron。
- [x] `Service/Invalidator.php`、`Service/Purge.php`、`Service/Warm.php`：记录 flush/purge/warmup。
- [x] `etc/config.xml`、`etc/system.xml`：统计配置。
- [x] `etc/adminhtml.xml`：菜单和 ACL。
- [x] `controllers/Adminhtml/PageCacheController.php`：统计页。
- [x] `Block/Adminhtml/Stats.php` 和 admin phtml 模板。
- [x] 测试：记录、flush、失败隔离、rollup、cleanup、面板数据、ACL。
- [x] 文档：PRD、ARCHITECTURE、API、README、CHANGELOG。

## 4.1 实现记录

- 代码：`sql/schema.php`、`Model/Stat.php`、`Model/Resource.php`、`Model/Counter.php`、`Model/Observer.php`、`Service/Invalidator.php`、`Service/Purge.php`、`Service/Warm.php`、`controllers/Adminhtml/PageCacheController.php`、`Block/Adminhtml/Stats.php`、admin phtml 模板。
- 表：`page_cache_stat`、`page_cache_stat_hourly`，通过声明式 `sql/schema.php` 创建。
- 记录：hit/miss/bypass/revalidated/dynamic/purge/flush/warmup；响应后批量写入；admin 前台事件跳过，系统事件不带 admin 路径。
- rollup/cleanup：`page_cache_stats_rollup` 每小时，`page_cache_stats_cleanup` 每天。
- 面板：`Reports > Page Cache`，ACL `admin/report/page_cache`，24 小时/7 天/30 天、store 过滤、hit rate、PHP duration、trend、top missed、recent events。
- 测试：`StatTest` 12 项；全量 137 passed（342 assertions）。
- 实机（SQLite）：24 小时视图 71.4% hit rate、2 misses；7 天视图 62.5%、3 misses，趋势按天显示 `2026-11-21` 和 `2026-11-24`；30 天视图 70.0%、3 misses、8 bypasses，`/old-page/` 进入 top missed；最近事件显示 `flush / cache_clean`。配置行显示开关、raw 30 天、rollup 365 天和最近 rollup 时间。
- 查询参数：`/page-cache-test/?utm_source=acceptance&x=1` 记录的路径为 `/page-cache-test/`。
- ACL：只有 `admin` 资源的受限用户看不到菜单；用其 session form key 铸造合法 key 访问面板，返回 HTTP 403「拒绝访问」。
- 跨库：SQLite、PostgreSQL 18、MySQL 8.0 均为 137 passed（342 assertions）；PostgreSQL 和 MySQL 在全新安装后存在两张统计表并跑通 rollup 查询。
- 静态层 nginx 已验证；统计面板不依赖 Caddy。

## 5. 验收标准

### AC-12.1 记录

- 开启统计后，hit/miss/bypass/revalidated 写入原始表。
- route、store、mode、原因码、服务端耗时正确。
- 不记录 query、cookie、customer、IP、User-Agent。
- 关闭统计后不写表。
- 数据库写入失败不影响页面响应。

### AC-12.2 rollup

- 过期的原始小时桶聚合到 rollup 表。
- 重复运行 rollup 不重复计数。
- 聚合成功后原始行删除。
- 保留期清理删除过期原始行和 rollup 行。

### AC-12.3 面板

- `Reports > Page Cache` 可打开，ACL 生效。
- 24 小时、7 天、30 天范围可切换。
- hit rate、平均 PHP 耗时、miss、bypass、趋势、top missed URL、最近 flush/purge 显示正确。
- 面板明确标注只统计 PHP 侧，不包含静态/代理/CDN 命中。

### AC-12.4 安全

- 无 PII 字段。
- 非授权 admin 不能访问面板。
- 统计写入、rollup、cleanup 失败不阻塞页面或 cron 其他任务。

### AC-12.5 验证门

- 新增单元测试和集成测试通过。
- PHPStan、php-cs-fixer、Rector 通过。
- 实机验证一个页面的 hit/miss 和面板数据。
- MySQL、PostgreSQL、SQLite 测试至少覆盖表创建和 rollup 查询。

## 6. 风险与待定

### 已确认

1. **第一版记录服务端耗时，不记录浏览器 TTFB**。面板标注“PHP 服务端耗时”。
2. **菜单放 `Reports > Page Cache`**，ACL `admin/report/page_cache`。
3. **原始数据保留 30 天，rollup 保留 365 天，均可配置**。
4. **统计默认关闭**，开启后每次 PHP 命中/未命中最多一次批量写入。
5. **只统计 PHP 侧**，静态文件、代理和 CDN 命中不计入。

### 其他风险

- 新增两张表，升级和清理需要运维关注。
- 多节点写同一张表，第一版没有聚合隔离；留到 FR-19。
- 面板没有图表库，趋势用表格或简单 CSS 柱状条，视觉表达有限。
- 统计写入在响应后执行，若进程在 flush 前退出，会丢一批事件。
- URL path 仍可能暴露页面 slug，但不包含 query 和客户数据。
