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
docker compose -f docker/compose.toolchain.yml run --rm app php artisan migrate --seed
```

## Quality gates (CI runs these on every push)

```bash
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash -lc \
  'npm ci --ignore-scripts --no-audit --no-fund && npm run check:vendor && npm run lint:js && \
   ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && php artisan test'
```

- **Pint** — code style (`./vendor/bin/pint` to auto-fix).
- **PHPStan** — level 5 static analysis (needs `--memory-limit=1G`).
- **PHPUnit** — `php artisan test` (uses SQLite `:memory:`).
- **ESLint** — browser code in `public/assets/js` and Node-based build scripts in `scripts`.
- **Vendor integrity** — `npm run check:vendor` rebuilds the local admin assets in memory and
  verifies their complete inventory and byte content against the checked-in distribution.
- **Playwright** — end-to-end (`npx playwright test`), against a running server.

## Test the runtime image locally

`docker-compose.dev.yml` builds the production image from source instead of pulling it from
GHCR — use it to verify your changes as they'll ship. Because it runs with
`APP_ENV=production`, set a unique `ADMIN_PASS` of at least 12 characters in your shell or local
`.env` file first:

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

## Handy commands

```bash
# add an admin from the CLI
php artisan rustdesk:user <name> <password> --admin

# regenerate the admin-console screenshots in docs/screenshots/ (seeds fictional demo data)
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash docker/demo-shots.sh
```

## Database

Development and production default to **MySQL/MariaDB** (the toolchain stack ships MariaDB).
Tests run on SQLite `:memory:`. SQLite is an optional target for small setups — see the
"Prefer SQLite?" note in `docker-compose.yml` and `.env.example`.

## Docs map

- **[AGENT.md](../AGENT.md)** — source-of-truth guide (architecture, conventions, task lookup)
- **[Wiki/](../Wiki/)** — architecture knowledge base (design system, core docs)
- **[docs/modernization/](modernization/)** — research → plan → status, incl. the
  [client API contract](modernization/02-client-api-contract.md)
- **[docs/screenshots/](screenshots/)** — admin-console gallery
- **[DevOps/logs/agent-changelog.md](../DevOps/logs/agent-changelog.md)** — change log
