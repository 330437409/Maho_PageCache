# 功能规格：Maho 页面缓存请求级代理模型

> 状态：实现完成，待发版 0.6.0
> 关联 PRD：FR-15
> 关联 ADR：ADR-0007、ADR-0010、ADR-0015，新增 ADR-0020
> 目标版本：0.6.0

## 1. 背景与目标

服务器端动态块（FR-14）在 local 命中时不生成完整 layout。block 需要 product、category、CMS page 或 CMS block 时，只能自己调用 `Mage::getModel(...)->load()`。同一页面的多个动态块会重复加载同一个实体；渲染一组商品时，每个商品各加载一次，形成 N+1。

本功能增加一个请求级代理加载器：

- 同一请求内，同一类型、同一 store、同一 ID 的实体只加载一次，后续调用复用同一个模型实例。
- 支持按类型批量加载，避免动态块渲染列表时逐条 `load()`。
- local 命中时从缓存 entry 恢复当前页面的 product/category/CMS page 上下文，注册回 `Mage::registry`，让现有依赖这些 registry 的 block 直接复用。
- 代理只缓存请求内的模型实例，不把模型数据写进页面缓存；实体失效继续沿用现有 tag 机制。
- 动态块可以在 layout 中声明需要预加载的实体，命中时由代理统一批量加载。

目标是把动态块渲染的重复实体查询收敛为“每个实体类型每批最多一次”，同时不引入新的持久化缓存和失效维度。

## 2. 需求

### 2.1 范围内

- 新增服务 `page_cache/proxy`，提供 product、category、CMS page、CMS block 的单个和批量获取。
- 同一请求内按 `type + store_id + id` 复用模型实例，负结果也缓存。
- 批量加载使用 collection，一次查询加载一批同类型实体。
- local 保存时记录当前页面的 product/category/CMS page ID；命中时预加载并恢复 registry。
- 动态块可用 `page_cache_proxies="product:1,product:2,category:3"` 声明预加载实体；命中时在渲染前批量加载。
- 代理加载过的实体把 `getCacheTags()` 合并进页面 tag，实体保存后页面按现有失效链路清理。
- 对引用格式、类型白名单、ID 范围和数量上限做校验；非法项忽略并记录 debug 日志。
- 新增 ADR-0020，记录“只缓存请求内实例，不缓存模型数据”的决策和失效边界。

### 2.2 范围外

- 把 product/category/CMS 数据序列化进页面缓存 entry。
- 拦截或重写 `Mage::getModel()`、`Mage::registry()`，让所有现有 block 自动走代理。
- 客户、session、购物车、价格规则等请求相关模型。
- 通过代理执行 save/delete，或把代理当作写模型使用。
- 自动加载 related、upsell、crosssell 等商品集合。
- 多节点共享代理状态（FR-19）。
- 新的后台配置项；功能跟随 `page_cache/dynamic/server_side` 和 block 标记启用。

## 3. 设计

### 3.1 服务 API

服务别名 `page_cache/proxy`，类 `Maho_PageCache_Service_Proxy`，通过 `Mage::getService('page_cache/proxy')` 获取。

```php
public function getProduct(?int $id = null): ?Mage_Catalog_Model_Product;
public function getProducts(array $ids): array; // [id => product]

public function getCategory(?int $id = null): ?Mage_Catalog_Model_Category;
public function getCategories(array $ids): array; // [id => category]

public function getCmsPage(?int $id = null): ?Mage_Cms_Model_Page;
public function getCmsPages(array $ids): array; // [id => page]

public function getCmsBlock(?int $id = null): ?Mage_Cms_Model_Block;
public function getCmsBlocks(array $ids): array; // [id => block]

public function preload(string|array $references): void;
public function capturePageContext(): array;
public function restorePageContext(array $context): void;
public function getTags(): array;
public function reset(): void;
```

规则：

- 单个 getter 传 `null` 时返回当前页面上下文实体；没有上下文时返回 `null`。
- 批量 getter 只返回找到的实体，缺失的 ID 不出现在结果里。
- `getProducts()` 等返回数组按传入 ID 顺序排列，键为 ID。
- 所有 getter 不抛异常；加载失败记录日志并返回 `null` 或空数组。
- `reset()` 清空实例、负结果和 tag，只用于测试和长驻进程。

### 3.2 引用格式

`preload()` 接受两种格式：

```php
$proxy->preload('product:1,product:2,category:3,cms_page:4,cms_block:5');
$proxy->preload([
    'product' => [1, 2],
    'category' => [3],
    'cms_page' => [4],
    'cms_block' => [5],
]);
```

解析规则：

- 类型白名单：`product`、`category`、`cms_page`、`cms_block`。
- ID 必须是正整数；重复项去重。
- 空字符串、未知类型、非数字 ID、零和负数忽略。
- 单次 `preload()` 最多处理 200 个唯一引用；超出部分截断并记录 warning。
- 单个 block 的 `page_cache_proxies` 最多解析 100 个引用；超出部分忽略并记录 debug。

### 3.3 请求内缓存键

实例 map 的键为：

```text
<type>:<store_id>:<id>
```

`store_id` 取当前 store。商品和 CMS 加载时显式使用该 store；分类 collection 通过当前 store 读取 store 相关属性。请求内切换 store 时不会复用另一个 store 的实例。

负结果也写入 map，值为 `null`，避免同一缺失实体重复查询。

### 3.4 批量加载

每个实体类型每批只发一次主查询：

- product：`catalog/product` collection，`addIdFilter()`、`addStoreFilter()`、`addAttributeToSelect('*')`、`addUrlRewrite()`、`addCategoryIds()`。
- category：`catalog/category` collection，`addIdFilter()`、`addAttributeToSelect('*')`、`addUrlRewriteToResult()`；store 由 collection 的当前 store 决定。
- cms page：`cms/page` collection，`main_table.page_id in (...)`、`addStoreFilter($storeId)`，包含 store 0 的全局页面。
- cms block：`cms/block` collection，`main_table.block_id in (...)`、`addStoreFilter($storeId)`，包含 store 0 的全局 block；过滤列必须带表名，避免和 store 表的同名列冲突。

加载后把每个 item 放入 map，并收集 `getCacheTags()`。`addCategoryIds()` 和 URL rewrite 的 join 属于固定附加查询，不随实体数量增长。

单个 getter 只加载缺失的 ID；如果 map 已有实例，直接返回，不再查询。

### 3.5 local 保存：记录页面上下文

`Observer::saveLocalResponse()` 调用 `capturePageContext()`，从当前请求读取：

- `Mage::registry('current_product')` 的 ID。
- `Mage::registry('current_category')` 的 ID。
- `Mage::getSingleton('cms/page')` 的 ID。

返回并写入 entry：

```php
'proxy_entities' => [
    'product' => [123],
    'category' => [45],
    'cms_page' => [67],
]
```

只保存 ID，不保存名称、价格、URL 或任何模型数据。`HtmlCache::save()` 增加可选参数 `$proxyEntities`，对 ID 做同样的正整数校验。

保存前把 `getTags()` 合并进页面 tag：

```php
$tags = array_merge($tags, Mage::getService('page_cache/proxy')->getTags());
```

这样 local HTML、meta 和静态文件都带实体 tag，商品/分类/CMS 保存时按现有链路失效。

### 3.6 local 命中：恢复上下文

`Service\Dynamic::renderServerBlocks()` 在渲染块之前：

1. 如果没有 `PC_DYNAMIC_SERVER` 标记，直接返回，不做任何代理加载。
2. 读取 entry 的 `proxy_entities`，调用 `preload()`。
3. 遍历有效的 block 定义，收集 `data['page_cache_proxies']`，再次调用 `preload()`。
4. 调用 `restorePageContext($entry['proxy_entities'])`：
   - 有 current product：注册 `product` 和 `current_product`。
   - 有 current category：注册 `current_category`，并注册 `current_entity_key` 为分类 path。
   - 有 CMS page：注册 `cms_page`；如果 `Mage::getSingleton('cms/page')` 还没有 ID，把预加载 page 的数据写入该 singleton，供 `cms/page` block 使用。
5. 按现有逻辑校验 token 并渲染 block。

页面上下文预加载只在存在服务器端动态块时发生；没有标记的 local 命中不增加查询。

### 3.7 动态块声明

block 在 layout XML 中增加属性：

```xml
<block type="catalog/product_list_related"
       name="related.products"
       template="catalog/product/list/related.phtml"
       page_cache_dynamic="1"
       page_cache_dynamic_server="1"
       page_cache_proxies="product:1,product:2,product:3" />
```

也可以在 PHP 中：

```php
$block->setData('page_cache_proxies', 'product:1,product:2');
```

`page_cache_proxies` 是标量字符串，会随现有 `scalarData()` 进入 block 定义。命中时 `renderServerBlocks()` 在渲染前统一预加载。

block 代码通过服务取模型：

```php
$proxy = Mage::getService('page_cache/proxy');
$product = $proxy->getProduct($id);
$products = $proxy->getProducts($ids);

if ($product === null) {
    return '';
}
```

对于当前页实体，可以省略 ID：

```php
$product = $proxy->getProduct();
$category = $proxy->getCategory();
$cmsPage = $proxy->getCmsPage();
```

### 3.8 失效

- 代理不跨请求保存模型数据，命中时读取的是当前数据库值，不会出现“页面还在、代理数据陈旧”。
- 保存时合并实体 tag，实体变化会清理 local HTML、meta、静态文件和 purge 队列，沿用现有失效链路。
- 实体删除后批量加载找不到该 ID，getter 返回 `null`；页面 tag 仍会在删除时触发失效。
- 升级到 0.6.0 后旧 entry 没有 `proxy_entities`，仍可命中，只是不会恢复上下文。发布说明建议升级后执行一次 `./maho cache:flush`。

### 3.9 安全与边界

- 只接受缓存 entry 和 layout 中的实体引用，不接受客户端传入的类型或 ID。
- 只加载 product/category/CMS page/CMS block，不加载 customer、quote、session、order 等请求相关模型。
- 不把模型数据写入缓存，不增加 PII 暴露面。
- 引用解析有类型白名单和数量上限；非法值不进入 SQL。
- 加载异常只记录日志，不影响页面响应；动态块仍走匿名 fallback。
- `restorePageContext()` 只在 local 命中路径调用，不改变正常 layout 请求的 registry。

## 4. 任务拆分

- [ ] `docs/adr/0020-request-scoped-proxy-models.md`：新 ADR，记录数据来源、批量加载、tag 失效和“不缓存模型数据”的边界。
- [x] `Service/Proxy.php`：解析、实例 map、批量加载、上下文捕获/恢复、tag 收集。
- [x] `Model/HtmlCache.php`：entry 增加 `proxy_entities`，保存时校验。
- [x] `Model/Observer.php`：保存时捕获上下文，保存前合并代理 tag。
- [x] `Service/Dynamic.php`：命中前预加载页面上下文和 block 声明，恢复 registry。
- [ ] `Helper/Data.php`：如需要，提供引用解析上限的常量或 getter；不新增后台配置。
- [x] 文档：PRD 状态、ARCHITECTURE、API、README、CHANGELOG。
- [x] 测试：解析、复用、批量查询数、store 隔离、缺失实体、tag、上下文恢复、动态块集成、回归。

## 4.1 实现记录

- 代码：`Service/Proxy.php`、`Model/HtmlCache.php`、`Model/Observer.php`、`Service/Dynamic.php`、`etc/config.xml`。
- ADR：`docs/adr/0020-request-scoped-proxy-models.md`。
- 测试：`tests/Backend/Unit/PageCache/ProxyModelTest.php` 新增 13 个用例；PageCache 套件 214 passed（624 assertions）。测试自建商品、分类、CMS page、CMS block 和 store view，不依赖 sample data。
- 静态检查：PHPStan、php-cs-fixer、Rector 通过。
- 实机（SQLite，local 模式）：
  - CMS 页面挂两个 `core/template` 服务器端动态块，声明 `page_cache_proxies="product:1"`。
  - 第一次请求 MISS，匿名 fallback 显示商品名；第二次请求 HIT，两个 block 都渲染 live 商品名，且 `spl_object_id` 相同，说明同一请求复用一个实例。
  - 修改商品名后页面缓存按 `catalog_product_1` 失效，下一次请求 MISS 并显示新名称，随后再次 HIT。
  - 临时 CMS 页面、模板和配置已清理。
- 跨库：模块 CI run 45404003036，SQLite、MySQL、PostgreSQL 全部通过。

## 5. 验收标准

### AC-15.1 按 store/context 加载

- 同一 product 在 store 1 和 store 2 加载出各自的 store 数据，不共享实例。
- category 使用当前 store 的属性；CMS page/block 使用当前 store 的 store filter。
- 当前页面的 product/category/CMS page 从 entry 的 `proxy_entities` 恢复，ID 与保存时一致。
- 没有上下文时单个 getter 返回 `null`，批量 getter 返回空数组。

### AC-15.2 动态块不产生 N+1

- 同一请求内两次 `getProduct($id)` 返回同一实例，只加载一次。
- `getProducts()` 批量加载 1 个和 5 个商品，查询次数不随商品数量增长。
- 声明 `page_cache_proxies="product:1,..."` 的动态块在渲染前完成批量加载，block 内逐项 getter 不再触发查询。
- local 命中有多个服务器端动态块时，当前 product/category/CMS page 各最多加载一次。
- 固定附加查询（分类 ID、URL rewrite）允许存在，但不得按实体数量增长。

### AC-15.3 实体失效后不陈旧

- 代理只保存请求内实例，不写页面缓存或持久缓存。
- 代理加载过的实体 tag 进入页面 tag；保存 product/category/CMS page/CMS block 后相关页面失效。
- 删除实体后代理返回 `null`，不返回旧数据。
- 旧 entry 没有 `proxy_entities` 时仍能正常命中，不报错。

### AC-15.4 安全和失败边界

- 未知类型、非正整数、超过上限的引用被忽略，不进入查询。
- 加载异常、collection 异常或单个实体缺失时页面仍为 200，动态块回退匿名 HTML。
- 代理不加载 session/customer/quote 模型，不读取 cookie。
- 不新增数据库表，不修改 Maho 核心表结构。

### AC-15.5 回归

- proxy/revalidating 的 Ajax/ESI 动态块行为不变。
- static 导出仍只包含匿名 fallback，不执行代理预加载。
- 现有 PageCache 测试全绿。
- `PHPStan`、`php-cs-fixer`、`Rector` 通过。

### AC-15.6 验证门

- 新增单元测试和集成测试通过，并记录命令与结果。
- 实机：product 页挂两个服务器端动态块，local 命中时两个块使用同一个 product 实例，查询日志只有一次商品加载。
- 实机：保存该商品后 local 页面失效，下一次请求 MISS 并重新渲染。
- SQLite、PostgreSQL、MySQL 至少覆盖批量加载、上下文恢复和 tag 合并。

## 6. 风险与待定

- 批量加载使用 `addAttributeToSelect('*')`，单个实体多时内存和查询列会增加；用 200 个引用上限控制。
- `addUrlRewrite()`、`addCategoryIds()` 仍是固定附加查询；如果 block 调用 related/upsell 等资源方法，仍可能产生额外查询，本 FR 只保证代理加载本身不 N+1。
- 恢复 `cms/page` singleton 可能影响依赖该 singleton 的第三方 block；只在 local 命中且 singleton 未加载时写入，并且仍以匿名 HTML fallback 兜底。
- 旧 entry 没有 `proxy_entities`，升级后的首次命中不会恢复上下文；发布说明要求 flush 一次。
- 分类 collection 的 store 由当前 store 决定，不支持同一请求内跨 store 复用；这符合 local 命中只服务当前 store 的前提。
- 如果主题在 `page_cache_proxies` 中声明了大量实体但 block 实际不使用，会产生无效预加载；用单 block 100 个上限和文档约束。

## 7. 已确认的选择

1. 采用请求级代理加载器，不采用继承真实模型的懒加载代理，也不把模型数据写入页面缓存。
2. 同一请求内按 `type + store_id + id` 复用实例，负结果也缓存。
3. 批量加载使用 collection；动态块通过 `page_cache_proxies` 声明预加载实体。
4. local 保存时只记录当前 product/category/CMS page 的 ID，命中时恢复 registry。
5. 实体 tag 合并进页面 tag，失效继续走现有链路。
6. 不新增后台配置；功能跟随 `page_cache/dynamic/server_side` 和 block 标记。
