#!/usr/bin/env bash
# Run the complete Playwright matrix against a disposable MariaDB-backed application.
# Invoke from the repository root through the dedicated Compose profile:
#   docker compose -f docker/compose.toolchain.yml --profile e2e run --rm e2e bash docker/e2e.sh
set -euo pipefail

cd /app

if [ "${DB_CONNECTION:-}" != "mariadb" ] \
    || [ "${DB_HOST:-}" != "e2e-db" ] \
    || [ "${DB_PORT:-}" != "3306" ] \
    || [ "${DB_DATABASE:-}" != "rustdesk_api_e2e" ] \
    || [ -n "${DB_SOCKET:-}" ] \
    || [ -n "${DB_URL:-}" ]; then
  echo "Refusing E2E reset: use the dedicated Compose e2e profile." >&2
  exit 1
fi

if [ ! -f vendor/autoload.php ]; then
  echo "== install locked PHP dependencies =="
  composer install --no-interaction --prefer-dist --no-progress
fi
if [ ! -x node_modules/.bin/playwright ]; then
  echo "== install locked browser-test dependencies =="
  npm ci --ignore-scripts --no-audit --no-fund
fi

SERVER_PID=""
cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

php artisan config:clear >/dev/null

echo "== verify isolated MariaDB target =="
php -r '
    $host = getenv("DB_HOST");
    $port = getenv("DB_PORT") ?: "3306";
    $database = getenv("DB_DATABASE");
    $username = getenv("DB_USERNAME");
    $password = getenv("DB_PASSWORD");
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $row = $pdo->query("SELECT DATABASE(), VERSION(), @@default_storage_engine")?->fetch(PDO::FETCH_NUM);
    if (!is_array($row)
        || ($row[0] ?? "") !== "rustdesk_api_e2e"
        || stripos((string) ($row[1] ?? ""), "MariaDB") === false
        || strcasecmp((string) ($row[2] ?? ""), "InnoDB") !== 0) {
        fwrite(STDERR, "Refusing E2E reset: live target is not the isolated MariaDB/InnoDB E2E database.\n");
        exit(1);
    }
'

echo "== migrate + seed isolated e2e db =="
php artisan migrate:fresh --seed --force

php -r '
    $pdo = new PDO(
        "mysql:host=e2e-db;port=3306;dbname=rustdesk_api_e2e;charset=utf8mb4",
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    $query = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND COALESCE(UPPER(ENGINE), ?) <> ?");
    $query->execute(["rustdesk_api_e2e", "BASE TABLE", "", "INNODB"]);
    if ((int) $query->fetchColumn() !== 0) {
        fwrite(STDERR, "Refusing E2E run: an application table is not InnoDB.\n");
        exit(1);
    }
'

echo "== start server =="
# --no-reload preserves the Compose environment when Laravel launches the PHP server process.
PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload --host=0.0.0.0 --port=8088 >/tmp/e2e-serve.log 2>&1 &
SERVER_PID=$!

echo "== wait for server =="
n=0
until curl -sf http://127.0.0.1:8088/admin/login >/dev/null 2>&1; do
  n=$((n+1))
  if [ "$n" -gt 40 ]; then
    echo "server did not come up" >&2
    cat /tmp/e2e-serve.log >&2
    exit 1
  fi
  sleep 1
done
echo "server up after ${n}s"

echo "== run Playwright matrix =="
E2E_BASE_URL=http://127.0.0.1:8088 npx playwright test "$@"
