# 功能规格：Maho_PageCache 0.7.0 Local HTML 压缩

> 状态：已实现，待 0.7.0 发版
> 关联 RFC：RFC-007 PERF-02
> 目标版本：0.7.0 Phase 1

## 1. 背景

local 模式把整页 HTML 存入 Maho 的 Symfony Cache。当前实现：

- 小于 4 KB 不压缩。
- 大于等于 4 KB 用 gzip level 6。
- 只有压缩后更小才保存 gzip。
- 命中时按 `html_encoding` 解压。
- 静态导出另外写 `.gz`。

压缩方向和默认值不变。本功能只把阈值和 level 变成配置，并为回退、解压失败和压缩率补测试与基准。

## 2. 范围

### 2.1 范围内

- 新增 `page_cache/local/compression_enabled`、`compression_min_bytes`、`compression_level`。
- 保留 `page_cache/general/html_max_size` 的位置和语义。
- 压缩失败回退 plain。
- 解压失败返回 MISS，不输出损坏内容。
- 压缩后仍受 `html_max_size` 约束。
- 为 level 1、3、6 建立压缩率和耗时基准。

### 2.2 范围外

- 不引入 Brotli、zstd 或其他编码。
- 不改变 CacheEnvelope 的 `html_encoding` 格式。
- 不改变静态导出的 `.gz` 行为。
- 不按页面类型自动选择 level。
- 不做后台实时压缩率统计。

## 3. 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `page_cache/local/compression_enabled` | 1 | 关闭后所有 local HTML 存 plain |
| `page_cache/local/compression_min_bytes` | 4096 | 小于该值的页面不压缩 |
| `page_cache/local/compression_level` | 6 | gzip level 1 到 9；非法值按 6 |

读取规则：

- 阈值小于等于 0 时按 4096。
- level 小于 1 或大于 9 时按 6。
- `html_max_size` 仍在 `page_cache/general`，超限在压缩前判断。

## 4. 写入流程

`HtmlCache::save()`：

1. 检查 `html_max_size`，超限直接返回 `null`。
2. 读取压缩开关和阈值。
3. 未开启、函数不存在或 HTML 小于阈值时存 plain。
4. 调用 `compress()`，使用配置的 level。
5. `gzencode` 返回 `false` 或压缩后不小于原文时存 plain。
6. 保存 envelope，`html_encoding` 为 `plain` 或 `gzip`。

`compress()` 是 protected 方法，测试用子类模拟压缩失败。

## 5. 读取流程

`CacheEnvelope::decode()`：

- `html_encoding=plain` 直接返回 HTML。
- `html_encoding=gzip` 调用 `gzdecode`。
- 解压失败返回 `null`，`HtmlCache::load()` 返回 `null`，请求按 MISS 重新渲染。

## 6. 后台

`System > Configuration > Page Cache > Local Cache`：

- `Compress Local HTML`
- `Compression Minimum (bytes)`
- `Compression Level`

`html_max_size` 继续在 `General` 组，避免升级时移动已有配置。

## 7. 基准

结果见 `docs/benchmark/0.7.0-compression.md`。默认 level 6：

- 23 KB 到 41 KB 页面压缩后为原文的 22% 到 27%。
- level 6 比 level 3 少 7% 到 14% 的存储。
- 35 KB 页面压缩约 0.4 到 0.5 ms，解压约 0.08 到 0.09 ms。
- 350 KB 合成页压缩约 6.1 ms，解压约 0.86 ms。

结论：保留 level 6 和 4 KB 阈值。

## 8. 任务拆分

- [x] `Helper/Data.php`：压缩开关、阈值、level 和默认值。
- [x] `Model/HtmlCache.php`：读取配置、protected `compress()`、失败回退。
- [x] `etc/config.xml`：`page_cache/local/*` 默认值。
- [x] `etc/system.xml`：`Local Cache` 配置组。
- [x] `app/locale/en_US`、`zh_CN`：后台文案。
- [x] 测试：开关、阈值、level、压缩失败、解压失败、超限。
- [x] `docs/benchmark/compression.php`：可复现的 gzip 基准。
- [x] `docs/benchmark/0.7.0-compression.md`：结果和结论。
- [x] 文档：ARCHITECTURE、API、README、CHANGELOG、RFC 状态。

## 9. 验收标准

### AC-01 配置

- `compression_enabled=0` 时大页面也存 plain。
- HTML 小于 `compression_min_bytes` 时存 plain。
- `compression_level` 生效；非法值按 6。
- 阈值小于等于 0 时按 4096。

### AC-02 安全

- 压缩失败回退 plain，不丢页面。
- 解压失败返回 MISS，不输出损坏 HTML。
- 超过 `html_max_size` 的页面在压缩前被拒绝。
- 压缩后仍只保存比原文小的结果。
- 缓存失效和版本语义不变。

### AC-03 回归

- 现有 PageCache 测试全绿。
- PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL CI 通过。
- 静态导出和动态占位符不回归。

## 10. 风险

- 开启压缩会增加 MISS 时的 CPU；默认 level 6 在 35 KB 页面约 0.4 到 0.5 ms，可接受。
- 关闭压缩会增加缓存后端的内存或磁盘占用，但降低 CPU。
- 压缩率取决于页面内容；已压缩过的二进制或随机内容可能不值得压缩，现有“只有更小才保存”规则会回退 plain。
- 修改 level 不会使旧 entry 失效；旧 entry 按自己的 `html_encoding` 读取，直到 TTL 或 flush。
