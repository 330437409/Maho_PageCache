# 功能规格：stock pending 年龄告警和监控

> 状态：已交付@v0.5.4
> 关联 ADR：ADR-0017
> 分支：`feat/page-cache-phase2`

## 1. 范围

- `page-cache:status` 显示 pending 数量、oldest 年龄和超阈值告警。
- 新增 `page_cache_stock_pending_alert` 小时 cron。
- 新增 `page_cache/general/stock_pending_warn_age`，默认 3600 秒，0 关闭告警。
- 阈值超限写 `var/log/page-cache-stock.log`，提示运行 stock reindex。

## 2. 设计

### 2.1 Service

`Maho_PageCache_Service_StockPending`：

- `getSummary(): {count, oldest_at, age}`，DB 异常时返回 0。
- `isStale(summary, maxAge)`：maxAge 为 0 时永远 false。
- `logIfStale(maxAge)`：只写 warning 日志，不修改 pending 行。

### 2.2 CLI

`page-cache:status` 输出：

```text
Stock pending: 2, oldest: 2h 5m
Stock pending warning: oldest item is 2h 5m old. Run ./maho index:reindex cataloginventory_stock.
```

年龄格式：秒、分钟、小时+分钟、天+小时。

### 2.3 Cron

`page_cache_stock_pending_alert` 每小时第 20 分运行，读取阈值并调用 `logIfStale()`。cron 失败只记录异常，不影响请求。

## 3. 验收标准

- 空表显示 `Stock pending: 0`，不告警。
- 有 pending 时显示数量和 oldest 年龄。
- 超过阈值时 CLI 和 cron 都产生告警；阈值为 0 时都不告警。
- DB 异常时 status 和 cron 不抛错。
- 现有测试、PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL 相关测试通过。

## 4. 实现记录

- 代码：`Service/StockPending.php`、`lib/MahoCLI/Commands/PageCacheStatus.php`、`Model/Observer.php`、`Helper/Data.php`、`etc/config.xml`、`etc/system.xml`。
- 测试：`StockPendingTest`、`ObserverTest`。
- 测试结果：195 passed（542 assertions）。
- 实机：写入 2 小时前的 pending 行后，status 输出 `Stock pending: 1, oldest 2h 0m` 和 reindex 提示。
- 交叉验证：模块 CI run 45361986046 在 SQLite、MySQL、PostgreSQL 三个任务上全部通过（2026-11-24）。
