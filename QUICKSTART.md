# Quick Start

Self-host RD‑API‑Server (admin console + client API for the RustDesk client) in one command.

## 1. Set first-run secrets and run it

Create a `.env` file next to `docker-compose.yml`. Generate unique values with a password
manager; do not copy the angle-bracket placeholders literally.

```env
ADMIN_PASS=<unique-admin-password-at-least-12-characters>
DB_PASSWORD=<unique-database-password-used-by-the-app-and-MariaDB>
```

```bash
docker compose up -d
```

The bundled `docker-compose.yml` starts the app behind **MariaDB** (the recommended database —
SQLite is single-writer and bottlenecks as devices scale). All data persists in Docker volumes.

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

## 4. Small setups: SQLite instead of MariaDB

MariaDB is the default and recommended for any real fleet. For a small deployment
(roughly **< 50 devices**) or a quick trial you can drop the database container and use SQLite:
set `DB_CONNECTION=sqlite`, then remove the `db` service, the `depends_on:` block, and the
`rustdesk-db` volume from `docker-compose.yml`. Data then lives in the `rustdesk-data` volume.

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
