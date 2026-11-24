# ADR-0009：local 模式把整页 HTML 存入 Symfony Cache

## 状态

已接受，随 Phase 2 实现。

## 背景

第二期要支持没有反向代理的环境。Phase 1 只设置 HTTP 头，HTML 仍由 PHP 每次生成。需要一个 PHP 内的整页缓存。

Maho 已有 Symfony Cache（file/redis）和 `page_cache` 缓存类型；第一期已经用它保存元数据、tag 索引、版本和 purge 队列。

## 决策

- `local` 模式把整页 HTML 存入 `Mage::app()->getCache()`。
- HTML key 包含 URL hash、context hash、URL 版本、全局版本和模式。
- 条目同时打 `PAGE_CACHE` 和 `PAGE_CACHE_HTML` tag。
- 不新增数据库表，不新增缓存后端。
- HTML 以字符串保存，第二期不压缩；条目大小有上限，默认 2 MB。
- 命中在 `controller_action_predispatch` 处理，设置 `FLAG_NO_DISPATCH`，不生成 layout。
- 旧条目靠 TTL 过期；版本变化后 key 不再命中。
- 无 session cookie 请求里的动态 block 保留匿名渲染；form key/SID 用占位符，浏览器在用户提交 POST 表单时才请求。

## 理由

- 复用现有缓存栈和 tag，不引入新依赖。
- 版本化 key 让 tag 失效不需要遍历删除所有 context 的条目。
- TTL 兜底，避免孤儿条目永久占用空间。
- 与 Phase 1 的元数据/版本/tag 索引共享，失效逻辑一致。

## 后果

- HTML 占用缓存内存/磁盘，需要大小上限和监控。
- 旧 context 的条目在 TTL 内仍占用空间。
- 浏览器缓存策略为 `private, max-age=0, must-revalidate`，每次回源到 Maho，但 Maho 内命中。
- 没有 HTML 压缩，Redis 内存可能增长；第三期可加压缩。

## 备选方案

- **新增 HTML 表**：更持久，但需要 schema 和清理；第二期不做。
- **另建缓存后端**：增加配置和运维面；复用 `page_cache` 足够。
- **只缓存 block 不缓存整页**：不能跳过 layout，收益小。
