# 功能规格：Maho 页面缓存静态文件导出

> 状态：已交付@v0.3.0
> 关联 PRD：FR-17
> 关联 ADR：ADR-0012
> 分支：`feat/page-cache-static-export`（待创建）

## 1. 背景与目标

第二期 `local` 模式把整页 HTML 存进 Symfony Cache，命中时仍要启动 PHP。实测本机 PHP 命中约 20ms，未命中约 170ms。

本功能在 `local` 模式成功写入后，额外把匿名默认页写成路径型静态文件：

- `var/page-cache/static/{store}/{path}/index.html`
- 同名 `.html.gz`

nginx 或 Caddy 用 `try_files` / `file` matcher 直接返回，PHP 不启动。元数据、URL 索引和 URL/global version 仍是权威来源，静态文件只是输出。

第一版只支持单节点。多节点共享存储和广播留到 FR-19。

## 2. 需求

### 2.1 范围内

- `local` 模式成功写入 HTML 后，写入静态 HTML 和 `.gz`。
- 只导出匿名、无 query 参数、默认 context 的页面。
- 路径型 key，Web 服务器可以直接匹配。
- 静态文件严格 session cookie bypass：带 `maho_session` 的请求必须回 PHP。
- 实体 tag 失效、全量失效、local 版本变化时删除对应静态文件。
- 后台 `page_cache` 类型刷新时清空静态目录。
- TTL 清理 cron，避免静态文件无限期保留。
- nginx 和 Caddy 配置示例，包含 deny 和 session bypass。
- 配置开关，默认关闭。
- 单元测试和实机验证。

### 2.2 范围外

- 允许参数变体的静态文件（`?p=2`、`?limit=12` 等）仍走 PHP/local。
- 登录用户、购物车、checkout、customer account 的静态化。
- 多节点共享存储和 purge 广播。
- CDN 自动 purge 适配器。
- 静态文件压缩算法协商（只写 gzip，br/zstd 由 Web 服务器或 CDN 处理）。
- 静态文件内容改写、minify 或图片优化。

## 3. 设计

### 3.1 运行流程

`local` 模式 MISS 时，`saveLocalResponse()` 当前顺序是：

1. 检查 bypass、版本、大小。
2. `HtmlCache::save()` 写元数据、URL 索引和 HTML。
3. 再次检查版本，版本变化则删除 HTML 并 bypass。
4. 应用 local MISS 头并计数。

新顺序：

1. 检查 bypass、版本、大小。
2. `HtmlCache::save()` 写元数据、URL 索引和 HTML。元数据新增 `store_code`。
3. 如果静态导出开启，且请求无 query 参数，`StaticCache::save()` 写静态文件和 `.gz`。
4. 再次检查版本；版本变化则删除 HTML 和静态文件，bypass。
5. 应用 local MISS 头并计数。

静态写入失败只记录日志，不影响第 4、5 步，页面继续使用 local 缓存。

### 3.2 路径映射

静态 key 使用浏览器请求的公共路径，而不是路由改写后的内部路径。`Context` 新增 `getPublicPath()`：优先使用 `getOriginalPathInfo()`，再按 `web/url/use_store` 去掉 store code 前缀。这样 `/page-cache-test/` 会写成 `page-cache-test/index.html`，不会写成 `catalog/category/view/id/4/index.html`。

这个修正也会改变 local 的 URL hash，旧缓存条目会自然失效一次。

静态根目录：

```text
var/page-cache/static/{store_code}/
```

`store_code` 必须匹配 `[A-Za-z0-9_-]+`，否则跳过静态导出。

URL 路径映射：

| URL path | 相对文件 |
|---|---|
| `/` | `index.html` |
| `/category` | `category/index.html` |
| `/category/` | `category/index.html` |
| `/product.html` | `product.html` |
| `/cms/page` | `cms/page/index.html` |

规则：

- 有允许 query 参数时不写静态文件。
- 路径只允许 `[A-Za-z0-9._~/-]`；含 `%`、非 ASCII、`..`、`.` 段、`//`、反斜杠、null 字节或长度超过 1024 的路径跳过静态导出。
- 跳过的页面仍由 local 缓存服务，不影响功能。
- 文件路径必须落在 `var/page-cache/static/{store_code}` 下，写入前校验前缀。

### 3.3 写入和原子性

- 创建目录 `0775`。
- 先写同目录临时文件，`LOCK_EX`，再 `rename()` 到目标路径；避免 Web 服务器读到半个文件。
- 写 plain HTML；如果 `gzencode()` 可用，再写 `.gz`。`.gz` 写失败只记录日志，不影响 plain 文件。
- 文件权限 `0644`，目录 `0775`。
- 静态文件大小沿用 `page_cache/general/html_max_size`；超过上限不写静态文件，local 缓存仍按现有规则处理。
- 静态文件 mtime 作为 TTL 清理依据。

### 3.4 失效

- `Service/Invalidator::invalidateTags()`：读取 meta 的 `url` 和 `store_code`，删除对应静态文件和 `.gz`。
- `Service/Invalidator::invalidateAll()`：清空 `var/page-cache/static` 下的所有内容，保留根目录。
- `HtmlCache::remove()`：删除 local HTML 时同时删除静态文件。
- `adminhtml_cache_refresh_type` 且 `type=page_cache`：调用 `invalidateAll()`，删除静态目录。
- 删除静态文件失败只记录日志；TTL 清理 cron 兜底。
- 静态文件删除不依赖 TTL，tag/全量失效必须立即删除。

### 3.5 TTL 清理

新增 cron：

```text
page_cache_static_cleanup   */5 * * * *
```

- 扫描静态根目录，删除 mtime 早于 `ttl + 300` 的 `.html` 和 `.gz`。
- 删除空目录。
- 不跟随 symlink。
- 静态导出关闭时也运行，清理遗留文件。
- 清理失败记录日志，不影响请求。
- 没有 cron 的部署，静态文件可能超过 TTL 继续服务；文档必须写明这是部署要求。

### 3.6 Web 服务器契约

静态层只处理：

- GET
- 无 query 参数
- Cookie 不含 `maho_session`
- 命中安全路径下的静态文件

缓存目录必须 deny 直接访问。

#### nginx 示例

```nginx
http {
    map $http_cookie $page_cache_cookie_off {
        default 0;
        "~*maho_session" 1;
    }

    map $args $page_cache_args_off {
        ""      0;
        default 1;
    }

    map "$page_cache_cookie_off$page_cache_args_off" $page_cache_static_off {
        default 0;
        "~1"    1;
    }

    map $page_cache_static_off $page_cache_static {
        0 /var/page-cache/static/default;  # 每个 store 一条，或按 Host 映射
        1 /__page_cache_static_off__;
    }

    server {
        listen 80;
        root /path/to/maho;  # Maho 根目录，不是 public

        location ^~ /var/page-cache/ {
            deny all;
        }

        location / {
            try_files
                $page_cache_static${uri}index.html
                $page_cache_static$uri/index.html
                $page_cache_static$uri
                @handler;

            gzip_static on;
        }

        location @handler {
            # 换成实际的 PHP-FPM / proxy 配置
            proxy_pass http://127.0.0.1:8000;
            proxy_set_header Host $http_host;
            proxy_set_header X-Forwarded-Proto $scheme;
        }
    }
}
```

说明：

- `root` 必须是 Maho 根目录，`/var/page-cache/static/default` 才会解析到 `var/page-cache/static/default`。
- `$page_cache_static` 是 sentinel 时，三个静态候选都不存在，直接走 PHP。
- 不再使用 `if` + `set`，避免 Nginx 在 `try_files` 前匹配到目录并返回 403。
- `/` 由 `${uri}index.html` 命中 `index.html`。
- `/category/` 由 `${uri}index.html` 命中 `category/index.html`。
- `/category` 由 `$uri/index.html` 命中 `category/index.html`。
- `/product.html` 由 `$uri` 命中。
- 不带 `$uri $uri/` 作为静态候选，避免目录被当成静态命中。
- nginx 示例不设置 `X-Page-Cache`；验收以 PHP 不启动和没有 `X-Maho-Cache` 为准。

#### Caddy 示例

Caddy 用 `public/var/page-cache/static` 指向 `var/page-cache/static` 的 symlink，root 保持 `public`：

```bash
ln -s ../../../var/page-cache/static public/var/page-cache/static
```

```caddyfile
:80 {
    root * public

    handle /var/page-cache/* {
        respond 403
    }

    @page_cache_static {
        method GET
        not header Cookie *maho_session*
        expression {query} == ""
        file {
            try_files /var/page-cache/static/default{path} /var/page-cache/static/default{path}/index.html /var/page-cache/static/default/index.html
        }
    }

    handle @page_cache_static {
        header X-Page-Cache HIT-static
        header Cache-Control "public, max-age=0, must-revalidate"
        header Vary "Accept-Encoding, Cookie"
        rewrite * {http.matchers.file.relative}
        file_server {
            precompressed gzip
        }
    }

    handle {
        reverse_proxy 127.0.0.1:8000 {
            header_up Host localhost:8000
        }
    }
}
```

说明：

- `expression {query} == ""` 排除所有 query 参数。
- `not header Cookie *maho_session*` 是严格 session bypass。
- `handle /var/page-cache/*` 必须在静态 matcher 之前，deny 直接访问。
- `index.html` 单独列一次，处理首页。
- 多 store 需要按 Host 或路径复制 matcher。

### 3.7 配置

新增：

| 路径 | 默认 | 说明 |
|---|---|---|
| `page_cache/static/enabled` | 0 | 静态导出总开关 |

不增加路径配置；静态根固定为 `var/page-cache/static`，避免用户把缓存写到 public 或其他不安全目录。

后台 `System > Configuration > Page Cache > Static Export`：

- Enable Static Export：yes/no。
- 注释说明需要 Web 服务器配置、deny、cron，以及第一版只支持单节点。

### 3.8 安全

- 静态文件只来自无 session cookie、无 query 参数的匿名请求。
- Web 服务器必须在 `Cookie` 含 `maho_session` 时回 PHP；示例配置必须包含这一条。
- 缓存目录必须 deny 直接访问。
- 路径和 store code 白名单，拒绝 `..`、`.` 段、`//`、反斜杠、null 字节、非 ASCII 和过长路径。
- 写入前校验最终路径前缀，防目录穿越。
- 临时文件 + rename，避免部分文件被服务。
- 静态写入失败不影响页面，不改变响应头。
- 静态文件不包含 session 数据；form key 仍是占位符，由前端懒加载。
- 清理 cron 不跟随 symlink。

## 4. 任务拆分

- [x] `Helper/Data.php`：`getStaticExportEnabled()`。
- [x] `etc/config.xml`、`etc/system.xml`：静态导出开关和说明。
- [x] `Model/Context.php`：公共路径使用 `getOriginalPathInfo()`，去掉 store code 前缀。
- [x] 新增 `Model/StaticCache.php`：路径映射、store code、原子写、gzip、removeByUrl、flush、cleanup。
- [x] `Model/HtmlCache.php`：meta 写 `store_code`；`remove()` 同时删静态文件。
- [x] `Model/Observer.php`：`saveLocalResponse()` 写静态；`adminhtml_cache_refresh_type` 处理 `page_cache`；新增 cleanup cron。
- [x] `Service/Invalidator.php`：tag 失效删静态；全量失效清空静态目录。
- [x] `tests/Backend/Unit/PageCache/StaticCacheTest.php`：路径、写入、gzip、删除、清理、安全。
- [x] `LocalCacheTest`、`InvalidatorTest`、`ObserverTest`：集成断言。
- [x] `docs/API.md`、`docs/ARCHITECTURE.md`、`README.md`、`CHANGELOG.md`：配置、Web 服务器示例、失效、cron。
- [x] nginx 或 Caddy 实机验证。

## 4.1 实现记录

- 代码：`Model/StaticCache.php`、`Model/Context.php`、`Model/HtmlCache.php`、`Model/Observer.php`、`Service/Invalidator.php`、`Helper/Data.php`。
- 路径：`var/page-cache/static/{store}/{public-path}`，目录式补 `index.html`，文件式保持原路径，同时写 `.gz`。
- 公共路径：`Context::getPublicPath()` 使用 `getOriginalPathInfo()`，去掉 store code 前缀，修复路由改写后静态路径写成内部路由的问题。
- 失效：tag 失效删静态，全量失效清空静态目录，后台刷新 `page_cache` 类型清空静态目录。
- 清理：`page_cache_static_cleanup` cron 每 5 分钟删除超过 `ttl + 300` 的文件。
- 测试：`StaticCacheTest` 10 项；`LocalCacheTest`、`InvalidatorTest`、`ObserverTest`、`ContextTest` 增加集成断言；全量 125 passed（300 assertions）。
- 实机：nginx 1.31 静态返回首页/分类/商品，无 `X-Maho-Cache`；session cookie 回 PHP `BYPASS`；query 回 PHP local HIT；gzip 返回 `Content-Encoding: gzip`；直接访问缓存目录 403；保存商品后静态文件删除，下一次 PHP MISS 后重建；全量 flush 清空静态目录；`cron:list` 显示 `page_cache_static_cleanup`，`cron:run` 成功。
- Caddy 示例按 Caddyfile 语法提供；本机 Docker Hub 拉取 caddy 镜像失败，未做 Caddy 实机验证，nginx 已覆盖 AC-11.2 的“nginx 或 Caddy”。

## 5. 验收标准

### AC-11.1 写入

- 匿名默认页 local MISS 后生成 `.html` 和 `.gz`。
- 首页、分类、商品、CMS 页路径映射正确。
- query 参数请求不生成静态文件。
- 路由改写后的公共路径映射正确，例如 `/page-cache-test/` 写成 `page-cache-test/index.html`。
- 静态导出关闭时不生成。
- 静态写入失败不影响页面响应。

### AC-11.2 Web 服务器直出

- nginx 或 Caddy 直接返回静态文件，PHP 不启动。
- 响应没有 `X-Maho-Cache`；Caddy 示例带 `X-Page-Cache: HIT-static`。
- gzip 文件存在并被 `gzip_static` / `precompressed gzip` 使用。
- 直接访问 `/var/page-cache/...` 返回 403。

### AC-11.3 session 和参数

- 带 `maho_session` 的请求不命中静态文件，回 PHP 并返回 `X-Maho-Cache: BYPASS`。
- 带 `p`、`limit` 等允许参数的请求回 PHP，命中 local。
- 带未知参数的请求回 PHP 并 bypass。

### AC-11.4 失效

- 保存 product/category/CMS 后，相关静态文件和 `.gz` 被删除；下一次请求 PHP MISS，随后重新生成。
- 全量 flush 清空静态目录。
- 后台刷新 `page_cache` 类型清空静态目录。
- 删除静态文件失败不阻塞 local 失效。

### AC-11.5 TTL 清理

- cron 删除超过 `ttl + 300` 的静态文件。
- 新鲜文件保留。
- 空目录被删除。
- 静态导出关闭时仍清理遗留文件。

### AC-11.6 安全

- `..`、`.` 段、`//`、反斜杠、null 字节、非 ASCII、过长路径不写静态文件。
- store code 不合法时不写静态文件。
- 临时文件不会残留。
- 清理 cron 不跟随 symlink。

### AC-11.7 验证门

- 新增单元测试和集成测试通过。
- PHPStan、php-cs-fixer、Rector 通过。
- nginx 或 Caddy 实机验证。
- 不改 Maho 核心表结构，不新增 Composer 依赖。

## 6. 风险与待定

### 已确认

1. **`X-Page-Cache: HIT-static`**：接受 Caddy 示例带该头，nginx 以 PHP 不启动和没有 `X-Maho-Cache` 为准。
2. **TTL 清理 cron**：接受每 5 分钟扫描，删除 `ttl + 300` 之前的文件。
3. **ASCII 路径限制**：接受含非 ASCII 或百分号编码的 URL 不做静态导出，仍走 local。
4. **静态根固定**：接受固定 `var/page-cache/static`，不做配置。
5. **单节点**：接受第一版不处理多节点共享存储，多节点等 FR-19。

### 其他风险

- Web 服务器配置错误可能直接暴露缓存文件；deny 是强制项。
- 静态文件在 TTL 过期后最多多服务约 5 分钟，直到 cleanup cron 运行。
- 失效漏删时，静态文件可能比 local 多活一段 TTL；tag/全量失效必须验证。
- 多 store 需要为每个 store 配置静态根或按 Host 映射。
- 静态文件不经过 PHP，无法根据 `If-None-Match` 返回 304；浏览器每次回源，但命中在 Web 服务器。
- nginx/Caddy 配置示例需要按实际 store code、目录和 TLS 调整。
