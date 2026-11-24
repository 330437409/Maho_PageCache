# 架构不变量

这些边界不能在没有新 ADR 的情况下打破。

- 不替换 `Mage_Core_Model_Cache`，不引入 `Zend_Cache`、`Zend_Locale` 或新的 Varien 代码。
- 缓存存储只使用 Maho 的 Symfony Cache（file/redis），不新增 Composer 依赖。
- 第一期不新增数据库表，不修改 Maho 核心表结构。
- 第一期只实现 `proxy` 和 `revalidating`，不实现 `local`、动态块打孔、服务器端占位符和代理模型。
- 默认只缓存匿名 GET；admin、install、API、checkout、customer account、preview、维护模式、POST 一律 bypass。
- 有 Maho session cookie 的请求不缓存、不跳过 session。
- 跳过 session 只通过 `controller_action_predispatch_session_start`，不读取 `customer/session`、`checkout/session`、`core/session`。
- 不缓存包含 `Set-Cookie` 的响应，不缓存非 200 响应，不缓存空 body。
- tag、context、bypass 原因和版本值必须过滤，不能直接回显用户输入。
- 缓存 key 和 purge 文件不使用邮箱、姓名、地址等 PII，只使用 hash 和 tag。
- tag 索引丢失、规则计算失败或异常时，回退全量失效；宁可多清，不可漏清。
- 全量失效递增全局版本；tag 失效递增相关 URL 版本。
- 304 必须在渲染前决定，不能先渲染再比较。
- purge 不执行任意 shell 命令；默认触发事件或写 `var/page-cache` 文件。
- 不复制 RockSolid、Cm_Diehard 或其他 GPL 代码。
- 第二期和第三期的接口可以规划，但不写空实现、不建占位字段、不加未使用的配置项。
