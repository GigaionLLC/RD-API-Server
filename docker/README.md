# Build dependency pins

The build and Compose definitions pin third-party images to an exact release tag and the
corresponding multi-architecture manifest digest. A tag documents the intended version; the
digest prevents a registry tag from silently resolving to different bytes later.

## Current pins

| Input | Version | Multi-architecture digest |
|---|---:|---|
| PHP CLI | 8.5.8 Bookworm | `sha256:fb740987f3e7aefd7f52d1f961fa91602874f2b6a5b0bf0105725f8987b54bee` |
| PHP Apache | 8.5.8 Bookworm | `sha256:76f447018df51801eb0587bdced331709c2d7ac4e0bb8b9cb00bd4f93dd85d1c` |
| Node.js | 24.18.0 Bookworm slim | `sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d` |
| Composer | 2.10.2 | `sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760` |
| PHP extension installer | 2.11.12 | `sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138` |
| MariaDB | 11.8.8 | `sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4` |
| Mailpit | 1.30.4 | `sha256:5a49a77c5bdbe7c5474450b4f46348d09949df3695257729c93a30369382d4f6` |
| RustDesk server (full-stack example) | 1.1.15 | `sha256:10818ec05b179039c6660f4d8e74b303f0db2858bbad2b18e24992ea22d54cd6` |

The toolchain installs `playwright@1.61.0` explicitly. This must match the resolved
`node_modules/playwright` version in `package-lock.json`, because each Playwright release expects
a specific browser revision. Node 24 is used because the [official Node.js release
schedule](https://github.com/nodejs/Release) marks Node 20 as end-of-life and Node 24 as an
active LTS release.

The Debian packages installed with `apt` intentionally follow the signed Bookworm repositories
instead of freezing individual package revisions that those repositories may retire. The image
digests, application dependency locks, and exact standalone tool versions remain fixed; rebuilds
can still receive Debian security updates. Never replace the pinned official Node image with a
downloaded repository setup script or execute a remote response through a shell.

## Supported database

RD-API-Server supports **MariaDB with InnoDB only**. Runtime startup, PHPUnit, browser tests,
CI, and screenshot capture reject other database drivers before migrations run. Use
`DB_CONNECTION=mariadb`. Names inherited from the MariaDB ecosystem remain intentional:
`pdo_mysql` is the required PHP extension, PDO uses a `mysql:` DSN, and the official MariaDB
image stores data under `/var/lib/mysql`.

The current `.env.example` comments out `DB_HOST`. This lets Laravel default direct host-side
commands to `127.0.0.1`, while the bundled root and development Compose files default their
application containers to the internal `db` service. Existing copied `.env` files that still set
`DB_HOST=127.0.0.1` must remove that line or change it to `db` before using bundled Compose.
For an external MariaDB deployment, `DB_HOST` and `DB_PORT` remain the normal overrides and must
name an endpoint reachable from the application container. The root/development Compose files
still define the bundled `db` service and make the app depend on it, even when its host is
overridden. An external-only topology requires a custom Compose definition/override that removes
or replaces both that service and the app's `depends_on` entry.

Runtime connections also accept `DB_SOCKET` for a Unix socket mounted into the container,
`DB_CONNECT_TIMEOUT` for an integer connection timeout from 1 to 10 seconds, and
`MYSQL_ATTR_SSL_CA` for a readable, mounted CA certificate. Leave these empty/defaulted for the
bundled TCP-connected `db` service. Runtime readiness and InnoDB checks use the same transport,
timeout, and CA settings as Laravel.

`DB_URL` is intentionally unsupported and rejected before migrations. Operators upgrading a
URL-only MariaDB configuration must expand it into discrete `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, and `DB_PASSWORD` values, validate them on the old release, and unset `DB_URL`
before starting this release.

The project Dockerfiles install no SQLite package, connection, fallback, test target, or data
path, and runtime startup rejects the SQLite driver before migrations. The pinned official PHP
base images may nevertheless expose inert `pdo_sqlite`/`sqlite3` modules compiled by upstream;
their appearance in `php -m` does not make SQLite a supported or exercised application path.

This is a breaking boundary. An existing MariaDB deployment with an explicit
`DB_CONNECTION=mysql` override must be backed up and pass the read-only
[InnoDB engine audit](../Wiki/database/database-index.md#upgrade-boundary) before that value is
renamed to `mariadb`. The setting change does not move compliant InnoDB data; MyISAM, Aria, or
other tables require DBA-reviewed conversion first. An existing SQLite deployment must be
converted while it is still running the last compatible release, before the MariaDB-only image
is started. This repository does not ship an automated converter. Back up its database, storage,
and application key together, then follow the
[manual SQLite-to-MariaDB migration boundary](../docs/sqlite-to-mariadb.md), or remain on the last
compatible release.

## GitHub Actions

Every third-party `uses:` entry under `.github/workflows/` is pinned to a full commit SHA, with
its intended major version retained as an inline comment. When updating an action, resolve the
major tag from the action's official upstream Git repository, use the peeled commit for an
annotated tag, review that commit's release notes and diff, and update every matching workflow
reference together. Do not replace a full SHA with a movable tag.

## First-party application image

The published `ghcr.io/gigaionllc/rustdesk-api-server:latest` reference is intentionally the
project's update channel, so it cannot be pinned in this repository without pointing new releases
back at an older build. Operators who require a fully locked deployment can set
`RUSTDESK_API_IMAGE` to a published tag and digest before running Compose, for example:

```bash
RUSTDESK_API_IMAGE='ghcr.io/gigaionllc/rustdesk-api-server:sha-abcdef@sha256:<64-hex-digest>' \
  docker compose up -d
```

## Production bootstrap credentials

The production and production-like Compose files deliberately have no `ADMIN_PASS` fallback.
Set a unique password of at least 12 characters in the shell or a local `.env` file before the
first `up`. The runtime seeder rejects a missing, short, known/default, placeholder, repeated, or
username-derived value before creating the full administrator. A failed seed is not marked as
installed, so correcting `ADMIN_PASS` and restarting safely retries the bootstrap. Once the
administrator exists, `ADMIN_PASS` may be removed from the deployment environment.

The toolchain stack uses `APP_ENV=local` and keeps the predictable development seed credential
needed by tests and screenshot fixtures. That fallback is never accepted by a production seed.
After bootstrap the entrypoint removes `ADMIN_PASS` from the Apache process environment; the
stored administrator password remains a one-way hash in the database.

## Application encryption key

The runtime entrypoint uses an explicit `APP_KEY` when one is supplied. Otherwise it reads
`storage/app/.appkey`, generating that file only on the first start. The root Compose files keep
`storage/` persistent, so the generated key normally survives container replacement.

Treat the application database and encryption key as one recoverable unit. Authenticator secrets
are encrypted with this key, and recovery-code digests are keyed by it. Back up the database
together with either `storage/app/.appkey` or the secret-store value used for `APP_KEY`; restoring
the database with a missing or different key makes existing authenticator secrets unreadable and
existing recovery codes unverifiable. Never copy `.appkey` into source control, logs, tickets, or
an image layer. In a multi-replica deployment, provide the same explicit `APP_KEY` to every API
replica instead of allowing each replica to generate its own key.

For a controlled rotation, take a database-and-key backup, set the new key as `APP_KEY`, and put
the old key in the comma-separated `APP_PREVIOUS_KEYS` value on every replica before restarting
them. Laravel can then decrypt values written under either key while new encrypted values use the
new key; recovery-code verification also checks the previous keys. Keep the old key available
until all authenticator enrollments and recovery-code sets created under it have been replaced
and any old encrypted sessions have expired. Recovery-code digests are one-way and cannot be
bulk-rekeyed. If an explicit `APP_KEY` overrides a generated `.appkey`, do not later remove the
environment value and silently fall back to the older file.

The release that introduces encrypted authenticator secrets requires a maintenance deployment:
stop or quiesce every old API replica, back up the database and key, run the migration with one
upgraded instance, and then start only upgraded replicas. Do not use a mixed-version rolling
deployment for this migration. An old replica can write plaintext after the migration while an
upgraded replica expects encrypted values.

Use the same quiesced deployment for the follow-on TOTP state normalization and email-verification
CHECK migrations. TOTP normalization validates every encrypted seed before writing and repairs
rows transactionally. The email-verification migration performs a read-only preflight and aborts
with affected user IDs if an exact `email` policy lacks a nonblank address; add an address or
explicitly change that policy, then retry. It never silently downgrades a configured second factor.
MariaDB cannot atomically combine either preflight/repair boundary with its later `ALTER TABLE`; a
legacy writer in between can make constraint installation fail. Confirm that the single upgraded
instance completed every migration before restoring traffic or starting another replica.

## Updating a pin

1. Select a supported, stable release from the upstream project's official release page. Update
   the readable version tag first; never retain a digest copied from a different tag.
2. Resolve the tag's manifest-list digest (not a platform-specific child manifest):

   ```bash
   docker buildx imagetools inspect node:24.18.0-bookworm-slim
   ```

   Confirm that the output includes both `linux/amd64` and `linux/arm64`, then copy the top-level
   `Digest` value into every matching Docker or Compose reference.
3. Search for stale broad or mutable references:

   ```bash
   rg 'FROM .*:(latest|[0-9]+($|-))|image: .*:(latest|[0-9]+$)|playwright@latest|curl .*[|] *bash' \
     --glob 'Dockerfile*' --glob 'docker-compose*.yml' --glob 'docker/**' --glob 'examples/**' .
   ```

   The only expected non-third-party `latest` reference is this project's own GHCR release
   channel described above.
4. If Playwright changes, update `package-lock.json` and the exact toolchain version together.
   Check the lock value with:

   ```bash
   docker run --rm -v "$PWD":/app -w /app \
     node:24.18.0-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d \
     node -p "require('./package-lock.json').packages['node_modules/playwright'].version"
   ```
5. Rebuild and verify from clean inputs:

   ```bash
   docker build --pull --no-cache -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .
   docker run --rm rustdesk-api-php-toolchain bash -lc \
     'php -v && composer --version && node --version && npm --version && playwright --version && playwright install --dry-run chromium'
   docker build --pull --no-cache -f docker/Dockerfile.runtime -t rustdesk-api:pin-test .
   docker compose config --quiet
   docker compose -f docker-compose.dev.yml config --quiet
   docker compose -f docker/compose.toolchain.yml config --quiet
   docker compose -f docker/compose.toolchain.yml --profile test config --quiet
   docker compose -f docker/compose.toolchain.yml --profile e2e config --quiet
   docker compose -f docker/compose.toolchain.yml --profile screenshots config --quiet
   docker compose -f examples/full-stack.docker-compose.yml config --quiet
   ```

For a release, also validate the runtime image for both published architectures with Buildx before
updating the pin table.
