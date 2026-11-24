# 功能规格：Maho 页面缓存第二期

> 状态：已交付@v0.2.0
> 关联 PRD：FR-11、FR-12、FR-13
> 关联 ADR：ADR-0002、ADR-0003、ADR-0007、ADR-0008、ADR-0009、ADR-0010、ADR-0011
> 分支：`feat/page-cache-phase2`

## 1. 背景与目标

第一期交付了 HTTP 集成层：`proxy` 和 `revalidating` 模式、session skip、ETag/304、tag 失效、purge 队列、CLI 和后台配置。第一期**不在 PHP 内保存 HTML**，所以没有反向代理的环境仍然每次生成 layout。

第二期补三件事：

- `local` 模式：用 Maho 的 Symfony Cache 保存整页 HTML，命中时在 layout 生成前直接返回。
- 动态块：缓存页面里的购物车、登录状态、消息等块用 Ajax 或 ESI 补洞。
- 缓存预热：按 sitemap、URL 列表或路由批量填充缓存。

第二期仍不做服务器端占位符和 product/category/CMS 代理模型，那些属于第三期。

## 2. 需求

### 2.1 范围内

- `local` 整页 HTML 缓存，TTL、tag/版本失效、context key、并发保护。
- local 命中时的 200 和条件请求 304。
- 动态块白名单和占位符。
- Ajax 动态块请求，返回当前页面里被标记为动态的 block。
- ESI 动态块 URL，供 proxy/revalidating 模式的反向代理拼接。
- form key 和 SID 的填充，保证缓存页面的第一次 POST 可以工作。
- `page-cache:warm`，支持 sitemap、URL 列表和数量限制。
- 配置：`local` 模式、动态块注入方式、动态块 TTL、预热选项。
- 测试：local 命中不生成 layout、动态块、form key、并发、预热、失效。

### 2.2 范围外

- 服务器端占位符和命中时重建 block。
- product/category/CMS 代理模型。
- 命中率后台网格。
- 多节点 purge 广播。
- 新数据库表。
- 具体代理的 purge 适配器。

## 3. 设计

### 3.1 运行流程

请求进入 `controller_action_predispatch_session_start`：

1. Phase 1 的 `Service\Request` 判断是否可缓存。
2. 可缓存时跳过 session。
3. 请求是动态块请求时，标记为 dynamic bypass，继续走正常 layout。
4. 请求是普通 GET 时，`local` 模式检查 HTML 缓存。
5. 命中时直接输出 HTML，设置 `X-Maho-Cache: HIT`，`FLAG_NO_DISPATCH`。
6. 未命中时走正常 layout，响应阶段保存 HTML。

`local` 命中不生成 layout。`proxy`/`revalidating` 继续由代理缓存，Phase 1 逻辑不变。

### 3.2 local HTML 存储

新增缓存条目：

| Key | 内容 | TTL |
|---|---|---|
| `PC_HTML_<hash>` | `{html, headers, tags, version, context, created_at}` | TTL |
| `PC_HTML_INDEX_<urlhash>` | 该 URL 的 context hash 列表 | TTL + 1 小时 |

`hash = sha256(urlHash . contextHash . urlVersion . globalVersion . mode)`。

- 只用 `Mage::app()->getCache()`，不新增后端、不新增表。
- HTML 作为字符串保存；第二期不做压缩。
- 保存时同时写 `PAGE_CACHE` 和 `PAGE_CACHE_HTML` tag。
- `page_cache` 缓存类型清理会同时清 HTML 和元数据。
- 条目大小上限由配置控制，默认 2 MB；超过上限不缓存，返回 `X-Maho-Cache: BYPASS` 原因 `html_too_large`。

### 3.3 local 命中

监听 `controller_action_predispatch`：

1. 只在 `local` 模式执行。
2. 请求必须可缓存，且不是动态块请求。
3. 读取 `PC_HTML_<hash>`。
4. 命中时设置响应 body、`Cache-Control: private, max-age=0, must-revalidate`、`ETag`、`X-Maho-Cache: HIT`、`X-Maho-Cache-Mode: local`。
5. `If-None-Match` 匹配时返回 304，body 为空。
6. 设置 `FLAG_NO_DISPATCH`，不生成 layout。
7. 没有命中时继续正常流程，响应阶段保存。

`local` 模式不使用 `Vary: Cookie` 作为服务端 key；HTML 只缓存匿名默认 context。浏览器私有缓存可以保留页面，但每次重新验证。

### 3.4 失效和版本

复用第一期：

- 实体/catalog rule/配置失效继续更新 `PC_META_<urlhash>.version` 和 `PC_VERSION_URL_<urlhash>`。
- HTML key 包含 `urlVersion` 和 `globalVersion`，版本变化后旧 HTML 不再命中。
- 旧 HTML 条目靠 TTL 过期；第二期不遍历删除所有 context 的 HTML。
- 全量失效递增全局版本并清理 `page_cache` 类型，HTML 一起清理。
- `PC_HTML_INDEX_<urlhash>` 用于后续可选清理和观测，不作为正确性依赖。

### 3.5 并发保护

- 每个 HTML key 使用 core 的 named lock，锁名 `page_cache_html_<hash>`。
- 未命中时尝试加锁，最多等待 5 秒。
- 拿到锁的请求渲染并保存；等待的请求在锁释放后重试读取缓存。
- 等待超时后正常渲染，但不保存，返回 `X-Maho-Cache: BYPASS` 原因 `lock_timeout`。
- 锁在请求结束或异常时释放。

### 3.6 动态块白名单

block 必须在 layout 里显式标记：

```xml
<reference name="header">
    <action method="setData"><key>page_cache_dynamic</key><value>1</value></action>
</reference>
```

`core_block_abstract_to_html_after` 观察者：

- 只有 `getData('page_cache_dynamic')` 为真的 block 才处理。
- 未标记的 block 原样进入缓存 HTML。
- 标记的 block 换成占位符，不把用户数据写进缓存。

默认不标记任何 block。主题或模块必须显式标记购物车、登录状态、消息、form key 等块。

### 3.7 占位符和动态请求

占位符：

```html
<!--page-cache-dynamic:<hex-token>:<hex-block-name>-->
```

`signed-token` 是 base64url 的 JSON + HMAC：

```json
{
  "v": 1,
  "block": "header.cart",
  "url": "https://example.com/category.html",
  "store": 1,
  "context": "<hash>",
  "exp": 1234567890
}
```

动态请求：

- Ajax：前端 `mahoFetch` 请求同一页面 URL，带 `X-Maho-Page-Cache: dynamic` 和 `X-Maho-Page-Cache-Blocks: <token>`。
- ESI：代理请求同一页面 URL，带 `?_page_cache_dynamic=<token>`；模块返回该 block 的 HTML，不是 JSON。
- 模块识别请求后，走正常 layout，但只返回被请求且在当前页面标记为 dynamic 的 block。
- 返回 `Cache-Control: private, no-store`、`X-Maho-Cache: DYNAMIC`。
- token 的 HMAC 使用 `local.xml` 的 crypt key；过期时间默认 1 小时。
- block 名称/类型/参数来自当前页面的 layout，不接受客户端任意 block 类型。

Ajax 响应：

```json
{
  "blocks": {
    "header.cart": "<html>...</html>"
  },
  "form_key": "..."
}
```

前端用 `mahoFetch` 替换每个占位符，并把 `form_key` 写回页面里所有隐藏字段。

### 3.8 form key 和 SID

- 缓存 HTML 里的 form key 和 SID 换成 `{{PAGE_CACHE_FORM_KEY}}` / `{{PAGE_CACHE_SID}}`。
- 动态请求会启动 session（它是 bypass 请求），返回当前 `form_key`。
- 前端替换占位符后，页面的表单可以使用新 form key 提交。
- 没有动态块但页面含表单时，主题必须标记一个 dynamic block（例如 footer 的 form key block），或者在配置里把该路由排除出 `cacheable_routes`。
- 第一次 POST 仍可能发生在动态请求完成前；前端在替换完成前禁用表单提交，或显示加载状态。

### 3.9 动态块缓存

- 第二期默认不缓存动态 block HTML：它们可能依赖 session/customer/cart。
- 可选 `page_cache/dynamic/ttl`：只对显式标记 `page_cache_dynamic_cacheable` 的 block 生效，key 包含 block、页面 URL、context、customer group、store；默认 0。
- 带 session 的 block 响应 `Cache-Control: private, no-store`，代理不得缓存。

### 3.10 预热

`page-cache:warm`：

```bash
./maho page-cache:warm --sitemap
./maho page-cache:warm --url=https://example.com/ --url=https://example.com/category.html
./maho page-cache:warm --sitemap --limit=100 --concurrency=4
```

- 使用 Symfony HttpClient（Maho 已有）发公开 HTTP GET。
- 只请求 `cacheable_routes`，跳过 404、redirect、非 200。
- 不发送 session cookie，不发送 `Cache-Control: no-cache`。
- 支持 `--dry-run` 列出 URL。
- local 模式命中后填充 HTML；proxy/revalidating 模式预热代理。
- 失败 URL 汇总，命令返回非零。

### 3.11 配置

新增：

| 路径 | 默认 | 说明 |
|---|---|---|
| `page_cache/general/mode` | `revalidating` | 增加 `local` |
| `page_cache/general/html_max_size` | `2097152` | HTML 条目上限，字节 |
| `page_cache/general/filter_path_marker` | 空 | 分层导航路径标记，空表示关闭 |
| `page_cache/general/max_filter_depth` | `2` | 分层导航最大深度 |
| `page_cache/general/max_path_segments` | `8` | 最大路径段数 |
| `page_cache/dynamic/injection` | `ajax` | `ajax` 或 `esi` |
| `page_cache/dynamic/ttl` | `0` | 动态 block 缓存秒数，0 不缓存 |
| `page_cache/warmup/concurrency` | `4` | 预热并发 |

### 3.12 安全

- local HTML 只保存匿名默认 context；session cookie 请求 bypass。
- 动态 token 必须 HMAC 校验、过期、绑定的 URL/store/context 与当前请求一致。
- 动态请求只返回当前页面 layout 里标记为 dynamic 的 block，不接受任意 block 类型。
- 动态响应 `private, no-store`；代理不得缓存。
- 预热不发送认证 cookie。
- HTML 大小上限，避免缓存超大页面。
- 路径段数和分层导航深度有上限，避免 URL 变体撑爆缓存。
- 动态块占位符用注释，不破坏列表和表格结构。
- form key/SID 只通过占位符替换，不把 session 数据写入共享缓存。

## 4. 任务拆分

### 2A local 模式

- [x] `Backend\Local`、HTML key、save/load、TTL、大小上限。
- [x] `controller_action_predispatch` 命中/304/`FLAG_NO_DISPATCH`。
- [x] 响应阶段保存 HTML，`X-Maho-Cache: HIT/MISS`。
- [x] 并发锁。
- [x] 配置 `mode=local`、`html_max_size`。
- [x] 测试：命中不生成 layout、304、tag 失效、锁超时、大小上限。

### 2B 动态块

- [x] block 标记 `page_cache_dynamic`。
- [x] 占位符和签名 token。
- [x] 动态请求识别、Ajax JSON、ESI HTML。
- [x] 只返回当前页面标记的 block。
- [x] form key/SID 占位符和前端替换。
- [x] 配置 `injection`、`dynamic/ttl`。
- [x] 测试：Ajax/ESI、token 校验、过期、任意 block 拒绝、form key POST。

### 2C 预热

- [x] `page-cache:warm` CLI。
- [x] sitemap/URL 读取、并发、失败汇总。
- [x] 配置 `warmup/concurrency`。
- [x] 测试：dry-run、limit、失败 URL、非 200 跳过。

### 2A 实现记录

- 代码：`Model/HtmlCache.php`、`Model/Backend/Local.php`、`Model/Observer.php`、`Service/Response.php`。
- 缓存 key：`PC_HTML_<sha256(urlHash|contextHash|urlVersion|globalVersion|mode)>`，元数据缺失时不命中。
- 并发：named lock `page_cache_html_<hash>`，默认等待 5 秒；等待后重新读缓存。
- 版本保护：渲染期间 URL 或全局版本变化时不保存，返回 `version_changed`。
- 测试：`vendor/bin/pest tests/Backend/Unit/PageCache`，106 passed（254 assertions）。
- 手工验证：首页/product/category 首次 MISS，第二次 HIT；`If-None-Match` 返回 304；session cookie 和 `no-cache` bypass；保存 product/category 后旧 ETag 返回 200；全量 flush 后重新 MISS/HIT；6 个并发请求为 1 MISS + 5 HIT。
- 已知限制：form key/SID 替换、动态块和预热分别在 2B/2C 实现。

### 2B 实现记录

- 代码：`Service/Dynamic.php`、`Model/Observer.php`（`handleDynamicBlock`、`sendDynamicResponse`）、`Model/System/Config/Source/Injection.php`。
- block 通过 layout 的 `page_cache_dynamic=1` 标记；只有显式标记的 block 会被占位符替换。
- token 为 base64url JSON + HMAC，绑定 block、URL、store、context，默认 1 小时过期。
- Ajax 返回 `{blocks, form_key, sid}`；ESI 返回单个 block HTML；动态响应 `private, no-store`。
- 缓存 HTML 的 form key/SID 替换为占位符。local 模式在用户聚焦或提交 POST 表单时才请求 form key，避免被动浏览建立 session；proxy 模式在页面加载时请求动态块并替换。
- 占位符是 `<!--page-cache-dynamic:<hex-token>:<hex-name>-->`，在列表和表格父级里是合法节点。
- 显式标记 `page_cache_dynamic_cacheable=1` 且 TTL>0 时，动态响应按 block 集合缓存；命中时跳过 layout，form key/SID 从当前 session 生成。
- 测试：`tests/Backend/Unit/PageCache/DynamicBlockTest.php`，19 passed。
- 手工验证（proxy 模式）：首页标记 `header` 动态后，缓存页面含占位符和脚本；动态请求返回 5831 字节 header HTML、form key 和 sid；Playwright 打开页面后占位符为 0、form key 已替换、无控制台错误。
- 手工验证（local 模式）：被动打开首页不产生 session cookie，下一次分类页仍可命中；商品页点击加入购物车时脚本才获取 form key，购物车成功显示 `PC-TEST-1`。
- 手工 ESI：Varnish 7.4 + `beresp.do_esi` 拼接 `<esi:include>`，外层页面缓存命中，ESI 子请求返回 `DYNAMIC` 和 `no-store`。

### 2C 实现记录

- 代码：`Service/Warm.php`、`lib/MahoCLI/Commands/PageCacheWarm.php`。
- 支持 `--sitemap`、重复 `--url`、`--limit`、`--concurrency`、`--dry-run`。
- sitemap 支持 index 和子 sitemap；只保留与 store base URL 同 host 的 URL。
- 测试：`tests/Backend/Unit/PageCache/WarmTest.php`，5 passed。
- 手工验证：`page-cache:warm --sitemap --limit=5` 预热 5 个 URL，随后 5 个页面全部 `X-Maho-Cache: HIT`；临时 sitemap 记录和文件已删除。

### 2D 实现记录

- 代码：`Service/Invalidator.php`、`Service/Request.php`、`Service/Dynamic.php`、`Model/Observer.php`、`Helper/Data.php`。
- stock 状态变化和批量属性更新按 product tag 失效；qty-only 更新不清理页面；超过 30 个商品回退全量失效。
- 路径段数超过 `max_path_segments` 返回 `path_depth` bypass；配置 `filter_path_marker` 后，分层导航超过 `max_filter_depth` 返回 `filter_depth` bypass。
- 动态块占位符改成 HTML 注释；DOMDocument 验证放在 `<ul>` 和 `<tbody>` 中不产生非法结构。
- 测试：`RequestPolicyTest`、`InvalidatorTest`、`DynamicBlockTest`，总计 106 passed（254 assertions）。
- 手工验证：stock 状态改为无货后旧 ETag 返回 200；qty-only 更新后旧 ETag 仍为 304；批量属性更新后旧 ETag 返回 200；proxy Ajax 的注释占位符在 Playwright 中替换为 header HTML。

### 文档

- [x] 更新 PRD FR-11 到 FR-13 状态。
- [x] 更新 ARCHITECTURE、API、README、CHANGELOG。
- [x] 新增/更新 ADR-0009、ADR-0010、ADR-0011。
- [x] 登记第三期 ADR-0012、ADR-0013、ADR-0014。

## 5. 验收标准

### AC-01 local 命中

- 匿名 GET 第一次 MISS，第二次 HIT。
- HIT 不生成 layout：`controller_action_layout_generate_blocks_before` 不触发。
- HIT 返回和 MISS 相同的 HTML（除动态占位符）。
- `X-Maho-Cache: HIT`、`X-Maho-Cache-Mode: local`、`ETag`、`Cache-Control: private, max-age=0, must-revalidate`。
- `If-None-Match` 命中返回 304，body 为空。

### AC-02 local 失效

- 保存 product/category/CMS page/block 后旧 HTML 不再命中。
- catalog rule 细粒度失效，其他规则/配置全量失效。
- 全量失效清理 HTML 和元数据。
- 旧 HTML 靠 TTL 过期，不返回错误内容。

### AC-03 并发

- 同一 key 并发请求只有一个渲染并保存。
- 等待请求在锁释放后命中。
- 锁超时不保存，返回 `lock_timeout` bypass。
- 异常时释放锁。

### AC-04 动态块 Ajax

- 标记的 block 在缓存 HTML 里是占位符，不含用户数据。
- Ajax 请求返回被标记 block 的 HTML。
- 未标记/不在当前页面的 block 请求被拒绝。
- token 过期、签名错误、URL/store/context 不匹配被拒绝。
- 响应 `private, no-store`。

### AC-05 动态块 ESI

- proxy/revalidating 模式的占位符输出 ESI include。
- 代理拼接后页面完整。
- 动态响应不被代理缓存。
- 没有代理时 ESI 配置给出可读错误。

### AC-06 form key

- 缓存 HTML 不含真实 form key/SID。
- 动态请求返回当前 form key，前端替换后表单可提交。
- 第一次 POST 在替换完成后成功。
- 没有动态 block 的含表单路由被排除或文档说明。

### AC-07 预热

- `--dry-run` 只列出 URL。
- `--limit` 限制数量。
- `--concurrency` 并发执行。
- 非 200/redirect 跳过并汇总。
- local 模式预热后第二次请求 HIT。
- 失败 URL 返回非零退出码。

### AC-08 安全

- session cookie 请求不写入 local HTML。
- 动态 token 不能伪造、过期、跨 URL/store 复用。
- 动态请求不能渲染任意 block 类型。
- 预热不带认证 cookie。
- HTML 超过上限不缓存。

### AC-09 验证门

- 新增单元/集成测试通过。
- 相关 PHPStan、php-cs-fixer、Rector 通过。
- 手工 curl 验证 local 200/304、Ajax/ESI、form key、预热。
- proxy 模式用 Varnish 复验 ESI 和动态响应不缓存。

## 6. 风险与待定

### 已确认的设计点

1. **动态块实现方式**：接受“同一页面 URL + dynamic 标记请求，只返回当前页面标记的 block”，不做 RockSolid 式服务器端任意 block 重建。
2. **form key 方案**：接受动态请求返回 form key 并前端替换；没有动态 block 的含表单路由需要排除或文档说明。
3. **动态块不缓存**：接受第二期默认不缓存动态 block HTML；可选 TTL 只对显式标记的 block 生效。
4. **local 模式浏览器缓存**：接受 `Cache-Control: private, max-age=0, must-revalidate`。
5. **预热使用公开 HTTP**：接受 `page-cache:warm` 走完整 web 栈，并要求站点 URL 从 CLI 可访问。

### 其他风险

- 动态请求生成 layout，可能比静态页面慢；第三期服务器端占位符/代理模型解决。
- ESI 需要代理支持；Cloudflare 对 ESI 支持有限。
- local HTML 占缓存空间，需监控和上限。
- form key 替换依赖 JS；禁用 JS 的用户第一次 POST 可能失败。
- 动态 block 白名单配置错误会泄露购物车/用户信息；默认不标记任何 block。
