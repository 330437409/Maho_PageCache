# 功能规格：Maho_PageCache 0.8.0 失效审计

> 状态：已交付@v0.8.0
> 关联 PRD：FR-23
> 目标版本：0.8.0

## 1. 背景

请求统计回答“命中多少”，但不能回答“这次是谁清的、清了哪些 tag、影响多少 URL、purge 和 warm 有没有入队”。FR-23 增加独立的失效审计表，记录每次 tag 或全量失效的来源和结果。

## 2. 范围

### 2.1 范围内

- 新增表 `page_cache_invalidation_log`。
- 记录时间、area、cron job、类型、reason、tag 总数、样本 tag、影响 URL 数、purge/warm 入队、store、mode。
- 审计开关独立于请求统计。
- 样本 tag 数量可配置，总数始终保存。
- 后台新增 `Invalidations` tab，支持分页、列筛选、CSV/Excel 导出和批量删除。
- 按 retention 每天清理。
- 写入失败只记录日志，不影响页面或失效流程。
- 不记录客户、cookie、完整 query 或 PII。

### 2.2 范围外

- 不替代 `page_cache_stat` 请求统计。
- 不记录每个 URL 的完整列表，只存影响数量和 tag 样本。
- 不做实时告警或外部日志导出。
- 不改变 purge outbox 的 ack/重试语义。

## 3. 表结构

```text
page_cache_invalidation_log
  log_id            INTEGER PK AUTOINCREMENT
  area              VARCHAR(16)   admin/frontend/cli/cron
  cron_job          VARCHAR(64) NULL
  invalidation_type VARCHAR(32)
  reason            VARCHAR(255) NULL
  tag_count         INTEGER
  sample_tags       TEXT          # JSON list
  affected_urls     INTEGER
  purge_queued      SMALLINT
  warm_queued       SMALLINT
  store_id          SMALLINT
  mode              VARCHAR(16) NULL
  created_at        DATETIME
  INDEX(created_at)
  INDEX(area, created_at)
  INDEX(invalidation_type, created_at)
```

## 4. 记录点

`Service\Invalidator`：

- `invalidateTags`：记录 tag 数量、样本、唯一 URL 变体数、purge/warm 是否入队；类型从 tag 前缀推断为 `product`、`category`、`cms` 或 `tag`，调用方可以显式指定。
- `invalidateAll`：记录全量类型、reason、清理前 URL 索引数量、proxy 是否入队全量 purge。
- `invalidateCatalogRule` 传入 `catalog_rule`。
- `invalidateProductIds` 接受 `product`、`stock`、`catalog_rule`、`import` 类型。
- 缓存 tag 清理失败回退 `invalidateAll('tag_fallback')` 时，只记录全量记录。
- URL 索引查询失败回退全量时，同样只记录全量记录。

## 5. 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `page_cache/audit/enabled` | 0 | 审计开关 |
| `page_cache/audit/retention_days` | 30 | 保留天数 |
| `page_cache/audit/sample_tags` | 20 | 每条最多保存的 tag 名数量 |

审计关闭时零写入。开启后，失效流程的数据库写入失败只记录异常，不抛给上层。

## 6. 后台

`Page Cache` 一级菜单新增 `Invalidations` tab：

- 列：Time、Area、Cron Job、Type、Reason、Tags、Tag Sample、URLs、Purge、Warm、Store、Mode。
- 时间范围复用 24 小时、7 天、30 天筛选。
- 标准 grid 分页和列筛选。
- CSV 和 Excel XML 导出。
- 批量删除，删除后回统计页。
- 审计关闭时 grid 显示关闭提示。

## 7. Cron

`page_cache_audit_cleanup` 每天 03:35 删除超过 `retention_days` 的记录。

## 8. 验收

- 商品、分类、CMS、配置、stock、catalog rule、import 失效各产生一条可区分类型的记录。
- tag 样本不超过配置，总数始终正确。
- 影响 URL 数等于本次唯一变体数；全量失效取清理前的 URL 索引数量。
- 审计关闭时不写表。
- 写入失败不影响页面或失效。
- 后台 tab 可分页、筛选、导出、批量删除。
- retention 到期自动清理。
- 不记录客户、cookie、完整 query 或 PII。
- SQLite、MySQL、PostgreSQL 通过。

## 9. 本地验证

2026-11-25：

- SQLite、MySQL 8.0、PostgreSQL 18 各 `289 passed / 823 assertions`。
- 开启审计后保存商品，生成 `type=product` 记录，tag 总数 3，reason 为前三个 tag；关闭审计时零写入。
- 后台 `Invalidations` grid 在 adminhtml design 下渲染成功，包含 `Tag Sample` 列和样本 tag。
- 审计记录可按 id 批量删除，retention cleanup 删除超过窗口的行。

## 10. 风险

- 审计表会随失效次数增长，retention 必须可靠。
- 全量失效的 URL 数来自索引，索引本身可能已经部分过期；它是估算值，不是精确站点 URL 总数。
- `area` 在 CLI/cron 场景只能区分 `cli`；没有通用 cron job code 时 `cron_job` 为空。
- 样本 tag 不是全量 tag，排查时需要结合日志或索引。
