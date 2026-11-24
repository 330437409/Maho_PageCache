# ADR-0014：第三期增加 cron 预热和 purge 后 requeue

## 状态

已接受，第三期。

## 背景

第二期提供 `page-cache:warm` CLI，支持 sitemap、显式 URL、limit、concurrency 和 dry-run。它适合手工执行，但失效后不会自动补缓存，也没有断点续跑、限速或 basic auth。

`mageaustralia/maho-module-fpc` 用 flag/state/queue 文件加 cron 分批预热，并在 purge 后把受影响 URL 重新入队。这个思路适合第三期。

## 决策

第三期在现有 CLI 之上增加**默认关闭**的调度预热：

- tag/URL 失效成功后，把受影响 URL 写入预热队列，而不是立即请求。
- cron 按配置的批次大小、并发和延迟消费队列。
- 支持 sitemap 全量预热、显式 URL、basic auth、失败汇总和断点续跑。
- 队列和状态存 Maho 缓存或专用表，必须有上限，避免无限增长。
- 只预热可缓存路由和可浏览 URL；404/redirect/非 200 跳过。
- 不发送 session cookie，不发送 `Cache-Control: no-cache`。
- 失败 URL 保留并记录，超过重试次数后移出队列。
- `page-cache:warm` 继续作为手工入口，cron 复用同一服务。

## 理由

- 缓存失效后最先到达的用户会承担回源成本，预热可以削峰。
- purge 后 requeue 只修复受影响页面，不需要全站爬取。
- cron 分批和限速避免预热把 PHP/数据库打满。
- basic auth 覆盖受保护的 staging 环境。

## 后果

- 需要 cron 配置和队列监控。
- 预热请求会消耗 Web 进程和数据库资源，必须限速。
- 队列可能重复或堆积，需要去重、上限和重试策略。
- 多节点部署需要共享队列或每节点独立预热。

## 备选方案

- **只保留手动 CLI**：实现简单，但失效后无人执行就没有预热。
- **内部 dispatch 预热**：快，但绕过 Web/代理/session/HTTP 路径，不作为默认。
- **全量 sitemap 预热**：覆盖广，但失效一个商品也全站爬取，成本太高。
