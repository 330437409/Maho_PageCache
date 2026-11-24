# ADR-0011：预热使用公开 HTTP 请求

## 状态

已接受，随 Phase 2 实现。

## 背景

第二期需要把页面提前放进 local HTML 缓存或反向代理。内部 dispatch 可以绕过网络，但和真实请求路径不同，容易漏掉 session skip、代理、CDN 和 HTTP 头。

## 决策

- `page-cache:warm` 使用 Symfony HttpClient 发公开 HTTP GET。
- 只请求 `cacheable_routes`，跳过 404、redirect、非 200。
- 不发送 session cookie，不发送 `Cache-Control: no-cache`。
- 支持 sitemap、显式 URL 列表、`--limit`、`--concurrency`、`--dry-run`。
- local 模式填充 HTML；proxy/revalidating 模式预热代理。
- 失败 URL 汇总，命令返回非零。

## 理由

- 走完整 web 栈，和真实用户请求一致。
- 同时覆盖 local 和代理模式。
- 不引入内部 dispatch 的特殊路径和权限绕过。
- Symfony HttpClient 已在 Maho 依赖中。

## 后果

- 需要站点 URL 从 CLI 可访问。
- 预热会消耗 web 进程和数据库资源，需要并发限制。
- 预热请求可能被 WAF/CDN 拦截，需要运维配合。
- 失败 URL 需要人工处理。

## 备选方案

- **内部 dispatch**：快，但绕过代理/session/HTTP 路径，不采用作为默认。
- **直接写缓存**：需要复制渲染逻辑，容易和真实响应不一致。
- **只预热 sitemap**：覆盖 CMS/分类/商品，但缺自定义 URL；保留显式 URL 列表。
