# ADR-0022：URL 索引按 context 保存变体

## 状态

已接受，0.6.1 审计 P1 / 0.7.0。

## 背景

ADR-0016 的 DB URL 索引每个 URL 只有一行，保存一个 `public_path`、一个 `store_code` 和一组变体 URL。proxy/static 的同一个 URL 可能有多个 context，例如不同 store、货币或允许参数；后写入的 context 会覆盖前一个。失效时只能删除最后一个 context 的静态文件，其他 context 继续陈旧到 TTL。

## 决策

- 保留 `page_cache_url` 作为 URL 级版本和变体容器。
- 新增 `page_cache_url_context`，按 `url_id + context_hash` 唯一，保存该 context 的 store、store code、公共路径和完整 URL。
- 新增 `page_cache_url_context_tag`，按 `context_id + tag` 唯一，保存该 context 的 tag。
- 失效按 tag 查出所有 context，逐个递增 URL 版本、删除对应静态文件和 enqueue purge URL。
- 同一 URL 的变体超过 200 个时标记 `variants_truncated`，proxy 模式回退全量 purge。
- 0.7.0 删除旧的 `page_cache_url_tag` 表；迁移时执行一次全量失效。
- 清理 cron 按 URL 的 `updated_at` 删除过期的 context、tag 和 URL 行。

## 理由

- 一个 URL 的多个 context 必须独立保存公共路径和 store，否则静态文件删除和 proxy purge 会漏。
- `context_hash` 已经包含 store、货币、客户组、税、允许参数和 variant，适合做 context 的唯一键。
- URL 级版本仍然足够让 local/revalidating 的所有 context 同时过期，不需要为每个 context 单独维护版本。
- 按 tag 查 context 再由 context 找到 URL，保留 tag 失效的细粒度。

## 后果

- 新增两张表，旧表在 0.7.0 删除，升级需要 `./maho migrate`。
- tag 行数随 context 增长，清理必须按 URL 的 `updated_at` 执行。
- 索引写入失败会导致 proxy/static 漏清，必须回退全量失效。
- 变体截断时只能全量 purge，不能保证单 URL 精确清理。
- local/revalidating 的缓存条目仍由原生 tag 失效，不依赖这两张表。

## 备选方案

- **继续单行 URL 索引**：无法保存多个 context 的公共路径和静态文件，不采用。
- **每个 context 一个 URL 行**：URL 版本会分裂，local/revalidating 失效需要遍历所有 context，复杂且容易漏，不采用。
- **只用原生 tag，不建 context 表**：proxy/static 不知道每个 context 的 URL 和公共路径，不采用。
- **变体超限时静默截断**：会漏清 proxy/static，不采用。
