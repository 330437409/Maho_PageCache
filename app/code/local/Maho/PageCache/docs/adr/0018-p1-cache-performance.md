# ADR-0018：P1 缓存性能和清理索引

## 状态

已接受，0.5.3。

## 背景

高流量下四个热点：

- purge queue 的 index 没有显式 TTL，默认 7200 秒，短于 86400 秒的 entry TTL，index 过期后 entry 无法处理。
- `Counter::increment()` 每次命中做 cache load + save，再写统计表。
- `StaticCache::cleanup()` 每 5 分钟递归扫描整个静态目录。
- local HTML 原样存储，大页面占用更多 Redis/文件缓存内存。

## 决策

- purge entry TTL 86400 秒，index TTL 90000 秒。
- PHP 侧计数只调用 `Stat::record()`；`Counter::getToday()` 从 `page_cache_stat` 聚合当天事件，不再使用 `PC_STAT_*` cache key。
- 新增 `page_cache_static_file` 表，保存静态文件相对路径和 `expires_at`。保存时 upsert，删除时同步删除行，cron 按 `expires_at` 删除。
- 索引为空时保留一次目录扫描回退，清理升级前遗留的文件。
- local HTML 大于 4 KB 时 gzip 存储，entry 增加 `html_encoding`；小于阈值保持明文，命中时按标记解压。

## 理由

- index TTL 不短于 entry，避免队列条目变成孤儿。
- 统计表已经是事件事实来源，计数器不再增加缓存热路径开销。
- 静态索引把 cron 从全树扫描降为按到期时间查询，同时保留遗留文件清理。
- gzip 阈值避免小页面付出压缩/解压成本；静态导出仍写明文和 `.gz`。

## 后果

- 统计关闭时不再有 PHP 计数，`page-cache:status` 显示 unavailable。
- 静态索引增加一张表；索引写入失败只记录日志，文件仍可被空索引回退清理。
- 缓存中的 HTML 是二进制字符串，调试时不能直接读取 `PC_HTML_*` 的 `html` 字段。

## 备选方案

- **DB purge 队列表**：更持久，但本轮只修 TTL 不同步，不扩大表结构，不采用。
- **采样计数**：实现简单但结果不精确，统计表已存在，不采用。
- **每 5 分钟全树扫描**：高流量和大目录下 I/O 成本高，不采用。
- **无条件 gzip**：小页面压缩收益低于 CPU，不采用。
