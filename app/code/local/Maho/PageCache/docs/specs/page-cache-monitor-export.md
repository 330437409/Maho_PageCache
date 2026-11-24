# 功能规格：监控指标导出

> 状态：实现完成，待跨库 CI 和发版 0.5.5
> 关联 ADR：ADR-0017
> 分支：`feat/page-cache-phase2`

## 1. 范围

- 新增 `page-cache:monitor` CLI。
- 支持 Prometheus、JSON、text 三种格式。
- 输出 stock pending、purge/warm queue、当天 hit/miss/bypass/revalidated 和开关状态。
- `--check` 在 stock pending 超过阈值时返回退出码 1。
- 不新增后台页面、不新增数据库表。

## 2. 设计

`Maho_PageCache_Service_Monitor`：

- `getMetrics()` 汇总 `StockPending::getSummary()`、`Counter::getToday()`、purge/warm 队列和开关。
- `formatPrometheus()` 输出 `maho_page_cache_*` gauge。
- `formatJson()` 和 `formatText()` 用于其他监控系统。
- `isAlerting()` 只依据 `stock_pending_stale`。

CLI：

- `--format=prometheus` 默认。
- `--format=json`。
- `--format=text`。
- `--check` 返回 1 表示告警，0 表示正常。

## 3. 验收标准

- Prometheus 输出包含 stock pending 数量和 oldest 秒数。
- JSON/text 输出字段与 Prometheus 一致。
- 超过阈值时 `--check` 退出码为 1，否则为 0。
- stats 关闭时 counter 为 0，`stats_enabled=0`，不误报 hit rate。
- 现有测试、PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL 测试通过。

## 4. 实现记录

- 代码：`Service/Monitor.php`、`lib/MahoCLI/Commands/PageCacheMonitor.php`。
- 测试：`MonitorTest`。
- 测试结果：198 passed（562 assertions）。
- 实机：`page-cache:monitor --format=text`、`--format=json`、`--format=prometheus` 和 `--check` 已验证。
- 交叉验证待补：SQLite 已通过；PostgreSQL、MySQL 待模块 CI。
