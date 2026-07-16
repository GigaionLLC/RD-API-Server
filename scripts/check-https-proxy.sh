#!/usr/bin/env bash
# Verify the public TLS-proxy path without printing cookies or other response data.
set -euo pipefail

fail() {
    echo "[https-proxy-check] ERROR: $1" >&2
    exit 1
}

origin="${1:-}"
origin="${origin%/}"
authority="${origin#https://}"

if [ -z "$origin" ] || [ "$authority" = "$origin" ] || [ -z "$authority" ] \
    || [[ "$authority" == */* ]] || [[ "$authority" == *\?* ]] \
    || [[ "$authority" == *\#* ]] || [[ "$authority" == *@* ]]; then
    fail "usage: scripts/check-https-proxy.sh https://api.example.com"
fi

for dependency in curl awk grep mktemp; do
    command -v "$dependency" >/dev/null 2>&1 \
        || fail "required command not found: $dependency"
done

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

curl_args=(
    --silent
    --show-error
    --fail
    --connect-timeout 10
    --max-time 20
)

redirect_headers="$workdir/redirect-headers"
curl "${curl_args[@]}" --dump-header "$redirect_headers" --output /dev/null \
    "$origin/admin"

location="$(awk '
    tolower($0) ~ /^location:/ {
        sub(/\r$/, "")
        sub(/^[^:]*:[[:space:]]*/, "")
        value = $0
    }
    END { print value }
' "$redirect_headers")"

[ "$location" = "$origin/admin/login" ] \
    || fail "/admin redirected to '${location:-<missing>}' instead of the HTTPS login URL"
echo "[https-proxy-check] HTTPS admin redirect: ok"

login_headers="$workdir/login-headers"
login_body="$workdir/login-body"
curl "${curl_args[@]}" --dump-header "$login_headers" --output "$login_body" \
    "$origin/admin/login"

if grep -Eiq "(href|src)=[\"']http://" "$login_body"; then
    fail "the login HTML contains an insecure stylesheet or script URL"
fi
echo "[https-proxy-check] Login asset URLs: ok"

cookie_count="$(grep -ic '^set-cookie:' "$login_headers" || true)"
[ "$cookie_count" -ge 2 ] \
    || fail "the login response did not set both session and CSRF cookies"

grep -Eiq '^set-cookie:[[:space:]]*XSRF-TOKEN=' "$login_headers" \
    || fail "the login response did not set the XSRF-TOKEN cookie"

if grep -i '^set-cookie:' "$login_headers" \
    | grep -Eiv ';[[:space:]]*secure([;[:space:]]|$)' >/dev/null; then
    fail "at least one login cookie is missing the Secure attribute"
fi

if ! grep -i '^set-cookie:' "$login_headers" \
    | grep -Eiv '^set-cookie:[[:space:]]*XSRF-TOKEN=' \
    | grep -Ei ';[[:space:]]*httponly([;[:space:]]|$)' \
    | grep -Ei ';[[:space:]]*secure([;[:space:]]|$)' >/dev/null; then
    fail "the login response did not set a Secure, HttpOnly session cookie"
fi
echo "[https-proxy-check] Secure login cookies: ok"

asset_headers="$workdir/asset-headers"
asset_status="$(curl "${curl_args[@]}" --head --dump-header "$asset_headers" \
    --output /dev/null --write-out '%{http_code}' \
    "$origin/assets/css/theme-dark.css")"

[[ "$asset_status" =~ ^2[0-9][0-9]$ ]] \
    || fail "the theme stylesheet returned HTTP $asset_status instead of a 2xx response"
grep -Eiq '^content-type:[[:space:]]*text/css([;[:space:]]|$)' "$asset_headers" \
    || fail "the theme stylesheet did not return a CSS content type"
echo "[https-proxy-check] Stylesheet reachability: ok"

echo "[https-proxy-check] Public HTTPS proxy path is healthy: $origin"
