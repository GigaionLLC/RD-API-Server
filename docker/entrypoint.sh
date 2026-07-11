#!/usr/bin/env bash
# Runtime entrypoint: prepares storage, app key, database, and caches, then starts Apache.
# Designed so `docker compose up` works with zero manual steps.
set -e

cd /var/www/html

echo "[entrypoint] preparing storage..."
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/public \
    storage/app/recordings \
    storage/logs \
    bootstrap/cache

# --- Persistent application key (kept in the storage volume across restarts) ---
KEYFILE=storage/app/.appkey
if [ -z "${APP_KEY:-}" ]; then
    if [ -f "$KEYFILE" ]; then
        APP_KEY="$(cat "$KEYFILE")"
    else
        APP_KEY="$(php artisan key:generate --show)"
        echo "$APP_KEY" > "$KEYFILE"
        echo "[entrypoint] generated a new APP_KEY (persisted)."
    fi
    export APP_KEY
fi

# --- Database ---
# The compose files set DB_CONNECTION=mysql (MariaDB) by default. SQLite here is only the
# fallback for a bare `docker run` with no database configured — fine for evaluation / small
# setups, but use MySQL/MariaDB for a real fleet (SQLite is single-writer). See docker-compose.yml.
DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_CONNECTION
if [ "$DB_CONNECTION" = "sqlite" ]; then
    : "${DB_DATABASE:=/var/www/html/storage/app/database.sqlite}"
    export DB_DATABASE
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
else
    echo "[entrypoint] waiting for database ${DB_HOST:-db}:${DB_PORT:-3306}..."
    for i in $(seq 1 60); do
        if php -r '
            $h=getenv("DB_HOST")?:"db"; $p=getenv("DB_PORT")?:"3306";
            $u=getenv("DB_USERNAME")?:"root"; $w=getenv("DB_PASSWORD")?:"";
            try { new PDO("mysql:host=$h;port=$p", $u, $w); exit(0); } catch (Throwable $e) { exit(1); }
        ' 2>/dev/null; then
            echo "[entrypoint] database is up."; break
        fi
        sleep 2
    done
fi

chown -R www-data:www-data storage bootstrap/cache || true

echo "[entrypoint] running migrations..."
php artisan migrate --force

# --- First-run seed (idempotent via a marker so admin edits are never overwritten) ---
if [ ! -f storage/app/.installed ]; then
    echo "[entrypoint] first run: seeding default admin + mail templates + demo..."
    php artisan db:seed --force || true
    touch storage/app/.installed
fi

# --- Production caches (rebuilt each boot so env changes take effect) ---
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache || true

echo "[entrypoint] ready -> starting: $*"
exec "$@"
