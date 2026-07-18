#!/usr/bin/env bash
# Supervise Nginx and PHP-FPM as peers and translate Docker stop signals into a bounded,
# graceful drain. tini remains PID 1 and forwards signals to this process group.
set -Eeuo pipefail

readonly fpm_socket=/run/php/rustdesk-api.sock
# Docker Compose defaults to a ten-second stop timeout. Keep the image default below that hard
# deadline so existing external stacks retain a kill margin; deployments may raise both values.
shutdown_grace="${RUNTIME_SHUTDOWN_GRACE_SECONDS:-8}"

case "$shutdown_grace" in
    ''|*[!0-9]*)
        echo "[runtime] RUNTIME_SHUTDOWN_GRACE_SECONDS must be an integer between 1 and 300." >&2
        exit 1
        ;;
esac
shutdown_grace="$(printf '%s' "$shutdown_grace" | sed 's/^0*//')"
shutdown_grace="${shutdown_grace:-0}"
if [ "${#shutdown_grace}" -gt 3 ]; then
    echo "[runtime] RUNTIME_SHUTDOWN_GRACE_SECONDS must be an integer between 1 and 300." >&2
    exit 1
fi
if [ "$shutdown_grace" -lt 1 ] || [ "$shutdown_grace" -gt 300 ]; then
    echo "[runtime] RUNTIME_SHUTDOWN_GRACE_SECONDS must be an integer between 1 and 300." >&2
    exit 1
fi
readonly shutdown_grace

fpm_pid=''
nginx_pid=''
shutting_down=false

process_running() {
    local pid="$1"
    local stat_line rest state

    [ -n "$pid" ] || return 1
    kill -0 "$pid" 2>/dev/null || return 1
    if [ -r "/proc/$pid/stat" ]; then
        stat_line="$(<"/proc/$pid/stat")" || return 1
        rest="${stat_line##*) }"
        read -r state _ <<< "$rest"
        [ "$state" != 'Z' ] || return 1
    fi
    return 0
}

assert_isolated_session() {
    local pid="$1"
    local name="$2"
    local stat_line rest process_group session

    for _ in $(seq 1 50); do
        [ -r "/proc/$pid/stat" ] || break
        stat_line="$(<"/proc/$pid/stat")" || break
        # Strip the parenthesized command name; the remaining fields begin with state, parent
        # PID, process group, and session. Allow setsid a bounded moment to perform the transition.
        rest="${stat_line##*) }"
        read -r _ _ process_group session _ <<< "$rest"
        if [ "$process_group" = "$pid" ] && [ "$session" = "$pid" ]; then
            return 0
        fi
        sleep 0.02
    done

    # Each managed server must lead its own session so tini -g sends TERM/QUIT only to this
    # supervisor, which can translate both into a graceful QUIT sequence.
    echo "[runtime] $name did not enter its isolated process session; refusing unsafe supervision." >&2
    return 1
}

signal_if_running() {
    local signal="$1"
    local pid="$2"

    if process_running "$pid"; then
        kill "-$signal" "$pid" 2>/dev/null || true
    fi
}

wait_until_stopped() {
    local pid="$1"
    local deadline="$2"

    while process_running "$pid"; do
        [ "$(date +%s)" -lt "$deadline" ] || return 1
        sleep 0.2
    done
    return 0
}

graceful_shutdown() {
    local deadline

    if [ "$shutting_down" = true ]; then
        return
    fi
    shutting_down=true
    deadline=$(($(date +%s) + shutdown_grace))

    echo "[runtime] stopping Nginx, then PHP-FPM (grace=${shutdown_grace}s)..."
    # Stop accepting new HTTP work first. Once Nginx has drained its clients, FPM can stop
    # without abandoning requests that were buffered but had not yet reached a PHP worker.
    signal_if_running QUIT "$nginx_pid"
    wait_until_stopped "$nginx_pid" "$deadline" || true
    signal_if_running QUIT "$fpm_pid"

    wait_until_stopped "$fpm_pid" "$deadline" || true
    if process_running "$nginx_pid" || process_running "$fpm_pid"; then
        echo "[runtime] graceful drain exceeded ${shutdown_grace}s; forcing remaining processes down." >&2
        signal_if_running KILL "$nginx_pid"
        signal_if_running KILL "$fpm_pid"
    fi

    wait "$nginx_pid" 2>/dev/null || true
    wait "$fpm_pid" 2>/dev/null || true
}

# shellcheck disable=SC2329 # Invoked indirectly by the signal trap below.
stop_from_signal() {
    trap - QUIT TERM INT
    graceful_shutdown
    exit 0
}

trap stop_from_signal QUIT TERM INT

rm -f "$fpm_socket"
# Each server runs in an isolated session. This preserves the required tini -g behavior for the
# entrypoint while preventing an explicit Docker SIGTERM from reaching Nginx as a fast-stop TERM
# before this supervisor can translate it to graceful SIGQUIT.
setsid php-fpm --nodaemonize --fpm-config /usr/local/etc/php-fpm.conf &
fpm_pid=$!
if ! assert_isolated_session "$fpm_pid" PHP-FPM; then
    signal_if_running KILL "$fpm_pid"
    wait "$fpm_pid" 2>/dev/null || true
    exit 1
fi

for _ in $(seq 1 100); do
    if [ -S "$fpm_socket" ]; then
        break
    fi
    if ! process_running "$fpm_pid"; then
        wait "$fpm_pid" || fpm_status=$?
        echo "[runtime] PHP-FPM exited before creating its Unix socket (status=${fpm_status:-0})." >&2
        exit 1
    fi
    sleep 0.1
done

if [ ! -S "$fpm_socket" ]; then
    echo "[runtime] PHP-FPM did not create $fpm_socket within 10 seconds." >&2
    signal_if_running QUIT "$fpm_pid"
    wait "$fpm_pid" 2>/dev/null || true
    exit 1
fi

setsid nginx -g 'daemon off;' &
nginx_pid=$!
if ! assert_isolated_session "$nginx_pid" Nginx; then
    signal_if_running KILL "$nginx_pid"
    graceful_shutdown
    exit 1
fi
echo "[runtime] Nginx pid=$nginx_pid; PHP-FPM pid=$fpm_pid; FastCGI socket=$fpm_socket."

set +e
exited_pid=''
wait -n -p exited_pid "$nginx_pid" "$fpm_pid"
exited_status=$?
set -e

if [ "$exited_pid" = "$nginx_pid" ]; then
    exited_name='Nginx'
elif [ "$exited_pid" = "$fpm_pid" ]; then
    exited_name='PHP-FPM'
else
    exited_name='managed process'
fi

echo "[runtime] $exited_name exited unexpectedly (status=$exited_status); stopping its peer." >&2
graceful_shutdown
exit 1
