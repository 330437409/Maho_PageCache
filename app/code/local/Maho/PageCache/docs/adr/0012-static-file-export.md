# ADR-0012：第三期增加可选的静态文件导出层

## 状态

已接受，第三期第一批。

## 背景

第二期 `local` 模式把整页 HTML 存进 Symfony Cache，命中时仍然要启动 PHP、路由和读取缓存。实测本机 PHP 命中约 20ms，未命中约 170ms。

`mageaustralia/maho-module-fpc` 的做法是把 HTML 写成 `var/fpc/{store}/{url}.html` 和 `.html.gz`，由 nginx `try_files` 或 Caddy `file_server` 直接返回，PHP 完全不启动。它的公开数据是静态约 6ms、PHP 命中约 45ms。这个方向值得借鉴，但不能直接采用它的失效和 session 模型。

## 决策

第三期增加一个**默认关闭**的静态文件导出层，作为 `local` 模式的附加输出：

- 在 `local` 模式成功写入 HTML 后，额外写一份路径型静态文件：
  - `var/page-cache/static/{store}/{public-path}`；目录式 URL 补 `index.html`
  - 同名 `.gz`
- 路径型文件只用于无 session cookie 的匿名默认 context。
- 静态路径使用浏览器公共路径，不使用路由改写后的内部路径。
- 允许的 query 参数变体继续走 PHP，不写静态文件，避免路径爆炸。
- 元数据、tag 索引和 URL/global version 仍是权威来源，静态文件只是输出。
- 失效时删除对应静态文件和 `.gz`，并保留现有 tag/version 失效。
- `page_cache_static_cleanup` cron 按 `ttl + 300` 删除过期文件。
- 提供 nginx 和 Caddy 示例，使用 `try_files` 或 `file` matcher。
- Web 服务器必须在请求 Cookie 含 `maho_session` 时跳过静态文件，交给 PHP。
- 缓存目录必须 deny 直接访问：nginx `location /var/page-cache { deny all; }`，Caddy `not path /var/page-cache/*`。
- PHP 命中路径不使用 `exit`，继续走正常响应生命周期。
- 多节点部署必须把静态目录放在共享存储，或由每个节点各自预热；默认本地目录只适合单节点。

## 理由

- 静态文件是页面缓存最快的传输层，能把 TTFB 从几十毫秒降到个位数。
- 路径型 key 便于 Web 服务器直接匹配，也便于人工排查。
- 保留现有元数据和版本失效，避免换成纯文件系统后丢失精确失效能力。
- 默认关闭，不强迫所有部署改 Web 服务器配置。

## 后果

- 需要 Web 服务器配置和目录权限。
- 静态文件和 Symfony Cache 同时存在，磁盘/内存占用增加。
- 允许参数、多 store、多货币和客户组变体不能全部静态化，静态层只覆盖最常见的匿名默认页。
- 缓存目录如果配置错误，可能被直接访问，必须在示例和文档中强制 deny。
- 多节点需要共享存储或节点级预热。

## 备选方案

- **只保留 Symfony Cache**：部署简单，但每次命中都要启动 PHP。
- **纯静态文件，不要元数据**：最快，但失效只能靠 TTL/全量删除，容易漏页面。
- **只依赖 CDN**：适合有 CDN 的部署，但不覆盖没有 CDN 的站点。
