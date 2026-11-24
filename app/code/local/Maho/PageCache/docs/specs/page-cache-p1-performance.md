# 功能规格：P1 缓存性能

> 状态：实现完成，待跨库 CI 和发版 0.5.3
> 关联 ADR：ADR-0018
> 分支：`feat/page-cache-phase2`

## 1. 范围

- purge entry/index TTL 对齐。
- PHP 侧计数改从统计表读取。
- 静态文件清理改用 `page_cache_static_file` 索引，空索引时回退目录扫描。
- local HTML 大于 4 KB 时 gzip 存储。

## 2. 设计

### 2.1 Purge TTL

- entry：86400 秒。
- index：90000 秒。
- `remove()` 重写 index 时保持 90000 秒。

### 2.2 Counter

- `increment()` 只调用 `Stat::record()`，不读写 cache。
- `getToday()` 调用 `Stat::getTodayCounts()`，按 UTC 当天聚合。
- 统计关闭时返回全 0，CLI 显示 counters unavailable。

### 2.3 Static file index

- 表：`page_cache_static_file(static_id, path_hash unique, path, expires_at, created_at, updated_at)`。
- `save()` 写文件和 `.gz` 后 upsert 索引，expires_at 为 `ttl + 300`。
- `removeByUrl()` 删除文件和索引行。
- `flush()` 删除全部索引行和目录内容。
- `cleanup()` 取最多 1000 条到期行，删除文件后删除行；索引为空时回退一次目录扫描。

### 2.4 HTML compression

- 4 KB 以下明文。
- 4 KB 以上 `gzencode(level 6)`，只有压缩后更小才使用。
- entry 增加 `html_encoding`：`plain` 或 `gzip`；`load()` 解压后返回原始 HTML。

## 3. 验收标准

- purge index 重写后 TTL 不短于 entry。
- Counter 不再命中 cache；统计表有事件时 `getToday()` 返回正确计数；统计关闭时 CLI 显示 unavailable。
- 静态文件保存后有索引行；removeByUrl/flush 删除行；cleanup 只删除到期行。
- 索引为空时目录扫描仍能清理遗留文件。
- 大 HTML 以 gzip 存储并可正确加载；小 HTML 保持明文。
- 现有 PageCache 测试、PHPStan、php-cs-fixer、Rector 通过。
- SQLite、PostgreSQL、MySQL 建表和静态索引测试通过。

## 4. 实现记录

- 代码：`Model/PurgeQueue.php`、`Model/Counter.php`、`Model/Stat.php`、`Model/StaticCache.php`、`Model/HtmlCache.php`、`Model/Key.php`、`sql/schema.php`、`etc/config.xml`、`lib/MahoCLI/Commands/PageCacheStatus.php`。
- 测试：`CounterTest`、`LocalCacheTest`、`StaticCacheTest`、`PurgeQueueTest`。
- 测试结果：187 passed（520 assertions）。
- PHPStan、php-cs-fixer、Rector 通过。
- 交叉验证待补：SQLite 已通过；PostgreSQL、MySQL 待模块 CI。
