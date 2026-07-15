#!/usr/bin/env bash
# Boot the app with DemoShowcaseSeeder data and capture the admin-console screenshots used in
# the README + docs/screenshots/ gallery. Run from the repository root:
#   docker compose -f docker/compose.toolchain.yml --profile screenshots run --rm \
#     -e CAPTURE_SCREENSHOTS=1 screenshots bash docker/demo-shots.sh
#
# The Compose profile provides a dedicated tmpfs-backed MariaDB instance. This script refuses
# any other host/database before running migrate:fresh, so development data cannot be selected.
set -euo pipefail

cd /app

# This is an explicit development fixture workflow. A clean checkout may not have a .env file,
# so opt into the local-only seed credential instead of inheriting Laravel's production default.
export APP_ENV="${APP_ENV:-local}"

if [ "${CAPTURE_SCREENSHOTS:-}" != "1" ]; then
  echo "Refusing screenshot capture: set CAPTURE_SCREENSHOTS=1 explicitly." >&2
  exit 1
fi

if [ "${DB_CONNECTION:-}" != "mariadb" ] \
    || [ "${DB_HOST:-}" != "screenshot-db" ] \
    || [ "${DB_PORT:-}" != "3306" ] \
    || [ "${DB_DATABASE:-}" != "rustdesk_api_screenshots" ] \
    || [ -n "${DB_SOCKET:-}" ] \
    || [ -n "${DB_URL:-}" ]; then
  echo "Refusing screenshot reset: use the dedicated Compose screenshots profile." >&2
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

mkdir -p /app/docs/screenshots
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
        || ($row[0] ?? "") !== "rustdesk_api_screenshots"
        || stripos((string) ($row[1] ?? ""), "MariaDB") === false
        || strcasecmp((string) ($row[2] ?? ""), "InnoDB") !== 0) {
        fwrite(STDERR, "Refusing screenshot reset: live target is not the isolated MariaDB/InnoDB screenshot database.\n");
        exit(1);
    }
'

echo "== migrate + seed isolated screenshot db =="
php artisan migrate:fresh --seed --force
php artisan db:seed --class="Database\\Seeders\\DemoShowcaseSeeder" --force

php -r '
    $pdo = new PDO(
        "mysql:host=screenshot-db;port=3306;dbname=rustdesk_api_screenshots;charset=utf8mb4",
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    $query = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND COALESCE(UPPER(ENGINE), ?) <> ?");
    $query->execute(["rustdesk_api_screenshots", "BASE TABLE", "", "INNODB"]);
    if ((int) $query->fetchColumn() !== 0) {
        fwrite(STDERR, "Refusing screenshot capture: an application table is not InnoDB.\n");
        exit(1);
    }
'

echo "== start server =="
# --no-reload preserves the guarded screenshot database environment in the child server.
php artisan serve --no-reload --host=0.0.0.0 --port=8088 >/tmp/serve.log 2>&1 &
SERVER_PID=$!

echo "== wait for server =="
n=0
until curl -sf http://127.0.0.1:8088/admin/login >/dev/null 2>&1; do
  n=$((n+1))
  if [ "$n" -gt 40 ]; then
    echo "server did not come up" >&2
    cat /tmp/serve.log >&2
    exit 1
  fi
  sleep 1
done
echo "server up after ${n}s"

echo "== sanity: device count via API-less check =="
php artisan tinker --execute="echo 'devices='.\App\Models\Device::count().' users='.\App\Models\User::count().' conns='.\App\Models\AuditConn::count().PHP_EOL;" || true

echo "== capture =="
E2E_BASE_URL=http://127.0.0.1:8088 \
  npx playwright test screenshots.spec.ts --project=desktop-dark --reporter=line

echo "== done =="
ls -la /app/docs/screenshots
