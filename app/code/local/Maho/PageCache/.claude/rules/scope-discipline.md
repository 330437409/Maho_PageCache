# 范围纪律

第一期范围（本次交付）是 PRD 中 FR-01 到 FR-10：

- 页面缓存配置和模式
- 匿名 GET session skip
- context 和 HTTP 头
- `revalidating` ETag/Last-Modified + 304
- `proxy` 和 purge 队列
- 实体 tag 失效
- catalog rule 细粒度、其他规则/配置全量失效
- bypass 和调试头
- CLI status/purge
- PHP 侧计数

第一期明确不做：

- `local` 模式
- 整页 HTML 缓存
- Ajax/ESI 动态块
- 服务器端占位符
- product/category/CMS 代理模型
- 缓存预热
- 后台命中率网格
- 具体代理的 purge 适配器
- 新数据库表
- 新 Composer 依赖

第二期和第三期只在 PRD、ARCHITECTURE 和 ADR 中规划，不写代码、不建占位字段、不加未使用的配置项。

遇到“顺手加一个”的冲动时先记录到 PRD 的后续期，不改当前期范围。确实需要提前做时，先让用户确认并更新 PRD、spec 和 ADR。
