# CLAUDE.md

> **Main documentation entry point → [AGENT.md](AGENT.md).**
> Read it first. It maps every task to the right doc, states the development rules, and
> defines the mandatory wrap-up/changelog protocol.

## What this repository is

`rustdesk-api` — a self-hosted **API server for the RustDesk remote-desktop client**
(address book, device/user management, login/OIDC/LDAP, audit logs, web admin, web client).

Built in **PHP 8.5 / Laravel 13**, **English** throughout, with a from-scratch **HTML +
jQuery + Bootstrap 5** dark dashboard (no Vue). It began as a port of an earlier Go server,
which has been **retired** now that the PHP app reached and exceeded parity (the repo is now
single-stack PHP). MariaDB with InnoDB is the only supported database.

## Where things are

| You need… | Go to |
|-----------|-------|
| The agent playbook (read first) | [AGENT.md](AGENT.md) |
| Architecture knowledge base | [Wiki/core/00-system-index.md](Wiki/core/00-system-index.md) |
| Design system / theme tokens | [Wiki/core/06-design-system.md](Wiki/core/06-design-system.md) · [DESIGN.md](DESIGN.md) |
| The rebuild plan + deep research | [docs/modernization/README.md](docs/modernization/README.md) |
| The RustDesk client API contract (spec) | [docs/modernization/02-client-api-contract.md](docs/modernization/02-client-api-contract.md) |
| Operational process (plans, backlog, logs) | [DevOps/](DevOps/) |

## Build & test (Docker toolchain — host has no Composer/Node)

```bash
# one-time: build the toolchain image (PHP 8.5 + Composer + Node + Playwright + linters)
docker build -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .

# dev stack (app + MariaDB + Mailpit)
docker compose -f docker/compose.toolchain.yml up -d
docker compose -f docker/compose.toolchain.yml run --rm app composer install
docker compose -f docker/compose.toolchain.yml run --rm app php artisan migrate

# non-database gates (run inside the toolchain image)
docker compose -f docker/compose.toolchain.yml run --rm app bash -lc \
  './vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G'

# PHPUnit uses a guarded, tmpfs-backed MariaDB schema; never run it against the dev database
docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test

# Playwright uses its own guarded, tmpfs-backed MariaDB application and installs missing locked deps
docker compose -f docker/compose.toolchain.yml --profile e2e run --rm e2e bash docker/e2e.sh
```

## Non-negotiables
- **No Vue / SPA frameworks.** Admin UI = Blade + jQuery + Bootstrap 5 + original CSS.
- **English everywhere**, including identifiers, comments, and docs.
- **Wire-compatibility:** never rename the JSON keys / API paths the RustDesk client speaks
  (see the contract doc). English renames apply to PHP identifiers, not the wire protocol.
- **MariaDB/InnoDB only:** use the dedicated Compose profiles for tests and screenshots. Existing
  SQLite installations must migrate before upgrading; see [docs/sqlite-to-mariadb.md](docs/sqlite-to-mariadb.md).
- Follow the **wrap-up protocol** in [AGENT.md](AGENT.md): log every change to
  [DevOps/logs/agent-changelog.md](DevOps/logs/agent-changelog.md).
