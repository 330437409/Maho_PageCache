# Maho_PageCache

Maho 的 HTTP 页面缓存集成层。第一期支持 `proxy` 和 `revalidating` 两种模式，第二期增加 `local` 模式，把整页 HTML 存进 Maho 的 Symfony Cache，第三期增加静态文件导出、统计面板、定时预热和服务器端动态块占位符。

## 状态

0.8.0 已发布@v0.8.0（dist）：FR-22 purge DB outbox、per-target ack/重试、`page-cache:purge:status` 和 stale 告警，以及 FR-23 失效审计表、后台 Invalidations tab、导出和 retention 已交付。0.7.0 已发布@v0.7.0（dist）：RFC-007 Phase 1 CacheEnvelope 和 PERF-02 压缩配置、Phase 2 Context Index 迁移和旧表清理、Phase 3 Warmup 安全补强、Phase 4 Stats 采样、Dynamic 边界与 Proxy Models 验证、PERF-04 Cookie 规范化文档已实现；0.6.1 FR-21 和审计 P1 修复已实现；Phase 5 完成，dist 三库 CI 通过；G9 保留 Known issue。0.5.5 已交付，第一期和第二期完成；第三期静态导出、统计面板、定时预热和服务器端占位符已交付；0.5.1 cache integrity 修复、0.5.2 tag 索引重构、0.5.3 P1 加固与性能优化、0.5.4 stock pending 监控和 0.5.5 监控指标导出与后台报表已交付，累计包含第一期的 HTTP 集成层、proxy/revalidating 两种模式、session skip、tag 失效、purge 队列、配置和 CLI，第二期的 `local` 模式、Ajax/ESI 动态块和 `page-cache:warm`，第三期的静态文件导出、统计面板、定时预热、服务器端动态块占位符和请求级代理模型，以及 0.5.1 的 cache integrity 修复、0.5.2 的 tag 索引重构、0.5.3 的 P1 加固与性能优化、0.5.4 的 stock pending 监控和 0.5.5 的监控指标导出与 Page Cache 后台报表。

- 第一期：HTTP 集成层，`proxy` + `revalidating`。0.2.0 已交付。
- 第二期：`local` 模式、整页 HTML 缓存、Ajax/ESI 动态块、缓存预热。0.2.0 已交付。
- local 模式只缓存无 session cookie 的匿名页面；被动浏览不会建立 session。动态块打孔用于 proxy/revalidating 模式；local 模式缓存匿名渲染，显式 opt-in 的 leaf block 可以在命中时服务器端重建。form key 在用户提交表单时才获取。
- 第三期：静态文件导出、统计面板、定时预热、服务器端占位符、代理模型、多节点 purge。静态导出和统计面板 0.3.0 已交付；定时预热 0.4.0 已交付；服务器端动态块占位符 0.5.0 已交付；请求级代理模型已并入 0.7.0；多节点只登记。

## 文档

- `docs/PRD.md`：需求、FR、验收标准。
- `docs/ARCHITECTURE.md`：模块结构、缓存键、运行时流程、失效机制。
- `docs/API.md`：后台配置、HTTP 头、CLI、purge 事件。
- `docs/adr/`：架构决策记录。
- `docs/specs/page-cache.md`：第一期规格。
- `docs/specs/page-cache-phase2.md`：第二期规格（0.2.0 已交付）。
- `docs/specs/page-cache-static-export.md`：第三期静态文件导出规格（0.3.0 已交付）。
- `docs/specs/page-cache-stats-dashboard.md`：第三期统计面板规格（0.3.0 已交付）。
- `docs/specs/page-cache-scheduled-warmup.md`：第三期定时预热规格（0.4.0 已交付）。
- `docs/specs/page-cache-server-placeholders.md`：第三期服务器端动态块占位符规格（0.5.0 已交付）。
- `docs/specs/page-cache-tag-index-refactor.md`：0.5.2 tag 索引重构规格。
- `docs/specs/page-cache-p1-hardening.md`：0.5.3 P1 缓存加固规格。
- `docs/specs/page-cache-p1-performance.md`：0.5.3 P1 缓存性能规格。
- `docs/specs/page-cache-p1-remaining.md`：0.5.3 P1 剩余正确性项规格。
- `docs/specs/page-cache-stock-pending-monitor.md`：0.5.4 stock pending 监控规格。
- `docs/adr/0019-session-cookie-policy.md`：session cookie 命中策略，FR-20 暂停。
- `docs/specs/page-cache-fr20-session-spike.md`：FR-20 session 命中只读安全 spike。
- `docs/OPERATIONS.md`：0.5.4 生产观察和监控接入 runbook。
- `docs/specs/page-cache-monitor-export.md`：0.5.5 监控指标导出规格。
- `docs/specs/page-cache-admin-menu.md`：0.5.5 Page Cache 一级菜单和 Warm Now 规格。
- `docs/specs/page-cache-proxy-models.md`：0.6.0 请求级代理模型规格。
- `docs/specs/page-cache-tag-budget.md`：0.6.1 proxy tag 预算与 overflow tag 规格。
- `docs/specs/page-cache-audit-p1-fixes.md`：0.6.1 审计 P1 修复规格。
- `docs/rfc/RFC-007-performance-architecture.md`：0.7.0 性能与架构优化 RFC。
- `docs/specs/page-cache-07-performance.md`：0.7.0 Local CacheEnvelope 规格。
- `docs/specs/page-cache-07-stats-sampling.md`：0.7.0 Stats 采样模式规格。
- `docs/adr/0021-local-cache-envelope.md`：Local CacheEnvelope 决策。
- `docs/adr/0023-stats-sampling.md`：Stats 请求级采样决策。
- `docs/benchmark/0.6.1-baseline.md`：0.6.1 性能基线和复现方法。
- `docs/benchmark/0.7.0-cacheenvelope-dev-check.md`：CacheEnvelope 开发中复测。
- `docs/benchmark/0.7.0-compression.md`：0.7.0 HTML 压缩基准。
- `docs/benchmark/0.7.0-local-hit-profile.md`：0.7.0 Local HIT 时间分布和 G9 结论。
- `docs/specs/page-cache-07-compression.md`：0.7.0 压缩配置规格。
- `docs/specs/page-cache-08-purge-outbox.md`：0.8.0 purge outbox 规格。
- `docs/specs/page-cache-08-invalidation-audit.md`：0.8.0 失效审计规格。
- `docs/adr/0024-purge-outbox.md`：Purge DB outbox 决策。
- `docs/deployment/README.md`：代理缓存 Cookie 规范化规则和验证结果。
- `docs/deployment/nginx-cache-key.md`：nginx cache key 配置。
- `docs/deployment/varnish-cache-key.vcl`：Varnish cache key 配置。
- `docs/adr/0020-request-scoped-proxy-models.md`：请求级代理模型的数据来源和失效边界。
- `.claude/rules/`：模块级防漂移规则。

## 设计来源

模块参考 RockSolid_PageCache 和 Cm_Diehard 的思路，但不复制代码、不引入它们的依赖：

- 从 Diehard 借鉴后端分层和 `revalidating` 的 ETag/304 策略。
- 从 RockSolid 借鉴实体 tag 失效和 context 缓存键。
- 动态块和代理模型分别放到第二期和第三期。

## 安装

模块位于 `app/code/local/Maho/PageCache`，并附带 `app/etc/modules/Maho_PageCache.xml`。安装步骤：

1. `composer dump-autoload`，让类映射、路由和观察者属性生效。
2. `./maho migrate`，创建统计表、预热队列、purge outbox `page_cache_purge_outbox`、URL 索引表 `page_cache_url`、`page_cache_url_context`、`page_cache_url_context_tag`、静态文件索引 `page_cache_static_file`、stock pending 表 `page_cache_stock_pending`，并写入 `page_cache` 缓存类型选项。0.7.0 升级会先做一次全量页面缓存失效，再删除旧 `page_cache_url_tag` 表。0.8.0 升级会为 proxy/revalidating 模式入队一次全量 purge；升级后运行 `./maho page-cache:purge` 或让外部消费者处理。
3. `./maho cache:flush`。
4. 在 `System > Configuration > Page Cache` 开启并选择模式。
5. 配置反向代理或 CDN：遵守 `Vary: Accept-Encoding, Cookie`，按 URL 和 `Cookie` 区分缓存对象，并在 `Cookie` 含 `maho_session` 时 bypass；消费 purge 文件或监听 `maho_page_cache_purge_urls` 事件。`X-Maho-Cache-Context` 只用于调试。purge 文件中的 `*` 表示全量 purge。使用 ESI 时，代理还必须解析 `esi:include` 并禁止缓存 `X-Maho-Cache: DYNAMIC` 响应。
6. 运行 `./maho page-cache:status` 检查。
7. 如果后台主题还没有统计面板使用的 Tailwind 类，运行 `./maho dev:admin:theme:build` 重新编译。

0.4.0 会创建统计表和预热队列表；0.5.2 会创建 URL 索引表；0.5.3 会创建静态文件索引表。升级到 0.5.3 后运行 `./maho migrate` 和 `./maho cache:flush`；旧 `PC_TAG_*` 和 `PC_STAT_*` 键不再读取。不新增 Composer 依赖。模块默认关闭，先在后台开启。

## 配置

```text
System > Configuration > Page Cache
```

主要字段：开关、模式（`proxy`、`revalidating`、`local`）、TTL、local HTML 大小上限、可缓存路由、允许的 query 参数、路径段数上限、分层导航标记和深度、是否遵守 no-cache、调试头、session skip 路由、proxy context 模式、stock pending 告警阈值、purge outbox 最大尝试/退避/stale/retention/lease、失效审计开关/保留期/tag 样本数、动态块注入方式、动态块 TTL、动态请求限流和窗口、服务器端动态块开关和类型白名单、预热开关/批次/并发/间隔/重试/队列上限/basic auth/允许的别名 origin、静态文件导出、统计开关和保留期。

## 静态文件导出

默认关闭。开启 `System > Configuration > Page Cache > Static Export` 后，`local` 模式会把匿名默认页额外写成：

```text
var/page-cache/static/{store_code}/{public_path}
var/page-cache/static/{store_code}/{public_path}.gz
```

`{public_path}` 对首页是 `index.html`，对 `/category/` 是 `category/index.html`，对 `/product.html` 是 `product.html`。

只导出无 `maho_session` cookie、无 query 参数的页面。带允许参数的变体继续走 PHP/local。

部署要求：

1. nginx 或 Caddy 直接返回静态文件，示例见 `docs/specs/page-cache-static-export.md`。
2. Web 服务器在 `Cookie` 含 `maho_session`、`___store` 或有 query 参数时回 PHP。
3. 缓存目录必须 deny 直接访问。
4. `page_cache_static_cleanup` cron 每 5 分钟清理超过 TTL 的文件。
5. 第一版只支持单节点；多节点共享存储留到 FR-19。

## 统计面板

一级菜单 `Page Cache` 用 Overview、Trend、Top Missed URLs、Recent Events、Invalidations 五个标准报表 grid 显示 PHP 侧 hit rate、平均耗时、趋势、top missed URL、最近 flush/purge/warmup 和失效审计，并提供 `Warm Now`（一个预热队列批次）和 `Configuration` 按钮。统计默认关闭；在 `System > Configuration > Page Cache > Statistics` 开启，并选择统计模式：

- `off`：不记录。
- `full`：记录每个请求事件，适合调试和验收。
- `sampled`：按 `page_cache/stats/sample_rate` 采样，适合高流量生产；面板标明计数为估算。
- `purge`、`flush`、`warmup` 系统事件始终完整记录。

- 原始事件保留 30 天，小时 rollup 保留 365 天，均可配置。
- `page_cache_stats_rollup` 每小时聚合，`page_cache_stats_cleanup` 每天清理。
- 静态文件、反向代理和 CDN 命中不经过 PHP，不计入面板。

## 服务器端动态块

local 命中时，显式标记的 leaf block 可以在服务器端重建，不需要生成完整 layout。

- 配置 `System > Configuration > Page Cache > Dynamic Blocks` 的 `Render Server-side Blocks on Local Hits`，并在 `Server-side Block Types` 填写允许的 block type。
- block 同时设置 `page_cache_dynamic=1` 和 `page_cache_dynamic_server=1`。
- 保存时保留匿名 HTML 作为 fallback，命中时用 live HTML 替换标记段。
- proxy/revalidating 继续 Ajax/ESI；static 文件只保留匿名 fallback。
- 重建失败、token 无效或启动 session 时回退匿名 HTML，不返回 500。

## 定时预热

默认关闭。开启 `System > Configuration > Page Cache > Warmup` 后，tag 失效会把受影响 URL 写入 `page_cache_warmup_queue`，`page_cache_warmup` cron 每分钟按批次消费。预热只请求 store base URL 或 `page_cache/warmup/allowed_hosts` 中显式列出的 origin；不跟随重定向，basic auth 只发送到这些 origin。

- 队列按 URL 去重，有上限；超过上限时淘汰最旧行。
- 404、redirect、bypass、401/403 和其他永久 4xx 跳过；429、5xx 和网络错误重试，超过次数后移出。
- 支持批次大小、并发、批次间隔、最大尝试次数、重试间隔和 basic auth。
- `page-cache:status` 显示队列长度和最近汇总；`page-cache:warm --queue` 手工补跑。
- 全量失效清空队列，不自动全站预热；全站预热继续用 `--sitemap`。

## CLI

```bash
./maho page-cache:status
./maho page-cache:monitor --format=prometheus
./maho page-cache:monitor --format=json --check
./maho page-cache:purge --dry-run
./maho page-cache:purge --limit=100
./maho page-cache:purge --tag=catalog_product_123
./maho page-cache:purge --target=proxy --retry-failed
./maho page-cache:purge:status
./maho page-cache:purge:status --check
./maho page-cache:warm --sitemap --limit=100
./maho page-cache:warm --queue --dry-run
./maho page-cache:warm --queue --limit=100
./maho page-cache:warm --url=https://example.com/ --url=https://example.com/category.html
```

## 测试

```bash
vendor/bin/pest tests/Backend/Unit/PageCache
```

当前 247 个测试通过。

## 许可证

代码遵循 Maho 仓库的 OSL-3.0/AFL-3.0 许可。设计参考的第三方项目是 GPL-3.0，不复制其代码。
