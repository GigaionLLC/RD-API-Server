#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$REPOSITORY_ROOT/docker/compose.performance.yml"

MODE="${PERF_MODE:-smoke}"
APACHE_IMAGE="${APACHE_IMAGE:-ghcr.io/gigaionllc/rustdesk-api-server:1.0.1@sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2}"
NGINX_IMAGE="${NGINX_IMAGE:-rustdesk-api:nginx-candidate}"
NGINX_PULL_POLICY="${NGINX_PULL_POLICY:-never}"
PROJECT_NAME="rdapi-perf-$$"
collector_pid=''
RESULTS_DIRECTORY="${PERF_RESULTS_DIR:-$SCRIPT_DIR/results/$(date -u +%Y%m%d-%H%M%S)-$MODE}"
mkdir -p "$RESULTS_DIRECTORY"
RESULTS_DIRECTORY="$(cd "$RESULTS_DIRECTORY" && pwd)"
if compgen -G "$RESULTS_DIRECTORY/*.summary.json" >/dev/null; then
    echo "Results directory already contains summary files: $RESULTS_DIRECTORY" >&2
    exit 2
fi

case "$MODE" in
    smoke)
        DEFAULT_DEVICES=30; DEFAULT_ACTIVE=0.2; DEFAULT_WARMUP=5s; DEFAULT_DURATION=20s
        DEFAULT_TRIALS=1; DEFAULT_ENFORCE=false; DEFAULT_P95=250; DEFAULT_P99=750
        DEFAULT_PRE_VUS=20; DEFAULT_MAX_VUS=100
        DEFAULT_K6_CPUS=2.0; DEFAULT_K6_MEMORY=1024m
        ;;
    steady)
        DEFAULT_DEVICES=15000; DEFAULT_ACTIVE=0.2; DEFAULT_WARMUP=2m; DEFAULT_DURATION=30m
        DEFAULT_TRIALS=3; DEFAULT_ENFORCE=true; DEFAULT_P95=250; DEFAULT_P99=750
        DEFAULT_PRE_VUS=1024; DEFAULT_MAX_VUS=4096
        DEFAULT_K6_CPUS=4.0; DEFAULT_K6_MEMORY=4096m
        ;;
    recovery)
        DEFAULT_DEVICES=10000; DEFAULT_ACTIVE=1.0; DEFAULT_WARMUP=15s; DEFAULT_DURATION=60s
        DEFAULT_TRIALS=1; DEFAULT_ENFORCE=true; DEFAULT_P95=2000; DEFAULT_P99=2000
        DEFAULT_PRE_VUS=1024; DEFAULT_MAX_VUS=4096
        DEFAULT_K6_CPUS=4.0; DEFAULT_K6_MEMORY=4096m
        ;;
    *)
        echo "PERF_MODE must be smoke, steady, or recovery." >&2
        exit 2
        ;;
esac

export PERF_MODE="$MODE"
export PERF_DEVICE_COUNT="${PERF_DEVICE_COUNT:-$DEFAULT_DEVICES}"
export PERF_ACTIVE_FRACTION="${PERF_ACTIVE_FRACTION:-$DEFAULT_ACTIVE}"
export PERF_WARMUP_DURATION="${PERF_WARMUP_DURATION:-$DEFAULT_WARMUP}"
export PERF_TEST_DURATION="${PERF_TEST_DURATION:-$DEFAULT_DURATION}"
export PERF_TRIALS="${PERF_TRIALS:-$DEFAULT_TRIALS}"
export PERF_ENFORCE_GATE="${PERF_ENFORCE_GATE:-$DEFAULT_ENFORCE}"
export PERF_P95_LIMIT_MS="${PERF_P95_LIMIT_MS:-$DEFAULT_P95}"
export PERF_P99_LIMIT_MS="${PERF_P99_LIMIT_MS:-$DEFAULT_P99}"
export PERF_PREALLOCATED_VUS="${PERF_PREALLOCATED_VUS:-$DEFAULT_PRE_VUS}"
export PERF_MAX_VUS="${PERF_MAX_VUS:-$DEFAULT_MAX_VUS}"
export PERF_ORDER_SEED="${PERF_ORDER_SEED:-20260718}"
export PERF_RESULTS_DIR="$RESULTS_DIRECTORY"
export PERF_APP_CPUS="${PERF_APP_CPUS:-2.0}"
export PERF_APP_MEMORY="${PERF_APP_MEMORY:-1024m}"
export PERF_DB_CPUS="${PERF_DB_CPUS:-2.0}"
export PERF_DB_MEMORY="${PERF_DB_MEMORY:-2048m}"
export PERF_K6_CPUS="${PERF_K6_CPUS:-$DEFAULT_K6_CPUS}"
export PERF_K6_MEMORY="${PERF_K6_MEMORY:-$DEFAULT_K6_MEMORY}"
export PERF_K6_USER="${PERF_K6_USER:-$(id -u):$(id -g)}"
export PERF_IMAGE="$APACHE_IMAGE"
export PERF_PULL_POLICY=missing

compose() {
    docker compose -p "$PROJECT_NAME" -f "$COMPOSE_FILE" "$@"
}

cleanup() {
    if [ -n "$collector_pid" ]; then
        kill "$collector_pid" >/dev/null 2>&1 || true
        wait "$collector_pid" 2>/dev/null || true
        collector_pid=''
    fi
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

collect_stats() {
    local output_path="$1" stop_path="$2" app_id="$3" db_id="$4"
    while [ ! -e "$stop_path" ]; do
        mapfile -t load_ids < <(docker ps -q \
            --filter "label=com.docker.compose.project=$PROJECT_NAME" \
            --filter 'label=com.docker.compose.service=k6' 2>/dev/null)
        docker stats --no-stream --format '{{ json . }}' "$app_id" "$db_id" "${load_ids[@]}" \
            >> "$output_path" 2>/dev/null || true
        sleep 1
    done
}

compose --profile load config --quiet
run_failed=0
order_state="$PERF_ORDER_SEED"

next_order_bit() {
    order_state=$(((1103515245 * order_state + 12345) & 2147483647))
    order_bit=$((order_state % 2))
}

for ((trial = 1; trial <= PERF_TRIALS; trial++)); do
    next_order_bit
    if ((order_bit == 0)); then
        targets=("apache|$APACHE_IMAGE|missing" "nginx|$NGINX_IMAGE|$NGINX_PULL_POLICY")
    else
        targets=("nginx|$NGINX_IMAGE|$NGINX_PULL_POLICY" "apache|$APACHE_IMAGE|missing")
    fi
    next_order_bit
    if ((order_bit == 0)); then
        profiles=(keepalive no-reuse)
    else
        profiles=(no-reuse keepalive)
    fi

    for target in "${targets[@]}"; do
        IFS='|' read -r runtime image pull_policy <<< "$target"
        export PERF_IMAGE="$image" PERF_PULL_POLICY="$pull_policy" PERF_RUNTIME="$runtime" PERF_TRIAL="$trial"

        for profile in "${profiles[@]}"; do
            label="$runtime-$profile-trial-$trial"
            export PERF_CONNECTION_PROFILE="$profile" PERF_RUN_LABEL="$label"
            cleanup
            echo "Starting $label with a fresh disposable database..."
            compose up -d --wait --wait-timeout 180 db app

            app_id="$(compose ps -q app)"
            db_id="$(compose ps -q db)"
            if [ -z "$app_id" ] || [ -z "$db_id" ]; then
                echo 'Unable to identify the app and database containers.' >&2
                exit 1
            fi
            runtime_image_id="$(docker inspect --format '{{.Image}}' "$app_id")"
            if [[ ! "$runtime_image_id" =~ ^sha256:[0-9a-f]{64}$ ]]; then
                echo "Unable to identify the runtime image for $label." >&2
                exit 1
            fi
            application_fingerprint="$(compose exec -T app bash /performance/fingerprint.sh /var/www/html)"
            if [[ ! "$application_fingerprint" =~ ^sha256:[0-9a-f]{64}$ ]]; then
                echo "Unable to fingerprint the application payload for $label." >&2
                exit 1
            fi
            oci_revision="$(docker image inspect "$runtime_image_id" \
                --format '{{if .Config.Labels}}{{index .Config.Labels "org.opencontainers.image.revision"}}{{end}}')"
            if [ -z "$oci_revision" ] || [ "$oci_revision" = '<no value>' ]; then
                oci_revision=unknown
            fi
            export PERF_RUNTIME_IMAGE_ID="$runtime_image_id"
            export PERF_APPLICATION_FINGERPRINT="$application_fingerprint"
            export PERF_OCI_REVISION="$oci_revision"

            compose exec -T app php /performance/seed.php \
                | tee "$RESULTS_DIRECTORY/$label.seed.json"

            stats_path="$RESULTS_DIRECTORY/$label.stats.jsonl"
            stop_path="$RESULTS_DIRECTORY/$label.stats.stop"
            rm -f "$stats_path" "$stop_path"
            collect_stats "$stats_path" "$stop_path" "$app_id" "$db_id" &
            collector_pid=$!
            echo "Running $label..."
            if ! compose --profile load run --rm k6; then
                run_failed=1
                echo "$label failed its k6 checks or thresholds." >&2
            fi
            touch "$stop_path"
            wait "$collector_pid" || true
            collector_pid=''
            rm -f "$stop_path"
        done
    done
done

cleanup
docker run --rm --entrypoint php --user "$PERF_K6_USER" --read-only --cap-drop ALL \
    --security-opt no-new-privileges \
    -v "$SCRIPT_DIR:/performance:ro" \
    -v "$RESULTS_DIRECTORY:/results" \
    "$APACHE_IMAGE" /performance/compare.php /results

echo "Performance results: $RESULTS_DIRECTORY"
if [ "$run_failed" -ne 0 ]; then
    echo 'One or more performance runs failed their response checks or configured thresholds.' >&2
    exit 1
fi
