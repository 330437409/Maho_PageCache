# ADR-0004：tag 加版本失效，规则和配置按混合粒度处理

## 状态

已接受。

## 背景

页面缓存的失效比对象缓存复杂：一个商品可能出现在商品页、分类页、搜索页、CMS widget 和首页。只按 URL 失效会漏，只按全量失效会频繁清空缓存。

Maho 的模型已经有 `getCacheIdTags()`，product、category、CMS page、CMS block 都有 `_cacheTag`。

## 决策

- 实体保存/删除：读取 `getCacheIdTags()`，按 tag 失效相关页面。
- product 保存：同时追加所属分类的 tag。
- catalog rule：用 `getMatchingProductIds()` 计算受影响产品，失效产品和分类 tag。
- sales rule、`core_config_data`、主题/design、layout、store 配置：全量失效。
- 后台清缓存、`core_clean_cache`、`adminhtml_cache_flush_all`：全量失效。
- 索引丢失、规则计算失败、受影响产品为空：回退全量失效。

全量失效递增全局版本；tag 失效递增相关 URL 的版本。

## 理由

- 实体 tag 是 Maho 现有模型提供的稳定信息，不需要新建映射表。
- catalog rule 影响范围可以计算，值得细粒度处理。
- sales rule 主要影响购物车和 checkout，页面缓存本来就 bypass，全量失效更安全。
- 配置和主题影响所有页面，细粒度映射容易漏。

## 后果

- 商品和分类的 tag 需要维护 tag 到 URL 的索引。
- 索引存缓存，可能被淘汰；淘汰时全量失效，牺牲命中率换正确性。
- catalog rule 保存可能变慢，必要时第二期改为异步。
- 规则和配置变更会导致全量失效，频率低，可接受。

## 备选方案

- **所有实体全量失效**：正确但命中率低，不采用。
- **所有实体细粒度**：规则和配置的依赖关系太复杂，不采用。
- **建 tag 映射表**：更可靠，但第一期不想新增表和迁移，先用缓存索引。
