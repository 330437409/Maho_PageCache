# ADR-0008：proxy 模式使用 Vary: Cookie，context 头只用于调试

## 状态

已接受。取代 [ADR-0006](0006-proxy-purge.md) 中“用自定义 Vary 作为代理 cache key”的部分。

## 背景

ADR-0006 让模块在响应里设置 `X-Maho-Cache-Context`，并用 `Vary: X-Maho-Cache-Context` 告诉代理按这个头区分缓存对象。

验收时发现这个设计不成立：`Vary` 列的是**请求头**，而 `X-Maho-Cache-Context` 是模块在响应里算出来的头。客户端不会发送它，代理也无法在请求阶段知道它，所以代理看到的请求头是空值，不同 context 仍然可能命中同一个对象。这个问题在真实代理上会表现为跨 store 或跨 context 的内容串页。

## 决策

- proxy 和 revalidating 模式都返回 `Vary: Accept-Encoding, Cookie`。
- 代理按 URL 和 `Cookie` 区分缓存对象。
- `X-Maho-Cache-Context` 继续输出，但只用于调试、审计和测试，不是代理 key。
- 代理必须在 `Cookie` 包含 `maho_session` 时 bypass，或只按 `maho_session`、store/货币相关 cookie 和 URL 生成 key。
- 带 session cookie 的 bypass 响应不设置 `Cache-Control: public`，代理不得缓存。
- 模块不新增 context cookie，也不依赖客户端 JS 设置 key；Phase 1 用标准 `Vary: Cookie` 保证正确性。

## 理由

- `Vary: Cookie` 是标准 HTTP 机制，代理不需要自定义逻辑就能区分匿名、session、store 和货币 cookie。
- 代理命中时不会运行 Maho 的 session skip 或 bypass，因此 key 必须包含会让页面个性化的 cookie。
- 相比自定义响应头，`Cookie` 是客户端真实发送的请求头，代理可以立即使用。
- `Vary: Cookie` 会增加缓存分片，尤其是第三方 cookie；这是正确性优先的代价。高流量站点可以配置代理只按 `maho_session` 和 store/货币 cookie 生成 key，而不是整个 `Cookie`。

## 后果

- 代理不能只按 URL 缓存；必须遵守 `Vary: Cookie` 或自定义更精确的 cookie key。
- 带第三方 cookie 的匿名用户可能产生额外缓存对象，命中率下降。
- `X-Maho-Cache-Context` 不再是安全边界，只是观测值。
- 目标代理仍需在部署环境复验两个 context 不共享对象，以及 session cookie 请求 bypass。

## 备选方案

- **继续用 `Vary: X-Maho-Cache-Context`**：代理无法在请求阶段得到该值，不能保证隔离，不采用。
- **要求代理在请求里注入 context 头**：可行但绑定代理配置，且需要代理实现 store/货币/税 context 逻辑，不采用作为默认方案。
- **新增 `maho_pc_ctx` 响应 cookie 并用 `Vary: Cookie`**：会增加 `Set-Cookie`，共享缓存可能因此拒绝缓存页面；Phase 1 不采用。
- **只缓存默认 context，非默认 context 全部 bypass**：可以作为代理不支持 `Vary: Cookie` 时的降级，但会降低命中率。
