# ADR-0003：匿名 GET 跳过 session，并用 session cookie 做保守判定

## 状态

已接受。

## 背景

Maho 的 `Mage_Core_Controller_Varien_Action::preDispatch()` 默认启动 session，并可能返回 `Set-Cookie`。这会让反向代理和 CDN 无法安全缓存 HTML。

Maho 已经提供 `controller_action_predispatch_session_start` 事件。监听者可以设置 `FLAG_NO_START_SESSION` 跳过 session。

难点是判断“这个请求是否匿名”。如果为了判断登录状态而去读 `customer/session`，本身就会启动 session，失去意义。

## 决策

第一期只在配置的可缓存路由上跳过 session，并使用 Maho session cookie 是否存在作为保守判定：

- 没有 session cookie：可以跳过 session。
- 有 session cookie：不跳过，标记 `session_cookie` bypass。
- POST、admin、checkout、customer account、preview、维护模式：不跳过。
- 登录、购物车、消息等状态不主动读取，避免启动 session。

## 理由

- 读 cookie 不会启动 session。
- 有 session cookie 的用户可能已经登录、加购或收到消息，绕过缓存最安全。
- 没有 session cookie 的匿名用户，页面内容通常只依赖 URL、store、货币和默认 context。
- 和 Maho `docs/hosting/edge-caching.md` 的建议一致。

## 后果

- 已有 session cookie 的匿名用户不会享受缓存，命中率会低一些，但不会泄露数据。
- 跳过 session 的页面 form key 为空，第一次 POST 可能失败；文档要说明，并允许配置 bypass 路由。
- 第二期做动态块时，需要重新评估 form key 和 SID 的占位符替换。

## 备选方案

- **通过 `customer/session` 判断登录**：会启动 session，失去缓存意义，不采用。
- **所有匿名请求都跳过 session**：无法区分登录和购物车，风险高，不采用。
- **只缓存静态 CMS 页**：安全但收益小，不采用。
