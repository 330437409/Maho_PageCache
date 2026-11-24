# 产品需求文档（PRD）：Maho 页面缓存

> 状态：0.5.5 已交付，第一期和第二期完成；第三期静态导出、统计面板、定时预热和服务器端占位符已交付；0.5.1 cache integrity 修复、0.5.2 tag 索引重构、0.5.3 P1 加固与性能优化、0.5.4 stock pending 监控和 0.5.5 监控指标导出与后台报表已交付
> 模块：`Maho_PageCache`
> 代码路径：`app/code/local/Maho/PageCache`
> 关联 spec：`docs/specs/page-cache.md`

## 1. 背景与目标

Maho 目前有 Symfony Cache 对象缓存（file/redis），但没有内置的 HTTP 页面缓存。每个匿名 GET 仍然要启动 Maho、路由、生成 layout、实例化并渲染 block。高流量店铺的商品页、分类页和 CMS 页会反复做同样的工作。

本模块的目标是给 Maho 补一层页面缓存，按三期交付：

- 第一期：HTTP 缓存集成层。设置 `Cache-Control`、`ETag`/`Last-Modified`、`Vary` 和调试头；匿名 GET 跳过 session；按实体 tag、规则和配置失效；支持 `proxy` 和 `revalidating` 两种模式；提供最小后台配置和 CLI。
- 第二期：`local` 模式。用 Symfony Cache 在 PHP 内保存整页 HTML，支持并发保护和缓存预热；用 Ajax 或 ESI 补动态块。
- 第三期：服务器端占位符打孔和 product/category/CMS 代理模型；命中率面板和多节点 purge 广播；可选静态文件导出、定时预热。

模块不替换 `Mage_Core_Model_Cache`，不引入 Zend_Cache，不复制 RockSolid 或 Cm_Diehard 的代码。它们只作为设计参考。

### 非目标

第一期明确不做：

- PHP 内整页 HTML 缓存，留给第二期。
- Ajax/ESI 动态块注入，留给第二期。
- 服务器端占位符打孔和模型代理，留给第三期。
- 缓存预热，留给第二期。
- 命中率后台网格，留给第三期。
- 具体反向代理的 purge 适配器（Varnish、Cloudflare、Fastly）。第一期只定义通用 purge 队列、CLI 和事件契约。
- 缓存 API 响应、后台页面、checkout、customer account。
- 修改 Maho 核心表结构。
- 引入新的 Composer 依赖。

## 2. 角色

| 角色 | 关注点 |
|---|---|
| 店铺管理员 | 开启页面缓存，设置 TTL、可缓存路由和 bypass 规则 |
| 运维/系统管理员 | 配置 Redis、反向代理、CDN，消费 purge 队列 |
| 开发者 | 新增可缓存路由，标记动态块，排查 hit/miss/bypass 原因 |
| Maho 运行时 | 继续使用现有 Symfony Cache 和 session 机制 |

## 3. 用户故事

- 作为店铺管理员，我希望匿名访问商品页、分类页和 CMS 页时返回可缓存的 HTTP 头，以便反向代理或 CDN 直接命中。
- 作为店铺管理员，我希望这些页面不启动 session、不发送 `Set-Cookie`，以便缓存不会被 session 污染。
- 作为运维，我希望 CDN 可以用 ETag 或 Last-Modified 做条件请求，命中时 Maho 只返回 304。
- 作为运维，我希望商品、分类、CMS 内容变化时能按 tag 失效，而不是每次全量清缓存。
- 作为运维，我希望规则和配置变化时不会漏页面；catalog rule 尽量细粒度，其他规则和配置全量失效。
- 作为开发者，我希望响应头告诉我这次请求是 HIT、MISS 还是 BYPASS，以及 bypass 原因。
- 作为开发者，我希望通过 CLI 查看缓存状态、处理 purge 队列，便于脚本和 cron 使用。

## 4. 功能需求（FR）

### 4.1 第一期（0.2.0 交付）

| 编号 | 需求 | 优先级 | 状态 |
|---|---|---|---|
| FR-01 | 页面缓存配置：开关、模式（`proxy`/`revalidating`）、TTL、可缓存路由、允许的 query 参数、bypass 规则、调试头开关 | P1 | 已交付@v0.2.0 |
| FR-02 | 匿名 GET session skip：可缓存路由在没有 Maho session cookie 时跳过 session，不返回会话 `Set-Cookie` | P1 | 已交付@v0.2.0 |
| FR-03 | 上下文与 HTTP 头：按 store、货币、客户组、税、query 参数生成 context hash；设置 `Cache-Control`、`Vary`、`X-Maho-Cache-*` | P1 | 已交付@v0.2.0 |
| FR-04 | `revalidating` 模式：设置 `ETag`；请求带 `If-None-Match` 且版本未变时返回 304，不渲染页面；`Last-Modified` 仅作为响应头 | P1 | 已交付@v0.2.0 |
| FR-05 | `proxy` 模式：设置反向代理可缓存的 `Cache-Control` 和 `X-Cache-Tags`；维护持久化的 tag 到 URL 索引；tag 失效时写入 purge 队列 | P1 | 已交付@v0.2.0 |
| FR-06 | 实体 tag 失效：product、category、CMS page、CMS block 的 save/delete 按 `getCacheIdTags()` 清理相关页面 | P1 | 已交付@v0.2.0 |
| FR-07 | 规则和配置失效：catalog rule 按受影响产品和分类细粒度失效；stock 状态变化和批量属性更新按 product tag 失效；sales rule、配置、主题、layout、全量缓存清理时全量失效 | P1 | 已交付@v0.2.0 |
| FR-08 | Bypass 策略与调试头：非 GET、admin、非白名单路由、未知 query 参数、路径过深、分层导航过深、有 session cookie、预览、维护模式、非默认上下文时 bypass，并输出原因 | P1 | 已交付@v0.2.0 |
| FR-09 | CLI：`page-cache:status` 显示模式、TTL、版本、队列长度和 PHP 侧计数；`page-cache:purge` 处理 purge 队列并触发 purge 事件或写 purge 文件 | P1 | 已交付@v0.2.0 |
| FR-10 | PHP 侧命中计数：记录 hit、miss、bypass、revalidated、304 的数量，供状态命令和后续面板使用；代理侧命中数不在第一期统计 | P1 | 已交付@v0.2.0 |

### 4.2 第二期（0.2.0 交付）

| 编号 | 需求 | 优先级 | 状态 |
|---|---|---|---|
| FR-11 | `local` 模式：用 Symfony Cache 在 PHP 内保存整页 HTML，支持 TTL、tag 失效、并发保护和上下文 key | P2 | 已交付@v0.2.0 |
| FR-12 | 动态块打孔：Ajax 和 ESI 两种注入；动态块白名单；form key 和 SID 处理；首次 POST 建立 session 的行为 | P2 | 已交付@v0.2.0 |
| FR-13 | 缓存预热 CLI：按 sitemap 或 URL 列表手工预热；第二期交付 CLI，不提供独立后台按钮；后台调度配置属于 FR-18 | P2 | 已交付@v0.2.0 |

### 4.3 第三期（0.3.0 交付静态导出和统计面板，0.4.0 交付定时预热，0.5.0 交付服务器端占位符）

| 编号 | 需求 | 优先级 | 状态 |
|---|---|---|---|
| FR-14 | 服务器端占位符打孔：缓存页面保存 block 占位符，命中时只渲染动态块 | P3 | 已交付@v0.5.0 |
| FR-15 | 代理模型：product、category、CMS 的懒加载代理，减少动态块渲染成本 | P3 | 计划 |
| FR-16 | 统计面板：DB 事件表、小时 rollup、后台面板，按路由/store/mode 统计 hit/miss/bypass/revalidated、PHP 耗时、top missed URL 和 flush 记录 | P3 | 已交付@v0.3.0 |
| FR-17 | 静态文件导出：把匿名默认页的 local HTML 额外写成 path-based `.html`/`.gz`，由 Web 服务器直接返回；公共路径映射；允许参数变体继续走 PHP；严格 session cookie bypass；默认关闭，必须 deny 缓存目录 | P3 | 已交付@v0.3.0 |
| FR-18 | 定时预热：后台 Warmup 配置组、purge 后 requeue、cron 分批预热、sitemap/URL、并发、限速、basic auth、重试和失败汇总；手工触发仍用 CLI | P3 | 已交付@v0.4.0 |
| FR-19 | 多节点：purge 广播、Redis 共享版本、静态文件共享存储 | P3 | 计划 |
| FR-20 | session cookie 安全命中：默认关闭的 opt-in，仅 local 模式对购物车为空、未登录、无消息且渲染不写 session 的匿名请求做短 TTL 缓存；proxy/revalidating 保持 bypass；需 ADR-0019 安全评审 | P2 | 暂停（等待 session 只读 API） |

> 状态取值：计划 / 开发中 / 已交付@vX.Y.Z / 暂停。第一期和第二期已交付 0.2.0；第三期静态导出和统计面板已交付 0.3.0；定时预热已交付 0.4.0；服务器端动态块占位符已交付 0.5.0；其余只登记。静态文件导出、统计面板和定时预热分别见 ADR-0012、ADR-0013、ADR-0014。第三期拆解见 `.tmp/brainstorm-phase3-2026-11-23.md`。

## 5. 非功能需求（NFR）

| 编号 | 约束 | 目标 |
|---|---|---|
| NFR-01 | 平台兼容 | PHP 8.5，Maho 26.11+，MySQL、MariaDB、PostgreSQL、SQLite 均可运行 |
| NFR-02 | 缓存后端 | 使用 Maho 的 Symfony Cache（file/redis），不引入 Zend_Cache、不新增 Composer 包 |
| NFR-03 | 安全 | 默认不缓存任何可能包含客户、购物车、价格或地址信息的页面；不缓存 admin、checkout、customer account、POST |
| NFR-04 | 隐私 | 缓存 key 和 purge 文件不包含邮箱、姓名、地址等 PII；只使用 hash 和 tag |
| NFR-05 | 正确性 | 缓存失效宁可多清，不可漏清；索引丢失时回退全量失效 |
| NFR-06 | 可观测 | 每个 PHP 处理的请求带 `X-Maho-Cache` 结果；bypass 原因可查；purge 有日志 |
| NFR-07 | 可维护性 | `declare(strict_types=1)`、SPDX 头、typed properties、单元和集成测试 |
| NFR-08 | 兼容性 | 不替换 `Mage_Core_Model_Cache`，不改变现有缓存类型的行为 |
| NFR-09 | 性能 | 第一期命中路径只做 header 和版本比较；不因缓存检查增加明显 DB 查询 |
| NFR-10 | 升级安全 | 不改 Maho 核心表结构；第一期不建新表；缓存索引可重建 |

## 6. 验收标准

### 6.1 第一期

#### AC-01 配置与模式

- 后台 `System > Configuration > Page Cache` 有开关、模式、TTL、可缓存路由、允许参数、调试头开关。
- 关闭模块时，不改变任何响应头，不跳过 session。
- 模式为 `proxy` 或 `revalidating` 时，匿名 GET 返回对应头。
- 非法模式回退为关闭，并在后台显示可读错误。

#### AC-02 匿名 GET session skip

- 配置的可缓存路由，在没有 Maho session cookie 时，不启动 session，响应没有 Maho 的会话 `Set-Cookie`。
- 请求带 Maho session cookie 时不跳过 session，并标记 bypass 原因 `session_cookie`。
- POST、admin、checkout、customer account、预览、维护模式不跳过 session。
- 跳过 session 的页面第一次 POST 可能没有 form key；该行为在文档中说明，并可通过 bypass 路由配置规避。

#### AC-03 上下文与 HTTP 头

- `proxy` 模式返回 `Cache-Control: public, s-maxage=<ttl>, max-age=0`。
- `revalidating` 模式返回 `Cache-Control: public, max-age=0, must-revalidate`。
- 两种模式都返回 `Vary: Accept-Encoding, Cookie`。
- `X-Maho-Cache` 的值为 `MISS`、`BYPASS` 或 `REVALIDATED`；`HIT` 留给第二期 local 模式或代理自己回传的命中头。
- `X-Maho-Cache-Bypass` 在 bypass 时给出稳定原因码。
- 调试头关闭时，不返回 `X-Maho-Cache-Tags` 和详细 bypass 原因。
- `proxy` 模式必须遵守 `Vary: Accept-Encoding, Cookie`，按 URL 和 `Cookie` 区分缓存对象；`X-Maho-Cache-Context` 只用于调试。代理忽略 `Vary` 时，多 store/context 站点不得启用 proxy 模式。

#### AC-04 revalidating 304

- 第一次 200 响应带 `ETag`，版本包含全局版本、URL 版本、URL、context 和 TTL。
- 第二次请求带相同 `If-None-Match` 时返回 304，响应体为空，不生成 layout。
- 实体保存或全量失效后，相同 `If-None-Match` 返回 200 和新 `ETag`。
- 304 只由 `If-None-Match` 触发；`Last-Modified` 仍返回，但 `If-Modified-Since` 不单独触发 304，避免同一秒内内容变化后返回旧内容。
- 没有缓存元数据或版本索引丢失时回退 200，不返回错误的 304。
- URL 版本键被淘汰但元数据仍在时，旧 ETag 返回 200，不返回错误的 304。

#### AC-05 proxy 模式与 purge

- 第一次响应带 `Cache-Control` 和 `X-Cache-Tags`。
- tag 失效时，原生 cache tag 清理页面，URL 索引提供相关 URL 写入 purge 队列。
- `page-cache:purge --dry-run` 列出待处理 URL，不删除队列。
- `page-cache:purge` 触发 `maho_page_cache_purge_urls` 事件，并写入 `var/page-cache/purge-<timestamp>.txt`。
- purge 成功后队列条目删除；失败时保留并记录错误。
- 索引丢失时全量失效，不静默漏页面。
- 全量失效写入 `*` 全量 purge 标记，代理消费者清空该模式的全部对象；全局版本严格递增，不重置或复用。

#### AC-06 实体 tag 失效

- 保存或删除 product、category、CMS page、CMS block 后，相关 tag 被清理。
- product 保存同时清理它所属分类的 tag。
- stock 状态变化清理 product tag；qty-only 更新不清理页面。
- 批量属性更新按 product tag 失效；超过 30 个商品回退全量失效。
- 相同实体重复保存不会产生重复 tag 或重复 purge 条目。
- 缓存后端不可用时，保存流程不报错，记录日志。

#### AC-07 规则和配置失效

- catalog rule 保存或删除时，按 `getMatchingProductIds()` 得到的产品和分类 tag 细粒度失效。
- sales rule 保存或删除时全量失效。
- `core_config_data`、主题/design、layout 变更时全量失效。
- 后台 `Flush Cache Storage`、`Flush Magento Cache` 时全量失效。
- 规则无法计算受影响产品时回退全量失效。

#### AC-08 Bypass 与调试

- 非 GET、admin、非白名单路由、未知 query 参数、路径段数超过上限、分层导航深度超过上限、有 session cookie、预览、维护模式均 bypass。
- 非默认 store/货币/客户组/税上下文按配置 bypass 或生成独立 context；`context_mode=default` 时，带 `___store` cookie 的 store 切换请求 bypass。
- 移动主题、A-B、geo-IP 模块通过 `page_cache_context_variant` 标记变体；proxy 模式 bypass，local 模式进入 context key。
- bypass 响应不被写入 URL 索引，也不产生 purge 条目。
- `X-Maho-Cache-Bypass` 原因码稳定、可搜索，不包含 PII。

#### AC-09 CLI 与计数

- `page-cache:status` 显示模式、TTL、全局版本、队列长度和 PHP 侧计数。
- `page-cache:purge` 支持 `--dry-run`、`--limit`、`--tag`。
- 计数只统计 PHP 处理的请求；代理直接命中的请求不计入，文档中说明。
- 缓存后端不支持原子计数时，计数为近似值，不阻塞请求。
- `miss`、`bypass`、`revalidated` 的计数契约与 API 文档一致；304 计入 `revalidated`，代理命中不计入 PHP 侧。

#### AC-10 验证门

- 新增单元测试和集成测试通过。
- 相关 PHPStan、php-cs-fixer、Rector 检查通过。
- `curl` 手工验证至少一个已有数据页的 200、304、bypass 和 purge；分类/商品页有数据时复验。
- 保存 product/category/CMS 后验证 tag 失效。
- 不改 Maho 核心表结构，不新增 Composer 依赖。

### 6.2 第二期和第三期

- 第二期：`local` 模式命中时不再生成 layout；动态块只渲染白名单 block；form key 和 SID 正确；并发请求只有一个回源。0.2.0 已交付。
- 第三期：服务器端占位符只渲染动态块；代理模型不产生 N+1；多节点 purge 广播可验证；后台面板显示 hit/miss/bypass 和队列状态。静态文件导出、统计面板和定时预热分别见 ADR-0012、ADR-0013、ADR-0014。

### 6.3 第三期验收草稿

#### AC-11 静态文件导出（FR-17）

- 匿名默认页在 local 写入成功后生成 path-based `.html` 和 `.gz`。
- 静态路径使用浏览器公共路径；路由改写后的 `/page-cache-test/` 写成 `page-cache-test/index.html`，不是内部路由路径。
- nginx/Caddy 直接返回静态文件时 PHP 不启动。Caddy 示例带 `X-Page-Cache: HIT-static`；nginx 以 PHP 不启动和没有 `X-Maho-Cache` 为准。
- 请求带 `maho_session` 或允许参数时回 PHP，分别得到 bypass 或 local 命中。
- 保存 product/category/CMS 后，相关静态文件被删除；全量 flush 和后台刷新 `page_cache` 类型清空静态目录。
- `page_cache_static_cleanup` cron 删除超过 `ttl + 300` 的静态文件。
- `.gz` 文件存在并被 Web 服务器使用。
- 直接访问 `/var/page-cache` 被拒绝。
- 静态写入失败不影响页面响应。
- 第一版单节点；多节点共享存储留到 FR-19。

#### AC-12 统计面板（FR-16）

- 统计表记录 hit/miss/bypass/revalidated、route、store、mode、PHP 耗时、flush/purge/warmup 原因。
- 小时 rollup 生效，原始数据按保留天数清理。
- 一级菜单 `Page Cache` 使用 Overview、Trend、Top Missed URLs、Recent Events 四个标准报表 grid，显示 hit rate、PHP 耗时、趋势、top missed URL 和最近 flush；ACL 保留 `admin/report/page_cache`，页面提供 `Warm Now` 和 `Configuration` 按钮。
- admin 前台请求不记录，避免 secret key 进入统计。
- 统计写入失败不影响页面响应，不记录完整 query 或 PII。

#### AC-13 定时预热（FR-18）

- 后台 `System > Configuration > Page Cache > Warmup` 提供 `Enable Scheduled Warmup`、并发、批次、延迟、重试、队列上限和 basic auth。
- 不提供手工预热按钮；手工触发通过 `./maho page-cache:warm`，后台只控制调度和队列参数。
- tag/URL 失效后，受影响 URL 进入预热队列。
- cron 按批次和并发消费队列，预热后 local/静态命中。
- 队列有上限，404/redirect/非 200 跳过，失败按次数重试后移出。
- 支持 sitemap、显式 URL、basic auth、限速和失败汇总。
- 不发送 session cookie。

#### AC-14 服务器端占位符（FR-14）

- 缓存页保存 block 定义，命中时只渲染白名单动态块，不生成完整 layout。
- token/context/store 校验通过，不返回用户数据。
- 动态块渲染不触发整页 layout 事件。
- 动态请求只允许 GET 和 `cacheable_routes` 中的路由；其他路由按正常 bypass 处理。
- 动态请求按客户端限流，超限返回 429 和 `rate_limit` bypass。
- token 过期时间不短于页面 TTL，URL 使用 store base URL 的 scheme/host。

#### AC-15 代理模型（FR-15）

- product/category/CMS 代理按当前 store/context 加载。
- 动态块渲染不产生 N+1。
- 实体失效后代理数据不陈旧。

#### AC-16 多节点（FR-19）

- A 节点失效后 B 节点不再命中旧版本。
- 静态文件通过共享存储或节点级预热保持一致。
- 全局版本严格递增，purge 广播可验证。

#### AC-17 session cookie 安全命中（FR-20）

- 默认关闭；只有显式 opt-in 且模式为 `local` 时才可能命中。
- proxy 和 revalidating 对任何 Maho session cookie 继续 bypass。
- 购物车非空、已登录、有未读消息、渲染写 session 或返回 `Set-Cookie` 时 bypass。
- HTML key 包含 session id 或等价会话分区值，跨 session 不共享。
- 命中响应不含 PII，form key/SID 和动态块继续使用占位符。
- kill switch、短 TTL、命中计数和测试齐备；任一条件无法确认时继续 bypass。

## 7. 风险与待定

- 反向代理对自定义 `Vary` 的支持不一致。第一期在代理不遵守 `X-Maho-Cache-Context` 时 bypass 非默认上下文。
- 代理侧命中数无法由 PHP 统计。第一期只统计 PHP 侧，代理统计留给外部监控或第三期。
- 跳过 session 后页面里的 form key 没有绑定真实 session。第二期用占位符和动态请求替换 form key/SID；没有 JavaScript 的客户端无法提交这些表单。
- URL 索引存 DB，缓存淘汰不影响索引；DB 异常时回退全量失效，不返回错误内容。
- catalog rule 的受影响商品计算可能很重。第一期在保存时计算，必要时异步化。
- 多 store、多货币、客户组、税的 context 组合会降低命中率。第一期先保证正确性，再逐步放宽。
- 许可证：只参考 RockSolid 和 Cm_Diehard 的设计，不复制 GPL 代码。

## 8. 术语

- **页面缓存**：对完整 HTML 响应做 HTTP 层缓存，不等同于 block HTML 缓存。
- **proxy 模式**：由反向代理或 CDN 存 HTML，Maho 设置头并维护 purge 队列。
- **revalidating 模式**：代理存 HTML，但每次请求回源做 ETag/Last-Modified 校验，304 表示未变。
- **local 模式**：Maho 自己在 Symfony Cache 里存整页 HTML，第二期 2A 实现。
- **context**：store、货币、客户组、税、query 参数等影响页面内容的维度。
- **tag**：实体或页面分类的失效标签，例如 `catalog_product_123`。
- **purge**：让反向代理或 CDN 丢弃某个 URL 的缓存。
