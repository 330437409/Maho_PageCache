# ADR-0015：local 模式使用服务器端动态块占位符

## 状态

已接受，第三期。

## 背景

ADR-0010 为 proxy/revalidating 定义了 Ajax 和 ESI 动态块。local 模式在命中时直接返回缓存的整页 HTML，动态块不做替换，匿名渲染结果进入共享缓存。这样动态块不会随命中刷新，也没有机会在不生成完整 layout 的情况下更新。

第三期计划服务器端占位符。范围必须限制在 PHP 能参与命中路径的 local 模式；static 文件由 Web 服务器直接返回，proxy 命中不运行 PHP，它们继续使用匿名 fallback 或 Ajax/ESI。

## 决策

- 服务器端占位符只用于 local 命中。
- block 必须同时标记 `page_cache_dynamic=1` 和 `page_cache_dynamic_server=1`。
- 全局开关 `page_cache/dynamic/server_side` 默认关闭；`page_cache/dynamic/server_types` 限制允许重建的 block type。
- 保存时把匿名 HTML 包在带签名 token 的注释标记中，并把 block 定义写入 local entry。
- 命中时只重建标记的 leaf block，使用 `Mage::app()->getLayout()->createBlock()` 和 `toHtml()`，不生成完整 layout。
- 不触发 `controller_action_layout_generate_blocks_before/after` 或整页 layout 事件。
- token 校验 URL、store、context 和过期时间。
- local 命中不得启动 session 或返回 `Set-Cookie`；出现时回退匿名 HTML 并移除该头。
- 重建失败、定义缺失或 token 无效时保留匿名 fallback，不返回 500。
- static 导出剥离标记，只写匿名 fallback；static 命中不执行服务器端重建。
- proxy/revalidating 继续使用 ADR-0010 的 Ajax/ESI。

## 理由

- 只有 local 命中路径有 PHP 和当前请求上下文，可以在不生成整页的情况下重建 block。
- 双重标记和 type 允许列表把可重建的 block 限制在主题显式声明、能独立渲染的 leaf block。
- 匿名 fallback 保证重建失败时页面仍可用，也让 static 文件有可服务的内容。
- 不启动 session 保持“被动浏览不建立 session”的既有契约。
- 不改变 proxy/revalidating，避免扩大风险。

## 后果

- 主题必须显式标记可重建 block，并保证它不依赖父 block、子 block、head 或 session。
- 带标记的 HTML 略大，仍受 `html_max_size` 限制。
- static 文件只能显示匿名 fallback，这是静态导出的已知限制。
- block 重建仍会触发 `core_layout_block_create_after`，但不会生成完整 layout。
- 多节点共享缓存和 static 文件留给 FR-19。

## 备选方案

- **在 local 命中时生成完整 layout**：和缓存目标冲突，不采用。
- **只做 Ajax/ESI**：local 命中仍返回旧的匿名动态块，无法满足 FR-14。
- **把动态块 HTML 写入共享缓存**：会缓存用户数据或过期内容，不采用。
- **客户端指定 block 类型**：扩大攻击面，不采用。
