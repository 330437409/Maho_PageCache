# ADR-0013：第三期用数据库统计表和后台面板替代缓存计数器

## 状态

已接受，第三期。

## 背景

第二期的 PHP 侧计数存在 `page_cache` 缓存类型里，只有 hit/miss/bypass/revalidated 几个当天计数。缓存类型清理会重置计数，也没有 TTFB、按路由、按 store、top missed URL 或 flush 事件。

`mageaustralia/maho-module-fpc` 用两张表加后台面板：

- 原始事件表记录 hit、miss、flush、purge、warmup、TTFB、flush reason、store。
- 小时 rollup 表保存历史聚合，原始表只保留最近数据。
- 后台面板显示 hit rate、TTFB、flush 次数、趋势、top missed URL 和最近 flush。

这个方向适合第三期，但不能让统计写入影响请求。

## 决策

第三期增加一个**默认关闭**的统计模块：

- 新增统计表，记录：
  - event type：hit、miss、bypass、revalidated、purge、flush、warmup、dynamic
  - route 或归一化 URL hash，不记录完整 query 和 PII
  - store、mode、response time
  - flush/purge 原因
- 写入按请求批量或异步，失败只记日志，不影响页面响应。
- 小时 rollup，原始数据有保留天数。
- 后台面板显示 hit rate、TTFB、按路由/store/mode 的趋势、top missed URL、最近 flush/purge。
- 现有缓存计数器保留为轻量 fallback；面板优先读数据库。
- 浏览器 TTFB 由前端脚本上报，设置上限，超过上限丢弃。
- 统计表通过 setup script 创建，升级脚本只增不删。

## 理由

- 缓存计数器会被清理，无法回答长期命中率和 flush 质量问题。
- 按路由和 top missed URL 能指导预热和缓存规则调整。
- 批量写入和失败隔离可以避免统计拖慢页面。
- 数据库统计比外部 APM 更容易在 Maho 后台直接查看。

## 后果

- 新增表和清理任务，需要运维关注增长。
- 统计写入增加少量数据库负载，必须批量/异步。
- 需要定义 URL 归一化，避免记录敏感 query。
- 多节点写入同一张表，需要确保写入幂等或可聚合。

## 备选方案

- **只保留缓存计数器**：实现小，但清理即丢失，无法做趋势。
- **外部 APM/Prometheus**：更专业，但要求运维接入，后台看不到。
- **日志文件 + 离线分析**：无需表，但查询和面板实现更复杂。
