#!/usr/bin/env bash
# Runtime entrypoint: prepares storage, app key, database, and caches, then starts Apache.
# Handles setup automatically after the required first-run secrets have been provided.
set -e

cd /var/www/html

# Reject legacy/unsupported connection names before any Artisan command boots the application.
DB_CONNECTION="${DB_CONNECTION:-mariadb}"
export DB_CONNECTION
if [ "$DB_CONNECTION" != "mariadb" ]; then
    echo "[entrypoint] unsupported DB_CONNECTION '$DB_CONNECTION'; MariaDB is the only supported database (set DB_CONNECTION=mariadb)." >&2
    exit 1
fi
if [ -n "${DB_URL:-}" ]; then
    echo "[entrypoint] DB_URL is not supported; expand it into the discrete MariaDB DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD settings." >&2
    exit 1
fi

# Normalize every value once, then export it so the raw safety probes and Laravel migrate the
# exact same target. Shell defaults preserve a literal "0" instead of treating it as missing.
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-rustdesk_api}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_SOCKET="${DB_SOCKET:-}"
DB_CONNECT_TIMEOUT="${DB_CONNECT_TIMEOUT:-5}"
MYSQL_ATTR_SSL_CA="${MYSQL_ATTR_SSL_CA:-}"

case "$DB_PORT" in
    ''|*[!0-9]*)
        echo "[entrypoint] DB_PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac
if [ "$DB_PORT" -lt 1 ] || [ "$DB_PORT" -gt 65535 ]; then
    echo "[entrypoint] DB_PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

case "$DB_CONNECT_TIMEOUT" in
    ''|*[!0-9]*)
        echo "[entrypoint] DB_CONNECT_TIMEOUT must be an integer between 1 and 10 seconds." >&2
        exit 1
        ;;
esac
if [ "$DB_CONNECT_TIMEOUT" -lt 1 ] || [ "$DB_CONNECT_TIMEOUT" -gt 10 ]; then
    echo "[entrypoint] DB_CONNECT_TIMEOUT must be an integer between 1 and 10 seconds." >&2
    exit 1
fi

DATABASE_PROBE_TIMEOUT=$((DB_CONNECT_TIMEOUT + 5))
export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_CONNECT_TIMEOUT
export MYSQL_ATTR_SSL_CA

echo "[entrypoint] preparing storage..."
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/public \
    storage/app/recordings \
    storage/logs \
    bootstrap/cache

# Discard a cache from an earlier container start before any other Artisan command reads it.
php artisan config:clear

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

# --- Database (MariaDB only) ---
if [ -n "${DB_SOCKET:-}" ]; then
    echo "[entrypoint] waiting for MariaDB socket ${DB_SOCKET}/${DB_DATABASE}..."
else
    echo "[entrypoint] waiting for MariaDB ${DB_HOST}:${DB_PORT}/${DB_DATABASE}..."
fi
database_ready=false
for i in $(seq 1 30); do
    if timeout "${DATABASE_PROBE_TIMEOUT}s" php -r '
        $h=(string) getenv("DB_HOST"); $p=(string) getenv("DB_PORT");
        $d=(string) getenv("DB_DATABASE");
        $u=(string) getenv("DB_USERNAME"); $w=(string) getenv("DB_PASSWORD");
        try {
            $socket=trim((string) getenv("DB_SOCKET"));
            $dsn=$socket !== ""
                ? "mysql:unix_socket=$socket;dbname=$d;charset=utf8mb4"
                : "mysql:host=$h;port=$p;dbname=$d;charset=utf8mb4";
            $options=[
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => (int) getenv("DB_CONNECT_TIMEOUT"),
            ];
            $ca=trim((string) getenv("MYSQL_ATTR_SSL_CA"));
            if ($ca !== "") {
                if (!is_readable($ca)) { exit(4); }
                $options[\Pdo\Mysql::ATTR_SSL_CA]=$ca;
            }
            $pdo=new PDO($dsn, $u, $w, $options);
            $server=$pdo->query("SELECT DATABASE() AS database_name, VERSION() AS version, @@default_storage_engine AS engine")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($server) || ($server["database_name"] ?? null) !== $d) { exit(5); }
            if (!is_array($server) || !is_string($server["version"] ?? null) || stripos($server["version"], "MariaDB") === false) { exit(2); }
            exit(is_string($server["engine"] ?? null) && strcasecmp($server["engine"], "InnoDB") === 0 ? 0 : 3);
        } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; then
        database_ready=true
        echo "[entrypoint] MariaDB is up."
        break
    else
        database_status=$?
        if [ "$database_status" -eq 2 ]; then
            echo "[entrypoint] the configured database server is not MariaDB; aborting before migrations." >&2
            exit 1
        fi
        if [ "$database_status" -eq 3 ]; then
            echo "[entrypoint] MariaDB must use InnoDB as its default storage engine; aborting before migrations." >&2
            exit 1
        fi
        if [ "$database_status" -eq 4 ]; then
            echo "[entrypoint] MYSQL_ATTR_SSL_CA must name a readable CA certificate; aborting before migrations." >&2
            exit 1
        fi
        if [ "$database_status" -eq 5 ]; then
            echo "[entrypoint] the selected MariaDB schema does not match DB_DATABASE; aborting before migrations." >&2
            exit 1
        fi
    fi
    sleep 2
done

if [ "$database_ready" != "true" ]; then
    echo "[entrypoint] MariaDB did not become ready within the bounded readiness window; aborting before migrations." >&2
    exit 1
fi

verify_innodb_tables() {
    if ! timeout "${DATABASE_PROBE_TIMEOUT}s" php -r '
        $h=(string) getenv("DB_HOST"); $p=(string) getenv("DB_PORT");
        $d=(string) getenv("DB_DATABASE");
        $u=(string) getenv("DB_USERNAME"); $w=(string) getenv("DB_PASSWORD");
        try {
            $socket=trim((string) getenv("DB_SOCKET"));
            $dsn=$socket !== ""
                ? "mysql:unix_socket=$socket;dbname=$d;charset=utf8mb4"
                : "mysql:host=$h;port=$p;dbname=$d;charset=utf8mb4";
            $options=[
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => (int) getenv("DB_CONNECT_TIMEOUT"),
            ];
            $ca=trim((string) getenv("MYSQL_ATTR_SSL_CA"));
            if ($ca !== "") {
                if (!is_readable($ca)) { exit(1); }
                $options[\Pdo\Mysql::ATTR_SSL_CA]=$ca;
            }
            $pdo=new PDO($dsn, $u, $w, $options);
            if ($pdo->query("SELECT DATABASE()")?->fetchColumn() !== $d) { exit(1); }
            $query=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = :table_type AND COALESCE(UPPER(ENGINE), :empty_engine) <> :required_engine");
            $query->execute(["table_type" => "BASE TABLE", "empty_engine" => "", "required_engine" => "INNODB"]);
            exit((int) $query->fetchColumn() === 0 ? 0 : 1);
        } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; then
        echo "[entrypoint] could not confirm the selected MariaDB schema and InnoDB table engines; aborting startup." >&2
        exit 1
    fi
}

verify_runtime_database_access() {
    if ! timeout "${DATABASE_PROBE_TIMEOUT}s" runuser -u www-data -- php -r '
        $h=(string) getenv("DB_HOST"); $p=(string) getenv("DB_PORT");
        $d=(string) getenv("DB_DATABASE");
        $u=(string) getenv("DB_USERNAME"); $w=(string) getenv("DB_PASSWORD");
        try {
            $socket=trim((string) getenv("DB_SOCKET"));
            $dsn=$socket !== ""
                ? "mysql:unix_socket=$socket;dbname=$d;charset=utf8mb4"
                : "mysql:host=$h;port=$p;dbname=$d;charset=utf8mb4";
            $options=[
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => (int) getenv("DB_CONNECT_TIMEOUT"),
            ];
            $ca=trim((string) getenv("MYSQL_ATTR_SSL_CA"));
            if ($ca !== "") {
                if (!is_readable($ca)) { exit(1); }
                $options[\Pdo\Mysql::ATTR_SSL_CA]=$ca;
            }
            $pdo=new PDO($dsn, $u, $w, $options);
            exit($pdo->query("SELECT DATABASE()")?->fetchColumn() === $d ? 0 : 1);
        } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; then
        echo "[entrypoint] the www-data runtime user cannot access the selected MariaDB database, socket, or TLS CA; aborting before migrations." >&2
        exit 1
    fi
}

# Existing tables must satisfy row-lock/transaction assumptions before data migrations run.
verify_runtime_database_access
verify_innodb_tables

chown -R www-data:www-data storage bootstrap/cache || true

echo "[entrypoint] running migrations..."
php artisan migrate --force

# Verify newly created or altered tables before seeding or serving requests.
verify_innodb_tables

# --- First-run seed (idempotent via a marker so admin edits are never overwritten) ---
if [ ! -f storage/app/.installed ]; then
    echo "[entrypoint] first run: seeding initial administrator + application defaults..."
    # Never suppress this failure. In production the seeder rejects a missing, known, or weak
    # ADMIN_PASS before creating the full administrator, and startup must remain fail-closed.
    php artisan db:seed --force
    touch storage/app/.installed
fi

# The bootstrap password is never needed by the web process after the one-time seed.
unset ADMIN_PASS

# --- Production caches (rebuilt each boot so env changes take effect) ---
php artisan config:cache
php artisan route:cache
php artisan view:cache

# The bundled Apache image accepts HTTP internally. An HTTPS origin therefore needs an explicitly
# trusted TLS proxy so Laravel can safely honor its sanitized forwarded headers.
# Check the parsed configuration rather than the raw environment value because wildcard, /0,
# and malformed entries are intentionally discarded by config/trustedproxy.php.
proxy_configuration_status=""
if ! proxy_configuration_status="$(php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $scheme = strtolower((string) parse_url((string) config("app.url"), PHP_URL_SCHEME));
    $proxies = config("trustedproxy.proxies");
    $missing = $scheme === "https" && (!is_array($proxies) || count($proxies) === 0);
    echo $missing ? "missing" : "ok";
')"; then
    echo "[entrypoint] unable to inspect the trusted-proxy configuration after caching; aborting startup." >&2
    exit 1
fi
if [ "$proxy_configuration_status" = "missing" ]; then
    echo "[entrypoint] warning: APP_URL uses HTTPS but no valid TRUSTED_PROXIES entry is configured." >&2
    echo "[entrypoint] Laravel will emit HTTP redirects/assets and cannot recover the client IP unless the TLS proxy is explicitly trusted." >&2
    echo "[entrypoint] Set the proxy's application-observed IP or narrow isolated-network CIDR, ensure it overwrites forwarded headers, and recreate this container." >&2
elif [ "$proxy_configuration_status" != "ok" ]; then
    echo "[entrypoint] trusted-proxy configuration probe returned an unexpected result; aborting startup." >&2
    exit 1
fi

chown -R www-data:www-data storage bootstrap/cache || true

echo "[entrypoint] ready -> starting: $*"
exec "$@"
