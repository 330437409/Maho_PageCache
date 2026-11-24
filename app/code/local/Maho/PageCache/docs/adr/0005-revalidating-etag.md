# ADR-0005：revalidating 使用版本 ETag 和渲染前 304

## 状态

已接受。0.5.1 起条件请求只使用 ETag；`Last-Modified` 仍返回，但 `If-Modified-Since` 不单独触发 304。

## 背景

`revalidating` 模式要求反向代理缓存 HTML，但每次请求回源确认内容是否变化。命中时 Maho 应返回 304，不生成 layout。

如果 ETag 基于响应体 hash，就必须先渲染页面才能比较，失去 304 的性能意义。

## 决策

ETag 使用版本 token，不使用响应体 hash：

```text
version = sha256(
    globalVersion
    . '|' . urlVersion
    . '|' . urlHash
    . '|' . contextHash
    . '|' . ttlBucket
    . '|' . mode
)
ETag = W/"<version>"
```

- `globalVersion` 在全量失效时递增。
- `urlVersion` 在实体 tag 失效时递增；缺失时按 1 处理。
- `contextHash` 包含 store、货币、客户组、税和允许的 query 参数。
- `ttlBucket = floor(time() / ttl)`，让 ETag 有自然过期。
- 304 在 `controller_action_predispatch` 判断，设置 `FLAG_NO_DISPATCH`，不进入 layout。
- 只有 `If-None-Match` 参与 304 判断。`Last-Modified` 继续返回给客户端和代理，但 `If-Modified-Since` 不单独触发 304，避免同一秒内内容变化后返回旧内容。

## 理由

- 304 必须在渲染前决定，版本 token 可以在不渲染的情况下计算。
- 弱 ETag 足够，因为不做 byte-range。
- 版本变化一定意味着内容可能需要更新，宁可多返回 200，不返回错误的 304。
- 和 Cm_Diehard 的 Revalidating 思路一致，但使用 Maho 的事件和缓存 API。

## 后果

- 内容变化但版本没有递增时，客户端可能拿到旧内容。因此失效逻辑必须覆盖所有内容变更。
- 版本索引丢失时，ETag 会变化，导致 200；这是安全的降级。
- `ttlBucket` 会让 ETag 周期性变化，即使内容没变，也会产生一次 200。TTL 越大，变化越少。

## 备选方案

- **响应体 hash**：必须先渲染，失去 304 的意义，不采用。
- **只用 Last-Modified**：精度低，同一秒内变化会误判，不采用。
- **不做 304，只做普通 proxy 缓存**：无法在 CDN 上实时失效，不采用。
