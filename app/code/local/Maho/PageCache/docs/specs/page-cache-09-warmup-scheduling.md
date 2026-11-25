# 功能规格：Maho_PageCache 0.9.0 预热调度升级

> 状态：已交付@v0.9.0；0.9.1 修复 store code/secure base、分块读取和收集失败退出码。
> 关联 PRD：FR-24
> 目标版本：0.9.0（单节点）

## 1. 背景

0.8.0 的 warm queue 按固定 chunk 并发：一个慢 URL 会让整批等待，下一批才能开始。URL 源主要是 sitemap 和显式 URL，缺少从商品/分类 URL 重写生成活跃页面的能力；后台也没有队列进度和锁状态。

本任务把预热改成滑动窗口，并增加活跃商品/分类 URL 源和后台进度。

## 2. 范围

### 2.1 范围内

- 滑动窗口并发：完成一个请求立即补下一个，慢 URL 不阻塞整批。
- `page_cache/warmup/delay_ms` 改为请求启动间隔。
- 新增 `page-cache:warm --active`，从 `core_url_rewrite` 读取活跃商品/分类 URL。
- `--store=N` 选择 store，默认当前 store。
- 过滤 disabled 商品、不可见商品、非活跃分类和 `options=R/RP` 的 301 重写。
- 目录式 URL 自动补尾斜杠，避免预热请求被 301。
- 后台显示 pending、due、oldest、锁状态和最近一次 processed/warmed/skipped/failed/dropped/remaining。
- 锁在 page cache flush 后仍有效；队列运行失败后释放。
- 单元测试和真实站点预热验证。

### 2.2 范围外

- 多节点预热广播和共享锁，属于 FR-19。
- 目录页面的全量自动定时预热；仍由 `--active` 手工或外部调度触发。
- 预热请求的 HTTP/2 多路复用调优。
- 代理 SWR/grace，属于 FR-25。

## 3. 滑动窗口

### 3.1 实现

生产环境有 ext-curl 时，`Service\Warm::warm()` 使用 `curl_multi`：

1. 维护最多 `concurrency` 个活跃 handle。
2. 一个 handle 完成后立即分类结果，从队列取下一个 URL 启动。
3. `delay_ms` 控制两次启动之间的最小间隔，不是批次间隔。
4. 每个 handle 用 header callback 读取 `X-Maho-Cache`，body 直接丢弃。
5. `max_redirects=0`，3xx 记为 skipped。

没有 ext-curl，或子类覆盖了 `createClient()`（测试和自定义传输）时，回退到 Symfony HttpClient 的 chunk 实现。两条路径使用同一套状态分类。

### 3.2 分类

- 200 且 `X-Maho-Cache` 不是 BYPASS：warmed。
- 200 + BYPASS：skipped `bypass`。
- 3xx：skipped `redirect`。
- 404/410：skipped `not_found`。
- 401/403：skipped `forbidden`。
- 429/5xx/网络错误：failed。
- 其他：skipped `http_<code>`。

## 4. 活跃 URL 源

`collectActiveCatalogUrls(int $storeId, int $limit): array`：

- 商品：当前 store 下 status=enabled，visibility 为 catalog/search/both。
- 分类：当前 store 下 is_active=1。
- URL 重写：`core_url_rewrite` 中 `store_id` 匹配、`is_system=1`、`options` 为空、`request_path` 非空。
- 目标路径限制为 `catalog/product/view/%` 和 `catalog/category/view/%`。
- 目录式路径补 `/`，`.html` 等文件式路径保持原样。
- 生成绝对 URL 后再过 `UrlPolicy`，只保留 store origin 或 `allowed_hosts`。
- 结果排序、去重、按 limit 截断。

CLI：

```bash
./maho page-cache:warm --active --store=1 --limit=100
./maho page-cache:warm --active --sitemap --limit=100
```

## 5. 后台

`Page Cache` dashboard 增加一行：

```text
Warmup queue: N pending, M due
Oldest pending: Ns
Warmup lock: Held / Free
Last warmup: processed=N warmed=N skipped=N failed=N dropped=N remaining=N
```

数据来源：

- `WarmQueue::getStats()`：pending、due、oldest。
- `core/lock::isHeld('page_cache_warmup_queue')`。
- `Service\Warm::getLastRun()`。

## 6. 锁

- 使用 `core/lock` 的 `page_cache_warmup_queue`。
- 锁不依赖 page cache 类型，cache flush 后仍保持。
- `consumeQueue()` 在 `finally` 中释放；异常不会留下锁。
- 并发 cron 只有一个消费者。

## 7. 验收

- 滑动窗口：慢 URL 不阻塞已完成的请求启动下一个。
- `--active` 只返回 active store 下的商品/分类 URL。
- disabled 商品、不可见商品、非活跃分类、301 重写不返回。
- 目录式 URL 带尾斜杠，预热不再被 301。
- 后台显示 pending/due/oldest/lock/last run。
- cache flush 后锁仍 held；失败后 lock free。
- SQLite、MySQL、PostgreSQL 通过。
- 实机 `--active` 全量预热成功。

## 8. 风险

- curl_multi 和 Symfony 回退是两条代码路径，分类逻辑必须保持一致。
- `--active` 会读取商品/分类集合，超大 catalog 需要 limit 或外部调度。
- 目录式补尾斜杠依赖站点使用尾斜杠 URL；使用 `.html` 后缀的站点保持原路径。
- 后台展示的是队列快照，不是实时推送。
