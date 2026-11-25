# ADR-0021：Local Cache 使用稳定 Envelope 和 Entry 内版本

## 状态

已接受，0.7.0 Phase 1 已实现。

## 背景

Local HIT 当前路径约 5 次 cache backend 读取：

1. `HtmlCache::load()` 读取 meta。
2. `getHash()` 调用 `getVersions()`，再次读取 meta。
3. 读取 global version。
4. 读取 URL version。
5. 读取 `PC_HTML_<hash>`。

meta 被读取两次；HTML key 和 lock key 都包含版本，导致每次版本变化都产生新的 key 和新的 lock 名。RFC-007 要求普通 Local HIT 不超过 3 次读取，同时保持版本校验、并发保护和现有 HTTP 语义。

0.6.1 基线见 `docs/benchmark/0.6.1-baseline.md`：Local HIT P95 64.5 ms，5 次 cache backend 读取。

## 决策

- 新增 `CacheEnvelope` v2，entry 自带 `schema_version`、`url_hash`、`context_hash`、`versions`、`mode`、`html`、`tags`、`headers`、`dynamic_blocks`、`proxy_entities`。
- 使用稳定 key `PC_HTML_V2_<sha256(urlHash|contextHash|mode)>`，不把版本放进 key。
- Local HIT 只读取：
  1. global version；
  2. URL version；
  3. envelope。
- entry 内版本必须与当前 global/url version 一致；不匹配即 miss。
- 稳定 lock key 使用同一 hash，不包含版本。
- meta 继续写入，供 revalidating ETag、dynamic 和旧 entry fallback 使用；普通 Local HIT 不再读取 meta。
- 旧 `PC_HTML_<hash>` entry 保留 fallback；升级后建议 `./maho cache:flush`。
- `Context::getHash()` 和 `getUrlHash()` 在实例内 memoize。
- `HtmlCache` 通过可覆盖的 `cache()` 方法访问 cache backend，测试用计数包装器验证读取次数。

## 理由

- global 和 URL 版本是失效正确性的必要输入，不能取消；两次版本读取加上一次 envelope 是 3 次下限。
- meta 在 Local HIT 中只重复提供版本信息，entry 已自带，不再需要。
- 稳定 key 让同一 URL/context 只有一个当前 envelope；旧版本通过 entry 校验被拒绝，不需要在 key 中制造新对象。
- 稳定 lock 名避免版本变化时锁分裂，减少并发 MISS 重复渲染。
- meta 仍是 revalidating 304 和 dynamic response 的前置条件，不能全局删除。
- entry 自带上下文和版本，读取时一次完成校验，避免读取后再查多个 key。

## 后果

- Local HIT 从约 5 次读取降到 3 次。
- 稳定 key 可能被并发覆盖；lock 和 entry 版本校验保证最终内容正确，最后写入者必须是当前版本。
- 旧 entry 在升级后通过 fallback 读取，读取次数较高；flush 或 TTL 后消失。
- meta 继续写入，写成本略增；换来 revalidating/dynamic 兼容。
- `getLockName()` 的版本参数保留但忽略，相关测试需要调整。
- entry 字段增加，缓存体积略增；HTML 仍是主体。
- 版本合并成单 key 可以进一步降到 2 次读取，但需要新版本 key 设计，留到后续评估。

## 备选方案

- **保留版本化 key，只把 meta 读取一次**：仍约 4 次读取，且版本变化会制造新 key 和 lock，不采用。
- **完全删除 meta**：revalidating 304 和 dynamic response 仍依赖 meta，会破坏现有路径，不采用。
- **只从 meta 读版本**：tag/URL 失效后 meta 可能滞后，存在旧内容命中风险，不采用。
- **合并 global/url 版本为单 key**：可到 2 次读取，但需要新的版本存储和迁移，复杂度高于 Phase 1 收益，暂不采用。
- **不改变 key，只在请求内缓存版本**：只能减少重复读取，无法消除 meta 和版本化 key 的结构问题，不采用。
