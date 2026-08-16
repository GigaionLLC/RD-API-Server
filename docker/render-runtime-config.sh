#!/usr/bin/env bash
# Validate optional runtime tuning and atomically render Nginx, FPM, and PHP request limits.
set -Eeuo pipefail

readonly TEMPLATE_DIR=/usr/local/etc/runtime-templates
readonly NGINX_TEMPLATE="$TEMPLATE_DIR/nginx.conf.template"
readonly FPM_TEMPLATE="$TEMPLATE_DIR/php-fpm-runtime.conf.template"

fail() {
    echo "[runtime-config] $*" >&2
    exit 1
}

uint_value() {
    local name="$1"
    local fallback="$2"
    local minimum="$3"
    local maximum="$4"
    local raw="${!name:-$fallback}"
    local normalized

    [[ "$raw" =~ ^[0-9]+$ ]] || fail "$name must be an integer between $minimum and $maximum."
    normalized="$(printf '%s' "$raw" | sed 's/^0*//')"
    normalized="${normalized:-0}"
    [ "${#normalized}" -le 18 ] || fail "$name must be an integer between $minimum and $maximum."
    [ "$normalized" -ge "$minimum" ] 2>/dev/null \
        && [ "$normalized" -le "$maximum" ] 2>/dev/null \
        || fail "$name must be an integer between $minimum and $maximum."

    printf '%s' "$normalized"
}

detected_cpu_count() {
    local available quota period quota_count

    available="$(nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || printf '1')"
    [[ "$available" =~ ^[0-9]+$ ]] && [ "$available" -ge 1 ] || available=1

    # Nginx's `auto` setting follows CPUs visible through affinity and can ignore a Docker CPU
    # quota. Prefer the tighter cgroup v2/v1 quota, rounded up for fractional CPU allocations.
    if [ -r /sys/fs/cgroup/cpu.max ]; then
        read -r quota period < /sys/fs/cgroup/cpu.max || true
        if [[ "$quota" =~ ^[0-9]+$ ]] && [[ "$period" =~ ^[0-9]+$ ]] \
            && [ "$quota" -gt 0 ] && [ "$period" -gt 0 ]; then
            quota_count=$(((quota + period - 1) / period))
            [ "$quota_count" -lt "$available" ] && available="$quota_count"
        fi
    else
        for cpu_dir in /sys/fs/cgroup/cpu /sys/fs/cgroup/cpu,cpuacct; do
            [ -r "$cpu_dir/cpu.cfs_quota_us" ] && [ -r "$cpu_dir/cpu.cfs_period_us" ] || continue
            quota="$(<"$cpu_dir/cpu.cfs_quota_us")"
            period="$(<"$cpu_dir/cpu.cfs_period_us")"
            if [[ "$quota" =~ ^[0-9]+$ ]] && [[ "$period" =~ ^[0-9]+$ ]] \
                && [ "$quota" -gt 0 ] && [ "$period" -gt 0 ]; then
                quota_count=$(((quota + period - 1) / period))
                [ "$quota_count" -lt "$available" ] && available="$quota_count"
            fi
            break
        done
    fi

    [ "$available" -ge 1 ] || available=1
    printf '%s' "$available"
}

max_chunk_bytes="$(uint_value RUSTDESK_RECORDING_UPLOAD_MAX_CHUNK_BYTES 8388608 1 4293918720)"
readonly max_chunk_bytes
readonly body_headroom_bytes=1048576
derived_body_bytes=$((max_chunk_bytes + body_headroom_bytes))
if [ "$derived_body_bytes" -lt 5242880 ]; then
    derived_body_bytes=5242880
fi
readonly derived_body_bytes
client_max_body_bytes="$(uint_value NGINX_CLIENT_MAX_BODY_BYTES "$derived_body_bytes" "$derived_body_bytes" 4294967296)"
readonly client_max_body_bytes

default_worker_processes="$(detected_cpu_count)"
worker_processes="$(uint_value NGINX_WORKER_PROCESSES "$default_worker_processes" 1 1024)"
worker_connections="$(uint_value NGINX_WORKER_CONNECTIONS 4096 256 65535)"
fpm_max_children="$(uint_value PHP_FPM_MAX_CHILDREN 16 1 512)"
fpm_default_start=$((fpm_max_children < 4 ? fpm_max_children : 4))
fpm_default_min_spare=$((fpm_default_start < 2 ? fpm_default_start : 2))
fpm_default_max_spare=$((fpm_max_children < 6 ? fpm_max_children : 6))
fpm_start_servers="$(uint_value PHP_FPM_START_SERVERS "$fpm_default_start" 1 "$fpm_max_children")"
fpm_min_spare_servers="$(uint_value PHP_FPM_MIN_SPARE_SERVERS "$fpm_default_min_spare" 1 "$fpm_max_children")"
fpm_max_spare_servers="$(uint_value PHP_FPM_MAX_SPARE_SERVERS "$fpm_default_max_spare" 1 "$fpm_max_children")"
fpm_max_requests="$(uint_value PHP_FPM_MAX_REQUESTS 500 1 100000)"
fpm_slowlog_timeout_seconds="$(uint_value PHP_FPM_SLOWLOG_TIMEOUT_SECONDS 5 1 300)"
readonly worker_connections fpm_max_children fpm_start_servers fpm_min_spare_servers
readonly fpm_max_spare_servers fpm_max_requests fpm_slowlog_timeout_seconds
readonly worker_processes

# Validate the supervisor deadline here as well so every runtime tuning error is rejected before
# the entrypoint can migrate or seed the database. The supervisor normalizes it again at launch.
shutdown_grace_seconds="$(uint_value RUNTIME_SHUTDOWN_GRACE_SECONDS 8 1 300)"
readonly shutdown_grace_seconds

[ "$fpm_min_spare_servers" -le "$fpm_start_servers" ] \
    || fail "PHP_FPM_MIN_SPARE_SERVERS must not exceed PHP_FPM_START_SERVERS."
[ "$fpm_start_servers" -le "$fpm_max_spare_servers" ] \
    || fail "PHP_FPM_START_SERVERS must not exceed PHP_FPM_MAX_SPARE_SERVERS."

case "${NGINX_ACCESS_LOG_ENABLED:-true}" in
    true|1|yes|on)
        access_log_directive='access_log /dev/stdout rustdesk;'
        ;;
    false|0|no|off)
        access_log_directive='access_log off;'
        ;;
    *)
        fail "NGINX_ACCESS_LOG_ENABLED must be true or false."
        ;;
esac

# --- Browser remote desktop transport -------------------------------------------------
#
# Optional. When both upstreams are set, this runtime terminates the viewer's WebSocket on
# the console's own hostname and certificate and forwards it to hbbs and hbbr, which speak
# plain ws only. That removes the separate TLS terminator, the second certificate and the
# two extra public ports a direct deployment needs — at the cost of putting this container
# in the media path, since relayed session video then passes through it.
#
# Values are interpolated into the Nginx configuration, so they are validated strictly
# rather than trusted: a hostname or IPv4 literal and a port, nothing else.
ws_upstream() {
    local name="$1"
    local raw="${!name:-}"
    local host port

    [ -n "$raw" ] || { printf ''; return 0; }
    [[ "$raw" =~ ^([A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?):([0-9]{1,5})$ ]] \
        || fail "$name must be host:port, for example hbbs:21118."

    host="${BASH_REMATCH[1]}"
    port="${BASH_REMATCH[3]}"
    [ "$port" -ge 1 ] && [ "$port" -le 65535 ] \
        || fail "$name port must be between 1 and 65535."

    printf '%s:%s' "$host" "$port"
}

ws_id_upstream="$(ws_upstream RUSTDESK_WS_ID_UPSTREAM)"
ws_relay_upstream="$(ws_upstream RUSTDESK_WS_RELAY_UPSTREAM)"

if { [ -n "$ws_id_upstream" ] && [ -z "$ws_relay_upstream" ]; } \
    || { [ -z "$ws_id_upstream" ] && [ -n "$ws_relay_upstream" ]; }; then
    # Half a configuration produces a rendezvous endpoint with no matching relay: the
    # session would connect and then stop, which is harder to diagnose than not starting.
    fail "RUSTDESK_WS_ID_UPSTREAM and RUSTDESK_WS_RELAY_UPSTREAM must be set together."
fi

ws_map=''
ws_locations=''
if [ -n "$ws_id_upstream" ]; then
    # Nginx resolves a literal hostname in proxy_pass once, at startup, and refuses to
    # start when it does not resolve. Written that way this container would fail to boot
    # whenever hbbs happened to be slower to start — and would then hold that first IP for
    # the life of the process, so a restarted hbbs would be proxied into a black hole. A
    # variable defers resolution to request time and re-resolves on the TTL below, which
    # needs an explicit resolver: take the container's own, as Docker's embedded DNS is
    # what makes the service names resolvable in the first place.
    ws_resolver="$(awk '/^nameserver[[:space:]]/ { print $2; exit }' /etc/resolv.conf 2>/dev/null || true)"
    [[ "$ws_resolver" =~ ^[0-9a-fA-F:.]+$ ]] || ws_resolver='127.0.0.11'

    ws_map="$(cat <<MAP
    # A WebSocket upgrade must be forwarded as such; anything else closes the hop cleanly.
    map \$http_upgrade \$connection_upgrade {
        default upgrade;
        ''      close;
    }

    resolver $ws_resolver valid=30s ipv6=off;
MAP
)"
    ws_locations="$(cat <<LOCATIONS
        # Browser remote desktop transport. \`^~\` so no regex location can claim these.
        #
        # X-Real-IP and X-Forwarded-For are blanked rather than forwarded, and that is the
        # single most important line here: hbbs overwrites the connection's address with
        # X-Real-IP — falling back to X-Forwarded-For, unvalidated — and then keys its
        # pending-response map on the result. Forward them and every operator arriving
        # through the same proxy collapses onto one key and they take each other's
        # PunchHoleResponse. Two people behind one public IP is enough to see it.
        location ^~ /ws/id {
            # Through a variable, so the name is resolved per request rather than pinned at
            # startup. See the resolver note above.
            set \$ws_id_upstream "$ws_id_upstream";
            proxy_pass http://\$ws_id_upstream;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection \$connection_upgrade;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP "";
            proxy_set_header X-Forwarded-For "";
            proxy_read_timeout 120s;
            proxy_send_timeout 120s;
        }

        # The relay carries the session itself: long-lived, and quiet whenever the remote
        # screen is static, so the timeout is generous and responses are never buffered.
        location ^~ /ws/relay {
            set \$ws_relay_upstream "$ws_relay_upstream";
            proxy_pass http://\$ws_relay_upstream;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection \$connection_upgrade;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP "";
            proxy_set_header X-Forwarded-For "";
            proxy_read_timeout 600s;
            proxy_send_timeout 600s;
            proxy_buffering off;
        }
LOCATIONS
)"
fi

if [ -L /run/php ] || { [ -e /run/php ] && [ ! -d /run/php ]; }; then
    fail "/run/php must be a real directory, not a link or another file type."
fi
mkdir -p /run/php /etc/nginx
chown root:www-data /run/php
chmod 0750 /run/php
[ "$(stat -c '%U:%G:%a' /run/php)" = 'root:www-data:750' ] \
    || fail "/run/php must remain root:www-data with mode 0750."

nginx_tmp="$(mktemp /etc/nginx/nginx.conf.XXXXXX)"
fpm_tmp="$(mktemp /usr/local/etc/php-fpm.d/www.conf.XXXXXX)"
php_tmp="$(mktemp "$PHP_INI_DIR/conf.d/zz-runtime-limits.ini.XXXXXX")"
validation_log="$(mktemp /tmp/runtime-config-validation.XXXXXX)"
trap 'rm -f "$nginx_tmp" "$fpm_tmp" "$php_tmp" "$validation_log"' EXIT

# The two WebSocket placeholders are multi-line, which `sed s|||` cannot express; awk
# substitutes them whole, and leaves an empty line behind when the feature is off.
sed \
    -e "s|__NGINX_WORKER_PROCESSES__|$worker_processes|g" \
    -e "s|__NGINX_WORKER_CONNECTIONS__|$worker_connections|g" \
    -e "s|__NGINX_CLIENT_MAX_BODY_BYTES__|$client_max_body_bytes|g" \
    -e "s|__NGINX_ACCESS_LOG_DIRECTIVE__|$access_log_directive|g" \
    "$NGINX_TEMPLATE" \
    | awk -v ws_map="$ws_map" -v ws_locations="$ws_locations" '
        $0 == "__NGINX_WS_MAP__"       { if (ws_map != "") print ws_map; next }
        $0 == "__NGINX_WS_LOCATIONS__" { if (ws_locations != "") print ws_locations; next }
        { print }
    ' > "$nginx_tmp"

sed \
    -e "s|__PHP_FPM_MAX_CHILDREN__|$fpm_max_children|g" \
    -e "s|__PHP_FPM_START_SERVERS__|$fpm_start_servers|g" \
    -e "s|__PHP_FPM_MIN_SPARE_SERVERS__|$fpm_min_spare_servers|g" \
    -e "s|__PHP_FPM_MAX_SPARE_SERVERS__|$fpm_max_spare_servers|g" \
    -e "s|__PHP_FPM_MAX_REQUESTS__|$fpm_max_requests|g" \
    -e "s|__PHP_FPM_SLOWLOG_TIMEOUT_SECONDS__|$fpm_slowlog_timeout_seconds|g" \
    "$FPM_TEMPLATE" > "$fpm_tmp"

{
    echo '; Generated by render-runtime-config.sh; do not edit in a running container.'
    echo 'expose_php = Off'
    echo "post_max_size = $client_max_body_bytes"
    echo "upload_max_filesize = $client_max_body_bytes"
} > "$php_tmp"

chmod 0644 "$nginx_tmp" "$fpm_tmp" "$php_tmp"
mv -f "$nginx_tmp" /etc/nginx/nginx.conf
mv -f "$fpm_tmp" /usr/local/etc/php-fpm.d/www.conf
mv -f "$php_tmp" "$PHP_INI_DIR/conf.d/zz-runtime-limits.ini"

if ! nginx -t > "$validation_log" 2>&1; then
    cat "$validation_log" >&2
    fail "Nginx rejected the rendered configuration."
fi
if ! php-fpm -tt > "$validation_log" 2>&1; then
    cat "$validation_log" >&2
    fail "PHP-FPM rejected the rendered configuration."
fi

echo "[runtime-config] Nginx workers=${worker_processes}, body ceiling=${client_max_body_bytes}B, FPM children=${fpm_max_children}, access log=${NGINX_ACCESS_LOG_ENABLED:-true}, shutdown grace=${shutdown_grace_seconds}s."
