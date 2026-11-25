#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
# SPDX-License-Identifier: OSL-3.0
#
# Verify a Static Export deployment through a real web server.
# Usage: ./verify.sh http://shop.example.com /page-cache-test-product/

set -euo pipefail

BASE_URL="${1:?usage: verify.sh BASE_URL [PATH]}"
PATH_INFO="${2:-/page-cache-test-product/}"
FAILED=0

header() {
    local url="$1"
    local extra="${2:-}"
    if [ -n "$extra" ]; then
        curl -sS -H "$extra" -D - -o /dev/null "$url"
    else
        curl -sS -D - -o /dev/null "$url"
    fi
}

check() {
    local label="$1"
    local headers="$2"
    local pattern="$3"
    local present="$4"
    if printf '%s' "$headers" | grep -qiE "$pattern"; then
        if [ "$present" = "yes" ]; then
            echo "ok   $label"
            return
        fi
        echo "FAIL $label: unexpected $pattern"
        FAILED=1
        return
    fi
    if [ "$present" = "no" ]; then
        echo "ok   $label"
        return
    fi
    echo "FAIL $label: missing $pattern"
    FAILED=1
}

URL="${BASE_URL}${PATH_INFO}"

H=$(header "$URL")
check "static hit marker" "$H" '^X-Page-Cache: HIT-static' yes
check "static hit has no PHP header" "$H" '^X-Maho-Cache:' no
check "static Cache-Control" "$H" '^Cache-Control: public, max-age=0, must-revalidate' yes
check "static Vary" "$H" '^Vary: .*Cookie' yes

H=$(header "$URL" 'Cookie: maho_session=test')
check "storefront session goes to PHP" "$H" '^X-Maho-Cache: BYPASS' yes
check "storefront session is not static" "$H" '^X-Page-Cache: HIT-static' no

H=$(header "$URL" 'Cookie: maho_admin_session=test')
check "admin session goes to PHP" "$H" '^X-Maho-Cache:' yes
check "admin session is not static" "$H" '^X-Page-Cache: HIT-static' no

H=$(header "$URL" 'Cookie: store=unknown')
check "unknown store goes to PHP" "$H" '^X-Maho-Cache:' yes
check "unknown store is not static" "$H" '^X-Page-Cache: HIT-static' no

H=$(header "${URL}?p=2")
check "query goes to PHP" "$H" '^X-Maho-Cache:' yes
check "query is not static" "$H" '^X-Page-Cache: HIT-static' no

H=$(header "$URL" 'Accept-Encoding: gzip')
check "gzip is negotiated" "$H" '^Content-Encoding: gzip' yes

CODE=$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/var/page-cache/static/default${PATH_INFO}index.html")
if [ "$CODE" = "403" ] || [ "$CODE" = "404" ]; then
    echo "ok   cache directory is not directly reachable ($CODE)"
else
    echo "FAIL cache directory returned $CODE, expected 403 or 404"
    FAILED=1
fi

H=$(header "${BASE_URL}/no-such-static-page/")
check "missing static file falls back to PHP" "$H" '^X-Maho-Cache:' yes

if [ "$FAILED" -ne 0 ]; then
    echo "Static Export verification failed."
    exit 1
fi

echo "Static Export verification passed."
