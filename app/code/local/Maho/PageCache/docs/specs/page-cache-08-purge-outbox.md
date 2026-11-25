# 功能规格：Maho_PageCache 0.8.0 Purge Outbox

> 状态：已交付@v0.8.0
> 关联 PRD：FR-22
> 关联 ADR：ADR-0024
> 目标版本：0.8.0

## 1. 背景

当前 purge queue 存在 `Mage::app()->getCache()`：

- `PC_PURGE_INDEX` 保存 entry id，`PC_PURGE_<id>` 保存 URL 和 tag。
- cache flush、Redis 故障或进程重启会丢 pending purge。
- `page-cache:purge` 在事件/文件成功后删除条目，没有消费者 ack，也无法区分多个目标。
- 失败只记录日志，没有 attempts、退避和可见状态。

本功能把待投递的 purge 放进 DB outbox，cache 只作为页面缓存本身，不再承担投递状态。

## 2. 范围

### 2.1 范围内

- 新增表 `page_cache_purge_outbox`。
- 每个目标一行：`target` 默认 `proxy`，为 FR-19 多节点/多 CDN 预留。
- 状态：`pending`、`processing`、`delivered`、`failed`。
- 原子领取、lease 过期恢复、attempts、指数退避、最大次数。
- `page-cache:purge` 保留 `--dry-run`、`--limit`、`--tag`，新增 `--target`、`--retry-failed`。
- 新增 `page-cache:purge:status`，支持 `--check`。
- stale 告警和 delivered/failed 记录清理 cron。
- 保留 `maho_page_cache_purge_urls` 事件和 `var/page-cache/purge-*.txt` 文件契约。
- 升级时入队一次全量 purge，避免 0.7.0 cache queue 丢失后漏清。

### 2.2 范围外

- 不实现 FR-19 的多节点广播；本功能只提供 per-target 数据模型。
- 不新增 Redis 或消息队列依赖。
- 不改变 proxy 响应头、tag 语义和 purge 文件格式。
- 不做后台 outbox 管理 UI；0.8.0 用 CLI 和日志。
- 不保证 purge 文件被外部消费者真正执行；ack 只表示事件/文件 hand-off 成功。

## 3. 表结构

```text
page_cache_purge_outbox
  outbox_id      INTEGER PK AUTOINCREMENT
  target         VARCHAR(32)  DEFAULT 'proxy'
  dedupe_key     CHAR(64)
  mode           VARCHAR(16)  DEFAULT 'proxy'
  urls           TEXT         # JSON list
  tags           TEXT         # JSON list
  status         VARCHAR(16)  DEFAULT 'pending'
  attempts       SMALLINT     DEFAULT 0
  available_at   DATETIME
  locked_at      DATETIME NULL
  locked_by      VARCHAR(64) NULL
  last_error     VARCHAR(255) NULL
  delivered_at   DATETIME NULL
  created_at     DATETIME
  updated_at     DATETIME
  INDEX(target, status, available_at)
  INDEX(status, created_at)
  INDEX(dedupe_key)
  INDEX(updated_at)
```

`dedupe_key = sha256(target|mode|sorted urls|sorted tags)`。入队时如果已有同 key 的 `pending` 或 `processing` 行，直接复用，不插入重复行。`failed` 行不阻止新的 pending 行。

## 4. 状态机

```text
enqueue -> pending
pending -> processing   claim，写 locked_at/locked_by
processing -> delivered ack，写 delivered_at
processing -> pending   fail，attempts++，available_at = now + backoff
processing -> failed    attempts >= max_attempts
processing -> pending   lease 过期恢复
failed -> pending       page-cache:purge --retry-failed
delivered/failed -> 删除 retention cleanup
```

退避：

```text
delay = min(retry_delay * 2^(attempts - 1), 3600)
```

`retry_delay` 默认 60 秒，`max_attempts` 默认 5。

## 5. 领取和 ack

`claim(target, limit, tag)`：

1. 恢复 lease 过期的 `processing` 行。
2. 选出 `status=pending` 且 `available_at <= now` 的候选行。
3. 逐行执行 `UPDATE ... SET status='processing', locked_at=..., locked_by=... WHERE outbox_id=? AND status='pending' AND available_at<=?`。
4. 只返回实际更新成功的行。

逐行 optimistic update 兼容 SQLite、MySQL 和 PostgreSQL，不依赖 `FOR UPDATE` 或数据库特有语法。

`ack(id)`：`status=delivered`，写 `delivered_at`，清空 lock。

`fail(id, error)`：`attempts++`；未到上限回到 `pending` 并设置 `available_at`；到上限置 `failed`。
`retryFailed(target)`：把 `failed` 行改回 `pending`，attempts 归零，`available_at=now`。

## 6. CLI

### 6.1 `page-cache:purge`

```text
--dry-run
--limit=100
--tag=<tag>
--target=proxy
--retry-failed
```

- `--retry-failed` 先重置该 target 的 failed 行，再正常处理。
- dry run 只列出 pending URL，不 claim、不改状态。
- 真实运行 claim 后调用 `Service\Purge::process`。
- 事件/文件成功：逐行 ack。
- 事件/文件失败：逐行 fail，保留并退避。
- 输出 processed、failed、purge file。

### 6.2 `page-cache:purge:status`

```text
page-cache:purge:status [--target=proxy] [--check]
```

显示：

- pending / processing / failed / delivered 数量；
- oldest pending 年龄；
- 最近成功和最近失败时间；
- 每个 target 的最近错误。

`--check` 在存在 stale pending 或 failed 时返回非零。

## 7. 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `page_cache/purge/max_attempts` | 5 | 最大投递次数 |
| `page_cache/purge/retry_delay` | 60 | 退避基数秒 |
| `page_cache/purge/stale_after` | 3600 | pending 超过该年龄告警 |
| `page_cache/purge/retention_days` | 7 | delivered/failed 记录保留天数 |
| `page_cache/purge/lease_seconds` | 300 | processing lease |

## 8. Cron

- `page_cache_purge_stale_alert` 每小时检查最老 pending，超过 `stale_after` 写 warning。
- `page_cache_purge_cleanup` 每天删除超过 `retention_days` 的 delivered/failed 行。

## 9. 升级

`upgrade-0.7.0-0.8.0.php`：

1. 若模块启用，入队一次 `target=proxy, mode=full, urls=['*'], tags=['*']`。
2. 清理旧 `PC_PURGE_INDEX` / `PC_PURGE_*` cache 键；`maho migrate` 已经清 cache，这里只做兜底。
3. 不迁移旧 cache queue 条目；它们在升级时已经随 cache flush 丢失，全量 purge 兜底。

## 10. 验收

- 入队后 flush cache、重启进程，pending 仍在。
- 两个 target，一个失败不影响另一个成功。
- ack 后才结束；失败按退避重试；达到上限后状态可见。
- lease 过期后行可被重新领取。
- 重复入队复用 pending 行，不产生重复投递。
- `page-cache:purge` 旧选项行为不变。
- `page-cache:purge:status --check` 在 stale/failed 时非零。
- SQLite、MySQL、PostgreSQL 通过。
- 不记录客户、cookie、完整 query 或 PII。

## 11. 本地验证

2026-11-25：

- SQLite、MySQL 8.0、PostgreSQL 18 各 `281 passed / 790 assertions`。
- 手动把 `var/page-cache` 设为不可写，`page-cache:purge` 把行标记为 `failed`，`page-cache:purge:status --check` 返回 1。
- 恢复目录权限后执行 `--retry-failed`，下一次 `page-cache:purge` ack 为 `delivered`。
- `page-cache:purge:status` 显示 pending、processing、failed、delivered、oldest pending、最近成功/失败和 last error。

## 12. 风险

- outbox 会长期保留 delivered/failed 行；retention cleanup 必须可靠。
- 事件/文件 hand-off 成功不代表代理真的清掉；ack 语义在文档中明确。
- 多节点 fan-out 仍需要 FR-19 的节点注册和共享版本，本功能只准备数据模型。
- 旧 cache queue 在升级时丢失，依赖全量 purge 兜底。
