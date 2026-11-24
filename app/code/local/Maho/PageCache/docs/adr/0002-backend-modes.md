# ADR-0002：三种后端模式分三期交付

## 状态

已接受。

## 背景

页面缓存有三种部署方式：

- `proxy`：反向代理/CDN 存 HTML，Maho 设置头和 purge。
- `revalidating`：反向代理/CDN 存 HTML，每次请求回源做 ETag/Last-Modified 校验。
- `local`：Maho 自己在 Symfony Cache 里存整页 HTML。

三种模式的缓存 key、失效和动态块处理不同，一次做完范围过大。

## 决策

第一期实现 `proxy` 和 `revalidating`，`local` 放到第二期。

- 第一期只做 HTTP 集成层，不缓存 HTML。
- 第二期实现 `local`，包括整页 HTML 存储、并发保护和动态块 Ajax/ESI。
- 第三期实现服务器端占位符打孔和 product/category/CMS 代理模型。

## 理由

- `proxy` 和 `revalidating` 共用 context、header、tag 和版本逻辑，可以一起做。
- `local` 需要额外的 HTML 存储、并发锁和动态块重建，复杂度明显更高。
- 先把 HTTP 层验证稳定，再决定是否需要在 PHP 内缓存。

## 后果

- 第一期没有 `local` 模式，虚拟主机环境可能无法直接受益。
- 第一期需要反向代理或 CDN 才能体现页面缓存价值。
- 第二期和第三期的接口要在第一期预留，但不写空实现、不建占位字段。

## 备选方案

- **只做 `revalidating`**：覆盖面窄，但实现最简单；用户选择两种都做。
- **只做 `local`**：不依赖代理，但 PHP 压力和动态块复杂度最高，不采用。
- **一次做三种**：范围过大，验证门无法覆盖，不采用。
