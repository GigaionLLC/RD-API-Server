#!/usr/bin/env bash
# Exercise the production image as a real Nginx/PHP-FPM/MariaDB system. This deliberately runs
# after the architecture image has been pushed by digest so CI verifies the exact artifact that
# can later be assembled into the multi-platform manifest.
set -Eeuo pipefail

if [ "$#" -ne 3 ]; then
    echo "Usage: $0 <image@digest> <expected-architecture> <platform>" >&2
    exit 64
fi

readonly image="$1"
readonly expected_arch="$2"
readonly platform="$3"
readonly mariadb_image='mariadb:11.8.8@sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4'
readonly admin_user='ci-runtime-admin'
readonly admin_password='CI-Runtime-Only_8462!Delete-Me'
readonly app_key='base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ='
readonly forwarded_client_ip='198.51.100.42'

suffix="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-${expected_arch}-$$"
suffix="$(printf '%s' "$suffix" | tr -cd 'a-zA-Z0-9_.-')"
readonly network_name="rd-runtime-smoke-${suffix}"
readonly db_name="rd-runtime-db-${suffix}"
work_dir="$(mktemp -d)"
readonly work_dir
declare -a containers=()
declare -a background_pids=()

cleanup() {
    local status=$?
    trap - EXIT
    set +e

    if [ "$status" -ne 0 ]; then
        echo "::group::Production runtime smoke diagnostics" >&2
        for container in "${containers[@]}"; do
            if docker inspect "$container" >/dev/null 2>&1; then
                echo "--- $container" >&2
                docker inspect "$container" \
                    --format 'state={{json .State}} stopSignal={{json .Config.StopSignal}}' >&2 || true
                docker logs --tail 300 "$container" >&2 || true
            fi
        done
        echo "::endgroup::" >&2
    fi

    local background_pid
    for background_pid in "${background_pids[@]}"; do
        kill "$background_pid" >/dev/null 2>&1 || true
        wait "$background_pid" >/dev/null 2>&1 || true
    done

    local index
    for ((index=${#containers[@]} - 1; index >= 0; index--)); do
        docker rm -f "${containers[$index]}" >/dev/null 2>&1 || true
    done
    docker network rm "$network_name" >/dev/null 2>&1 || true
    rm -rf "$work_dir"
    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

fail() {
    echo "[runtime-smoke] $*" >&2
    return 1
}

wait_for_healthy() {
    local container="$1"
    local attempts="${2:-120}"
    local state health

    for _ in $(seq 1 "$attempts"); do
        state="$(docker inspect "$container" --format '{{.State.Status}}' 2>/dev/null || true)"
        health="$(docker inspect "$container" --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' 2>/dev/null || true)"
        [ "$health" = healthy ] && return 0
        [ "$state" = running ] || fail "$container stopped before becoming healthy."
        sleep 1
    done

    fail "$container did not become healthy within ${attempts}s."
}

wait_for_app() {
    local container="$1"
    local attempts="${2:-120}"
    local state

    for _ in $(seq 1 "$attempts"); do
        state="$(docker inspect "$container" --format '{{.State.Status}}' 2>/dev/null || true)"
        [ "$state" = running ] || fail "$container stopped during application startup."
        if docker exec "$container" sh -eu -c '
            test -S /run/php/rustdesk-api.sock
            php -r '\''exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'\''
        ' >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    fail "$container did not serve /up within ${attempts}s."
}

wait_for_exit() {
    local container="$1"
    local attempts="${2:-45}"
    local running

    for _ in $(seq 1 "$attempts"); do
        running="$(docker inspect "$container" --format '{{.State.Running}}' 2>/dev/null || true)"
        [ "$running" = false ] && return 0
        sleep 1
    done

    fail "$container did not exit within ${attempts}s."
}

published_port() {
    local container="$1"
    local published port

    published="$(docker port "$container" 80/tcp | head -n 1)"
    port="${published##*:}"
    [[ "$port" =~ ^[0-9]+$ ]] || fail "Could not determine the published HTTP port from $published."
    printf '%s' "$port"
}

master_pid() {
    local container="$1"
    local process_prefix="$2"

    docker exec -e PROCESS_PREFIX="$process_prefix" "$container" php -r '
        $prefix = (string) getenv("PROCESS_PREFIX");
        foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $path) {
            $command = str_replace("\0", " ", (string) @file_get_contents($path));
            if (str_starts_with($command, $prefix)) {
                echo basename(dirname($path));
                exit(0);
            }
        }
        fwrite(STDERR, "Could not find process beginning with {$prefix}.\n");
        exit(1);
    '
}

start_app() {
    local name="$1"
    local publish_port="${2:-false}"
    local -a publish_args=()

    [ "$publish_port" = true ] && publish_args=(-p '127.0.0.1::80')
    containers+=("$name")
    docker run -d \
        --name "$name" \
        --platform "$platform" \
        --cpus 2 \
        --network "$network_name" \
        "${publish_args[@]}" \
        --tmpfs '/var/www/html/storage:rw,nosuid,nodev,noexec,size=134217728,mode=0770,uid=33,gid=33' \
        -e APP_ENV=production \
        -e APP_DEBUG=false \
        -e APP_URL=https://smoke.example.test \
        -e APP_KEY="$app_key" \
        -e DB_CONNECTION=mariadb \
        -e DB_HOST="$db_name" \
        -e DB_PORT=3306 \
        -e DB_DATABASE=rustdesk_api_runtime_smoke \
        -e DB_USERNAME=rustdesk_runtime \
        -e DB_PASSWORD=rustdesk_runtime \
        -e DB_SOCKET= \
        -e DB_URL= \
        -e ADMIN_USER="$admin_user" \
        -e ADMIN_PASS="$admin_password" \
        -e CACHE_STORE=database \
        -e SESSION_DRIVER=database \
        -e MAIL_MAILER=log \
        -e RUSTDESK_REQUIRE_DEPLOYMENT=false \
        -e RUSTDESK_AUTO_REGISTER=true \
        -e TRUSTED_PROXIES="$network_gateway" \
        -e SESSION_SECURE_COOKIE=true \
        -e NGINX_ACCESS_LOG_ENABLED=false \
        "$image" >/dev/null

    wait_for_app "$name"
}

actual_arch="$(docker image inspect "$image" --format '{{.Architecture}}')"
[ "$actual_arch" = "$expected_arch" ] \
    || fail "Expected $expected_arch image, received $actual_arch."

[ "$(docker image inspect "$image" --format '{{.Config.StopSignal}}')" = SIGQUIT ] \
    || fail 'The production image must declare graceful STOPSIGNAL SIGQUIT.'

# Validate the artifact without starting the database-dependent entrypoint. The FastCGI pool may
# retain inherited OCI EXPOSE metadata for 9000, but its effective configuration must be a Unix
# socket and bootstrap-only tools must not be present in the final layer.
docker run --rm --platform "$platform" --entrypoint sh "$image" -eu -c '
    nginx -t
    php-fpm -tt > /tmp/php-fpm-config 2>&1
    grep -Fq "listen = /run/php/rustdesk-api.sock" /tmp/php-fpm-config
    ! grep -Eq "listen = ([^/].*:)?9000$" /tmp/php-fpm-config
    test ! -e /usr/bin/composer
    test ! -e /usr/local/bin/install-php-extensions
'

# Runtime tuning must fail before the entrypoint can wait for or mutate a database.
if docker run --rm --platform "$platform" \
    -e NGINX_WORKER_PROCESSES=0 \
    "$image" > "$work_dir/invalid-runtime.log" 2>&1; then
    fail 'An invalid Nginx worker count was accepted.'
fi
grep -Fq 'NGINX_WORKER_PROCESSES must be an integer between 1 and 1024.' \
    "$work_dir/invalid-runtime.log" \
    || fail 'The invalid runtime setting did not return its validation error.'
if grep -Fq '[entrypoint] waiting for MariaDB' "$work_dir/invalid-runtime.log" \
    || grep -Fq '[entrypoint] running migrations' "$work_dir/invalid-runtime.log"; then
    fail 'Runtime tuning validation occurred after database startup work.'
fi

docker network create "$network_name" >/dev/null
network_gateway="$(docker network inspect "$network_name" --format '{{(index .IPAM.Config 0).Gateway}}')"
[ -n "$network_gateway" ] || fail 'Docker did not assign an application-observed proxy gateway.'
readonly network_gateway

containers+=("$db_name")
docker run -d \
    --name "$db_name" \
    --platform "$platform" \
    --network "$network_name" \
    --tmpfs '/var/lib/mysql:rw,nosuid,nodev,noexec,size=536870912,mode=0770,uid=999,gid=999' \
    -e MARIADB_ROOT_PASSWORD=rustdesk_runtime_root \
    -e MARIADB_DATABASE=rustdesk_api_runtime_smoke \
    -e MARIADB_USER=rustdesk_runtime \
    -e MARIADB_PASSWORD=rustdesk_runtime \
    --health-cmd 'healthcheck.sh --connect --innodb_initialized' \
    --health-interval 1s \
    --health-timeout 5s \
    --health-retries 90 \
    --health-start-period 5s \
    "$mariadb_image" >/dev/null
wait_for_healthy "$db_name"

readonly app_name="rd-runtime-app-${suffix}"
start_app "$app_name" true

docker exec "$app_name" sh -eu -c '
    grep -Fxq "worker_processes 2;" /etc/nginx/nginx.conf
    test "$(stat -c "%a:%U:%G" /run/php)" = "750:root:www-data"
    test "$(stat -c "%a:%U:%G" /run/php/rustdesk-api.sock)" = "660:www-data:www-data"
    php -r '\''
        $socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 0.25);
        if (is_resource($socket)) {
            fclose($socket);
            fwrite(STDERR, "FastCGI unexpectedly listens on TCP port 9000.\n");
            exit(1);
        }
    '\''
'

fpm_pid="$(master_pid "$app_name" 'php-fpm: master process')"
docker exec -e TARGET_PID="$fpm_pid" "$app_name" php -r '
    $environment = (string) @file_get_contents("/proc/".getenv("TARGET_PID")."/environ");
    if (str_contains("\0".$environment, "\0ADMIN_PASS=")) {
        fwrite(STDERR, "ADMIN_PASS leaked into the PHP-FPM master environment.\n");
        exit(1);
    }
'

host_port="$(published_port "$app_name")"
readonly base_url="http://127.0.0.1:${host_port}"
readonly public_host='smoke.example.test'
declare -a proxy_headers=(
    -H "Host: ${public_host}"
    -H 'X-Forwarded-Proto: https'
    -H "X-Forwarded-For: ${forwarded_client_ip}"
)

curl -fsS "${proxy_headers[@]}" "$base_url/up" >/dev/null

expected_version="$(sed -n "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" config/app.php | head -n 1)"
[ -n "$expected_version" ] || fail 'Could not read the expected application version.'
curl -fsS "${proxy_headers[@]}" "$base_url/api/version" \
    | jq -e --arg version "$expected_version" '.version == $version' >/dev/null

curl -fsS -D "$work_dir/css.headers" -o "$work_dir/theme.css" \
    "${proxy_headers[@]}" "$base_url/assets/css/theme-dark.css"
grep -Eiq '^content-type:[[:space:]]*text/css' "$work_dir/css.headers" \
    || fail 'The static theme stylesheet has the wrong content type.'

curl -fsS -D "$work_dir/login.headers" -o "$work_dir/login.html" \
    "${proxy_headers[@]}" "$base_url/admin/login"
grep -Fq "https://${public_host}/assets/css/theme-dark.css" "$work_dir/login.html" \
    || fail 'Trusted HTTPS proxy scheme was not reflected in generated asset URLs.'
! grep -Eq '(href|src)="http://' "$work_dir/login.html" \
    || fail 'The HTTPS admin page contains an insecure HTTP asset URL.'
grep -Eiq '^set-cookie:.*;[[:space:]]*secure([;[:space:]]|$)' "$work_dir/login.headers" \
    || fail 'The proxied HTTPS login did not emit a Secure session cookie.'
! grep -Eiq '^x-powered-by:' "$work_dir/login.headers" \
    || fail 'The dynamic response discloses the PHP runtime version.'
! grep -Eiq '^server:[[:space:]]*nginx/[0-9]' "$work_dir/login.headers" \
    || fail 'The dynamic response discloses the Nginx runtime version.'

redirect_status="$(curl -sS -D "$work_dir/redirect.headers" -o /dev/null -w '%{http_code}' \
    "${proxy_headers[@]}" "$base_url/admin")"
[ "$redirect_status" = 302 ] || fail "Expected /admin to redirect with 302, received $redirect_status."
grep -Eiq "^location:[[:space:]]*https://${public_host}/admin/login" "$work_dir/redirect.headers" \
    || fail 'The admin redirect did not preserve the trusted HTTPS origin.'

login_response="$(curl -fsS "${proxy_headers[@]}" \
    -H 'Content-Type: application/json' \
    --data-binary "{\"username\":\"${admin_user}\",\"password\":\"${admin_password}\",\"id\":\"ci-login\",\"uuid\":\"ci-login-uuid\"}" \
    "$base_url/api/login")"
access_token="$(jq -er '.access_token | select(type == "string" and length > 0)' <<< "$login_response")"
curl -fsS "${proxy_headers[@]}" \
    -H "Authorization: Bearer ${access_token}" \
    -X POST "$base_url/api/currentUser" \
    | jq -e --arg user "$admin_user" '.name == $user' >/dev/null

readonly heartbeat_id='ci-runtime-heartbeat'
curl -fsS "${proxy_headers[@]}" \
    -H 'Content-Type: application/json' \
    --data-binary "{\"id\":\"${heartbeat_id}\",\"uuid\":\"ci-runtime-heartbeat-uuid\",\"conns\":[]}" \
    "$base_url/api/heartbeat" >/dev/null
recorded_ip="$(docker exec "$db_name" mariadb --batch --skip-column-names \
    -urustdesk_runtime -prustdesk_runtime rustdesk_api_runtime_smoke \
    -e "SELECT last_online_ip FROM devices WHERE rustdesk_id='${heartbeat_id}' LIMIT 1")"
[ "$recorded_ip" = "$forwarded_client_ip" ] \
    || fail "Expected recovered client IP $forwarded_client_ip, received ${recorded_ip:-none}."

method_status="$(curl -sS -o /dev/null -w '%{http_code}' \
    "${proxy_headers[@]}" -X POST "$base_url/api/version")"
[ "$method_status" = 405 ] || fail "Expected POST /api/version to return 405, received $method_status."

for denied_path in '/.env' '/not-a-front-controller.php' '/index.php/path-info'; do
    denied_status="$(curl -sS -o /dev/null -w '%{http_code}' \
        "${proxy_headers[@]}" "$base_url$denied_path")"
    [ "$denied_status" = 404 ] \
        || fail "Expected $denied_path to return 404, received $denied_status."
done

truncate -s 4194304 "$work_dir/four-megabyte-body.bin"
body_status="$(curl -sS -o /dev/null -w '%{http_code}' \
    "${proxy_headers[@]}" -H 'Content-Type: application/octet-stream' \
    --data-binary "@$work_dir/four-megabyte-body.bin" "$base_url/api/heartbeat")"
[ "$body_status" != 413 ] \
    || fail 'Nginx rejected a supported four-megabyte request body.'

truncate -s 10485760 "$work_dir/oversized-body.bin"
oversized_status="$(curl -sS -o /dev/null -w '%{http_code}' \
    "${proxy_headers[@]}" -H 'Content-Type: application/octet-stream' \
    --data-binary "@$work_dir/oversized-body.bin" "$base_url/api/heartbeat")"
[ "$oversized_status" = 413 ] \
    || fail "Expected the default oversized request to return 413, received $oversized_status."

# A managed child crash must fail the whole container so an orchestrator can replace it.
docker exec "$app_name" sh -eu -c "kill -KILL $fpm_pid"
wait_for_exit "$app_name"
[ "$(docker inspect "$app_name" --format '{{.State.ExitCode}}')" -ne 0 ] \
    || fail 'The container exited successfully after PHP-FPM was killed.'

readonly nginx_failure_name="rd-runtime-nginx-failure-${suffix}"
start_app "$nginx_failure_name"
nginx_pid="$(master_pid "$nginx_failure_name" 'nginx: master process')"
docker exec "$nginx_failure_name" sh -eu -c "kill -KILL $nginx_pid"
wait_for_exit "$nginx_failure_name"
[ "$(docker inspect "$nginx_failure_name" --format '{{.State.ExitCode}}')" -ne 0 ] \
    || fail 'The container exited successfully after Nginx was killed.'

# Explicit TERM must be translated to graceful QUIT, while a normal Docker stop must use the
# image's real SIGQUIT stop signal. Hold a supported request body in flight across each signal;
# a clean process exit without the HTTP 200 would not prove that the drain actually worked.
readonly term_name="rd-runtime-term-${suffix}"
start_app "$term_name" true
term_port="$(published_port "$term_name")"
curl -sS --limit-rate 1M -H 'Expect:' -H 'Content-Type: application/octet-stream' \
    --data-binary "@$work_dir/four-megabyte-body.bin" \
    -o "$work_dir/term.response" -w '%{http_code}' \
    "http://127.0.0.1:${term_port}/api/heartbeat" > "$work_dir/term.status" &
term_curl_pid=$!
background_pids+=("$term_curl_pid")
sleep 1
docker kill --signal=TERM "$term_name" >/dev/null
if ! wait "$term_curl_pid"; then
    fail 'The in-flight request was interrupted during explicit TERM.'
fi
[ "$(cat "$work_dir/term.status")" = 200 ] \
    || fail 'The in-flight request did not complete with HTTP 200 during explicit TERM.'
wait_for_exit "$term_name" 13
[ "$(docker inspect "$term_name" --format '{{.State.ExitCode}}')" -eq 0 ] \
    || fail 'Explicit TERM did not produce a clean graceful shutdown.'

readonly quit_name="rd-runtime-quit-${suffix}"
start_app "$quit_name" true
quit_port="$(published_port "$quit_name")"
curl -sS --limit-rate 1M -H 'Expect:' -H 'Content-Type: application/octet-stream' \
    --data-binary "@$work_dir/four-megabyte-body.bin" \
    -o "$work_dir/quit.response" -w '%{http_code}' \
    "http://127.0.0.1:${quit_port}/api/heartbeat" > "$work_dir/quit.status" &
quit_curl_pid=$!
background_pids+=("$quit_curl_pid")
sleep 1
docker stop -t 13 "$quit_name" >/dev/null
if ! wait "$quit_curl_pid"; then
    fail 'The in-flight request was interrupted during the declared SIGQUIT stop path.'
fi
[ "$(cat "$work_dir/quit.status")" = 200 ] \
    || fail 'The in-flight request did not complete with HTTP 200 during the SIGQUIT stop path.'
[ "$(docker inspect "$quit_name" --format '{{.State.ExitCode}}')" -eq 0 ] \
    || fail 'The declared SIGQUIT stop path did not produce a clean graceful shutdown.'

echo "[runtime-smoke] $expected_arch production image passed HTTP, proxy, FastCGI, security, failure, and shutdown checks."
