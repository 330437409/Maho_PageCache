# 生产观察 Runbook：0.5.4

> 适用版本：0.5.4
> 目标：观察 stock pending 告警和页面缓存命中数据，确认监控有效且没有引入回归。
> 本文只描述操作，不改代码。

## 1. 观察前检查

1. 确认模块版本为 0.5.4：`./maho page-cache:status` 或查看 `etc/config.xml`。
2. 执行 `./maho migrate`，确认 `page_cache_stock_pending` 表存在。
3. 确认 cron 可用：`./maho cron:list | grep page_cache`。
4. 需要命中数据时开启统计：`page_cache/stats/enabled=1`，选择 `page_cache/stats/mode`（`full` 或 `sampled`），并确认 `page_cache_stat` 表可写。
5. 确保日志目录可写：`var/log/page-cache-stock.log` 和 `var/log/exception.log`。

## 2. 每日检查

### 2.1 状态命令

```bash
./maho page-cache:status
./maho page-cache:purge:status
./maho page-cache:purge:status --check
```

记录：

- `Mode`
- `Global version`
- `Purge queue`（Pending / Processing / Failed / Delivered）
- `Oldest pending`、最近成功和最近失败
- `Stock pending` 的数量和 oldest 年龄
- `Warmup` 队列的 pending、due、oldest、锁状态和最近一次 processed/warmed/skipped/failed/dropped/remaining
- `PHP counters (today)` 或 `counters unavailable`

`page-cache:purge:status --check` 按 `--target` 检查单个 target；`page-cache:monitor` 的 purge 指标聚合所有 target，并额外输出每个 target 的 pending/failed/oldest 序列。

### 2.1.1 Purge outbox

- `Pending` 长期不降：检查 `page-cache:purge` 是否在跑，事件/文件消费者是否失败；看 `Last error` 和 `var/log/page-cache-purge.log`。
- `Failed`：修复下游后执行 `./maho page-cache:purge --retry-failed`。
- `Processing` 长期不降：worker 可能崩溃，`page_cache/purge/lease_seconds` 后会回到 pending。
- `Delivered` 增长：`page_cache_purge_cleanup` 每天按 `retention_days` 清理。
- ack 只表示事件/文件 hand-off 成功，不代表代理真的清掉；代理侧仍要有自己的失败监控。

### 2.2 日志

```bash
tail -n 200 var/log/page-cache-stock.log
tail -n 200 var/log/page-cache-purge.log
grep -i page_cache var/log/exception.log | tail -n 50
```

关注：

- `Page cache stock pending:` 告警
- `page-cache-stock.log` 是否每小时重复
- 异常堆栈是否指向 `page_cache`

### 2.3 统计面板

`System > Page Cache`，记录：

- hit rate
- hit / miss / bypass / revalidated
- PHP 平均耗时
- top missed URL
- 最近 flush / purge / warmup
- `Invalidation Log` tab：area、cron job、类型、reason、tag 样本、影响 URL 数、purge/warm 入队

统计关闭时，记录 `counters unavailable`，不要用缓存计数代替。采样模式下面板显示 `Sampled` 和采样率，hit rate 与总量是估算值；`purge`、`flush`、`warmup` 仍为完整记录。

失效审计默认关闭，独立于统计。开启后按 `page_cache/audit/retention_days` 清理；写入失败只记日志，不影响页面。

### 2.4 cron

```bash
./maho cron:list | grep page_cache
```

确认存在：

- `page_cache_stock_pending_alert`，每小时第 20 分
- `page_cache_static_cleanup`，每 5 分钟
- `page_cache_stats_rollup`，每小时第 5 分
- `page_cache_stats_cleanup`，每天 03:15
- `page_cache_purge_stale_alert`，每小时第 10 分
- `page_cache_purge_cleanup`，每天 03:25
- `page_cache_audit_cleanup`，每天 03:35
- `page_cache_warmup`，每分钟（滑动窗口消费）

### 2.5 全量预热

cron 只消费 tag 失效写入的队列，不会自动全量预热。部署、`cache:flush` 或首次上线后，需要手工跑一次 `--active`。

先确认 URL 数量和范围：

```bash
./maho page-cache:warm --active --store=1 --limit=1000000 --dry-run | tail -1
```

`--limit` 是硬上限，不是分页偏移；重复用小 limit 跑只会预热同一批排在前面的 URL。全量预热时把 limit 设为不小于活跃 URL 总数。

低峰执行，从默认并发 4 开始：

```bash
time ./maho page-cache:warm --active --store=1 --limit=1000000 --concurrency=4
```

- 有 failed 时命令返回非零；3xx、404/410、401/403 和其他永久 4xx 记为 skipped，不重试。
- 目录式 URL 自动补尾斜杠；`.html` 站点保持原路径。
- 只请求 store base URL 和 `page_cache/warmup/allowed_hosts` 中的 origin，不跟随重定向。
- 源站 CPU 或数据库压力高时降低 `--concurrency`，或用 `--delay` 增加请求启动间隔（无 ext-curl 时回退为批次间隔）。
- 手工全量预热不持有 warmup 队列锁，可能和 cron 消费重叠。队列 `due` 较高时先让 cron 清空，再跑全量。
- 静态导出页面只在静态文件仍存在时不需要预热；`cache:flush` 或失效后静态文件已删除，预热用于让请求回源 PHP 并重新生成静态文件。

跑完检查：

```bash
./maho page-cache:status
./maho page-cache:monitor --format=text --check
```

每次全量预热记录一行：

| 日期 | store | limit | concurrency | 耗时 | warmed | skipped | failed | 备注 |
|---|---|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |  |  |

## 3. 接入现有监控

### 3.1 Prometheus textfile

每 2 分钟导出一次，先写临时文件再原子替换：

```bash
*/2 * * * * cd /var/www/maho && ./maho page-cache:monitor --format=prometheus > /tmp/page_cache.prom && mv /tmp/page_cache.prom /var/lib/node_exporter/textfile/page_cache.prom
```

Prometheus 告警规则示例：

```yaml
groups:
  - name: maho-page-cache
    rules:
      - alert: MahoPageCacheStockPendingStale
        expr: maho_page_cache_stock_pending_stale == 1
        for: 10m
      - alert: MahoPageCacheStockPendingBloat
        expr: maho_page_cache_stock_pending_count > 1000
        for: 30m
      - alert: MahoPageCacheHitRateLow
        expr: (maho_page_cache_hit_today + maho_page_cache_revalidated_today) / clamp_min(maho_page_cache_hit_today + maho_page_cache_miss_today + maho_page_cache_revalidated_today, 1) < 0.5
        for: 30m
      - alert: MahoPageCachePurgeFailed
        expr: maho_page_cache_purge_failed > 0
        for: 5m
      - alert: MahoPageCachePurgeTargetFailed
        expr: maho_page_cache_purge_target_failed > 0
        for: 5m
      - alert: MahoPageCachePurgeStale
        expr: maho_page_cache_purge_stale == 1
        for: 15m
      - alert: MahoPageCacheWarmupBacklog
        expr: maho_page_cache_warmup_due > 100
        for: 15m
      - alert: MahoPageCacheWarmupLockStuck
        expr: maho_page_cache_warmup_lock == 1
        for: 30m
      - alert: MahoPageCacheInvalidationSpike
        expr: maho_page_cache_invalidation_24h > 500
        for: 10m
```

`stats_enabled=0` 时 counter 为 0，不要让 hit rate 告警误报。

### 3.2 Zabbix / Nagios / 脚本

```bash
./maho page-cache:monitor --format=text --check
```

- 退出码 0：正常
- 退出码 1：stock pending 超过告警阈值，或 purge 超过 stale 阈值，或存在 failed purge 行
- 输出一行 key=value，可直接作为 check 结果

JSON 模式适合自定义采集：

```bash
./maho page-cache:monitor --format=json
```

### 3.3 日志告警

`page_cache_stock_pending_alert` 每小时写 `page-cache-stock.log`。现有日志平台可以匹配：

```text
Page cache stock pending:
```

日志告警和指标告警至少保留一个；两者都关闭时只能靠人工 `page-cache:status`。

## 4. Stock pending 判定

| 状态 | 条件 | 动作 |
|---|---|---|
| 绿 | count=0 | 记录，继续观察 |
| 黄 | count>0 且 oldest < 3600 秒 | 正常瞬时；下一次检查确认是否清空 |
| 红 | oldest >= 3600 秒，或连续两次检查 count 增加 | 运行 `./maho index:reindex cataloginventory_stock`，再查 status |
| 严重 | count > 1000，或 oldest > 24 小时 | 检查 stock index 模式和 cron；必要时禁用页面缓存并升级处理 |

告警阈值为 0 表示关闭日志告警，不等于 pending 已清空；status 仍会显示实际数量。

## 5. 命中数据判定

0.5.4 不改变缓存行为，命中率应与 0.5.3 基线接近。

- hit rate 周环比下降超过 10%：检查 bypass 原因、session cookie 比例、缓存 flush、路由配置。
- `session_cookie` bypass 明显上升：确认是否登录用户或购物车用户增加，不要为了命中放宽 session 策略。
- `purge queue` 持续增长：检查 purge consumer 是否运行。
- `warmup` failed 持续增加：检查目标 URL、basic auth、代理和超时。
- `global version` 异常频繁变化：检查主题、配置、规则是否被反复保存。

## 6. 数据采集表

每天记录一行：

| 日期 | mode | hit | miss | bypass | revalidated | hit rate | stock pending | oldest | warnings | purge queue | warmup failed | 备注 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |  |  |  |  |  |  |

建议观察节奏：

- 第 1 天：每 2 小时检查一次 status 和 stock 日志。
- 第 2 到 7 天：每天一次。
- 第 8 到 30 天：每周一次。
- 第 30 天：复盘，决定关闭观察或开 issue。

## 7. 上线前 staging 验证

在 staging 执行一次，不修改生产数据：

1. 保存一个商品的库存状态。
2. `./maho page-cache:status` 确认 `Stock pending` 出现该商品或数量增加。
3. 运行 `./maho index:reindex cataloginventory_stock`。
4. 再次 status，确认 pending 清空。
5. 带 session cookie 请求首页，确认 `X-Maho-Cache: BYPASS` 和 `session_cookie` 原因保持不变。
6. 无 cookie 请求首页，确认 `X-Maho-Cache: HIT` 或正常 MISS。

## 8. 回滚和升级

- 告警阈值太敏感：把 `page_cache/general/stock_pending_warn_age` 调大，或设为 0 关闭日志告警。
- pending 长时间不清：先运行 stock reindex；仍不清时检查 indexer 模式、cron 和 `page_cache_stock_pending` 表，不要手工删行，因为行是重试信号。
- 页面出现旧库存：立即执行 stock reindex，必要时 `./maho cache:flush` 并临时关闭页面缓存。
- 需要完全回滚：关闭 `page_cache/general/enabled`，刷新缓存，保留 `page_cache_stock_pending` 表供排查。
- 从 0.7.0 回滚到 0.6.x：0.7.0 已删除旧 `page_cache_url_tag` 表。回滚代码后运行 `./maho migrate`，声明式 schema 会重建空表；不要恢复旧表数据。0.6.x 在索引缺失或为空时会走全量失效，页面不会串用，但代理 purge 范围会变大。
- 回滚前先做数据库备份，并确认 0.6.x 的 `page_cache_url`/`page_cache_url_tag` 查询在该站点没有被自定义代码直接依赖。
- 2026-11-25 演练：0.8.0 → 0.7.0 → 0.6.1 在 SQLite 副本上通过，`page_cache_url_tag` 在 0.6.1 迁移时重建，站点 200；0.8.0 的 purge outbox 和审计表保留。恢复 0.8.0 后已重跑 `./maho migrate`。
- 切换 `local.xml` 指向副本后，先 `./maho cache:flush` 再 `./maho migrate`，避免旧 config 缓存指向已删除的数据库。

## 9. 隐私

- 统计表只保存 route、URL path、store、mode、耗时和原因码，不保存 cookie、邮箱或客户姓名。
- 不要把 `maho_session` cookie、客户数据或完整请求头贴进 issue 或日志。
