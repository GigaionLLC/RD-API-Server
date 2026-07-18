# Build dependency pins

The build and Compose definitions pin third-party images to an exact release tag and the
corresponding multi-architecture manifest digest. A tag documents the intended version; the
digest prevents a registry tag from silently resolving to different bytes later.

## Current pins

| Input | Version | Multi-architecture digest |
|---|---:|---|
| PHP CLI | 8.5.8 Bookworm | `sha256:fb740987f3e7aefd7f52d1f961fa91602874f2b6a5b0bf0105725f8987b54bee` |
| PHP FPM | 8.5.8 Bookworm | `sha256:83c155135b9c4aa664fc6ce47020a10fe53576a0ed3468119cf2efec22fd16b9` |
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

## Runtime layer and dependency cache design

`docker/Dockerfile.runtime` derives both dependency assembly and the final image from the same
digest-pinned PHP-FPM stage. Native PHP extensions are compiled once per target architecture;
the build does not copy modules between different PHP base variants. Composer is copied only into
the dependency stage. The extension installer, C/C++ compiler drivers, `make`, and
`linux-libc-dev` kernel headers are removed after the shared runtime layer is built. None is
present in the final image. The final runtime branch installs Bookworm's security-maintained
`nginx-light` and `tini` packages independently of Composer assembly, so BuildKit can execute
those branches in parallel.

The Dockerfile copies `composer.json` and `composer.lock` before application source. It installs
the exact production dependency graph without scripts or an autoloader, then copies the source and
generates the optimized, script-free autoloader. As a result, ordinary PHP, Blade, JavaScript, or
documentation changes do not redownload dependencies. A build-time platform check still verifies
the locked production packages against the exact final PHP extension set.

One Docker Desktop implementation run rebuilt the invalidated extension layer, removed its
compiler drivers, `make`, and kernel headers, and assembled the image in 74.3 seconds. A warm
application/source rebuild measured 5.9 seconds, while a fully unchanged verification build
measured 0.84 seconds. These timings demonstrate that the cache boundaries work; they are not a
CI duration guarantee or runtime-capacity evidence.

## Production HTTP runtime and tuning

The Nginx/PHP-FPM candidate is a drop-in container replacement: it still listens for HTTP on
container port `80`, serves the same Laravel routes, consumes the existing environment variables,
and uses the same `/var/www/html/storage` persistence boundary. No Compose port, reverse-proxy
target, database, storage mount, or application-key migration is required by the web-server
change.

Nginx and PHP-FPM run in the same container, supervised as peer processes by `tini`. FastCGI uses
the permission-restricted Unix socket `/run/php/rustdesk-api.sock`; PHP-FPM does not listen on TCP
port `9000`, and Compose does not publish it. The upstream FPM base may retain OCI `EXPOSE 9000`
metadata, so validate the effective FPM configuration and live listener table instead of treating
that inert image metadata as network reachability.

Request buffering remains enabled so slow uploads do not occupy a PHP worker. The generated Nginx
and PHP body ceiling is the larger of 5 MiB or
`RUSTDESK_RECORDING_UPLOAD_MAX_CHUNK_BYTES + 1 MiB`; this keeps the configured recording chunk and
the supported 4 MiB CSV import below the web-server boundary. An explicit
`NGINX_CLIENT_MAX_BODY_BYTES` may raise that ceiling but cannot lower it below the derived value.
FastCGI response buffering is disabled so streamed CSV exports and recording downloads are not
spooled into Nginx temporary files.

The runtime validates optional tuning and generated server configuration before it creates
application state or applies database migrations. It rejects malformed, out-of-range, or
internally inconsistent values:

| Setting | Image default | Valid boundary / purpose |
|---|---:|---|
| `RUNTIME_SHUTDOWN_GRACE_SECONDS` | `8` | Integer `1`-`300`; internal drain deadline |
| `NGINX_ACCESS_LOG_ENABLED` | `true` | Boolean; controls the one request log on stdout |
| `NGINX_WORKER_PROCESSES` | Cgroup-aware | Integer `1`-`1024`; defaults to the tighter available-CPU or container-quota count |
| `NGINX_WORKER_CONNECTIONS` | `4096` | Integer `256`-`65535` per Nginx worker |
| `NGINX_CLIENT_MAX_BODY_BYTES` | Derived | Derived ceiling through `4294967296` bytes |
| `PHP_FPM_MAX_CHILDREN` | `16` | Integer `1`-`512`; hard concurrent PHP-request limit |
| `PHP_FPM_START_SERVERS` | Up to `4` | Integer `1`-`PHP_FPM_MAX_CHILDREN` |
| `PHP_FPM_MIN_SPARE_SERVERS` | Up to `2` | Integer `1`-`PHP_FPM_START_SERVERS` |
| `PHP_FPM_MAX_SPARE_SERVERS` | Up to `6` | At least `PHP_FPM_START_SERVERS`, no more than the child limit |
| `PHP_FPM_MAX_REQUESTS` | `500` | Integer `1`-`100000`; recycles workers after bounded use |
| `PHP_FPM_SLOWLOG_TIMEOUT_SECONDS` | `5` | Integer `1`-`300`; slow-request diagnostics on stderr |

PHP-FPM access logging is deliberately disabled because Nginx owns the single toggleable request
log. High-heartbeat deployments can set `NGINX_ACCESS_LOG_ENABLED=false` after confirming that
their operational audit requirements are met; application audit records remain separate. Size
`PHP_FPM_MAX_CHILDREN` from measured worker memory and MariaDB connection capacity, leaving memory,
database, and scheduler headroom. Raising it blindly can move queuing or failure into MariaDB.

The image defaults to an 8-second drain so unchanged external Compose files retain margin inside
Compose's historical 10-second stop timeout. The bundled production and development Compose files
opt into `RUNTIME_SHUTDOWN_GRACE_SECONDS=30` and `stop_grace_period: 35s`. When overriding these,
keep the Compose grace period longer than the internal runtime deadline. The supervisor first
stops Nginx from accepting new work, drains it, then gracefully stops PHP-FPM; both the declared
`SIGQUIT` stop path and an explicit `SIGTERM` use that sequence.

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

Runtime publication is part of the same `CI` dependency graph as PHP, JavaScript, vendor-integrity,
and browser gates. Pull requests receive read-only quality checks and cannot reach package-write
jobs. A trusted main push publishes only a full-commit `sha-<40-hex>` discovery tag after every
quality job passes. Registry tags remain movable, so deployments and benchmark evidence must pair
that tag with the recorded content digest. Only a stable annotated `vMAJOR.MINOR.PATCH` tag that
points directly to the current main commit may move `latest` and the SemVer aliases. The final
publication job rechecks both refs immediately before publication so a superseded run cannot roll
the image channel backward.

AMD64 runs on `ubuntu-24.04` and ARM64 runs on the native `ubuntu-24.04-arm` runner. Each job
builds one architecture, pushes an untagged canonical digest, pulls and smoke-tests that digest on
matching hardware, and uploads only the validated digest. The final job requires exactly two
digest artifacts before creating the multi-architecture manifest and verifies both runtime
platforms, provenance attestations, and every generated tag. QEMU is not installed or used.

The native digest smoke gate starts the real image with disposable MariaDB, then checks Nginx and
FPM syntax, Unix-socket ownership, absence of a TCP FastCGI listener, migrations, health/version,
static MIME types, API authentication and heartbeat behavior, trusted-proxy HTTPS URLs and client
IP recovery, secure cookies, body-size boundaries, hidden server versions, denied dotfiles and
non-front-controller PHP paths, removal of build tools and `ADMIN_PASS`, peer-process failure, and
graceful in-flight completion through both `SIGQUIT` and explicit `SIGTERM`. This gate verifies
runtime parity and security on each native architecture; it does not replace the separate
fleet-capacity and public reverse-proxy canary gates.

BuildKit caches are isolated by architecture. GitHub's cache accelerates repeat jobs, while the
`buildcache-amd64` and `buildcache-arm64` registry references provide a durable read fallback.
Only main writes the registry fallback, avoiding tag-build races; release builds consume it and
write their own short-lived GitHub cache. These cache references are build inputs, not release
channels. Final images are still assembled from newly built, smoke-tested content-addressed
digests.

Documentation-only main pushes are excluded from CI image publication, but path filtering is not
applied to pull requests and GitHub does not apply it to tag pushes. Manual runs on main execute
the same quality and publication graph. Failed or cancelled architecture jobs leave only untagged
digests and never move `latest` or a version tag.

## First-party application image

The published `ghcr.io/gigaionllc/rustdesk-api-server:latest` reference is intentionally the
project's stable update channel. While the Nginx/PHP-FPM candidate is under capacity and canary
validation, `latest` remains the Apache-based v1.0.1 image at manifest digest
`sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2`.
Main-branch candidate builds use full-commit SHA discovery tags plus content digests and do not
move stable channels.
Operators who require a fully locked deployment can set
`RUSTDESK_API_IMAGE` to a published tag and digest before running Compose, for example:

```bash
RUSTDESK_API_IMAGE='ghcr.io/gigaionllc/rustdesk-api-server:sha-<40-hex-commit>@sha256:<64-hex-digest>' \
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
After bootstrap the entrypoint removes `ADMIN_PASS` before PHP-FPM inherits the application
environment; the stored administrator password remains a one-way hash in the database.

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
