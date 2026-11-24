# Maho_PageCache

Maho 的 HTTP 页面缓存集成层。第一期支持 `proxy` 和 `revalidating` 两种模式，第二期增加 `local` 模式，把整页 HTML 存进 Maho 的 Symfony Cache，第三期增加静态文件导出、统计面板、定时预热和服务器端动态块占位符。

## 状态

0.5.2 已交付，累计包含第一期的 HTTP 集成层、proxy/revalidating 两种模式、session skip、tag 失效、purge 队列、配置和 CLI，第二期的 `local` 模式、Ajax/ESI 动态块和 `page-cache:warm`，第三期的静态文件导出、统计面板、定时预热和服务器端动态块占位符，以及 0.5.1 的 cache integrity 修复和 0.5.2 的 tag 索引重构。

- 第一期：HTTP 集成层，`proxy` + `revalidating`。0.2.0 已交付。
- 第二期：`local` 模式、整页 HTML 缓存、Ajax/ESI 动态块、缓存预热。0.2.0 已交付。
- local 模式只缓存无 session cookie 的匿名页面；被动浏览不会建立 session。动态块打孔用于 proxy/revalidating 模式；local 模式缓存匿名渲染，显式 opt-in 的 leaf block 可以在命中时服务器端重建。form key 在用户提交表单时才获取。
- 第三期：静态文件导出、统计面板、定时预热、服务器端占位符、代理模型、多节点 purge。静态导出和统计面板 0.3.0 已交付；定时预热 0.4.0 已交付；服务器端动态块占位符 0.5.0 已交付；代理模型和多节点只登记。

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
- `.claude/rules/`：模块级防漂移规则。

## 设计来源

模块参考 RockSolid_PageCache 和 Cm_Diehard 的思路，但不复制代码、不引入它们的依赖：

- 从 Diehard 借鉴后端分层和 `revalidating` 的 ETag/304 策略。
- 从 RockSolid 借鉴实体 tag 失效和 context 缓存键。
- 动态块和代理模型分别放到第二期和第三期。

## 安装

模块位于 `app/code/local/Maho/PageCache`，并附带 `app/etc/modules/Maho_PageCache.xml`。安装步骤：

1. `composer dump-autoload`，让类映射、路由和观察者属性生效。
2. `./maho migrate`，创建统计表、预热队列、URL 索引表 `page_cache_url`/`page_cache_url_tag`，并写入 `page_cache` 缓存类型选项。
3. `./maho cache:flush`。
4. 在 `System > Configuration > Page Cache` 开启并选择模式。
5. 配置反向代理或 CDN：遵守 `Vary: Accept-Encoding, Cookie`，按 URL 和 `Cookie` 区分缓存对象，并在 `Cookie` 含 `maho_session` 时 bypass；消费 purge 文件或监听 `maho_page_cache_purge_urls` 事件。`X-Maho-Cache-Context` 只用于调试。purge 文件中的 `*` 表示全量 purge。使用 ESI 时，代理还必须解析 `esi:include` 并禁止缓存 `X-Maho-Cache: DYNAMIC` 响应。
6. 运行 `./maho page-cache:status` 检查。
7. 如果后台主题还没有统计面板使用的 Tailwind 类，运行 `./maho dev:admin:theme:build` 重新编译。

0.4.0 会创建统计表和预热队列表；0.5.2 会创建 URL 索引表。升级到 0.5.2 后运行 `./maho migrate` 和 `./maho cache:flush`；旧 `PC_TAG_*` 索引不再读取。不新增 Composer 依赖。模块默认关闭，先在后台开启。

## 配置

```text
System > Configuration > Page Cache
```

主要字段：开关、模式（`proxy`、`revalidating`、`local`）、TTL、local HTML 大小上限、可缓存路由、允许的 query 参数、路径段数上限、分层导航标记和深度、是否遵守 no-cache、调试头、session skip 路由、动态块注入方式、动态块 TTL、服务器端动态块开关和类型白名单、预热开关/批次/并发/间隔/重试/队列上限/basic auth、静态文件导出、统计开关和保留期。

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
2. Web 服务器在 `Cookie` 含 `maho_session` 或有 query 参数时回 PHP。
3. 缓存目录必须 deny 直接访问。
4. `page_cache_static_cleanup` cron 每 5 分钟清理超过 TTL 的文件。
5. 第一版只支持单节点；多节点共享存储留到 FR-19。

## 统计面板

`Reports > Page Cache` 显示 PHP 侧 hit rate、平均耗时、trend、top missed URL 和最近 flush/purge/warmup。默认关闭；在 `System > Configuration > Page Cache > Statistics` 开启。

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

默认关闭。开启 `System > Configuration > Page Cache > Warmup` 后，tag 失效会把受影响 URL 写入 `page_cache_warmup_queue`，`page_cache_warmup` cron 每分钟按批次消费。

- 队列按 URL 去重，有上限；超过上限时淘汰最旧行。
- 404、redirect、bypass、401/403 和其他永久 4xx 跳过；429、5xx 和网络错误重试，超过次数后移出。
- 支持批次大小、并发、批次间隔、最大尝试次数、重试间隔和 basic auth。
- `page-cache:status` 显示队列长度和最近汇总；`page-cache:warm --queue` 手工补跑。
- 全量失效清空队列，不自动全站预热；全站预热继续用 `--sitemap`。

## CLI

```bash
./maho page-cache:status
./maho page-cache:purge --dry-run
./maho page-cache:purge --limit=100
./maho page-cache:purge --tag=catalog_product_123
./maho page-cache:warm --sitemap --limit=100
./maho page-cache:warm --queue --dry-run
./maho page-cache:warm --queue --limit=100
./maho page-cache:warm --url=https://example.com/ --url=https://example.com/category.html
```

## 测试

```bash
vendor/bin/pest tests/Backend/Unit/PageCache
```

当前 173 个测试通过。

## 许可证

代码遵循 Maho 仓库的 OSL-3.0/AFL-3.0 许可。设计参考的第三方项目是 GPL-3.0，不复制其代码。
