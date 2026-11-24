# 测试与质量门

## 原则

- 新行为先写失败测试，再写实现。测试要能在实现前红、实现后绿。
- 测试表达需求，不表达实现细节。优先测行为、边界和失败路径。
- 不声称完成，除非能出示命令和结果。
- 不跑全量 `composer test`，除非用户要求、改测试基座或复现 CI 问题。

## 必跑

- 新增或修改的单元测试。
- 涉及 session、HTTP 头、缓存、失效、CLI 的集成测试。
- `composer lint` 或至少针对修改文件运行 php-cs-fixer、PHPStan、Rector。
- 手工 curl 验证 CMS、分类、商品页的 200、304、bypass 和 purge。
- 保存 product/category/CMS 后验证 tag 失效。

## 测试覆盖重点

- Context：store、货币、客户组、税、query 参数、默认上下文。
- Bypass：所有原因码，至少覆盖 method、route、session_cookie、parameter、admin、preview、maintenance。
- Session skip：无 cookie 跳过、有 cookie 不跳过、POST 不跳过。
- Header：proxy/revalidating 的 `Cache-Control`、`Vary`、`X-Maho-Cache`。
- ETag：匹配 304、版本变化 200、context 变化 200、TTL 变化 200、元数据丢失 200。
- UrlIndex：添加、变体合并与截断、tag 查询、removeTag、清理、DB 异常回退全量。
- PurgeQueue：入队、去重、dry-run、limit、tag 过滤、成功删除、失败保留。
- 失效：product/category/CMS、catalog rule 细粒度、sales rule/config 全量。
- CLI：status 输出、purge 选项、事件/文件输出。
- 计数：不阻塞请求、结果 key 正确、代理命中不统计的说明。

## 证据

完成声明必须包含：

- 测试命令和通过结果。
- 手工验收步骤和结果。
- 如涉及页面，提供 curl 输出或明确的页面路径。
- 未运行的测试和原因。

## 红线

- 跳过测试直接实现。
- 测试绿了但页面实际不可用。
- 用“应该可以”代替实际验证。
- 为通过测试而弱化断言或删除失败测试。
- 为了命中率放宽 bypass，导致客户数据泄露。
