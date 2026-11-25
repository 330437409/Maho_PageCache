# ADR-0025：FR-20 保持暂停，不实现 session cookie 命中

## 状态

已接受。补充 ADR-0019，不取代它；FR-20 回滚为暂停。

日期：2026-11-25

## 背景

FR-20 的目标是让带 Maho session cookie、但购物车为空且未登录的匿名请求也能命中 local 页面缓存。ADR-0019 已定下严格 bypass 的默认策略，只读 spike（`docs/specs/page-cache-fr20-session-spike.md`）确认了三个阻塞点：

- Maho 的 `Session_Abstract::start()` 在启动后总是续写 `maho_session` cookie，没有只读启动模式。
- 默认 layout 的 minicart、welcome、top links、footer、messages 和列表 toolbar 都读取 session。
- `holdsState()` 只判断访客是否会丢失状态，不能判断页面是否可以共享；catalog 的 sort/display/limit 被它视为 disposable，但这些值会改变 HTML。

2026-11-25 重新评估后，决定不通过自定义 session 分支来绕过官方 API 的缺失。

## 决策

- FR-20 保持暂停，不进入实现。
- 不增加 FR-20 配置项、session 分支、缓存分区、数据库字段或测试结构。
- proxy、revalidating 和 local 三种模式继续对所有 Maho session cookie 严格 bypass。
- 只有同时满足以下条件才重新打开 FR-20：
  1. Maho 核心提供只读 session 启动，不续写 cookie、不写 validator/metadata，或提供等价的 session 版本号。
  2. 核心或页面缓存模块定义明确的 cache-neutral 判定，覆盖 cart、customer、messages、catalog sort/display/limit、compare、wishlist、currency 和第三方模块。
  3. 所有 session 依赖 block 可以标记为 dynamic，或从缓存 HTML 中移除，命中路径不读取 session。
  4. 有独立安全评审、短 TTL、kill switch、命中计数和回滚步骤。
- 重新打开时先写新的 RFC/ADR，不直接修改本 ADR 的决策正文。

## 收益评估

FR-20 的理论收益上限是「带 session cookie 但实际可共享的匿名请求」占可缓存请求的比例。这个比例必须用数据测量，不能按感觉估计：

```text
session_cookie_share = session_cookie bypass / (hit + miss + revalidated + session_cookie bypass)
```

即使这个比例很高，实际收益还会被以下因素压低：

- 购物车非空、已登录、有消息、货币切换、愿望清单、第三方模块读写 session 的请求仍要 bypass。
- 可共享页面里的 minicart、welcome、top links、footer、messages、toolbar 仍然要动态渲染或从缓存 HTML 中移除，命中并不等于零渲染成本。
- 缓存必须按 session 分区时，每个 session 的条目更短命，命中率进一步下降。
- 无 cookie 的匿名流量已经由 local 和 Static Export 覆盖，FR-20 只增加「已有 session cookie 的空购物车访客」这一层。

结论：收益不确定，且只影响显式开启的 local 站点；为一个可能是个位数到十几个百分点的增量，维护一套自定义 session 启动和 cache-neutral 判定，风险和成本都过高。

## 没有官方 API 时的可行路径

1. **保持严格 bypass（当前选择）**：不实现，不增加配置；优先用 Static Export、无 session 的 CMS/目录页和动态块提高命中率。
2. **向 Maho 核心提交只读 session API**：增加 `start(readOnly: true)` 或等价的 session revision，保证不续写 cookie、不写 metadata；这是重新打开 FR-20 的前置条件。
3. **只缓存完全无 session 依赖的页面**：例如纯 CMS 页面，所有个性化块走动态占位符；仍需完整 block 审计，不能证明其他模块不读 session，暂不作为 FR-20 的子集交付。
4. **自定义 session bootstrap（不采用）**：直接解析 cookie、用 Symfony/PHP 的 `read_and_close` 读 session，绕过 Maho 的 session 封装。它依赖未公开的内部结构，升级易碎，也无法覆盖核心 block 的写入路径，因此不采用。

## 影响与回滚范围

- 运行时影响：无。FR-20 从未实现，没有代码、配置、数据库表或对外 API 可回滚。
- 数据影响：无迁移、无数据删除、无缓存格式变化。
- 已有行为：保持严格 `session_cookie` bypass，与 ADR-0019 一致。
- 历史：保留 ADR-0019、spike 和 PRD 记录，不删除已 push 的历史，不执行 `git revert`。
- 后续：如果核心提供只读 API，按新的 RFC/ADR 重新评估，不在本 ADR 上直接改决策。

## 后果

- 带 session cookie 的匿名访客继续回源，命中率低于理论值。
- 缓存正确性和跨 session 隐私保持保守。
- 页面缓存模块不再为 FR-20 承担未使用代码、配置和测试债务。
- 如果产品确实需要提升这部分命中率，应优先推动 Maho 核心的 session 只读能力，而不是在插件里分叉 session。

## 备选方案

- **空购物车直接共享命中**：无法证明无其他 session 数据，代理还会串用户，不采用。
- **proxy 按 session cookie 缓存**：隐私和 `Set-Cookie` 风险高，不采用。
- **自定义只读 session 分支**：依赖内部实现，升级易碎，维护成本高于收益，不采用。
- **只缓存 CMS 静态页**：可作为未来子集，但仍需 session 写入和 PII 检查，暂不单独采用。
- **保持严格 bypass**：当前选择，见 ADR-0019。
