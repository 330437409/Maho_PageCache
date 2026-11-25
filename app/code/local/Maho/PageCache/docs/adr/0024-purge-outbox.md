# ADR-0024：Purge 使用 DB outbox 和 per-target ack

## 状态

已接受，随 0.8.0 FR-22 实现。

## 背景

0.7.0 的 purge queue 存在 Maho cache：

- `PC_PURGE_INDEX` 和 `PC_PURGE_<id>` 都是 cache entry。
- cache flush、Redis 故障或进程重启会丢 pending purge。
- 事件/文件写出后立即删除条目，没有 ack。
- 只有一个队列，没有 per-target 状态，无法为多节点或多 CDN 独立重试。

FR-22 要求 purge 在 cache flush 和进程重启后仍存在，并且每个目标独立 ack、重试和可见状态。

## 决策

- 新增 `page_cache_purge_outbox` 表，作为待投递 purge 的持久化来源。
- 每个目标一行，`target` 默认 `proxy`；FR-19 可以在此基础上增加节点或 CDN target。
- 状态为 `pending`、`processing`、`delivered`、`failed`。
- 领取使用逐行 optimistic update，不依赖 `FOR UPDATE`。
- `processing` 有 lease；过期后回到 `pending`，支持崩溃重放。
- 失败按指数退避重试；达到 `max_attempts` 后保留 `failed` 状态，不静默删除。
- 事件/文件 hand-off 成功后 ack，失败后 fail；ack 只表示 hand-off 成功。
- 入队按 `target|mode|urls|tags` 的 hash 去重，复用 pending/processing 行。
- delivered/failed 行按 retention 清理。
- 保留 `maho_page_cache_purge_urls` 事件和 purge 文件契约。
- 升级时入队一次全量 purge，弥补旧 cache queue 在 cache flush 中丢失。

## 理由

- DB 是 Maho 已经依赖的持久化层，不引入新的服务或依赖。
- cache 适合页面内容，不适合需要 ack 和重试的投递状态。
- per-target 行让一个目标失败不阻塞其他目标，也为 FR-19 留出模型。
- 逐行 optimistic update 在 SQLite、MySQL、PostgreSQL 上行为一致。
- lease 和退避让进程崩溃或消费者故障后可以恢复，不需要分布式锁。
- 去重避免同一失效在短时间内产生大量重复行。

## 后果

- 新增一张表和 cleanup cron。
- `page-cache:purge` 的语义从“删除 cache entry”变成“claim -> hand-off -> ack/fail”。
- delivered/failed 记录会短期保留，需要 retention。
- 事件/文件成功但代理实际失败时，ack 仍会删除 outbox 行；代理侧必须有幂等和自己的监控。
- 旧 cache queue 在升级时丢失，升级会入队全量 purge；代理需要能处理 `*`。

## 备选方案

- **继续用 cache queue**：实现简单，但无法满足 cache flush/重启不丢，否决。
- **Redis 持久化队列**：需要额外依赖和运维，0.8.0 不引入，否决。
- **消息队列**：可靠性高，但增加部署复杂度，超出范围，否决。
- **单表单行多目标 JSON**：减少行数，但无法 per-target ack 和重试，否决。
- **数据库行锁 `FOR UPDATE`**：SQLite 不支持，且持有时间不可控，不采用。
