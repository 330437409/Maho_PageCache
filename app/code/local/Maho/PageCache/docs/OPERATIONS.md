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
```

记录：

- `Mode`
- `Global version`
- `Purge queue`
- `Stock pending` 的数量和 oldest 年龄
- `Warmup` 队列和最近一次结果
- `PHP counters (today)` 或 `counters unavailable`

### 2.2 日志

```bash
tail -n 200 var/log/page-cache-stock.log
grep -i page_cache var/log/exception.log | tail -n 50
```

关注：

- `Page cache stock pending:` 告警
- `page-cache-stock.log` 是否每小时重复
- 异常堆栈是否指向 `page_cache`

### 2.3 统计面板

后台一级菜单 `Page Cache`，记录：

- hit rate
- hit / miss / bypass / revalidated
- PHP 平均耗时
- top missed URL
- 最近 flush / purge / warmup

统计关闭时，记录 `counters unavailable`，不要用缓存计数代替。采样模式下面板显示 `Sampled` 和采样率，hit rate 与总量是估算值；`purge`、`flush`、`warmup` 仍为完整记录。

### 2.4 cron

```bash
./maho cron:list | grep page_cache
```

确认存在：

- `page_cache_stock_pending_alert`，每小时第 20 分
- `page_cache_static_cleanup`，每 5 分钟
- `page_cache_stats_rollup`，每小时第 5 分
- `page_cache_stats_cleanup`，每天 03:15
- `page_cache_warmup`，每分钟

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
```

`stats_enabled=0` 时 counter 为 0，不要让 hit rate 告警误报。

### 3.2 Zabbix / Nagios / 脚本

```bash
./maho page-cache:monitor --format=text --check
```

- 退出码 0：正常
- 退出码 1：stock pending 超过告警阈值
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

## 9. 隐私

- 统计表只保存 route、URL path、store、mode、耗时和原因码，不保存 cookie、邮箱或客户姓名。
- 不要把 `maho_session` cookie、客户数据或完整请求头贴进 issue 或日志。
