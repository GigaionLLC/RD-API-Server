# Quick Start

Self-host RD‑API‑Server (admin console + client API for the RustDesk client) in one command.

## 1. Set first-run secrets and run it

Create a `.env` file next to `docker-compose.yml`. Generate unique values with a password
manager; do not copy the angle-bracket placeholders literally.

```env
ADMIN_PASS=<unique-admin-password-at-least-12-characters>
DB_PASSWORD=<unique-database-password-used-by-the-app-and-MariaDB>
DB_CONNECTION=mariadb
```

```bash
docker compose up -d
```

The bundled `docker-compose.yml` starts the app with **MariaDB/InnoDB**, the only supported
database. Application and database data persist in separate Docker volumes.

The current `.env.example` intentionally leaves `DB_HOST` commented. With no host override,
Laravel uses `127.0.0.1` for direct host-side commands while the bundled root/development Compose
files select their internal service name, `db`. If an older copied `.env` contains
`DB_HOST=127.0.0.1`, remove that line or change it to `DB_HOST=db` before starting Compose;
container loopback is the application container itself. Leave `DB_PORT=3306` for the bundled
database. For an external MariaDB server, set the conventional `DB_HOST` and `DB_PORT` values to
an endpoint reachable from inside the application container. This changes the app's destination
but does not remove the bundled `db` service or the app's dependency on it. For a truly
external-only deployment, maintain a custom Compose definition/override that removes or replaces
both the `db` service and the app's `depends_on` entry.

- **Admin console:** http://localhost:21114/admin
- **Client API base:** http://localhost:21114/api
- **Initial login:** `admin` / the unique `ADMIN_PASS` you supplied above.

Production has no default admin password. On a new database the container stops before seeding
if `ADMIN_PASS` is missing, shorter than 12 characters, known/default, a placeholder, repeated,
or derived from `ADMIN_USER`.

## 2. Point it at your RustDesk servers

Set these once (e.g. in a `.env` file next to `docker-compose.yml`, or in your shell), then
`docker compose up -d`:

```env
RUSTDESK_ID_SERVER=your.server:21116
RUSTDESK_RELAY_SERVER=your.server:21117
RUSTDESK_API_SERVER=http://your.server:21114
RUSTDESK_KEY=<contents of id_ed25519.pub>
PORT=21114
```

In the RustDesk client, set **API Server** to your `RUSTDESK_API_SERVER` and log in.

Upgrades do not alter an existing administrator. If an earlier install used a default password,
reset it in **Users** or run `php artisan rustdesk:user admin --admin` inside the application
container. The command asks for the password twice without echoing it. For non-interactive
automation, pipe one password line to `--password-stdin`; passing a password as a positional
argument is deprecated because shells can expose it in history and process listings.

### Production HTTPS and reverse proxies

When 1Panel/OpenResty, Nginx Proxy Manager, Nginx, or another reverse proxy terminates TLS,
configure the public origin and the inbound trust boundary together:

```env
APP_URL=https://api.example.com
RUSTDESK_API_SERVER=https://api.example.com
TRUSTED_PROXIES=172.23.0.5
SESSION_SECURE_COOKIE=true
```

`TRUSTED_PROXIES` is the proxy address **as seen by the application container**, not the proxy's
public address. Prefer that exact address. If container addresses can change, use only the narrow
CIDR of an isolated network shared exclusively by the application and trusted proxies. Never use
`*`, `/0`, all of `172.16.0.0/12`, or another network reachable by untrusted clients. Prevent
direct access to the application port, too.

Use one of these topologies instead of leaving the application port publicly reachable:

- Put a containerized proxy and the application on a dedicated internal Docker network, proxy to
  the service on port 80, and do not publish the application port. Trust the proxy's exact address
  or that exclusive network's narrow CIDR.
- For a proxy running directly on the same host, bind the application port to loopback, for
  example `127.0.0.1:21114:80`. With this repository's bundled `docker-compose.yml`, set
  `PORT=127.0.0.1:21114`; the Compose definition appends the container's `:80` target.
- For a proxy on another host, firewall the application port so only that proxy can connect.

#### 1Panel/OpenResty example

In 1Panel, open **Websites → your site → Configuration → Basic → Reverse proxy**. A proxy on the
same host can target `http://127.0.0.1:21114`; a proxy on another host can target a private backend
such as `http://api-backend.lan:21114`. Use **View source** on the enabled `/` rule and confirm it
overwrites the public host and scheme and supplies a trustworthy client address:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $remote_addr;
```

For a single public edge, overwriting `X-Forwarded-For` prevents a client-supplied chain from
becoming authoritative. A deployment with a trusted CDN in front of 1Panel must configure that
CDN's real-IP boundary separately. When 1Panel is on another host, bind port 21114 only to the
backend's private interface and add a host-firewall rule that permits the 1Panel source address
and rejects other callers. Compose port binding alone does not filter source addresses.

Do not trust a shared Docker bridge gateway or NAT address merely because it appears first in an
access log when untrusted containers or callers can also reach the backend through it. Move a
containerized application and proxy to an isolated direct network instead. A loopback-only
backend for a native host proxy deliberately makes the host itself the trusted boundary.

RD-API-Server accepts only sanitized `X-Forwarded-For` and `X-Forwarded-Proto` values from an
explicitly trusted peer. The edge must overwrite `Host` and `X-Forwarded-Proto` and construct a
trustworthy `X-Forwarded-For` chain. Forwarded host, port, and path-prefix headers are ignored to
prevent URL poisoning; a nonstandard public HTTPS port must be present in the sanitized `Host`
header. `APP_URL` alone does not make Laravel trust a forwarded HTTPS scheme. Missing or
mismatched trust produces HTTP redirects and asset references, browser mixed-content errors,
inaccurate client IPs, and cookies without request-derived `Secure` protection.

The runtime logs an explicit warning when `APP_URL` uses HTTPS but no valid proxy entry survives
configuration parsing. Pulling a newer image does not add missing deployment environment values;
update the Compose or orchestrator configuration and recreate the API container.

To identify the immediate peer, make one `/admin` request and correlate it with the application
access log. The first address on that exact request line is what the application container sees;
validate that it is proxy-specific before trusting it:

```bash
docker logs --tail 200 rustdesk-api 2>&1 \
  | grep -F '"GET /admin HTTP/' \
  | tail -1

docker inspect rustdesk-api --format '{{json .NetworkSettings.Networks}}'
docker inspect <proxy-container> --format '{{json .NetworkSettings.Networks}}'
docker network inspect <shared-network> \
  --format '{{range .IPAM.Config}}{{println .Subnet}}{{end}}'
```

After setting the exact address or isolated-network CIDR, recreate the application container so
its startup cache contains the new values:

```bash
docker compose up -d --force-recreate <api-service>

docker compose exec <api-service> php artisan config:show app.url
docker compose exec <api-service> php artisan config:show trustedproxy.proxies
docker compose exec <api-service> php artisan config:show session.secure
```

Inspect only these non-secret keys; a broad configuration dump can expose `APP_KEY` and other
credentials. Then run the repository's fail-closed smoke check against the complete public
edge-to-application path:

```bash
scripts/check-https-proxy.sh https://api.example.com
```

The check fails on an absent/wrong HTTPS redirect, any insecure stylesheet or script URL,
missing or non-`Secure` login cookies, a TLS/DNS/request error, or an unreachable theme asset.

## 3. Email (optional)

For 2FA email codes, invitations, and alarm notifications, add SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=no-reply@example.com
```

## 4. Database support and upgrades

MariaDB with InnoDB is required. SQLite, Oracle MySQL, PostgreSQL, and SQL Server are not
supported runtime or test targets. The application deliberately rejects any other configured
driver before it runs migrations.

TCP connections use `DB_HOST` and `DB_PORT`. Advanced runtime deployments may instead set
`DB_SOCKET` to a Unix socket mounted into the application container, set `DB_CONNECT_TIMEOUT` to
an integer from 1 to 10 seconds, or set `MYSQL_ATTR_SSL_CA` to a readable, mounted CA certificate.
The bundled `db` service uses TCP and needs none of those advanced overrides.

`DB_URL` is intentionally rejected, even when it names MariaDB. Before upgrading a URL-only
deployment, expand it into `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`,
verify those discrete values against the old release, and then unset `DB_URL`. Keep credentials in
the deployment's secret store rather than logging the URL while converting it.

If an existing MariaDB deployment has `DB_CONNECTION=mysql` in its `.env` or Compose override,
first back it up and run both read-only checks below while connected to the application database:

```sql
SELECT VERSION() AS server_version,
       @@default_storage_engine AS default_storage_engine;
```

`server_version` must contain `MariaDB` and `default_storage_engine` must be `InnoDB`. Then verify
that every existing base table also uses InnoDB:

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND COALESCE(UPPER(ENGINE), '') <> 'INNODB';
```

No rows from the table query, together with the required server identity and default engine, means
the supported boundary is satisfied. You may then change the value to `DB_CONNECTION=mariadb`;
this selects Laravel's MariaDB connection without moving or rewriting the existing InnoDB data.
If any check fails or the table query returns MyISAM, Aria, or another engine, do not upgrade yet:
keep the backup and have a MariaDB DBA review, convert, and validate the deployment first.

An installation still using SQLite must be moved to MariaDB on the last SQLite-compatible
release before this release is started. There is no automated converter in this project. Back
up the database, storage, and application key first, then follow the
**[manual SQLite-to-MariaDB migration boundary](docs/sqlite-to-mariadb.md)**. Otherwise, remain
on the last compatible release.

## 5. Optional settings

```env
# Cap peers per address book (0 = unlimited). Advertised to clients + enforced server-side.
RUSTDESK_AB_MAX_PEERS=0

# Prometheus metrics at GET /metrics. Empty = endpoint disabled (404).
# When set, scrapers must send `Authorization: Bearer <token>`.
RUSTDESK_METRICS_TOKEN=

# Reject unknown devices until they enroll with a deployment token or are approved.
RUSTDESK_REQUIRE_DEPLOYMENT=true
RUSTDESK_AUTO_REGISTER=false

# Delete audit logs + alarms older than N days (0 = keep forever). Pruned daily by the scheduler.
RUSTDESK_AUDIT_RETENTION_DAYS=0
```

These are the secure defaults. Existing approved devices continue reporting normally, while an
unknown stock client receives `{}` from heartbeat and `ID_NOT_FOUND` from sysinfo until it is
deployed or approved. Legacy first-seen enrollment can be restored only by setting
`RUSTDESK_REQUIRE_DEPLOYMENT=false` and `RUSTDESK_AUTO_REGISTER=true` together. That mode trusts
the first caller for an ID, so use it only on a trusted network; per-IP/global registration rates
and a total-device quota remain enabled and are configurable through `.env.example`.

Session-recording uploads are deliberately off by default because the stock RustDesk uploader
does not send an account token. To accept stock clients, enable the route and allow only the
literal device source addresses or trusted network CIDRs that should upload:

```env
RUSTDESK_RECORDING_UPLOAD_ENABLED=true
RUSTDESK_RECORDING_UPLOAD_ALLOWED_IPS=192.0.2.0/24,2001:db8:1234::/48
```

Never use an all-address CIDR on an Internet-facing API. Behind a reverse proxy, set
`TRUSTED_PROXIES` to that proxy's exact address/CIDR so source binding and rate limits see the
real client address. A trusted proxy or custom client can instead send a random 32+ character
`RUSTDESK_RECORDING_UPLOAD_TOKEN` through `Authorization: Bearer` or
`X-Recording-Token`; a proxy must strip any inbound copy before injecting that header. Default
limits are 8 MiB per chunk, 2 GiB per file, 10 GiB total, 5,000 files, four active uploads per
source, and 600 requests per source/minute; each has a matching setting in `.env.example`.

**Webhooks** (Slack / Telegram / generic) are configured in the console under **Webhooks** — no
env needed. Failed deliveries retry automatically if the scheduler cron is running; add it to
keep retries flowing:

```bash
* * * * * docker compose exec -T rustdesk-api php artisan schedule:run >> /dev/null 2>&1
```

## Common commands

```bash
docker compose logs -f rustdesk-api      # view logs
docker compose exec rustdesk-api php artisan rustdesk:user alice --admin   # securely prompts twice
docker compose down                      # stop (data is kept in the volume)
docker compose pull && docker compose up -d   # update
```

---
Developers: see **[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)** for the build/test/lint workflow,
plus [AGENT.md](AGENT.md) and [docs/modernization/](docs/modernization/).
