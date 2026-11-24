# ADR-0010：动态块用同一页面的 dynamic 请求，不做任意 block 重建

## 状态

已接受，随 Phase 2 实现。

## 背景

Phase 1 缓存整页 HTML 时，购物车、登录状态、消息、form key 等块不能进入共享缓存。第二期需要补洞。

RockSolid 式服务器端占位符会序列化 block 定义并在命中时重建，复杂且容易和 Maho layout 变化冲突。第三期才计划代理模型和服务器端占位符。

## 决策

- block 在 layout 里显式标记 `page_cache_dynamic=1`。
- 缓存 HTML 里只保留签名占位符，不写用户数据。
- Ajax 请求同一页面 URL，带 `X-Maho-Page-Cache: dynamic` 和签名 token。
- ESI 请求同一页面 URL，带签名的 `_page_cache_dynamic` 参数。
- 模块走正常 layout，只返回当前页面里标记为 dynamic 的 block。
- 不接受客户端指定任意 block 类型；block 必须来自当前页面 layout。
- 动态响应 `Cache-Control: private, no-store`，代理不得缓存。
- 第二期默认不缓存动态 block HTML；可选 TTL 只对显式标记 `page_cache_dynamic_cacheable` 的 block 生效。
- `local` 模式不替换动态 block，而是缓存无 session 时的匿名渲染；local 命中只面向无 session cookie 的请求。form key/SID 仍用占位符，前端在用户提交 POST 表单时才请求。

## 理由

- 复用正常 layout 和 block 渲染，不引入任意代码执行/对象重建。
- block 白名单和签名 token 让攻击面小。
- 动态请求仍生成 layout，但只发生在有动态块的页面，且可以后续用第三期优化。
- 不把用户数据写入共享 HTML 缓存。

## 后果

- 动态请求比静态命中慢；第三期服务器端占位符/代理模型解决。
- 主题必须显式标记动态 block；默认不标记任何块。
- ESI 依赖代理支持。
- 动态 block 的 form key 通过响应返回，前端替换。
- local 模式的被动浏览不会建立 session，动态 block 的匿名渲染会进入整页缓存；proxy 模式仍在页面加载时打孔。

## 备选方案

- **RockSolid 式服务器端重建**：复杂、容易和 layout 冲突，留给第三期。
- **整页 bypass 有动态块的请求**：简单但命中率低；作为没有标记 block 时的降级。
- **客户端直接请求 block controller**：需要重建 block 上下文，安全风险高，不采用。
