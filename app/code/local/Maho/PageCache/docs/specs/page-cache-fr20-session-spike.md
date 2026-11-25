# FR-20 session cookie 命中安全 spike

> 状态：只读 spike 完成，结论为暂停 FR-20；2026-11-25 决策为不实现，保持严格 bypass，见 ADR-0025
> 关联 FR：FR-20
> 关联 ADR：ADR-0019
> 日期：2026-11-24

## 1. 目标

只读验证：如果把带 Maho session cookie 的匿名请求纳入 local 命中，是否能在“购物车为空、未登录、无消息”时保证响应可共享且不写 session。本文不改代码。

## 2. 方法

- 静态追踪 `session_start`、session cookie、可缓存路由的 layout 和 block。
- 检查 Maho 现有的 session state 判定 `holdsState()`。
- 在开发站用 curl 创建匿名 session，观察 `Set-Cookie`、`X-Maho-Cache` 和页面差异；只读 GET，不改商品、订单或客户数据。

## 3. 关键证据

### 3.1 session 启动总会续写 cookie

`Mage_Core_Model_Session_Abstract::start()` 在启动后调用 `setSessionCookie()`，后者无条件 `set()` 当前 session id。HTTP 观察确认：

- `GET /customer/account/login/` 返回 `Set-Cookie: maho_session=...`。
- 带该 cookie 请求 `/` 仍返回同一个 `Set-Cookie`，并标记 `X-Maho-Cache-Bypass: session_cookie`。
- 无 cookie 的 `/` 是 `X-Maho-Cache: HIT`。

`Observer::sendResponse()` 对任何 `Set-Cookie` 响应直接 bypass。因此 FR-20 必须先从命中路径去掉 session cookie 续写，或证明可以安全保留，当前没有这个模式。

### 3.2 可缓存页默认包含 session 依赖 block

layout 没有把核心 block 标成 `page_cache_dynamic`；`grep page_cache_dynamic app/design` 无结果。默认页包含：

- `checkout/cart_minicart`，读取 `checkout/session` 的 quote 和数量。
- `page/html_welcome`，读取 `customer/session` 的登录状态和姓名。
- `page/template_links`，登录/登出链接和 customer tag。
- `page/html_footer`，cache key 含 `isLoggedIn()`。
- `core/messages`，读取并清空 `core/session` 消息。
- 分类/商品列表 toolbar，读取 `catalog/session` 的 sort、direction、display mode、limit。

这些 block 在“购物车为空、未登录”时通常渲染相同，但读取本身会启动 session，且其他 session 状态会改变 HTML。

### 3.3 `holdsState()` 不能当个性化判定

`Mage_Core_Model_Session_Abstract::holdsState()` 只判断“访客是否会丢失状态”，不判断页面是否可共享。`catalog/session` 把这些 key 声明为 disposable：

- `sort_order`
- `sort_direction`
- `display_mode`
- `limit_page`
- `catalog_compare_items_count`
- 最近访问的 category/product

所以一个只有排序/分页状态的 session 会被 `holdsState()` 判为“无状态”，但分类页 HTML 已经不同。FR-20 需要自己的“cache-neutral”判定，不能复用 `holdsState()`。

### 3.4 其他 session 状态

- `checkout/session` 的 quote id 和 cart 标志。
- `core/session` 的 messages、form key、last_url。
- `customer/session` 的登录、customer group、wishlist display type。
- 货币切换写入 session，而当前 context 使用 store 默认货币，命中会返回错误货币。
- 第三方模块可以读写 session，没有 manifest。

### 3.5 写路径

- session `validate()` 会写 validator key、更新 metadata；首次启动还会写 secure cookie check。
- `core/messages` 的 `getMessages(true)` 清空消息，是写操作。
- toolbar 在带 order/limit 参数的 GET 上写 `catalog/session` 并重定向。
- `checkout/session::getQuote()` 对空购物车创建内存 quote，不落库，但会读取 customer session 和 quote id。
- 没有“打开 session 但不续写 cookie、不写 validator/metadata”的核心 API。

## 4. HTTP 观察

```text
GET /customer/account/login/
HTTP/1.1 200
Set-Cookie: maho_session=...
X-Maho-Cache: BYPASS
X-Maho-Cache-Bypass: route

GET / with maho_session
HTTP/1.1 200
Set-Cookie: maho_session=...
X-Maho-Cache: BYPASS
X-Maho-Cache-Bypass: session_cookie

GET / without cookie
HTTP/1.1 200
X-Maho-Cache: HIT
```

空购物车、未登录的 HTML diff 主要是 form key 占位符和动态脚本，这个单一状态不能证明其他 session 状态安全。

## 5. 结论

FR-20 当前不可安全实现，继续保持严格 bypass。

重新评估前必须同时具备：

1. 核心提供只读 session 启动，不续写 cookie、不写 validator/metadata，或提供 session 版本号。
2. 明确的 cache-neutral 判定，覆盖 cart、customer、messages、catalog sort/display/limit、compare、wishlist、currency 和第三方模块。
3. 所有 session 依赖 block 标记为 dynamic 或从缓存 HTML 中移除，命中路径不读取 session。
4. 每次 session 变更都能失效对应缓存；form key/SID/PII 继续安全处理。
5. 独立安全评审和 kill switch。

## 6. 建议

- FR-20 状态改为“暂停（无官方 session 只读 API；不实现，见 ADR-0025）”。
- 不增加配置项和未使用代码。
- 继续维护严格 bypass；如果产品需要提升命中率，优先做无 session 的静态/CMS 页面和动态块，而不是放宽 session 判定。
