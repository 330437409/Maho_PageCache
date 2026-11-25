# 功能规格：Maho_PageCache 0.7.0 Local CacheEnvelope

> 状态：实现完成，待发版 0.7.0
> 关联 RFC：RFC-007 PERF-01
> 关联 ADR：ADR-0021
> 目标版本：0.7.0 Phase 1
> 基线：`docs/benchmark/0.6.1-baseline.md`

## 1. 背景与目标

0.6.1 Local HIT 的 cache backend 读取路径约为 5 次：

1. `HtmlCache::load()` 读取 `PC_META_<urlhash>`。
2. `getHash()` 调用 `getVersions()`，再次读取 meta。
3. 读取 global version。
4. 读取 URL version。
5. 读取 `PC_HTML_<hash>`。

其中 meta 被读取两次，版本 key 被重复计算。0.7.0 Phase 1 引入 `CacheEnvelope` v2：

- Local HIT 读取不超过 3 次 cache backend。
- 不取消版本校验。
- 不改变 proxy/revalidating/local 的 HTTP 语义。
- 旧 entry 可安全失效，不做反序列化兼容。

## 2. 范围

### 2.1 范围内

- 新增 `CacheEnvelope` v2 存储格式。
- 新增稳定 envelope key 和稳定 lock key。
- Local HIT 使用 global + URL version + envelope 三次读取。
- meta 保留给 revalidating、dynamic 和旧 entry fallback。
- `Context` 内 memoize `getHash()` 和 `getUrlHash()`。
- 旧 `PC_HTML_<hash>` entry 的 fallback 读取。
- 计数 cache 读取的测试接口。

### 2.2 范围外

- 不实现 PERF-02 压缩策略。
- 不实现 PERF-03 Stats sampling。
- 不改变 proxy/revalidating 的 ETag/304 逻辑。
- 不改变 tag、URL index、静态导出和 warmup 语义。
- 不把 meta 从 revalidating/dynamic 路径移除。
- 不引入新的 cache backend 或外部依赖。

## 3. 当前路径与目标

### 3.1 当前 HIT

```text
load(context)
  -> load meta
  -> getHash()
       -> getVersions()
            -> load meta
            -> load global
            -> load url
  -> load HTML
```

约 5 次读取。

### 3.2 目标 HIT

```text
loadEnvelope(context)
  -> load global version
  -> load url version
  -> load envelope
  -> validate schema/context/url/versions/mode
  -> decode gzip if needed
```

3 次读取。meta 不参与普通 Local HIT。

## 4. 设计

### 4.1 CacheEnvelope v2

Entry 结构：

```php
[
    'schema_version' => 2,
    'html' => '...',
    'html_encoding' => 'plain'|'gzip',
    'url_hash' => '...',
    'context_hash' => '...',
    'versions' => [
        'global' => 100,
        'url' => 20,
    ],
    'mode' => 'local',
    'tags' => [],
    'headers' => [],
    'ttl' => 3600,
    'created_at' => 0,
    'dynamic_blocks' => [],
    'proxy_entities' => [],
]
```

要求：

- `html` 非空字符串。
- `schema_version` 必须为 2。
- `url_hash`、`context_hash` 必须与当前 Context 一致。
- `versions.global`、`versions.url` 必须与当前版本一致。
- `mode` 必须为 `local`。
- `html_encoding` 只允许 `plain` 或 `gzip`。
- gzip 解压失败必须返回 miss，不能返回损坏内容。

### 4.2 Key

新增：

```php
public function getEnvelopeKey(Maho_PageCache_Model_Context $context): string
{
    return 'PC_HTML_V2_' . hash(
        'sha256',
        $context->getUrlHash() . '|' . $context->getHash() . '|' . $context->getMode(),
    );
}
```

稳定 key 不包含版本：

- 版本变化由 entry 内 `versions` 校验。
- 同一 URL + context 只有一个当前 envelope。
- 旧 `PC_HTML_<hash>` 继续存在于缓存中直到 TTL，不主动批量删除。

Lock key 也改为稳定：

```php
return 'page_cache_html_' . hash(
    'sha256',
    $context->getUrlHash() . '|' . $context->getHash() . '|' . $context->getMode(),
);
```

`getLockName($context, ?array $versions = null)` 保留可选参数兼容，但忽略 `$versions`。

### 4.3 读取 API

新增：

```php
/**
 * @return array{entry: array<string, mixed>|null, versions: array{global: int, url: int}}
 */
public function loadEnvelope(
    Maho_PageCache_Model_Context $context,
    ?array $versions = null,
): array;
```

流程：

1. `$versions ??= $this->getCurrentVersions($context)`。
   - `getCurrentVersions()` 只读 global 和 URL 两个版本，不读 meta。
2. 读取 `getEnvelopeKey($context)`。
3. 校验 entry。
4. 版本、context、url 不匹配返回 `entry=null`，但返回当前 `versions`。
5. gzip 解码。
6. 如果 v2 entry 不存在，调用旧格式 fallback。

`load($context, $versions = null)` 保留为兼容包装：

```php
return $this->loadEnvelope($context, $versions)['entry'];
```

### 4.4 旧格式 fallback

如果 v2 envelope 不存在：

1. 读取 `PC_META_<urlhash>`。
2. 如果 meta 不存在，返回 miss。
3. `getVersions($context)` 按现有 meta + global + url 逻辑读取。
4. 计算旧 `PC_HTML_<hash>` key。
5. 读取旧 entry，按现有校验返回。

Fallback 只服务升级前的旧 entry。发布说明要求升级后执行一次 `./maho cache:flush`，旧 entry 也可以按 TTL 自然过期。

### 4.5 写入

`HtmlCache::save()` 保持现有参数，写入：

- v2 envelope key。
- 现有 meta key，供 revalidating、dynamic 和旧路径使用。
- 现有 URL index、静态文件和 tag 逻辑不变。

`save()` 返回的 entry 结构保持兼容，新增 `schema_version`、`versions` 等字段。

`remove()`：

- 删除 v2 envelope key。
- 删除旧 `PC_HTML_<hash>` key（如果可计算）。
- 现有 meta/ETag/静态文件清理不变。

### 4.6 命中流程

`Observer::serveLocalHit()`：

1. `$result = $cache->loadEnvelope($context)`。
2. `$result['entry'] !== null` 时直接 `serveLocalEntry()`。
3. miss 时获取稳定 lock。
4. 获取 lock 后再次 `loadEnvelope($context)` 并重新读取当前版本。
5. 仍 miss 时注册 lock name 和第二次读取的 `versions`，进入正常渲染。
6. `saveLocalResponse()` 使用注册的 versions，并在保存前后检查版本未变化。

HIT 路径 3 次读取；MISS 路径允许更多，因为需要并发保护和重新校验。

### 4.7 Context memoization

`Maho_PageCache_Model_Context` 增加：

- `private ?string $hashCache = null;`
- `private ?string $urlHashCache = null;`

`getHash()` 和 `getUrlHash()` 在同一实例内复用结果。Context 构造后 data 不再变化，不需要失效逻辑。

### 4.8 可测试的 cache 访问

`HtmlCache` 增加：

```php
protected function cache(): Mage_Core_Model_Cache;
```

所有 `Mage::app()->getCache()` 调用改为 `$this->cache()`。测试子类可以返回一个计数包装器，验证 HIT 读取次数。

包装器只用于测试，不进入生产代码路径。

## 4.1 实现记录

- 代码：`Model/CacheEnvelope.php`、`Model/Key.php`、`Model/Context.php`、`Model/HtmlCache.php`、`Model/Observer.php`。
- 测试：`CacheEnvelopeTest`、`LocalHitReadCountTest`，并更新 `LocalCacheTest`；PageCache 套件 247 passed（715 assertions）。
- 读取次数：`loadEnvelope()` 无显式版本时 3 次（global、url、envelope）；显式版本时 1 次。
- 静态检查：PHPStan、php-cs-fixer、Rector 通过。
- 实机：local 模式 MISS → HIT → 304；调用 `invalidateTags(['catalog_product_1'])` 后下一次 MISS，再下一次 HIT。
- 开发中复测（PHP built-in server）：RPS +2.56%，P95 -1.42%，读取次数 5 → 3。读取目标达成，RFC 的 P95 ≥15% 目标未达成，见 `docs/benchmark/0.7.0-cacheenvelope-dev-check.md`。
- 旧 `PC_HTML_<hash>` entry 的 fallback 由单元测试覆盖。
- 跨库：待模块仓库 CI 在 SQLite、MySQL、PostgreSQL 上验证。

## 5. 任务拆分

- [ ] `Model/CacheEnvelope.php`：v2 schema、校验、编码/解码。
- [ ] `Model/Key.php`：envelope key、稳定 lock key。
- [ ] `Model/Context.php`：hash memoization。
- [ ] `Model/HtmlCache.php`：`loadEnvelope()`、`getCurrentVersions()`、fallback、`cache()`、save/remove v2。
- [ ] `Model/Observer.php`：`serveLocalHit()` 使用 `loadEnvelope()` 和稳定 lock；保存路径不变。
- [ ] `docs/adr/0021-local-cache-envelope.md`：ADR。
- [ ] 文档：ARCHITECTURE、API、README、CHANGELOG。
- [ ] 测试：读取次数、版本失效、context/url 校验、gzip、旧格式 fallback、并发、静态/动态回归。

## 6. 验收标准

### AC-01 读取次数

- 普通 Local HIT 最多 3 次 cache backend 读取。
- 计数测试通过：global、url、envelope 各一次。
- 不读取 meta。

### AC-02 版本正确性

- global version 变化后旧 envelope 不命中。
- URL version 变化后旧 envelope 不命中。
- 版本在渲染期间变化时不保存旧 HTML。
- 同一请求内版本读取结果只用于当前请求，不跨请求缓存。

### AC-03 上下文正确性

- context hash 不匹配时 miss。
- URL hash 不匹配时 miss。
- mode 不是 local 时 miss。
- 不同 store/currency/customer group/query 变体不串用。

### AC-04 压缩

- plain 和 gzip entry 都能读取。
- 解压失败返回 miss。
- 压缩后仍受 `html_max_size` 限制。

### AC-05 兼容性

- 旧 `PC_HTML_<hash>` entry 可通过 fallback 读取，直到 TTL 或 flush。
- 旧 entry 不会被误当作 v2。
- revalidating ETag/304 不受影响。
- dynamic server-side、proxy models、static export、URL index 不回归。
- 现有 CLI、配置和 HTTP 头不变。

### AC-06 并发

- 并发 MISS 仍然只有一个有效写入者。
- 第二次读取在 lock 内进行。
- 版本变化时释放 lock，不保存旧 HTML。
- lock 名稳定，不随版本变化。

### AC-07 回归与验证

- PageCache 测试全绿。
- 新增 `CacheEnvelopeTest` 和 `LocalHitReadCountTest`。
- PHPStan、php-cs-fixer、Rector 通过。
- SQLite、MySQL、PostgreSQL CI 通过。
- 实机 local 模式 HIT/MISS/304 验证。
- 0.7.0 benchmark 对比 0.6.1 基线，记录 P95 和读取次数。

## 7. 风险

- 稳定 key 覆盖写入时，两个并发请求可能互相覆盖；lock 和版本校验必须保证最终内容正确。
- 旧 entry fallback 会增加旧缓存命中时的读取次数；升级后 flush 可消除。
- 版本读取仍是 2 次；如果要降到 2 次总读取，需要合并版本 key，留到后续评估。
- meta 继续写入会增加一点写成本，但 revalidating/dynamic 仍依赖它。
- `getLockName` 忽略版本参数可能影响测试假设，需要更新测试。
- CacheEnvelope 的序列化字段增加，entry 体积略增；HTML 仍是主体。

## 8. 已确认的选择

1. 使用稳定 envelope key，版本放 entry 内校验。
2. Local HIT 读取 global + url + envelope，共 3 次。
3. meta 保留，不删除；只从 Local HIT 路径移除。
4. 旧 entry 走 fallback，升级建议 flush。
5. 不把版本合并成单 key，留到后续优化。
