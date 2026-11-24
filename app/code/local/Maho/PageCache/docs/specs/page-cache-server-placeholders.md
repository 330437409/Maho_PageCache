# 功能规格：Maho 页面缓存服务器端动态块占位符

> 状态：已交付@v0.5.0
> 关联 PRD：FR-14
> 关联 ADR：ADR-0007、ADR-0010，新增 ADR-0015
> 分支：`feat/page-cache-phase2`（继续）

## 1. 背景与目标

第二期的本地页面缓存命中时，整页 HTML 直接来自缓存。动态块在 local 模式不替换，匿名渲染结果被写进共享缓存。这样缓存页里的动态块不会随请求刷新，也无法在不重新渲染整页的情况下更新。

本功能为 local 命中增加服务器端占位符：

- 页面保存时记录被显式标记的动态块定义。
- 缓存 HTML 中保留匿名渲染作为 fallback。
- 命中时只重建这些 block，并用 live HTML 替换 fallback，不生成完整 layout。
- proxy/revalidating 继续使用 Ajax/ESI，不改变现有行为。
- static 文件继续提供匿名 fallback，不执行服务器端重建。

## 2. 需求

### 2.1 范围内

- local 模式下显式标记的 leaf block 可以保存定义并在命中时服务器端重建。
- 新增 block 标记 `page_cache_dynamic_server=1`，与现有 `page_cache_dynamic=1` 同时存在才生效。
- 新增全局开关 `page_cache/dynamic/server_side`，默认关闭。
- 缓存 HTML 使用带签名 token 的 HTML 注释标记，保留匿名 HTML。
- 命中时校验 token、URL、store、context 和过期时间。
- 重建使用轻量 block 工厂，不触发整页 layout 生成事件。
- 重建失败、token 无效或产生 session/cookie 时回到匿名 fallback。
- static 导出剥离标记，只保留匿名 fallback。
- 记录 `dynamic` 统计事件和 debug 头。

### 2.2 范围外

- 改变 session cookie bypass 策略。
- 在 local 命中路径启动 session 或返回客户数据。
- proxy/revalidating 的服务器端重建。
- 任意 block 类型、客户端指定 block、带子 block 的容器 block。
- 代理模型（FR-15）和多节点（FR-19）。
- 动态块自身的独立缓存 TTL（沿用现有 `page_cache/dynamic/ttl`）。

## 3. 设计

### 3.1 开关和标记

配置：

| 路径 | 默认 | 说明 |
|---|---|---|
| `page_cache/dynamic/server_side` | 0 | local 命中服务器端重建开关 |
| `page_cache/dynamic/server_types` | 空 | 允许重建的 block type 别名，逗号分隔；空表示不启用 |

block 必须同时满足：

- `page_cache_dynamic=1`，沿用现有动态块白名单。
- `page_cache_dynamic_server=1`，显式声明可以被服务器端重建。
- block type 在 `server_types` 中。
- block 是 leaf block，`toHtml()` 不依赖父 block、子 block、head 或整页 layout。

主题在 layout XML 中用 `setData` 设置两个标记。文档给出示例。

### 3.2 保存

`Observer::handleDynamicBlock()` 在 local 正常渲染时：

1. 检查配置和两个 block 标记。
2. 检查 block type 在允许列表。
3. 生成 `id = substr(sha256(name|type|template), 0, 16)`。
4. 生成 token：`block id`、URL、store、context、exp。exp 为页面 TTL + 300 秒。
5. 把 block 的匿名 HTML 包在标记中：

```html
<!--PC_DYNAMIC_SERVER:<token>-->
...anonymous html...
<!--/PC_DYNAMIC_SERVER:<id>-->
```

6. 记录定义：

```php
[
    'id' => $id,
    'type' => $type,
    'name' => $name,
    'template' => $template,
    'data' => $scalarData,
]
```

`$scalarData` 只保留标量、null 和标量数组；对象、闭包和其他资源不保存。block 需要的数据必须能通过这些值恢复。

`saveLocalResponse()` 把定义列表传给 `HtmlCache::save()`，entry 增加 `dynamic_blocks`。HTML 大小上限仍然作用于带标记的 HTML。

### 3.3 命中

`Backend\Local::applyHit()` 在设置 body 前调用 `Service\Dynamic::renderServerBlocks()`：

1. 用非贪婪正则匹配 `PC_DYNAMIC_SERVER` 标记。
2. 校验 token 签名、exp、URL、store、context。
3. 用 `id` 从 entry 的 `dynamic_blocks` 取定义。
4. `Mage::app()->getLayout()->createBlock($type, $name, $data)`，设置 template。
5. 调用 `toHtml()`，用 live HTML 替换整个标记段。
6. 任一步失败：保留匿名 fallback，记录日志，不抛 500。
7. 302/304 命中不重建；304 的 body 为空，客户端继续使用匿名 fallback。

block 创建会触发 `core_layout_block_create_after`；不得触发 `controller_action_layout_generate_blocks_before/after`、`controller_action_layout_load_before/after` 或整页 `_generateBlocks`。

### 3.4 session 和 cookie 保护

local 命中只面向无 session cookie 的请求。服务器端重建必须保持这一点：

- 重建前后检查 `Set-Cookie` 响应头。
- 如果出现 `Set-Cookie` 或 session 已启动，丢弃 live HTML，回到匿名 fallback，并移除本次重建产生的 `Set-Cookie`。
- 记录 warning 和 block id，不记录用户数据。
- 允许列表中的 block 必须不读取 session、customer、checkout、quote 或 cookie 中的用户数据。
- 新增测试覆盖“重建 block 尝试启动 session 时 fallback”。

### 3.5 static 导出

`StaticCache::save()` 写入前调用 `Service\Dynamic::stripServerBlockMarkers()`：

- 删除开始和结束标记。
- 保留标记内的匿名 HTML。
- static 文件不包含 token 或 block 定义。
- static 命中不执行服务器端重建，继续显示匿名 fallback。

### 3.6 失效和统计

- 动态块定义的缓存 key 随 local entry 一起失效；tag 失效和版本变化无需额外处理。
- 重建成功时记录 `dynamic` 统计事件；失败按现有日志记录，不单独建表。
- debug 头增加 `X-Maho-Cache-Dynamic: server` 和重建数量；关闭 debug 时不输出。

## 4. 任务拆分

- [ ] `docs/adr/0015-server-side-dynamic-blocks.md`：新 ADR，说明 local 范围、白名单、session 限制和 static fallback。
- [ ] `docs/adr/0010-dynamic-blocks.md`：注明 ADR-0015 补充 local 服务器端占位符，Ajax/ESI 仍适用于 proxy/revalidating。
- [ ] `etc/system.xml`、`etc/config.xml`：`server_side` 和 `server_types`。
- [ ] `Helper/Data.php`：配置 getter 和 type 列表解析。
- [ ] `Service/Dynamic.php`：标记、token、定义、render、strip。
- [ ] `Model/Observer.php`：local 保存时收集定义，命中时调用 render。
- [ ] `Model/HtmlCache.php`：entry 保存 `dynamic_blocks`。
- [ ] `Model/Backend/Local.php`：命中时替换 body。
- [ ] `Model/StaticCache.php`：写入前剥离标记。
- [ ] 语言包：后台配置文案。
- [ ] 测试：保存、命中、fallback、token、session、layout 事件、static、proxy 回归。
- [ ] 文档：PRD、ARCHITECTURE、API、README、CHANGELOG。

## 4.1 实现记录

- 代码：`Service/Dynamic.php`、`Model/Observer.php`、`Model/HtmlCache.php`、`Model/Backend/Local.php`、`Model/StaticCache.php`、`Helper/Data.php`、`etc/config.xml`、`etc/system.xml`。
- ADR：`docs/adr/0015-server-side-dynamic-blocks.md`，ADR-0010 补充说明。
- 测试：163 passed（443 assertions）。
- 实机（SQLite，local 模式）：
  - 临时 CMS 页面挂载一个 `core/template` 动态块，block 同时标记两个 flag，`server_types=core/template`。
  - 第一次请求 MISS，响应包含匿名时间和 `PC_DYNAMIC_SERVER` 标记。
  - 第二次请求 HIT，时间更新为 2 秒后的 live 值，标记被替换，响应没有 `Set-Cookie`。
  - 静态导出文件包含匿名时间，不含标记，`.gz` 正常生成。
  - 临时 CMS 页面、模板、layout update、配置和静态文件已清理。
- 跨库：功能只使用缓存和 HTML 替换，无新增表；由模块仓库 CI 在 SQLite、PostgreSQL、MySQL 上跑完整 PageCache 套件。

## 5. 验收标准

### AC-14.1 保存

- 同时标记 `page_cache_dynamic` 和 `page_cache_dynamic_server` 且 type 在允许列表的 leaf block，保存时写入定义，HTML 中出现标记和匿名 fallback。
- 未同时满足标记、type 或 leaf 条件的 block 保持现有行为。
- 定义只包含标量数据，不包含对象、session、customer 或 cookie。
- 带标记的 HTML 仍受 `html_max_size` 限制。

### AC-14.2 命中

- local 命中时 live block HTML 替换匿名 fallback，页面其余部分来自缓存。
- 响应带 `X-Maho-Cache: HIT`，不生成完整 layout。
- 不触发整页 layout 生成事件。
- token 无效、过期、URL/store/context 不匹配、block 渲染异常时保留匿名 fallback，响应仍为 200。
- 304 命中不重建，body 为空。

### AC-14.3 安全

- 只使用 entry 中的 block 定义，不接受客户端指定 block 或 type。
- token 使用应用密钥签名，校验 URL、store、context 和 exp。
- local 命中不启动 session，不返回 `Set-Cookie`；出现时 fallback 并移除该头。
- block 定义和缓存 HTML 不包含客户、session、IP、User-Agent 或 cookie 数据。

### AC-14.4 static

- static 文件不含 `PC_DYNAMIC_SERVER` 标记和 token。
- static 文件保留匿名 fallback。
- local 命中仍执行服务器端重建；static 命中不执行。

### AC-14.5 回归

- proxy/revalidating 的 Ajax/ESI 动态块行为不变。
- 现有 PageCache 测试全绿。
- session cookie bypass、304、tag 失效和静态导出不变。

### AC-14.6 验证门

- 新增单元测试和集成测试通过。
- PHPStan、php-cs-fixer、Rector 通过。
- 实机：local 模式命中的一个简单动态块显示 live 值，响应无 `Set-Cookie`，不生成完整 layout。
- 实机：static 文件存在且不含标记，static 命中显示匿名 fallback。
- SQLite、PostgreSQL、MySQL 至少覆盖保存、命中、fallback 和 static 剥离。

## 6. 风险与待定

- 轻量 block 重建可能缺少父 block、head 或 layout 数据；只允许显式 opt-in 的 leaf block，失败回退匿名 HTML。
- 允许列表配置错误会回退到匿名 fallback，页面不报错，但动态块不更新；日志和 debug 头可查。
- 重建 block 可能启动 session；已有保护会回退，但会留下 session 文件。允许列表必须排除 session block。
- 页面 HTML 里带 token 和匿名 fallback，缓存体积略有增加；仍受大小上限。
- static 文件只能显示匿名 fallback，这是静态导出的已知限制。
- 多节点共享缓存和 static 文件留给 FR-19。

## 7. 已确认的选择

1. 只做 local 模式，proxy/revalidating 继续 Ajax/ESI，static 只保留匿名 fallback。
2. 双重 opt-in：现有 `page_cache_dynamic=1` 加新 `page_cache_dynamic_server=1`，再加全局开关和 type 允许列表。
3. 保存匿名 HTML 作为 fallback，命中时用 live HTML 替换标记段，失败不报错。
4. 禁止 local 命中启动 session，出现 `Set-Cookie` 就回退并移除该头。
5. 新增 ADR-0015，ADR-0010 继续覆盖 proxy/revalidating 的 Ajax/ESI。
