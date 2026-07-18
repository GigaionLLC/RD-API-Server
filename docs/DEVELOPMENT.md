# Development

Everything runs in a Docker **toolchain image** — the host needs no PHP / Composer / Node.
For the project's architecture, conventions, and task map, start with **[AGENT.md](../AGENT.md)**.

## Toolchain

```bash
# build the dev/test toolchain (PHP 8.5 + Composer + Node + Playwright + linters)
docker build -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .

# dev stack (app + MariaDB + Mailpit)
docker compose -f docker/compose.toolchain.yml up -d
docker compose -f docker/compose.toolchain.yml run --rm app composer install
docker compose -f docker/compose.toolchain.yml run --rm app npm ci --ignore-scripts --no-audit --no-fund
docker compose -f docker/compose.toolchain.yml run --rm app php artisan migrate --seed
```

## Quality gates (CI runs these on every push)

```bash
docker compose -f docker/compose.toolchain.yml run --rm app bash -lc \
  'npm run check:vendor && npm run lint:js && \
   ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G'

docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test

docker compose -f docker/compose.toolchain.yml --profile e2e run --rm e2e bash docker/e2e.sh
```

The standalone lint/static-analysis command expects the locked dependencies installed in the
Toolchain section. The `e2e` runner is also safe on a clean checkout: it first rejects any target
other than its dedicated disposable database, then installs missing PHP or browser dependencies
from `composer.lock` / `package-lock.json` before running. It never updates either lock file.

- **Pint** — code style (`./vendor/bin/pint` to auto-fix).
- **PHPStan** — level 5 static analysis (needs `--memory-limit=1G`).
- **PHPUnit** — the `test` service above targets only the guarded `rustdesk_api_testing`
  database on the tmpfs-backed `test-db` MariaDB service.
- **ESLint** — browser code in `public/assets/js` and Node-based build scripts in `scripts`.
- **Vendor integrity** — `npm run check:vendor` rebuilds the local admin assets in memory and
  verifies their complete inventory and byte content against the checked-in distribution.
- **Playwright** — the `e2e` profile runs the full browser matrix against a guarded application
  and tmpfs-backed `e2e-db`, both using the exact database `rustdesk_api_e2e`. CI creates the same
  isolated schema on its job-scoped MariaDB service; never target the persistent development
  database with browser fixtures.

## Test the runtime image locally

`docker-compose.dev.yml` builds the production image from source instead of pulling it from
GHCR — use it to verify your changes as they'll ship. Because it runs with
`APP_ENV=production`, set a unique `ADMIN_PASS` of at least 12 characters in your shell or local
`.env` file first:

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

The source-built candidate runs Nginx and PHP-FPM in one container without changing its external
contract: HTTP remains on container port `80`, persistent application data remains under
`/var/www/html/storage`, and existing application/database/reverse-proxy environment settings are
unchanged. FastCGI is private to `/run/php/rustdesk-api.sock`; there must be no live TCP listener
on port `9000`, even though the upstream FPM image can retain inert OCI exposure metadata.

The image validates optional Nginx/FPM tuning before migrations. Defaults are 16 FPM children, 4
starting workers, 2 minimum and 6 maximum spare workers, 500 requests per worker, a five-second
slow-log threshold, a cgroup-quota-aware Nginx process count, 4,096 connections per Nginx worker,
and one Nginx access log on stdout. The body limit is derived from the configured recording chunk
plus 1 MiB of headroom, never below 5 MiB. See the
[runtime tuning table](../docker/README.md#production-http-runtime-and-tuning) before overriding
these settings; child count must be sized against measured worker memory and MariaDB connection
capacity.

An unchanged custom Compose file gets the image's eight-second graceful-drain default, which fits
inside Compose's ten-second stop timeout. The bundled Compose files intentionally pair a
30-second internal drain with `stop_grace_period: 35s`. Keep the outer stop grace longer than the
runtime deadline if either value is customized.

CI smoke-tests the exact image digest on native AMD64 and ARM64 after starting it with a disposable
MariaDB schema. The gate covers Nginx/FPM syntax, Unix-socket permissions and TCP isolation,
startup/migrations, HTTP and API behavior, trusted HTTPS proxy handling, client-IP recovery,
secure cookies, static assets, request-size limits, protected paths, build-tool and bootstrap-secret
removal, managed-process crashes, and in-flight graceful shutdown through `SIGQUIT` and explicit
`SIGTERM`.

The runtime remains a candidate until it passes the capacity comparison and a public
reverse-proxy canary; `latest` continues to identify the published v1.0.1 Apache runtime during
that evaluation. A short local harness check can be run with:

```powershell
.\tests\Performance\run.ps1 -Mode smoke
```

The smoke preset verifies the comparison machinery only. Follow
[`tests/Performance/README.md`](../tests/Performance/README.md) for the reproducible steady and
recovery workloads; their results, not the web-server label alone, decide whether the candidate
is eligible for promotion.

## Handy commands

```bash
# add an admin from the CLI (prompts twice without echoing the password)
php artisan rustdesk:user <name> --admin

# non-interactive automation: pipe exactly one password line
printf '%s\n' "$RUSTDESK_USER_PASSWORD" | php artisan rustdesk:user <name> --admin --password-stdin

# regenerate the admin-console screenshots in docs/screenshots/ (seeds fictional demo data)
docker compose -f docker/compose.toolchain.yml --profile screenshots run --rm \
  -e CAPTURE_SCREENSHOTS=1 screenshots bash docker/demo-shots.sh
```

## Database

MariaDB with InnoDB is the only supported database in development, tests, CI, screenshots, and
production. Use `DB_CONNECTION=mariadb`; the required PHP extension and PDO protocol retain the
upstream names `pdo_mysql` and `mysql:`.

The current `.env.example` leaves `DB_HOST` commented so `docker-compose.yml` and
`docker-compose.dev.yml` can select the internal `db` service. An older copied `.env` containing
`DB_HOST=127.0.0.1` must remove that line or change it to `db` before either Compose stack starts;
inside the app container, loopback does not reach the database container. `DB_HOST` and `DB_PORT`
remain the normal interface for an external MariaDB endpoint reachable from the container. A host
override does not remove the bundled `db` service or the app's `depends_on`; a genuinely
external-only development topology needs a custom Compose definition/override that removes or
replaces both.

The ordinary `app` service uses the persistent development database `rustdesk_api`. Never run
PHPUnit from that service. The `--profile test` command starts a separate tmpfs-backed `test-db`
service and a guarded `test` runner that forces the exact database name
`rustdesk_api_testing`. The test bootstrap verifies both the database name and the live MariaDB
server before any refresh migration can run, so an inherited `.env` value cannot erase the
development schema.

The `e2e` profile applies the same separation to browser tests: its `e2e` runner resets only
`rustdesk_api_e2e` on the tmpfs-backed `e2e-db` service, verifies the live MariaDB/InnoDB target,
installs missing locked dependencies, starts the application, and runs the complete Playwright
project matrix.

Screenshot capture is isolated again: the `screenshots` profile uses `screenshot-db`, database
`rustdesk_api_screenshots`, and tmpfs storage. After rejecting any other configured target, its
runner installs missing locked dependencies. It never backs up, restores, or migrates the
persistent development database.

## Docs map

- **[AGENT.md](../AGENT.md)** — source-of-truth guide (architecture, conventions, task lookup)
- **[Wiki/](../Wiki/)** — architecture knowledge base (design system, core docs)
- **[docs/modernization/](modernization/)** — research → plan → status, incl. the
  [client API contract](modernization/02-client-api-contract.md)
- **[docs/screenshots/](screenshots/)** — admin-console gallery
- **[SQLite-to-MariaDB boundary](sqlite-to-mariadb.md)** — manual upgrade requirements for
  installations created with the retired database path
- **[DevOps/logs/agent-changelog.md](../DevOps/logs/agent-changelog.md)** — change log
