# nginx Cache Key 配置

> 状态：0.7.0 PERF-04，已在 nginx 1.31 上验证；0.10.0 增加 stale-while-revalidate 配置
> 关联：[代理缓存 Cookie 规范化](README.md)、[RFC-007](../rfc/RFC-007-performance-architecture.md) PERF-04

nginx 不使用 `Vary` 生成 `proxy_cache` 的 key，而是使用 `proxy_cache_key`。下面把模块的 `Vary: Accept-Encoding, Cookie` 语义显式写进 key，并只放行真正影响页面的 Cookie。

## 配置

```nginx
# 带 session 的请求不读也不写共享缓存。
map $http_cookie $maho_page_cache_bypass {
    default 0;
    "~*(^|;\s*)(maho_session|maho_admin_session|frontend|adminhtml|om_frontend)=" 1;
}

# 只允许 store 和分析 Cookie。其他 Cookie 一律 bypass。
map $http_cookie $maho_page_cache_unknown_cookie {
    default 1;
    "" 0;
    "~^(?:(?:store|_ga|_gid|_gcl_au|_fbp|_fbc)=[^;]*(?:;\s*)?)+$" 0;
}

# 上游明确 private/no-store/no-cache 的响应不写入缓存。
map $upstream_http_cache_control $maho_page_cache_uncacheable {
    default 0;
    "~*(private|no-store|no-cache)" 1;
}

proxy_cache_path /var/cache/nginx/maho levels=1:2 keys_zone=maho:100m
                 max_size=10g inactive=1h use_temp_path=off;

server {
    listen 80;
    server_name shop.example.com;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;

        proxy_cache maho;
        proxy_cache_key "$scheme$request_method$host$request_uri$cookie_store";
        proxy_cache_bypass $maho_page_cache_bypass $maho_page_cache_unknown_cookie;
        proxy_no_cache $maho_page_cache_bypass $maho_page_cache_unknown_cookie $maho_page_cache_uncacheable;
        proxy_cache_valid 200 5m;
        proxy_cache_background_update on;
        add_header X-Maho-Proxy-Cache $upstream_cache_status always;
    }
}
```

## 为什么这样写

- `proxy_cache_key` 只包含 `$cookie_store`，所以 `_ga` 等分析 Cookie 不产生新对象。
- `proxy_cache_bypass` 在查找缓存前执行：带 session 或未知 Cookie 的请求直接回 PHP。
- `proxy_no_cache` 同时拦住这些请求的响应，防止个性化页面被写入共享缓存。
- `$maho_page_cache_uncacheable` 是第二道防线：即使其他配置让 nginx 考虑缓存，`private`、`no-store`、`no-cache` 的响应也不会落盘。
- nginx 默认不缓存带 `Set-Cookie` 的响应，因此不要配置 `proxy_ignore_headers Set-Cookie`。
- `proxy_cache_valid 200 5m` 是兜底；模块正常发送 `Cache-Control: max-age=0, public, s-maxage=3600`，nginx 会按共享缓存 TTL 使用它。若站点 TTL 更短，把这里的值调低。
- `proxy_cache_background_update on` 让 nginx 在 TTL 过期后先返回旧页，同时后台刷新。是否允许返回旧页由 `page_cache/proxy/swr` 输出的 `stale-while-revalidate` 秒数决定。
- 不要同时配置 `proxy_cache_use_stale updating`。nginx 的 `updating` 参数会覆盖响应头的时间窗口，后台刷新一直未完成时可以无限返回旧页。实测：TTL=5、SWR=2、上游 10 秒时，只开 `proxy_cache_background_update` 会在 3.5 秒后返回 `EXPIRED` 并重新回源；加上 `updating` 后同一时刻仍返回 `UPDATING` 和旧页。
- purge 仍是硬删除，SWR 不会保留被 purge 的对象。
- 后台刷新失败时不启用 `stale-if-error`：SWR 窗口内仍可返回旧页，超过窗口后按正常回源处理；不要添加 `proxy_cache_use_stale error timeout http_500`。

## 验证

```bash
# 1. 预热，第二次应为 HIT
curl -sS -o /dev/null -D - http://127.0.0.1/page-cache-test-product/ | grep -i x-maho-proxy-cache
curl -sS -o /dev/null -D - http://127.0.0.1/page-cache-test-product/ | grep -i x-maho-proxy-cache

# 2. 分析 Cookie 共享同一个对象
curl -sS -o /dev/null -D - -H 'Cookie: _ga=GA1.1.123' \
  http://127.0.0.1/page-cache-test-product/ | grep -i x-maho-proxy-cache

# 3. session 必须 BYPASS，且响应不进入缓存
curl -sS -o /dev/null -D - -H 'Cookie: maho_session=abc123' \
  http://127.0.0.1/page-cache-test-product/ | grep -iE 'x-maho-proxy-cache|cache-control|set-cookie'

# 4. 未知 Cookie 必须 BYPASS
curl -sS -o /dev/null -D - -H 'Cookie: shop_pref=blue' \
  http://127.0.0.1/page-cache-test-product/ | grep -i x-maho-proxy-cache

# 5. 第二个 store 使用独立对象
curl -sS -o /tmp/store2.html -H 'Cookie: store=second' \
  http://127.0.0.1/page-cache-test-product/
grep -o '<title>[^<]*</title>' /tmp/store2.html
```

预期：步骤 1 第二次为 `HIT`；步骤 2 为 `HIT`；步骤 3、4 为 `BYPASS`；步骤 5 的标题包含第二个 store 的后缀，随后不带 Cookie 的请求仍命中默认 store 的对象。

## 限制

- `map` 中的分析 Cookie 列表是白名单。新增第三方脚本时，把它的 Cookie 名加进去，或接受 bypass。
- 站点装了用 Cookie 改价格、语言、库存或 A/B 测试的扩展时，不能直接使用本配置；必须把该 Cookie 加入 key 或 bypass。
- 该配置只处理 `GET`/`HEAD`。其他方法 nginx 默认不缓存，但仍应确认上游对写请求返回 `private` 或 `no-store`。
- nginx 不做 tag purge；实体失效后需要按 URL purge，或使用模块的代理 purge 集成。
