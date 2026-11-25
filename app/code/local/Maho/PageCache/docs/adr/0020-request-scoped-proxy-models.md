# ADR-0020：请求级代理模型只缓存实例，不缓存模型数据

## 状态

已接受，随 FR-15（0.6.0）实现。

## 背景

FR-14 的服务器端动态块在 local 命中时不生成完整 layout。block 需要 product、category、CMS page 或 CMS block 时，只能各自调用 `Mage::getModel(...)->load()`。同一页面的多个动态块会重复加载同一个实体；渲染一组商品时每个商品各加载一次，形成 N+1。

页面缓存 entry 已经保存完整 HTML、tag 和动态块定义。可选方案有三种：

- 请求级代理加载器：同一请求内复用模型实例，批量 collection 加载，不持久化模型数据。
- 继承真实模型的懒加载代理：第一次访问数据才 `load()`，可直接放入 `Mage::registry`。
- 页面缓存内模型快照：保存 entry 时序列化动态块用到的模型数据，命中时零 DB。

## 决策

- 新增 `page_cache/proxy` 服务，提供 product、category、CMS page、CMS block 的单个和批量获取。
- 请求内按 `type + store_id + id` 复用实例；负结果也缓存，避免重复查询。
- 同类型多 ID 使用 collection 批量加载；固定附加查询只允许分类 ID 和 URL rewrite 这类不随实体数量增长的查询。
- local 保存时只记录当前页面的 product/category/CMS page ID，写入 entry 的 `proxy_entities`；命中时批量加载并恢复 `current_product`、`current_category`、`cms_page` 等 registry。
- 动态块可用 `page_cache_proxies` 声明预加载实体；命中时在渲染前统一加载。
- 代理加载过的实体把 `getCacheTags()` 合并进页面 tag；实体失效继续走现有本地缓存、静态文件和 purge 链路。
- 不拦截 `Mage::getModel()`，不修改核心模型，不把模型数据写入页面缓存。
- 只覆盖 product/category/CMS page/CMS block；customer、quote、session、order 等请求相关模型不在范围内。

## 理由

- 动态块已经通过显式标记 opt-in。代理也采用显式服务调用和 `page_cache_proxies` 声明，边界清晰，不改变未标记 block 的行为。
- 请求级实例和批量 collection 能把重复加载和 N+1 收敛，同时避免新的持久化缓存和失效维度。
- 页面缓存 entry 已经保存 HTML 和 tag，再保存模型数据会放大缓存体积、引入价格/商店上下文和失效复杂度。
- 继承真实模型的懒加载代理需要覆盖 Magento 模型的内部数据访问，风险高于收益。
- 复用现有 tag 失效链路，满足“实体失效后代理数据不陈旧”，不新增后台配置。

## 后果

- 动态块需要改用 `Mage::getService('page_cache/proxy')` 取实体，或在 layout 中声明 `page_cache_proxies`。
- 当前页面的 product/category/CMS page 会在 local 命中时预加载；没有服务器端动态块的命中不增加查询。
- 批量加载使用 `addAttributeToSelect('*')`，用引用数量上限控制开销。
- block 调用 related、upsell 等资源方法仍可能产生额外查询；本 ADR 只保证代理加载本身不 N+1。
- 升级后旧 entry 没有 `proxy_entities`，仍可命中，但不会恢复上下文；发布说明要求 flush 一次。
- 多节点共享代理状态不在本 ADR 范围，留给 FR-19。

## 备选方案

- **继承真实模型的懒加载代理**：可以放进现有 registry 并延迟加载，但要覆盖 Magento 模型的 `getData()`、`hasData()` 等内部路径，测试面和回归风险大，不采用。
- **页面缓存内模型快照**：命中零 DB，但缓存体积、价格/商店上下文和失效复杂度最高，不采用。
- **不做代理，只依赖 block 自己的 `load()`**：实现最小，但重复加载和 N+1 仍然存在，不满足 FR-15。
- **拦截 `Mage::getModel()` 全局替换**：透明但侵入核心工厂，影响所有请求，不采用。
