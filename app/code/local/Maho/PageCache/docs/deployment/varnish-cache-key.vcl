# SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
# SPDX-License-Identifier: OSL-3.0
#
# Varnish 7.4 cache key profile for Maho page cache, 0.7.0 PERF-04.
# Tested against Maho with proxy mode and a second store view.
#
# See docs/deployment/README.md for the cookie rules and verification steps.

vcl 4.1;

backend default {
    .host = "127.0.0.1";
    .port = "8000";
}

sub vcl_recv {
    if (req.method != "GET" && req.method != "HEAD") {
        set req.http.X-PC-Pass = "1";
        return (pass);
    }

    if (req.http.Authorization) {
        set req.http.X-PC-Pass = "1";
        return (pass);
    }

    if (req.http.Cache-Control ~ "(?i:no-cache|no-store)" || req.http.Pragma ~ "(?i:no-cache)") {
        set req.http.X-PC-Pass = "1";
        return (pass);
    }

    # A storefront or admin session is personal. Never read or write shared cache.
    if (req.http.Cookie ~ "(?i)(^|;\s*)(maho_session|maho_admin_session|frontend|adminhtml|om_frontend)=") {
        set req.http.X-PC-Pass = "1";
        return (pass);
    }

    if (req.http.Cookie) {
        # Remove analytics cookies only after confirming they do not change the page.
        set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)(_ga|_gid|_gcl_au|_fbp|_fbc)=[^;]*", "");
        set req.http.Cookie = regsub(req.http.Cookie, "^;\s*", "");
        if (req.http.Cookie == "") {
            unset req.http.Cookie;
        }
    }

    return (hash);
}

sub vcl_hash {
    hash_data(req.url);
    if (req.http.host) {
        hash_data(req.http.host);
    } else {
        hash_data(server.ip);
    }

    # store and every unknown cookie stay in the key. They isolate variants
    # until an operator confirms the cookie is safe to drop.
    if (req.http.Cookie) {
        hash_data(req.http.Cookie);
    }

    return (lookup);
}

sub vcl_backend_fetch {
    # Match the host and port that Maho sees in its configured base URL.
    # Remove this line when the backend should receive the public Host header.
    set bereq.http.Host = "localhost:8000";
}

sub vcl_backend_response {
    if (beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
    } elseif (beresp.http.Cache-Control ~ "(?i:private|no-store|no-cache)") {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
    } elseif (beresp.status != 200) {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
    } elseif (beresp.ttl > 300s) {
        # Match the nginx profile. Raise it when URL purge is reliable.
        set beresp.ttl = 300s;
    }
}

sub vcl_deliver {
    if (req.http.X-PC-Pass) {
        set resp.http.X-Maho-Proxy-Cache = "BYPASS";
    } elseif (obj.hits > 0) {
        set resp.http.X-Maho-Proxy-Cache = "HIT";
    } else {
        set resp.http.X-Maho-Proxy-Cache = "MISS";
    }
}
