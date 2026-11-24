# ADR-0019：session cookie 命中策略保持保守

## 状态

已接受。当前版本不实现命中；FR-20 只登记产品方向，默认关闭。2026-11-24 的只读 spike 结论为暂停，见 `docs/specs/page-cache-fr20-session-spike.md`。

## 背景

当前缓存策略把 Maho session cookie 是否存在作为匿名判定：

- 没有 session cookie：可跳过 session 并缓存。
- 有 session cookie：不跳过 session，所有模式标记 `session_cookie` bypass。

这会让已经建立 session、但购物车为空且未登录的匿名用户失去命中。审核确认这是保守策略，不是缺陷。放宽会引入 session 启动、动态块、隐私、代理串用户和回滚风险，不能混入 cache integrity 修复。

## 决策

- 默认配置继续严格 bypass：proxy、revalidating 和 local 三种模式都不缓存带 Maho session cookie 的请求。
- 不在当前版本增加“空购物车即可命中”的隐式行为，也不增加未使用的配置项。
- 新增 FR-20 作为后续产品需求，默认关闭，只有满足以下条件才允许进入实现：
  1. 仅 `local` 模式。proxy 和 revalidating 永远不缓存带 session cookie 的请求。
  2. 显式 opt-in 配置，默认关闭，并提供运行时可用的 kill switch。
  3. session 已启动后确认：购物车为空、未登录、无未读消息、无其他会影响页面的 session 数据。
  4. 渲染期间不写 session、不返回 `Set-Cookie`；一旦出现任一会话写入，本次响应不缓存。
  5. HTML key 包含 session id 或等价的会话分区值，禁止跨 session 共享。
  6. form key/SID 和动态块继续走占位符；页面不得包含用户姓名、地址、邮箱等 PII。
  7. 短 TTL、命中计数和 bypass 原因可观测；购物车加购、登录、消息、会话写入、kill switch 均有测试。
  8. 实现前完成独立安全评审，并记录回滚步骤。

任何一项无法确认时，继续 bypass。

## Spike 结果

只读验证确认三个阻塞点：

- `session_start` 总会续写 `maho_session` cookie，PageCache 对含 `Set-Cookie` 的响应直接 bypass；没有只读 session 启动 API。
- 默认 layout 的 minicart、welcome、top links、footer、messages、toolbar 都读取 session；核心 block 没有标记为 dynamic。
- `holdsState()` 把 catalog 的 sort/display/limit 视为 disposable，但这些值会改变页面，因此不能作为 cache-neutral 判定。

结论：FR-20 暂停，直到核心提供只读 session 启动和明确的 cache-neutral 判定。

## 理由

- 空购物车不等于页面无个性化：登录状态、消息、客户分组、愿望清单和第三方模块都可能读取 session。
- proxy 缓存按 URL/Cookie 共享对象，带 session cookie 的内容一旦命中会跨用户泄露。
- local 缓存即使按 session id 分区，也需要保证渲染不写 session，否则响应不可复用。
- 默认严格 bypass 的收益损失可控，错误命中的代价不可接受。

## 后果

- 有 session cookie 的匿名用户继续回源，命中率低于理论值。
- FR-20 即使实现，也只影响显式开启的 local 站点，且缓存对象按 session 分区。
- 当前版本不需要数据库、配置或测试结构变更。

## 备选方案

- **空购物车直接共享命中**：无法证明无其他 session 数据，代理还会串用户，不采用。
- **proxy 按 session cookie 缓存**：隐私和 `Set-Cookie` 风险高，不采用。
- **只缓存 CMS 静态页**：可以作为 FR-20 的子集，但仍需 session 写入和 PII 检查，暂不单独采用。
- **保持严格 bypass**：当前默认，继续采用。
