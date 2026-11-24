# ADR-0006：proxy 模式使用自定义 Vary 和通用 purge 契约

## 状态

已接受。Vary 部分已被 [ADR-0008](0008-proxy-vary-cookie.md) 取代；purge 契约仍然有效。

## 背景

`proxy` 模式把 HTML 存在 Varnish、nginx、Cloudflare、Fastly 等外部缓存。不同代理的 purge API 不同，耦合具体代理会让模块无法复用。

页面内容还可能随 store、货币、客户组、税和 query 参数变化，不能只按 URL 缓存。

## 决策

- 响应设置 `Vary: Accept-Encoding, X-Maho-Cache-Context`。
- `X-Maho-Cache-Context` 是 context hash。
- 代理必须把 `X-Maho-Cache-Context` 纳入 cache key。
- 代理不支持自定义 `Vary` 时，只缓存默认 context；非默认 context 直接 bypass。
- `X-Cache-Tags` 输出页面 tag。
- tag 失效时写 purge 队列，不直接调用代理 API。
- `page-cache:purge` 处理队列，触发 `maho_page_cache_purge_urls` 事件，并写 `var/page-cache/purge-<timestamp>.txt`。
- 第一期不提供 Varnish、Cloudflare、Fastly 适配器。

## 理由

- 自定义 Vary 是通用 HTTP 机制，代理支持程度不同，但契约清晰。
- 事件加文件是低耦合的 purge 契约，站点可以自己接代理 API。
- 不在核心模块里塞多个代理 SDK，避免依赖和许可证问题。
- 队列和事件可以测试，代理适配器可以单独开发和发布。

## 后果

- 运维需要自己写 purge 消费者，或者使用后续的代理适配器。
- 代理不支持 `X-Maho-Cache-Context` 时，非默认 context 无法缓存，命中率下降。
- purge 文件包含 URL，必须放在非 public 目录并限制权限。
- 代理命中不会经过 Maho，PHP 侧计数无法统计。

## 备选方案

- **直接集成 Varnish/Cloudflare**：范围大、依赖多、每增加一个代理都要改核心，不采用。
- **只用 URL 做 cache key**：多货币、多客户组会串内容，不采用。
- **只支持默认 context**：安全但命中率低，作为代理不支持 Vary 时的降级。
