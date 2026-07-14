#!/usr/bin/env bash
# Boot the app with DemoShowcaseSeeder data and capture the admin-console screenshots used in
# the README + docs/screenshots/ gallery. Run inside the toolchain image:
#   docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash docker/demo-shots.sh
#
# Uses the app's DEFAULT sqlite database so the CLI (seed) and `php artisan serve` (which does
# not forward shell-exported env to its child) read the same DB. The existing
# database/database.sqlite is backed up and restored, so your dev data is untouched.
set -e

# This is an explicit development fixture workflow. A clean checkout may not have a .env file,
# so opt into the local-only seed credential instead of inheriting Laravel's production default.
export APP_ENV="${APP_ENV:-local}"

DB=/app/database/database.sqlite
BAK=/app/database/database.sqlite.demobak

echo "== back up existing db =="
test -f "$DB" && cp "$DB" "$BAK" || true

restore() {
  test -f "$BAK" && mv -f "$BAK" "$DB" || true
}
trap restore EXIT

mkdir -p /app/docs/screenshots
php artisan config:clear >/dev/null 2>&1 || true

echo "== migrate + seed (default db) =="
php artisan migrate:fresh --seed --force
php artisan db:seed --class="Database\\Seeders\\DemoShowcaseSeeder" --force

echo "== start server =="
php artisan serve --host=0.0.0.0 --port=8088 >/tmp/serve.log 2>&1 &
SERVER_PID=$!

echo "== wait for server =="
n=0
until curl -sf http://127.0.0.1:8088/admin/login >/dev/null 2>&1; do
  n=$((n+1))
  test "$n" -gt 40 && { echo "server did not come up"; cat /tmp/serve.log; break; }
  sleep 1
done
echo "server up after ${n}s"

echo "== sanity: device count via API-less check =="
php artisan tinker --execute="echo 'devices='.\App\Models\Device::count().' users='.\App\Models\User::count().' conns='.\App\Models\AuditConn::count().PHP_EOL;" || true

echo "== ensure chromium =="
npx playwright install chromium >/dev/null 2>&1 || true

echo "== capture =="
E2E_BASE_URL=http://127.0.0.1:8088 npx playwright test screenshots.spec.ts --reporter=line || true

kill "$SERVER_PID" 2>/dev/null || true
echo "== done =="
ls -la /app/docs/screenshots
