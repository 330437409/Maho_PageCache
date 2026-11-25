# 功能规格：Maho 页面缓存 proxy tag 预算与 overflow tag

> 状态：实现完成，待发版 0.6.1
> 关联 PRD：FR-21
> 关联 ADR：无新增 ADR，沿用 ADR-0006、ADR-0008
> 目标版本：0.6.1（待 0.6.0 发版后）

## 1. 背景与目标

proxy 模式把页面 tag 通过 `X-Cache-Tags` 发给反向代理。代理可以按 URL purge，也可以按 tag purge。

当前 `Maho_PageCache_Model_Backend_Abstract::getTagHeader()` 只取前 50 个 tag，没有 overflow tag。分类页、搜索页列出很多商品时，页面 tag 很容易超过 50 个。被截断的商品 tag 不会出现在代理的 tag 集合里，按 tag purge 时这些页面不会被清掉，可能陈旧到 TTL。

本功能给 proxy tag header 加预算和 overflow tag：

- header 未超限时保持原样。
- 超限时先保留页面自身、分类、CMS 等宽 tag，再保留商品 tag。
- 被丢掉的 tag 用 overflow tag 代替。
- purge payload 自动带上对应 overflow tag，保证按 tag purge 不漏清。
- URL 索引和本地 native tag 行为不变。

## 2. 需求

### 2.1 范围内

- `X-Cache-Tags` 和调试头 `X-Maho-Cache-Tags` 统一走 tag 预算。
- 预算固定为 50 个 tag，不新增后台配置。
- 商品 tag 识别为 `catalog_product_<id>`；其他 tag 视为宽 tag。
- 商品 tag 被丢时加 `page_cache_overflow_product`。
- 非商品 tag 被丢时加 `page_cache_overflow`。
- purge payload 按被清 tag 的类型追加对应 overflow tag。
- 文档说明按 URL purge 不受影响，按 tag purge 必须识别 overflow tag。

### 2.2 范围外

- 不改 URL 索引表结构，不限制 URL 索引保存的 tag 数量。
- 不改本地 native cache tag，本地失效仍按完整实体 tag。
- 不做按变更属性过滤 listing tag。
- 不新增后台配置或环境变量。
- 不改 proxy/revalidating/local 的模式选择。

## 3. 设计

### 3.1 常量

新增 `Maho_PageCache_Model_TagBudget`：

```php
public const int MAX_TAGS = 50;
public const string OVERFLOW = 'page_cache_overflow';
public const string OVERFLOW_PRODUCT = 'page_cache_overflow_product';
private const string PRODUCT_TAG_PATTERN = '/^catalog_product_\d+$/';
```

overflow tag 只包含小写字母和下划线，能通过 `sanitizeHeaderValue()` 和 `Model\Key::sanitizeTag()`。

### 3.2 `fit()`

`fit(array $tags, int $maxTags = self::MAX_TAGS): array`

- 去重、去空；tag 顺序按传入顺序。
- tag 数不超过预算时原样返回，保证 header 字节级不变。
- 超过预算时：
  1. 分成 `others`（非商品 tag）和 `products`（`catalog_product_<id>`）。
  2. 先放 `others`，再放 `products`。
  3. 被丢的组追加对应 overflow tag。
  4. 如果 overflow tag 占用后超过预算，从末尾回退原 tag，直到总数不超过预算。
  5. 输出顺序为保留的 `others`、保留的 `products`、overflow tag。
- 输入中已有的 overflow tag 保留，不重复添加。
- `maxTags` 小于 3 时按 3 处理，保证至少能放下两个 overflow tag。

示例：

```text
预算 50，输入 60 个商品 tag
输出 49 个商品 tag + page_cache_overflow_product

预算 50，输入 60 个宽 tag + 5 个商品 tag
输出 48 个宽 tag + page_cache_overflow_product + page_cache_overflow

预算 50，输入 40 个宽 tag + 40 个商品 tag
输出 40 个宽 tag + 9 个商品 tag + page_cache_overflow_product
```

### 3.3 `withOverflow()`

`withOverflow(array $tags): array`

- 保留传入 tag 和顺序。
- 遇到 `catalog_product_<id>` 追加 `page_cache_overflow_product`。
- 遇到非商品 tag 追加 `page_cache_overflow`。
- 已有 overflow tag 不重复添加。
- 结果只用于 proxy purge payload，不用于本地 native cache clean。

### 3.4 header

`Backend\Abstract::getTagHeader()`：

1. 继续用 `sanitizeHeaderValue()` 清理 tag。
2. 去重、去空。
3. 调用 `TagBudget::fit()`。
4. 用逗号拼接。

`X-Cache-Tags` 和调试头 `X-Maho-Cache-Tags` 都走这条路径。未超限时输出与当前实现一致。

### 3.5 purge

`Service\Invalidator::invalidateTags()` 在写 proxy purge 队列时：

```php
$this->queue()->enqueue($urls, Maho_PageCache_Model_TagBudget::withOverflow($tags), Maho_PageCache_Helper_Data::MODE_PROXY);
```

- URL 索引查询、版本递增、静态文件删除仍按原始 tag 执行。
- warm queue 的 reason 仍用原始 tag 前三个，不写 overflow。
- 全量 purge 仍是 `*`，不带 overflow。
- `page-cache:purge --tag=<tag>` 仍按队列里的 tag 过滤；overflow tag 也在队列里，可以直接按 overflow 过滤。

### 3.6 兼容性

- 未超限的页面 header 不变。
- 按 URL purge 的消费者不受影响，因为 purge payload 仍包含 URL。
- 按 tag purge 的消费者需要识别两个 overflow tag；文档和 CHANGELOG 说明。
- 本地 cache、静态文件、URL 索引、warmup 行为不变。

## 4. 任务拆分

- [ ] `Model/TagBudget.php`：`fit()`、`withOverflow()`、常量和商品 tag 识别。
- [ ] `Model/Backend/Abstract.php`：`getTagHeader()` 调用 `fit()`。
- [ ] `Service/Invalidator.php`：proxy purge payload 调用 `withOverflow()`。
- [ ] `docs/specs/page-cache-tag-budget.md`：本规格。
- [ ] 文档：PRD 状态、ARCHITECTURE、API、README、CHANGELOG。
- [ ] 测试：预算边界、宽 tag/商品 tag、overflow、header、purge payload、未超限不变。
- [ ] 验证：单元/集成测试、PHPStan、php-cs-fixer、Rector、实机 proxy 模式。

## 4.1 实现记录

- 代码：`Model/TagBudget.php`、`Model/Backend/Abstract.php`、`Service/Invalidator.php`。
- 测试：`tests/Backend/Unit/PageCache/TagBudgetTest.php` 新增 10 个用例，`InvalidatorTest` 新增 overflow payload 用例；PageCache 套件 225 passed（653 assertions）。
- 静态检查：PHPStan、php-cs-fixer、Rector 通过。
- 实机（CLI，proxy 模式）：
  - 60 个 `catalog_product_*` tag 的响应头输出 50 个 tag，包含 `page_cache_overflow_product`。
  - 失效 `catalog_product_1` 后 purge payload 为 `catalog_product_1,page_cache_overflow_product`。
- 跨库：待模块仓库 CI 在 SQLite、MySQL、PostgreSQL 上验证。

## 5. 验收标准

### AC-21.1 预算

- tag 数不超过 50 时 `fit()` 原样返回。
- 超过 50 时输出不超过 50 个 tag。
- 宽 tag 优先保留，商品 tag 其次。
- 商品 tag 被丢时包含 `page_cache_overflow_product`。
- 非商品 tag 被丢时包含 `page_cache_overflow`。
- 已有 overflow tag 不重复。
- 输入顺序在未超限时不变。

### AC-21.2 header

- `X-Cache-Tags` 未超限时与当前实现字节级一致。
- 超限时 header 包含对应 overflow tag，且 tag 数不超过 50。
- 调试头 `X-Maho-Cache-Tags` 行为一致。
- local/revalidating 模式不输出 `X-Cache-Tags`，行为不变。

### AC-21.3 purge

- `invalidateTags(['catalog_product_1'])` 的 proxy purge payload 包含 `catalog_product_1` 和 `page_cache_overflow_product`。
- `invalidateTags(['cms_page_1'])` 的 payload 包含 `page_cache_overflow`。
- 同时包含商品和非商品 tag 时两个 overflow tag 都出现。
- URL 索引、版本、静态文件、warm queue 行为不变。
- 全量 purge 仍为 `*`。

### AC-21.4 回归

- 现有 PageCache 测试全绿。
- proxy/revalidating 的 URL purge 不受影响。
- PHPStan、php-cs-fixer、Rector 通过。
- 实机 proxy 模式验证一次：超过 50 个 tag 的分类页 header 包含 overflow tag；修改被丢商品后 purge payload 包含 overflow tag。

## 6. 风险与待定

- overflow tag 会带来 over-purge：商品 tag 被丢的页面会被任何商品变化清掉。这是为了不漏清，属于可接受代价。
- 预算固定 50，不随代理配置变化；如果某个代理需要更小或更大的 header，后续再加配置。
- 商品 tag 识别只覆盖 `catalog_product_<id>`；自定义商品 tag 前缀不会被识别，需要后续扩展。
- 已有 overflow tag 的页面不会被重复清理，`withOverflow()` 会去重。

## 7. 已确认的选择

1. 预算固定 50，不新增配置。
2. 宽 tag 优先，商品 tag 其次。
3. 商品 tag overflow 用 `page_cache_overflow_product`，其他 tag 用 `page_cache_overflow`。
4. 只在 proxy purge payload 加 overflow，不改本地 native tag。
5. 未超限时 header 字节级不变。
