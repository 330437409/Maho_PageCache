# RFC-007：Maho_PageCache 0.7.0 性能与架构优化

- 状态：Accepted
- 批准：2026-11-24
- 目标版本：0.7.0
- 基线：0.6.1 开发版（0.6.0 + FR-21 + 审计 P1 修复）
- 类型：性能优化 / 架构调整
- 实施方式：分阶段交付
- 兼容策略：尽量保持现有配置和 API
- 数据库迁移：0.6.1 已完成 context 索引；0.7.0 默认不再新增表
- 关联文档：`docs/PRD.md`、`docs/ARCHITECTURE.md`、`docs/API.md`、`docs/adr/`、`docs/specs/`
- 修订：2026-11-24，按当前代码和 0.6.1 审计修复对齐

## 0. 摘要

### 0.1 背景

Maho_PageCache 已经形成完整的页面缓存系统：

- Proxy：反向代理和 CDN 页面缓存。
- Revalidating：基于 ETag 的 HTTP 条件缓存。
- Local：基于 Symfony Cache 的整页 HTML 缓存。
- Dynamic：Ajax、ESI 和服务器端占位符。
- Static Export：匿名默认页静态导出。
- Invalidation：Tag、URL Index、Version 和 Purge。
- Warmup：sitemap、队列、重试和定时预热。
- Stats：缓存统计、报表和指标导出。
- Proxy Models：product、category、CMS page、CMS block 的请求级代理。

功能完整之后，0.7.0 要解决的是运行成本和职责边界，而不是继续加功能：

1. Local 命中路径承担了过多重复读取和解压工作。
2. 缓存变体划分偏保守，代理缓存复用率有提升空间。
3. 核心缓存与 Dynamic、Warmup、Stats、Static 的边界需要更清楚。

### 0.2 0.7.0 目标

四个目标：

- 减少 Local 命中时的 PHP 执行成本。
- 减少无意义的代理缓存变体。
- 简化核心架构和子系统依赖。
- 保持缓存隔离和失效正确性。

### 0.3 非目标

0.7.0 不做：

- 不重新实现 Symfony Cache。
- 不替换 Maho 原有缓存系统。
- 不改变 Maho 核心 session 行为。
- 不默认缓存登录用户或带 session cookie 的请求。
- 不改变 proxy、revalidating、local 三种 backend 的基本语义。
- 不把统计、预热、静态导出强制拆成独立 Composer 包。
- 不为提高命中率牺牲 store、currency、customer group 或隐私隔离。
- 不引入 Redis、Kafka、RabbitMQ 等新的强制依赖。
- 不实现 FR-19 多节点广播；多节点单独立项。

## 1. 设计原则

### 1.1 Cache Hit First

命中路径只做必要工作：

- 只执行必要的缓存读取。
- 不在命中路径写数据库统计。
- 不加载不需要的 model。
- 不扫描文件系统。
- 不执行 warmup、purge 或管理任务。

### 1.2 Correctness Before Hit Rate

以下维度继续强制隔离：

- store；
- currency；
- customer group；
- tax context；
- 影响页面内容的 query 参数；
- session；
- 动态内容；
- 其他实际影响响应的业务上下文。

不能证明安全的上下文继续 bypass。

### 1.3 Keep Core Small

核心只负责：

1. 判断请求是否可缓存。
2. 计算 cache key。
3. 读取和保存缓存。
4. 校验版本。
5. 收集和处理 tag。
6. 输出 HTTP 缓存响应。

Dynamic、Warmup、Stats、Static 通过 service 与核心交互。

### 1.4 Measure Before Optimize

每项优化必须有：

- 优化前基线；
- 优化后数据；
- 相同测试环境；
- 正确性测试；
- 性能回归测试。

当前没有足以确认生产 RPS、P95/P99 和真实命中率的完整 benchmark，本 RFC 不预设未经测量的提升百分比。

## 2. 当前基线

### 2.1 组件状态

| 组件 | 当前状态 | 0.7.0 处理 |
|---|---|---|
| Proxy / Revalidating | 已实现 | 保留 |
| Local HTML Cache | 已实现 | 优化命中路径 |
| Dynamic Ajax / ESI | 已实现 | 简化默认使用策略 |
| Server-side Placeholder | 已实现 | 保留高级功能 |
| Static Export | 已实现 | 保持可选 |
| Tag-aware Cache | 已实现 | 保留原生 tag |
| URL Context Index | 0.6.1 已实现 | 0.7.0 完成迁移和旧表清理 |
| Warmup | 已实现 | 强化执行安全和队列状态 |
| Stats | 已实现 | 增加采样模式 |
| Proxy Models | 0.6.0 已实现 | 保留按需加载 |

### 2.2 已确认问题

| 编号 | 问题 | 级别 | 状态 | 处理 |
|---|---|---|---|---|
| ARCH-01 | URL tag 索引按 URL 覆盖，多个 context 互相丢 tag | P0 | 0.6.1 已修复 | 0.7.0 回归和旧表清理 |
| ARCH-02 | URL 行只保存一份 store/public_path | P1 | 0.6.1 已修复 | context 行独立保存，0.7.0 验证 |
| SEC-01 | Warmup URL 只校验 scheme | P0 | 0.6.1 已修复 | origin allowlist，0.7.0 复验 |
| SEC-02 | 子 sitemap 请求前缺少 host 校验 | P0 | 0.6.1 已修复 | 请求前校验，0.7.0 复验 |
| PERF-01 | Local HIT 重复读取 meta/version | P1 | 0.7.0 已实现 | 引入 CacheEnvelope |
| PERF-02 | gzip 解压和压缩策略缺少基准 | P2 | 0.7.0 已实现 | 建立压缩基准和配置 |
| PERF-03 | Stats 开启后写入随请求量增长 | P1 | 0.7.0 已实现 | 增加 sampled 模式 |
| PERF-04 | 完整 Cookie 可能产生大量代理变体 | P1 | 0.7.0 已实现（文档） | Cookie 规范化部署方案 |
| ARCH-03 | Dynamic、Static、Warmup、Stats 与核心耦合较多 | P2 | 部分 | 按 service 边界整理 |
| ARCH-04 | Warm queue 领取不是原子状态机 | P2 | 待做 | lease/processing 状态 |
| PERF-05 | Static Export 定位需要明确 | P2 | 保留 | 继续作为 Local 扩展 |
| ARCH-05 | Proxy Models 是否需要重写 | P2 | 已确认不重写 | 保留按需加载 |

### 2.3 两个已澄清的设计

**Stats 不是默认每次请求都写数据库。**

`page_cache/stats/enabled` 默认关闭。开启后 `Stat::record()` 先写 PHP 内存 buffer，通常响应结束时 `insertMultiple()` 批量写入，buffer 达到 50 条时也会提前 flush。0.7.0 只针对生产开启详细统计时的写入成本优化。

**Proxy Models 不是每次 Local HIT 都加载实体。**

只有缓存页面存在服务器端动态块时，才预加载实体并恢复 registry。没有服务器端动态块的 Local HIT 不增加这部分查询。0.7.0 保留这个按需加载原则。

## 3. 目标架构

```text
Maho Front Controller
        |
Page Cache Core
  Request Policy
  Context Resolver
  Cache Key + Version
  Cache Backend
        |
Invalidation Engine
  Native Tags · Context Index · URL Version · Purge

Backend Adapters
  Proxy        Varnish / CDN
  Local        Symfony Cache
  Static       Nginx / Caddy

独立管理服务（不进入正常 HIT 路径）
  Dynamic Fragment
  Warmup Worker
  Stats Collector
  Static Manager
```

### 3.1 核心决策

| 编号 | 决策 |
|---|---|
| D01 | 保留三种 backend 的基本行为 |
| D02 | 缓存准入、context、key、backend、invalidation 作为核心边界 |
| D03 | Dynamic、Warmup、Stats、Static 保持独立 service |
| D04 | 复杂管理操作从正常 HIT 路径移出 |
| D05 | 业务正确性优先于命中率 |
| D06 | 不新增强制外部依赖 |
| D07 | URL 索引使用 context 级 tag（0.6.1 已完成） |
| D08 | 普通 Local HIT 不做无必要的 model 加载 |
| D09 | 多节点能力单独立项，不进入 0.7.0 |

## 4. PERF-01：Local Cache 命中路径优化

### 4.1 当前路径

`HtmlCache::load($context)` 当前大致执行：

1. 读取 meta。
2. `getHash()`。
3. `getVersions()`。
4. 再次读取 meta。
5. 读取 global version。
6. 读取 URL version。
7. 计算 HTML key。
8. 读取 HTML。
9. gzip 时解压。

普通 HIT 约 5 次 cache backend 读取。MISS 后 `serveLocalHit()` 还会再次读版本、拿锁、重读缓存，这是并发保护，不能简单删除。

### 4.2 优化目标

在保持版本校验和并发安全的前提下：

- 避免重复读 meta。
- 避免重复计算 context hash。
- 避免同一请求重复读 global version。
- 避免不必要的 key 计算。
- 在 entry 内聚必要元数据。

### 4.3 CacheEnvelope

建议统一为：

```php
[
    'schema_version' => 2,
    'html' => '...',
    'html_encoding' => 'gzip',
    'context_hash' => '...',
    'url_hash' => '...',
    'versions' => ['global' => 100, 'url' => 20],
    'tags' => [],
    'headers' => [],
    'ttl' => 3600,
    'created_at' => 0,
    'dynamic_blocks' => [],
    'proxy_entities' => [],
]
```

要求：

1. entry 自带必要元数据。
2. 不再额外读取内容重复的 meta。
3. global/url version 必须与 entry 一致。
4. 版本读取结果可在当前请求内复用。
5. 版本变化后旧 entry 不得命中。
6. 序列化和失效机制保持不变。

目标：普通 HIT 的 cache backend 读取从约 5 次降到不超过 3 次；2 次作为后续优化目标，但必须先验证版本存储设计，不能取消版本校验。

### 4.4 兼容性

- 旧 entry 无 `schema_version` 视为旧格式。
- 升级通过版本隔离让旧 entry 自然失效。
- 不反序列化旧格式。
- `HtmlCache::load()` 对外主要结构不变。
- `serveLocalEntry()` 的 HTTP 行为不变。

### 4.5 验收

- [ ] 普通 HIT 不重复读 meta。
- [ ] 普通 HIT 的 cache backend 读取不超过 3 次。
- [ ] global version 更新后旧 HTML 不命中。
- [ ] URL version 更新后旧 HTML 不命中。
- [ ] 并发 MISS 仍然只有一个有效写入者。
- [ ] 页面生成期间版本变化时不保存旧 HTML。
- [ ] gzip 和 plain entry 都能读取。
- [ ] 旧 entry 升级后安全失效。

## 5. PERF-02：HTML 压缩策略

### 5.1 当前设计

- 4 KB 以下不压缩。
- 4 KB 以上 gzip level 6。
- 只有压缩后更小才保存 gzip。
- 命中时按 `html_encoding` 解压。
- 静态导出另外写 `.gz`。

方向正确，0.7.0 不做无条件压缩或取消压缩。

### 5.2 优化方向

建立基准：

| 页面类型 | 测试重点 |
|---|---|
| 小型 CMS 页面 | 压缩是否值得 |
| 普通商品页 | CPU 与存储平衡 |
| 大型分类页 | Redis 内存节省 |
| 大型 HTML | 解压耗时 |
| 动态占位符页 | 压缩率和重建成本 |

测试 gzip level 1、3、6。默认保留 level 6，只有基准证明有收益才调整。

建议新增配置：

| 配置 | 默认 |
|---|---|
| `page_cache/local/compression_enabled` | 1 |
| `page_cache/local/compression_min_bytes` | 4096 |
| `page_cache/local/compression_level` | 6 |

安全限制：

- 压缩失败回退 plain。
- 解压失败必须 MISS，不能返回损坏内容。
- 压缩后仍受 `html_max_size` 限制。
- 不改变缓存失效语义。

### 5.3 验收

- [x] 小于阈值的页面不压缩。
- [x] 压缩后更大时回退 plain。
- [x] 压缩失败正常回退。
- [x] 解压失败安全 MISS。
- [x] 三库测试通过。

> PERF-02 状态（2026-11-25）：`page_cache/local/compression_*` 已实现，默认 level 6 和 4 KB 阈值不变。基准见 `docs/benchmark/0.7.0-compression.md`：23-41 KB 页面压缩到 22%-27%，level 6 比 level 3 少 7%-14% 存储，35 KB 页面压缩约 0.4-0.5 ms、解压约 0.08-0.09 ms；350 KB 合成页压缩约 6.1 ms、解压约 0.86 ms。

## 6. PERF-03：Stats 生产环境优化

### 6.1 当前状态

默认关闭；开启后 buffer + `insertMultiple()` 写入 `page_cache_stat`，buffer 50 条提前 flush。

高流量下，如果每个请求都产生统计记录，写入量仍随请求量增长。

### 6.2 新模式

新增 `page_cache/stats/mode`：

| 模式 | 行为 | 用途 |
|---|---|---|
| `off` | 完全不记录 | 生产默认 |
| `full` | 每个符合条件的事件都记录 | 调试、验收 |
| `sampled` | 按采样率记录 | 高流量生产 |

默认：

```text
enabled = 0
mode = full
sample_rate = 0.05
```

`enabled=0` 永远关闭，因此生效默认是 off；`enabled=1` 时由 `mode` 决定。mode 默认 `full` 而不是 `off`，避免旧安装升级后统计被静默关闭；mode 缺失或非法也按 `full` 处理。

### 6.3 Sampled 模式

- 采样决策在请求开始时确定。
- 同一请求的事件使用同一个决策。
- HIT、MISS、BYPASS 使用同一个采样率。
- 面板明确显示采样模式。
- 采样数据不能标记为精确总量；估算必须标注采样率。
- Purge、Flush、Warmup 等系统事件完整记录，不采样。

### 6.4 数据处理

0.7.0 不强制 Redis 计数器或消息队列。保留 buffer + `insertMultiple()`，优化：

- 关闭时零数据库写入。
- sampled 减少写入。
- 保留原始事件和小时聚合。
- 保留隐私限制。
- 写入失败不影响页面。

### 6.5 验收

- [ ] off 模式零统计写入。
- [ ] full 模式保持精确语义。
- [ ] sampled 采样率符合配置。
- [ ] 系统事件不被采样丢弃。
- [ ] Dashboard 显示当前统计模式。
- [ ] 采样数据不被错误标记为精确总量。
- [ ] 统计失败不影响 HIT/MISS。

## 7. PERF-04：代理缓存变体优化

### 7.1 当前设计

Proxy 和 Revalidating 使用：

```text
Vary: Accept-Encoding, Cookie
```

ADR-0008 仍然成立：

- `X-Maho-Cache-Context` 只是调试信息。
- 不能用响应阶段生成的 context header 作为请求阶段的 Vary key。
- 代理必须遵守 Cookie 隔离。
- `maho_session` 请求必须 bypass。
- 高流量站点可以只按真正影响页面的 Cookie 生成 key。

### 7.2 问题

完整 Cookie 可能包含大量与页面内容无关的数据，例如 `_ga`、`_gcl_au`、utm cookie、营销 cookie。按整个 Cookie 字符串做 key 会让同一页面产生大量变体。

### 7.3 新设计

提供代理端 Cookie 规范化建议，不直接改变插件默认行为。

| Cookie | 处理 |
|---|---|
| `maho_session` 和 session id | 强制 bypass |
| store 选择 | 严格隔离 |
| 货币、地区等实际影响页面的 cookie | 纳入 key 或 bypass |
| analytics / tracking | 经验证不影响内容后从 key 中剔除 |
| A/B testing | 纳入 key 或 bypass，不能忽略 |

已提供并在真实代理上验证：

- `docs/deployment/README.md`：Cookie 分类、安全约束和验证步骤。
- `docs/deployment/nginx-cache-key.md`：nginx `proxy_cache` 白名单和 bypass。
- `docs/deployment/varnish-cache-key.vcl`：Varnish Cookie 清洗、pass 和 hash。

### 7.4 安全约束

禁止：

- 删除全部 Cookie 参与缓存。
- 忽略 store 选择状态。
- 允许带 session cookie 的请求命中公共缓存。
- 把未知业务 Cookie 默认视为无影响。
- 只靠 `X-Maho-Cache-Context` 保证隔离。

### 7.5 验收

- [x] 两个 store 不共享错误页面。
- [x] 不同货币不共享错误价格。Maho 的货币选择保存在 session 中，由 session bypass 覆盖。
- [x] 带 session cookie 的请求不命中公共缓存。
- [x] analytics cookie 只在确认安全后不影响共享。
- [x] 未知业务 cookie 按保守策略处理。
- [x] 至少一种真实反向代理完成 HIT/MISS 验证。

> PERF-04 状态（2026-11-25）：nginx 1.31 与 Varnish 7.4.3 实测。无 Cookie 与 `_ga` 共用对象；`store=second` 使用独立对象且标题正确；`maho_session`、`frontend`、`maho_admin_session` 全部 bypass；未知 Cookie 在 nginx bypass，在 Varnish 保留在 hash 中形成独立变体。临时第二 store view 在验证后删除。

## 8. ARCH-01：Context 级 URL 索引

### 8.1 当前状态

0.6.1 已实现，0.7.0 完成迁移、旧表清理和回归。三库 CI 仍待发布前执行。

实际结构：

```text
page_cache_url
  url_id
  url_hash UNIQUE
  url
  urls
  variants_truncated
  public_path/store_id/store_code   # 兼容旧数据
  version
  created_at/updated_at

page_cache_url_context
  context_id
  url_id
  context_hash
  store_id
  store_code
  public_path
  url
  created_at/updated_at
  UNIQUE(url_id, context_hash)

page_cache_url_context_tag
  context_id
  tag
  UNIQUE(context_id, tag)
  INDEX(tag)

```

### 8.2 失效逻辑

1. 原生 Symfony tag 清理缓存对象。
2. 查询包含该 tag 的 context。
3. 找到 context 所属 URL。
4. 同一个 URL 只递增一次版本。
5. 删除每个 context 的静态文件。
6. 收集该 URL 的所有变体进入 purge queue。
7. 启用 warmup 时按 context 的 store 进入 warm queue。
8. 删除已失效的 tag 关联。

URL 级版本仍作为保守失效机制；0.7.0 不改变 ETag 语义。

### 8.3 迁移

0.6.1 创建了新表。0.7.0 的 `maho migrate`：

1. `maho migrate` 先清理缓存。
2. `upgrade-0.6.1-0.7.0.php` 调用 `Invalidator::invalidateAll('upgrade_0_7_0')`，递增 global version、清理 URL/context 索引、静态文件和队列；proxy 模式下入队全量 purge。
3. 删除旧 `page_cache_url_tag` 表。
4. 新缓存请求重新写入 context index。
5. 旧代码不再读取或清理该表。

### 8.4 验收

- [x] 同 URL 不同 query context 不覆盖 tag。
- [x] 同 URL 不同 store 不覆盖 store 信息。
- [x] 商品更新只失效相关 context。
- [x] 分类更新找到所有关联 context。
- [x] 同一 context 并发保存不丢 tag。
- [x] 变体超过 200 仍执行全量 purge 安全策略。
- [x] DB 异常不静默漏失效。
- [ ] SQLite、MySQL、PostgreSQL 通过。本地 SQLite 已通过；三库 CI 在发布前执行。

> Phase 2 状态（2026-11-25）：context 索引回归测试补齐；`upgrade-0.6.1-0.7.0.php` 已实现并在本地 SQLite 执行成功，旧 `page_cache_url_tag` 已删除，模块版本升到 0.7.0。三库 CI 仍开放。

## 9. ARCH-02：Context 与 Cache Key 优化

### 9.1 目标

减少无意义变体，不降低隔离能力。

区分：

- **URL Identity**：scheme、host、path。
- **Business Context**：store、currency、customer group、tax。
- **Presentation Context**：允许的 query、variant。

### 9.2 Query 规范化

保留现有允许参数策略，增加：

- 参数顺序规范化；
- 参数值规范化；
- 空值策略；
- 重复参数策略；
- 参数最大数量；
- 参数最大长度；
- 筛选深度限制；
- 分页最大深度。

`?p=1&order=price` 和 `?order=price&p=1` 只有在确认响应完全等价后才能归一化。`?order=price` 和 `?order=name` 必须不同。

utm、gclid、fbclid 等追踪参数只有在确认不影响页面、A/B、营销逻辑和归因后，才允许忽略。

### 9.3 Store 与 Host

- 保留 store 级隔离。
- Host 必须匹配 store base URL 或明确配置的可信域名。
- 不可信 Host 不进入缓存，也不进入 warm queue。
- Context 不能把未验证 Host 当作可信缓存目标。

### 9.4 验收

- [ ] 不同 store 不共享缓存。
- [ ] 不同货币不共享错误价格。
- [ ] 不同客户组不共享错误内容。
- [ ] 参数排序一致的请求可以共享。
- [ ] 不同筛选条件不能共享。
- [ ] 不可信 Host bypass。
- [ ] ADR-0008 Cookie 隔离继续成立。

## 10. ARCH-03：Dynamic 动态区块简化

### 10.1 当前能力

Ajax、ESI、HMAC token、form key、SID、server-side placeholder、动态块缓存、rate limit。

### 10.2 0.7.0 定位

| 功能 | 定位 | 默认 |
|---|---|---|
| Ajax Dynamic | 标准动态内容 | 按 block 标记使用 |
| ESI | Varnish 高级功能 | 关闭 |
| HMAC Token | 安全校验 | 保留 |
| Form Key | 表单安全 | 保留 |
| SID Placeholder | session 兼容 | 保留 |
| Server-side Placeholder | Local 高级功能 | 关闭 |
| Dynamic Cache | 动态片段缓存 | 按 block 显式启用 |
| Rate Limit | 动态请求保护 | 保留 |

### 10.3 约束

普通缓存页面不得因为 Dynamic 开启就重建动态块。只允许：

```text
显式标记 dynamic
  -> 满足 backend 条件
  -> token 验证
  -> 生成动态内容
```

服务器端动态块继续遵守：

- type 白名单；
- block name 验证；
- token 绑定 URL、store、context、exp；
- 不任意创建 block；
- 失败返回匿名 fallback；
- 不允许 session 内容写入公共缓存。

### 10.4 验收

- [x] 没有动态块时普通 HIT 不触发 Dynamic 重建。
- [x] Ajax 不回归。
- [x] ESI 不回归。
- [x] HMAC 验证不回归。
- [x] Server-side Placeholder 只按配置启用。
- [x] rate limit 生效。

> Phase 4 状态（2026-11-25）：Stats 采样和 Dashboard 已实现；Dynamic 边界补了“无 server marker 原样返回”“server-side 关闭不包装”测试；Proxy Models 补了“无 server block 的 local HIT 不恢复 registry、不预加载”测试。现有 Ajax、ESI、HMAC、rate limit 测试保持通过。

## 11. SEC-01 / SEC-02：Warmup 安全加固

### 11.1 当前状态

0.6.1 已实现 `page_cache/urlPolicy`：

- 从 store base URL 生成 scheme+host+port allowlist。
- 入队、显式 URL、子 sitemap、发请求前都校验。
- 非 store origin 不请求。
- Basic Auth 只发往 allowlist 内 origin。
- `max_redirects=0`。

0.7.0 补强：

- 显式 `page_cache/warmup/allowed_hosts`，用于 store 别名域名；scheme、host、port 必须完全匹配。
- 子 sitemap 请求前的完整 allowlist 测试。
- 保持 `max_redirects=0`，逐跳重定向不跟随。
- DNS 解析后的 IP 校验不做：store origin 和 `allowed_hosts` 是显式配置的可信目标，允许 localhost/私网部署；若部署要求防止 DNS rebinding，应在出站代理或 DNS 层限制，而不是在 warmup 里解析 IP。

### 11.2 规则

1. 仅允许 HTTP/HTTPS。
2. 必须是明确配置的 store 域名或显式 allowed_hosts。
3. store base URL 明确配置为 localhost/私网时允许，因为这是部署内的可信目标；生产应使用公网域名。
4. 非 store origin 一律拒绝，不需要额外 DNS/IP 黑名单。
5. 不自动跟随未验证重定向。
6. Basic Auth 只发往可信 origin。
7. URL 入队和消费前分别校验。
8. 子 sitemap 请求前校验。

### 11.3 验收

- [x] 恶意 Host 不能进入 warmup。
- [x] 非 store 私网 IP 访问被拒绝。显式配置的私网 store origin 例外。
- [x] DNS rebinding 场景有防护或明确说明边界。边界见 11.1：只信任显式 origin，不做 DNS/IP 解析。
- [x] 恶意 sitemap 子地址不会被请求。
- [x] 恶意重定向被拒绝。`max_redirects=0`，302 记为 `redirect`。
- [x] Basic Auth 不发送到其他 Host。
- [x] 正常 warmup 不回归。

> Phase 3 状态（2026-11-25）：`allowed_hosts` 已实现；新增恶意 sitemap 子地址、越权 Host 的 Basic Auth、私网/DNS 边界和别名 origin 测试。Warmup 安全项完成。

## 12. ARCH-04：Warmup Queue 和后台服务边界

### 12.1 保留

URL 去重、retry、backoff、queue limit、cron、lock。

### 12.2 0.7.0 优化

建议增加状态：

```text
pending / processing / completed / failed
```

字段：

```text
queue_id
url
status
attempts
available_at
locked_at
locked_by
last_error
created_at
updated_at
```

要求：

- 原子领取；
- lease 过期恢复；
- 最大重试；
- 幂等执行；
- 重复 URL 合并；
- 失败可观察。

如果当前 core lock 已能保证单节点安全，第一期可以保留 lock，不强制引入分布式锁。

### 12.3 范围

0.7.0 不实现完整 FR-19 多节点广播。Static Export 继续单节点约束。

## 13. PERF-05：Static Export 定位

### 13.1 定位

把 Local Cache 的匿名默认页进一步下沉到 Web Server。

保留现有能力：

- 固定缓存根；
- store 独立目录；
- 原子写入；
- gzip；
- URL index 失效；
- 静态文件索引；
- TTL 清理；
- Nginx/Caddy 配置。

### 13.2 要求

1. 只导出匿名默认页。
2. 带 session cookie 回 PHP。
3. 带 query 参数回 PHP。
4. 失效时删除对应 store 文件。
5. 文件不存在回源 PHP。
6. 静态目录禁止直接访问。
7. 不允许符号链接逃逸。
8. 不在 HIT 路径扫描目录。
9. 保留 `page_cache_static_file` 索引清理。
10. 多节点继续明确标记为不支持。

### 13.3 是否独立成 backend

暂不拆。保留：

```text
Local Backend
  └── Static Export Adapter
```

Static 依赖 Local entry、URL index、tag invalidation 和版本机制，独立 backend 会增加状态协调成本。

## 14. ARCH-05：Proxy Models 保留按需加载

### 14.1 决策

不重写 FR-15。继续使用：

```text
type + store_id + id
```

保留 product、category、CMS page、CMS block、批量 collection、negative cache、tag 收集、registry 恢复和动态 block 显式依赖。

### 14.2 优化

- 没有 server-side dynamic block 时不预加载实体。
- debug 模式记录 `proxy_entity_load_count`、`proxy_entity_batch_count`、`proxy_entity_cache_hit`、`proxy_entity_negative_hit`，不写生产数据库。

### 14.3 验收

- [ ] 同一实体请求内只加载一次。
- [ ] 同类型批量不 N+1。
- [ ] 没有 server-side dynamic block 不预加载。
- [ ] Registry 恢复不改变页面行为。
- [ ] 商品、分类、CMS tag 失效正确。

## 15. 代码与文档结构

0.7.0 不做大规模目录搬迁。逻辑结构：

```text
PageCache/
├── Model/
│   ├── Backend/
│   │   ├── Abstract.php
│   │   ├── Proxy.php
│   │   ├── Revalidating.php
│   │   └── Local.php
│   ├── CacheEnvelope.php
│   ├── Context.php
│   ├── Key.php
│   ├── Version.php
│   ├── HtmlCache.php
│   ├── UrlIndex.php
│   ├── UrlContextIndex.php
│   ├── PurgeQueue.php
│   ├── WarmQueue.php
│   ├── Stat.php
│   └── StaticCache.php
├── Service/
│   ├── Request.php
│   ├── Response.php
│   ├── CachePolicy.php
│   ├── Invalidator.php
│   ├── UrlPolicy.php
│   ├── Dynamic.php
│   ├── Proxy.php
│   ├── Warm.php
│   ├── Purge.php
│   ├── Monitor.php
│   └── StockPending.php
├── Helper/
├── Block/
├── controllers/
├── etc/
├── sql/
├── docs/
└── tests/
```

说明：

- 逻辑分组，不要求立即按目录重排。
- 不重复创建已有 service。
- 旧 public service alias 尽量保留。
- 只有明确收益时才加新抽象。

### 15.1 文档

新增：

```text
docs/
├── adr/
│   ├── 0021-local-cache-envelope.md
│   ├── 0023-stats-sampling.md
│   └── 0024-warmup-url-security.md
└── specs/
    ├── page-cache-07-performance.md
    ├── page-cache-07-stats-sampling.md
    └── page-cache-07-warmup-security.md
```

ARCH-01 的 ADR 已在 0.6.1 审计修复中覆盖，可补 `0022-context-level-url-index.md` 作为正式记录。

## 16. 配置兼容性

新增配置：

| 配置 | 默认 | 说明 |
|---|---|---|
| `page_cache/stats/mode` | `off` | 统计模式 |
| `page_cache/stats/sample_rate` | `0.05` | 采样比例 |
| `page_cache/local/compression_enabled` | 1 | 是否压缩 |
| `page_cache/local/compression_min_bytes` | 4096 | 压缩阈值 |
| `page_cache/local/compression_level` | 6 | gzip level |
| `page_cache/warmup/allowed_hosts` | 空 | 可信 host，默认由 store base URL 推导 |

规则：

- 现有 `stats/enabled` 保留兼容。
- `enabled=0` 永远关闭。
- `enabled=1` 时才读 `mode`。
- 老配置升级时必须映射到兼容行为。
- `enabled=1` 不能静默变成 `off`。
- `allowed_hosts` 为空时不能信任任意 host，必须从 store base URL 构造。
- 新配置后台要有清晰说明。

### 16.1 数据库迁移

0.6.1 已增加：

- `page_cache_url_context`
- `page_cache_url_context_tag`

0.7.0 默认不再新增表；Warm queue 状态扩展如实施，单独评审。

约束：

1. 使用 Maho 声明式 schema。
2. 不修改核心表。
3. SQLite、MySQL、PostgreSQL 兼容。
4. 升级脚本可重复执行。
5. 不丢排队任务。
6. 迁移失败不能继续用不完整索引。
7. 旧索引不能被误认为完整 context 索引。

## 17. Benchmark 方案

### 17.1 环境

统一 Maho 版本、PHP 版本、PHP-FPM、数据库、商品/分类数据、硬件、网络和 Web Server。至少 5 轮，记录中位数。

### 17.2 对照组

| 编号 | 模式 |
|---|---|
| B0 | No Cache |
| B1 | Revalidating |
| B2 | Local + File |
| B3 | Local + Redis |
| B4 | Proxy + Varnish |
| B5 | Local + Static |

Proxy 和 Static 分别统计 PHP 是否启动。

### 17.3 页面

首页、商品详情、分类、分页分类、排序分类、CMS、带动态块商品页、多 store 商品页。

### 17.4 场景

100% warm HIT、100% MISS、90/10、并发首次访问、热门商品失效、批量更新、多 store、多 query、动态块高频、warmup 高并发。

### 17.5 指标

RPS、P50/P95/P99、PHP CPU、FPM workers、PHP memory、Symfony cache reads/writes、MySQL QPS/writes、Redis ops、hit rate、cache storage、invalidation duration、warmup completion。

### 17.6 目标

| 指标 | 目标 |
|---|---|
| Local HIT cache reads | ≤ 3 |
| Local HIT P95 | 改善 ≥ 15% |
| Cache correctness | 零隔离回归 |
| Stats OFF | 零统计写入 |

### 17.7 报告

```text
docs/benchmark/0.6.1-baseline.md
docs/benchmark/0.7.0-result.md
docs/benchmark/raw/
```

包含脚本、原始数据、环境参数和分析。

## 18. 版本与实施计划

### 18.1 与现有 FR 路线图的关系

本 RFC 接管 0.7.0。原 0.7.0 的 FR-22 顺延，待 PRD 同步后调整为：

| 版本 | 内容 |
|---|---|
| 0.6.1 | FR-21 tag budget、审计 P1 修复（已实现，待发版/CI） |
| 0.7.0 | RFC-007 性能与架构优化（本文） |
| 0.8.0 | FR-22 purge 持久化、FR-23 失效审计 |
| 0.9.0 | FR-19 多节点 |
| 0.10.0 | FR-24 预热调度、FR-25 proxy SWR |
| 1.0.0 | FR-26 开发工具（可选） |

RFC 已通过，PRD 版本节奏已同步；本 RFC 不修改 FR 定义。

### 18.2 阶段

| 阶段 | 内容 | 级别 | 预计 |
|---|---|---|---|
| Phase 1 | 0.6.1 benchmark 基线、CacheEnvelope、Local HIT 读取优化、gzip 基准 | P1 | 3-4 天 |
| Phase 2 | Context Index 回归、迁移、旧表清理 | P0 | 1-2 天 |
| Phase 3 | Warmup 安全补强（DNS/IP/重定向测试）、Cookie 规范化文档 | P1 | 2-3 天 |
| Phase 4 | Stats sampled、Dashboard、Dynamic 边界、Proxy Models 验证 | P1/P2 | 3-4 天 |
| Phase 5 | 全量回归、benchmark、三库 CI、Nginx/Varnish 实机、发布/回滚 | Gate | 3-5 天 |

> Phase 1 状态（2026-11-24）：0.6.1 基线、CacheEnvelope spec、ADR-0021 已完成；CacheEnvelope 已实现（247 tests），普通 HIT 读取 5 → 3。开发中复测 RPS +2.56%、P95 -1.42%，P95 ≥15% 目标未达成；B2/B3 FPM 便携环境、B3 Redis、B4 Varnish、B5 nginx 已补齐；FPM 未跑赢 built-in server，G9 继续开放。压缩配置和基准已完成（PERF-02）。
>
> Phase 3 状态（2026-11-25）：Cookie 规范化文档和 nginx/Varnish 配置已完成并实测；`allowed_hosts`、恶意 sitemap 子地址、越权 Basic Auth、私网/DNS 边界测试已完成。
>
> Phase 4 状态（2026-11-25）：stats mode 与请求级采样已实现，ADR-0023 已接受；Dynamic 边界和 Proxy Models 按需加载验证已补齐。
>
> Phase 5 状态（2026-11-25）：本地回归完成，PageCache 272 tests / 768 assertions；TranslateDeclarationTest 通过，PHPStan、php-cs-fixer、Rector 通过；SQLite 升级和旧表删除已实测；SQLite、MySQL 8.0、PostgreSQL 18 本地各 272/768 通过；B0-B5、FPM、压缩、CacheEnvelope 和 Local HIT profile 已归档。dist 三库 CI 已通过（run 45532751929）；开放项：G9 P95 ≥15%（实测 P95 -1.42%；Profile 显示页面缓存逻辑仅占 0.66 ms / 3%，当前架构下不可达）、回滚演练。

预计 12-18 个工作日，已扣除 0.6.1 已完成的 ARCH-01/SEC-01/SEC-02。阶段可部分并行，不含 FR-19 多节点。

### 18.3 顺序

```text
Benchmark Baseline
  -> Local HIT Optimization
  -> Context Index Regression
  -> Warmup Security
  -> Stats Sampling
  -> Dynamic Simplification
  -> Full Regression
  -> 0.7.0 Release
```

Phase 2 的索引正确性是上线门槛，不能因为 Phase 1 性能好就跳过。

### 18.4 每阶段交付

- 代码；
- 自动化测试；
- ARCHITECTURE 更新；
- ADR；
- CHANGELOG；
- 性能或正确性验证记录。

不能只交设计文档而不交验收结果。

## 19. 测试与质量门槛

### 19.1 自动化

- Pest；
- PHPStan；
- php-cs-fixer；
- Rector；
- SQLite；
- MySQL；
- PostgreSQL。

### 19.2 新增测试

| 测试 | 重点 |
|---|---|
| CacheEnvelopeTest | 新旧格式、版本、压缩 |
| LocalHitPerformanceTest | cache 读取次数 |
| ContextIndexTest | URL/context 关系 |
| ContextTagIndexTest | 多 context tag |
| ContextConcurrencyTest | 并发写入 |
| MultiStoreInvalidationTest | store 隔离 |
| QueryVariantTest | query 变体 |
| StatsSamplingTest | 采样准确性 |
| WarmupUrlPolicyTest | SSRF |
| SitemapSecurityTest | 子 sitemap |
| ProxyCookieKeyTest | Cookie 规范化 |
| DynamicRegressionTest | 动态块 |
| StaticRegressionTest | 静态文件 |
| CacheVersionRegressionTest | 全局/URL version |

### 19.3 安全测试

- 恶意 host；
- 内网 IP；
- IPv6 本地地址；
- DNS rebinding；
- 重定向到内网；
- 恶意 sitemap；
- Basic Auth 泄露；
- session cookie 串缓存；
- store 串缓存；
- 动态 token 伪造；
- 静态路径穿越。

### 19.4 兼容性

升级不能破坏：

- 现有 backend 配置；
- 现有 cache key API；
- CLI 命令；
- warmup 任务；
- purge 事件；
- 后台权限；
- 统计报表；
- Nginx/Caddy 配置。

允许通过版本迁移和清空旧页面缓存兼容，但必须有明确升级说明。

## 20. 发布与回滚

### 20.1 发布前

1. 数据库备份。
2. 缓存配置备份。
3. 记录 0.6.1 版本。
4. 记录 global version。
5. 记录 purge queue 和 warmup queue。
6. 检查静态文件目录。
7. 关闭不必要的详细统计。
8. 暂停 warmup consumer，执行迁移。

### 20.2 发布

```text
关闭 PageCache
  -> 升级代码
  -> ./maho migrate
  -> 检查 context index
  -> 清理旧页面缓存
  -> 清理静态缓存
  -> 代理 purge
  -> 启用 PageCache
  -> warmup
  -> 验证 HIT/MISS/BYPASS
  -> 观察指标
```

### 20.3 回滚

发生缓存串用、旧内容未失效或页面错误时：

1. 关闭 PageCache。
2. 全量代理 purge。
3. 清理 local 页面缓存。
4. 清理静态文件。
5. 回滚代码。
6. 恢复兼容配置。
7. 保留新表，避免误删正在使用的数据。
8. 检查商品、分类、CMS 页面。
9. 确认后再启用旧版本。

禁止在没有验证数据兼容性时直接回滚数据库 schema。

0.7.0 的 `page_cache_url_tag` 已在升级时删除。回滚到 0.6.x 时：

1. 先关闭页面缓存并做数据库备份。
2. 回滚代码。
3. 运行 `./maho migrate`，声明式 schema 会重建空的 `page_cache_url_tag`。
4. 不要恢复旧表数据；它只是索引，旧数据已经和当前缓存不一致。
5. 0.6.x 在索引缺失或为空时回退全量失效，页面安全，但 purge 范围会变大。

## 21. Release Gate

- [x] G1 Local HIT 优化完成，读取 ≤ 3。
- [x] G2 Context Index 完成（0.6.1 已实现，0.7.0 复验）。
- [x] G3 多 store 隔离完成。
- [x] G4 Warmup 安全完成（0.6.1 origin allowlist 已实现，0.7.0 补强复验）。
- [x] G5 Stats off/full/sampled 正常。
- [x] G6 Dynamic 回归完成。
- [x] G7 Static 回归完成，Nginx 实机通过。
- [x] G8 SQLite/MySQL/PostgreSQL 通过。PageCache 套件在本地 SQLite、MySQL 8.0、PostgreSQL 18 各 272/768 通过；dist CI run 45532751929 通过。
- [ ] G9 Benchmark 完成，P95 目标达成。基准已完成，P95 -1.42%，未达 ≥15%。Profile 显示页面缓存逻辑仅占 0.66 ms / 3%，当前架构下 15% 不可达；0.7.0 按现状发布，目标转 fast path 或 Static Export。
- [x] G10 文档、升级、回滚、配置说明完整。

> G9 决策（2026-11-25）：0.7.0 按现状发布，G9 保留未通过。Profile 见 `docs/benchmark/0.7.0-local-hit-profile.md`；15% 目标不在 0.7.0 内继续追，转到 Static Export 或后续 fast-path RFC。

## 22. 决策汇总

| 编号 | 决策 | 结果 |
|---|---|---|
| D01 | 保留三种 backend | 不推翻现有架构 |
| D02 | CacheEnvelope | 减少命中读取 |
| D03 | Context 级 URL index | 0.6.1 已修复，0.7.0 复验 |
| D04 | Context 维度收敛 | 提高复用率 |
| D05 | Stats sampling | 降低数据库写入 |
| D06 | Cookie 规范化 | 降低代理碎片 |
| D07 | Dynamic 分层 | 减少默认复杂度 |
| D08 | Static 继续作为 Local 扩展 | 不增加独立状态系统 |
| D09 | Warmup URL Policy | 0.6.1 已实现，0.7.0 补强 |
| D10 | Proxy Models 按需加载 | 保留 FR-15 |
| D11 | 多节点单独立项 | 不扩大 0.7.0 |
| D12 | Benchmark 成为发布门槛 | 用数据证明优化有效 |

## 23. 结论

0.7.0 不是重写缓存引擎，而是把现有功能型插件升级为更高效、职责清晰、可持续扩展的生产级缓存系统。

最重要的三个改造：

1. **Local HIT 路径瘦身**：减少重复 cache 读取，不加载不需要的实体。
2. **Context Index 复验**：0.6.1 已修复 URL 与 context 的关系，0.7.0 完成迁移、旧表清理和回归。
3. **缓存策略收敛**：降低无意义的 Cookie、Query 和动态内容复杂度。

范围严格控制：不把 FR-19 多节点、全新缓存后端或个性化缓存系统塞进 0.7.0。
