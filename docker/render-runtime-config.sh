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

sed \
    -e "s|__NGINX_WORKER_PROCESSES__|$worker_processes|g" \
    -e "s|__NGINX_WORKER_CONNECTIONS__|$worker_connections|g" \
    -e "s|__NGINX_CLIENT_MAX_BODY_BYTES__|$client_max_body_bytes|g" \
    -e "s|__NGINX_ACCESS_LOG_DIRECTIVE__|$access_log_directive|g" \
    "$NGINX_TEMPLATE" > "$nginx_tmp"

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
