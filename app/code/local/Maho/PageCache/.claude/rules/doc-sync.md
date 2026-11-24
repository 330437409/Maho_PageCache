# 文档同步

行为变化后同步这些文档：

| 变化 | 更新 |
|---|---|
| FR 范围、优先级、状态 | `docs/PRD.md` §4 |
| 模块、缓存键、运行时流程、失效机制 | `docs/ARCHITECTURE.md` |
| 后台配置、HTTP 头、CLI、purge 事件 | `docs/API.md` |
| 架构决策 | 新增或更新 `docs/adr/` |
| 第一期功能细节 | `docs/specs/page-cache.md` |
| 用户可见变化 | `CHANGELOG.md` |
| 安装和使用方式 | `README.md` |

规则：

- 不删历史 ADR。被取代的 ADR 标记“已被 ADR-NNNN 取代”。
- 不把实现细节写进 PRD。PRD 只保留需求、验收和状态。
- 不在多个文档复制同一段决策。决策放 ADR，其他文档引用。
- 文档和代码在同一个提交里更新。
- 验收前确认 PRD 的 FR 状态与代码、测试、CHANGELOG 一致。
