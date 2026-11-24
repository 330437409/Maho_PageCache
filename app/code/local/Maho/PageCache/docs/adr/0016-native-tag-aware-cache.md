# ADR-0016：页面缓存使用原生 tag-aware cache 和 DB URL 索引

## 状态

已接受，0.5.2 内部重构。

## 背景

ADR-0004 的失效围绕“tag 到 URL hash”的缓存索引。该索引：

- tag key 和 registry 的 TTL 与 meta 不一致，会周期性触发全量失效。
- 每个 tag 做 load-modify-save，分类页和多商品请求产生大量缓存往返。
- registry 是全量数组，并发保存会丢更新。
- query 变体共用 meta 的单 URL 字段，proxy/static 只清最后一个变体。

0.5.1 做了 meta 缺失跳过、TTL 对齐和变体列表的止血，但索引结构仍是瓶颈。

## 决策

- local HTML、meta 和 ETag 使用原生 tag-aware cache 的实体 tag，tag 加 `PAGE_CACHE_TAG_` 命名空间。
- `Invalidator` 用 `Mage_Core_Model_Cache::clean($tags)` 原子失效，删除 `TagIndex`、tag key 和 registry。全量失效递增全局版本并清空 URL 索引，不清缓存类型，保留统计计数器。
- 新增 `page_cache_url` 和 `page_cache_url_tag` DB 表，保存 page URL hash、变体 URL、公共路径、store、版本和 tag。
- DB 索引只服务 proxy purge、warmup、静态文件删除和版本递增；local/revalidating 的缓存条目由原生 tag 失效。
- URL 变体上限 200；超限标记并要求全量 purge。
- DB 查询异常时全量失效；写入异常记录日志。
- `page_cache_url` 随 `page_cache_static_cleanup` cron 按 meta TTL 清理。

## 理由

- 原生 tag 失效是缓存适配器原子操作，没有 registry 丢更新和 TTL 竞争。
- DB 索引持久、可查询、不会因为缓存淘汰而触发周期性全量失效。
- 变体 URL 和公共路径在 DB 中可完整保存，proxy/static 不再依赖单 meta URL。
- 命名空间 tag 避免误清其他模块的实体缓存。

## 后果

- 新增两张表，需要 `./maho migrate`。
- DB 索引写入失败会造成 proxy/static 漏清；必须记录日志，并在查询失败时全量失效。
- URL 行需要清理，否则表会增长。
- 原生 tag 的命名空间必须稳定，升级时旧 tag 缓存不再读取。
- 外部行为保持 0.5.1：失效范围、purge/warm URL、静态删除、304、bypass 不变。

## 备选方案

- **继续修 TagIndex**：TTL、registry 和并发问题仍在，不采用。
- **只用原生 tag，不要 URL 表**：local/revalidating 可以，但 proxy/static 不知道要清哪些 URL，不采用。
- **只用 URL 表，不用原生 tag**：local/revalidating 需要手动遍历和删除缓存条目，失去原子性，不采用。
- **CDN tag purge**：依赖代理能力，作为后续适配器。
