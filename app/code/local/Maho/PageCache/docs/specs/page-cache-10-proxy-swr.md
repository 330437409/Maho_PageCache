# 功能规格：Maho_PageCache 0.10.0 Proxy SWR

> 状态：已交付@v0.10.0
> 关联 PRD：FR-25
> 目标版本：0.10.0
> 分支：`feat/fr25-proxy-swr`

## 1. 背景

proxy 模式当前只发送 `Cache-Control: public, s-maxage=<ttl>, max-age=0`。TTL 到期后，同一页面的并发请求会一起回源，形成回源峰值。反向代理支持 `stale-while-revalidate`，可以在 TTL 过期后的宽限期内先返回旧页，同时在后台刷新。

FR-25 只增加这个响应头，不改变 Maho 内的缓存读写，也不改变 purge 语义。

## 2. 范围

### 2.1 范围内

- 新增 `page_cache/proxy/swr`，单位秒，默认 0。
- `proxy` 模式的 `Cache-Control` 在配置大于 0 时追加 `stale-while-revalidate=<seconds>`。
- 配置为 0、空或非法值时，不输出该指令。
- 配置变更后下一次响应生效，不需要清缓存或部署代码。
- `local` 和 `revalidating` 的 `Cache-Control` 不变。
- 补单元测试、配置文档和运维说明。
- 用 nginx `proxy_cache` 实机验证：TTL 过期后首个请求返回旧页，后台完成刷新，下一个请求返回新页。

### 2.2 范围外

- 不实现 `stale-if-error`。FR-25 的 grace 指 `stale-while-revalidate` 的秒数，不是错误兜底。
- 不改变 purge 行为：tag 和全量 purge 仍然是硬删除，SWR 只在 TTL 自然过期后生效。
- 不改变 local/revalidating 的 ETag、304 或缓存策略。
- 不改变代理的 context、Vary、tag header 和 URL 索引。
- 不新增数据库表、后台页面、CLI 命令或事件。
- 不自动配置反向代理；部署方需要在代理侧允许 `stale-while-revalidate` 和后台更新。

## 3. 配置

| 配置路径 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `page_cache/proxy/swr` | text | 0 | `stale-while-revalidate` 秒数，0 到 86400。0 关闭。 |

后台位置：`System > Configuration > Page Cache > Proxy`，字段标签 `Stale While Revalidate (seconds)`。

Helper 增加：

```php
public const XML_PATH_PROXY_SWR = 'page_cache/proxy/swr';

public function getProxySwr(): int
{
    $value = trim((string) Mage::getStoreConfig(self::XML_PATH_PROXY_SWR));
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        return 0;
    }

    return min(86400, (int) $value);
}
```

负值、空字符串、小数和含非数字字符的值按 0 处理；超过 86400 时按 86400。

## 4. 响应头

`Maho_PageCache_Model_Backend_Proxy::getCacheControl()`：

```text
# swr = 0
Cache-Control: public, s-maxage=3600, max-age=0

# swr = 60
Cache-Control: public, s-maxage=3600, max-age=0, stale-while-revalidate=60
```

- 只影响 proxy backend。
- `applyNotModified()` 仍走 `getCacheControl(0)`；proxy 模式不会进入 304 分支，保持现状。
- `Vary`、`X-Maho-Cache`、`X-Cache-Tags` 和 debug header 不变。
- 头值只包含整数秒，不使用用户输入或本地化格式。

## 5. TTL、purge 和代理行为

- `s-maxage` 仍是主 TTL；SWR 只在 `s-maxage` 过期后生效，宽限期为配置秒数。
- tag 或全量 purge 仍立即删除代理对象；删除后没有旧页可返回，SWR 不延长失效内容。
- 代理不支持该指令时忽略它，行为与当前版本一致。
- nginx 只需要 `proxy_cache_background_update on`；`stale-while-revalidate` 响应头本身允许后台更新期间返回旧页。不要加 `proxy_cache_use_stale updating`，它会覆盖响应头的时间窗口。
- SWR 不改变 Maho 的 purging、版本号或 URL 索引。

## 6. 测试计划

新增 `tests/Backend/Unit/PageCache/ProxySwrTest.php`：

1. 配置 60：proxy `Cache-Control` 包含 `stale-while-revalidate=60`，且保留 `public`、`s-maxage`、`max-age=0`。
2. 配置 0：proxy `Cache-Control` 不包含 `stale-while-revalidate`。
3. 配置负数、空值、小数、`12abc` 或超过 86400：按 0 或 86400 处理。
4. 配置 60 时，local 响应不包含 `stale-while-revalidate`。
5. 配置 60 时，revalidating 响应不包含 `stale-while-revalidate`，仍包含 `must-revalidate`。
6. 修改配置后同一进程的下一次响应使用新值。

回归：

- `ResponseTest`、`ProxyContextTest`、`TagBudgetTest` 全绿。
- PageCache 全量单元测试。
- php-cs-fixer、Rector、PHPStan。
- SQLite、MySQL、PostgreSQL CI。

实机：

- 用 nginx `proxy_cache` 指向测试站点，TTL 短于 SWR，上游故意慢于 SWR。
- 第一次请求 MISS 并缓存。
- TTL 过期后、SWR 窗口内请求返回 `STALE` 旧页，同时 nginx 后台回源。
- 超过 SWR 窗口后，后台仍未完成时请求必须返回 `EXPIRED` 并重新回源，不能继续 `STALE`。
- 后台刷新完成后，下一次请求返回新页。
- 不加 `proxy_cache_use_stale updating`；同一场景加上它会破坏 SWR 时间窗口。
- 删除代理缓存对象后立即请求不得返回旧页。
- 后台刷新失败时不启用 `stale-if-error`，超过 SWR 后正常回源或返回上游错误。

## 7. 验收

- proxy 响应按配置输出 `stale-while-revalidate`；0 时不输出。
- local 和 revalidating 的响应头与 0.9.2 完全一致。
- purge 仍是硬删除；SWR 只在 TTL 过期后生效。
- 单元测试覆盖 0、正数、非法值和三种模式。
- nginx 实机验证 TTL 过期后的 stale + background refresh。
- 文档同步：PRD、API、ARCHITECTURE、README、nginx 部署文档、CHANGELOG。

## 8. 风险

- 代理厂商对 `stale-while-revalidate` 的实现不一致；默认 0，部署方自行开启。
- SWR 会让旧内容在 TTL 后继续可见一个窗口；对价格、库存等敏感页面，TTL 和 SWR 必须保守。
- 如果代理不执行 purge 而只依赖 TTL，SWR 会延长旧内容可见时间；文档必须明确 purge 仍是硬删除。
- 不新增 ADR：这是 FR-05 proxy 响应头的配置增强，不推翻 ADR-0006/0008 的 Vary 和 purge 契约。

## 9. 已确认

1. 配置路径使用 `page_cache/proxy/swr`，默认 0。
2. FR-25 只做 `stale-while-revalidate`，不做 `stale-if-error`。
3. 实机代理验证使用 nginx。

## 10. 实现记录

- 代码：`Helper/Data.php`、`Model/Backend/Proxy.php`、`etc/config.xml`、`etc/system.xml`。
- 单元测试：`ProxySwrTest` 14 passed / 20 assertions，覆盖 0、正数、负数、空值、小数、`12abc`、空格和 86400 上限。
- PageCache + Translate：331 passed / 933 assertions。
- nginx 实机（只开 `proxy_cache_background_update`）：TTL=5、SWR=2、上游 10 秒时，过期后 1 秒返回 `STALE` v1，3.5 秒后返回 `EXPIRED` 并回源。
- nginx 对照（同时加 `proxy_cache_use_stale updating`）：同一时刻仍返回 `UPDATING` 和旧页，证明该指令会破坏 SWR 时间窗口。
- 后台刷新失败：SWR 窗口内返回 `STALE` v1，超过窗口且上游仍为 500 时返回 `500`，没有意外启用 `stale-if-error`。
- 硬删除代理对象后重启 nginx：下一次请求 `MISS` v2，不返回旧页。
- 三库验收：SQLite、MySQL、PostgreSQL 各 326 passed / 928 assertions。
- 验收报告：`.tmp/acceptance-version-0.10.0-2026-11-25.md`，无阻塞项。
- php-cs-fixer、Rector、PHPStan 通过。
