# 代理缓存 Cookie 规范化

> 状态：0.7.0 PERF-04 部署建议，已在 nginx 1.31 和 Varnish 7.4.3 上验证
> 关联：[RFC-007](../rfc/RFC-007-performance-architecture.md) PERF-04、[ADR-0008](../adr/0008-proxy-vary-cookie.md)

模块在 `proxy` 和 `revalidating` 模式发送：

```text
Vary: Accept-Encoding, Cookie
```

代理必须按 Cookie 隔离缓存对象。直接使用完整 `Cookie` 请求头最安全，但 `_ga`、`_gcl_au`、utm 等营销 Cookie 会让同一页面产生大量变体。本目录给出两种经过验证的规范化配置：

- [nginx-cache-key.md](nginx-cache-key.md)：nginx `proxy_cache` 的 Cookie 白名单和 session bypass。
- [varnish-cache-key.vcl](varnish-cache-key.vcl)：Varnish 的 Cookie 清洗、session pass 和 hash 规则。

## Cookie 分类

| Cookie | 处理 | 原因 |
|---|---|---|
| `maho_session` | 强制 bypass，不读也不写共享缓存 | 购物车、客户登录、货币、税费、消息等个性化状态 |
| `maho_admin_session`、`adminhtml` | 强制 bypass | 后台请求不应进入公共缓存 |
| 旧版 `frontend`、`om_frontend` | 强制 bypass | Maho 会迁移这些旧 Cookie，迁移期间仍可能携带 session id |
| `store` | 纳入 cache key | Maho 用它选择 store view，不同 store 的内容和 URL 不同 |
| `_ga`、`_gid`、`_gcl_au`、`_fbp`、`_fbc` | 经验证不影响页面后从 key 中剔除 | 分析和广告归因，不改变 HTML |
| 其他未知 Cookie | 保守处理：nginx bypass；Varnish 保留在 hash 中 | 第三方模块可能用 Cookie 改变价格、库存、语言或内容 |

Maho 的货币选择保存在 session 中，不写独立的 currency cookie；因此 `maho_session` bypass 同时覆盖货币和税费变化。若站点安装了写 currency、region 或 A/B 测试 Cookie 的扩展，必须把该 Cookie 加入 key，或让带该 Cookie 的请求 bypass。

## 安全约束

- 不要删除全部 Cookie 后共用缓存。
- 不要让带 `maho_session` 的请求命中公共缓存。
- 不要把未知业务 Cookie 默认视为无影响。
- 不要只靠 `X-Maho-Cache-Context` 隔离；它是调试头，代理在请求阶段拿不到。
- 代理必须保留模块的 `Cache-Control` 和 `Set-Cookie` 语义。带 `Set-Cookie` 的响应不得写入共享缓存。

## 验证结果

2026-11-25 在本地开发站验证，临时创建第二个 store view，并用 `design/head/title_suffix` 区分页面标题：

| 请求 | nginx | Varnish | 结果 |
|---|---|---|---|
| 无 Cookie | `MISS` → `HIT` | `MISS` → `HIT` | 正常缓存 |
| `_ga=...` | `HIT`（与无 Cookie 同 key） | `HIT`（与无 Cookie 同 key） | 分析 Cookie 不产生变体 |
| `store=second` | 独立 `MISS` → `HIT` | 独立 `MISS` → `HIT` | 两个 store 不共享对象，标题各自正确 |
| `maho_session=...` | `BYPASS` | `BYPASS` | 不读也不写共享缓存 |
| `frontend=...` | `BYPASS` | `BYPASS` | 旧 session Cookie 仍被拦截 |
| `shop_pref=blue` | `BYPASS` | 保留在 hash 中，独立变体 | 未知 Cookie 保守处理 |

nginx 返回两个 store 的标题分别为 `Page Cache Test Product` 和 `Page Cache Test Product  Second Store`，切换后默认 store 的缓存对象没有被覆盖。

## 上线检查

1. 在代理配置中先启用 session bypass，再启用 Cookie 白名单。
2. 用浏览器无痕窗口访问一次页面，确认响应头有 `X-Maho-Cache: HIT` 或代理侧 `X-Maho-Proxy-Cache: HIT`。
3. 带 `maho_session` 登录后确认代理返回 `BYPASS`，且响应含 `Cache-Control: private` 或 `no-store`。
4. 创建第二个 store view，确认两个 store 的页面标题、货币和 URL 不串。
5. 安装新扩展后，检查它是否新增影响页面的 Cookie；有则更新 key 或 bypass 列表。
6. 确认代理的 purge 能按 URL 清理缓存；tag purge 需要代理或 CDN 自己的集成。
