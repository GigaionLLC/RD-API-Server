# Quick Start

Self-host RD‑API‑Server (admin console + client API for the RustDesk client) in one command.

## 1. Run it

```bash
docker compose up -d
```

The bundled `docker-compose.yml` starts the app behind **MariaDB** (the recommended database —
SQLite is single-writer and bottlenecks as devices scale). All data persists in Docker volumes.

- **Admin console:** http://localhost:21114/admin
- **Client API base:** http://localhost:21114/api
- **Default login:** `admin` / `admin` — change it immediately (or set `ADMIN_PASS` before the
  first `up`, see below).

## 2. Point it at your RustDesk servers

Set these once (e.g. in a `.env` file next to `docker-compose.yml`, or in your shell), then
`docker compose up -d`:

```env
ADMIN_PASS=choose-a-strong-password
DB_PASSWORD=choose-a-strong-db-password     # used by both the app and the bundled MariaDB
RUSTDESK_ID_SERVER=your.server:21116
RUSTDESK_RELAY_SERVER=your.server:21117
RUSTDESK_API_SERVER=http://your.server:21114
RUSTDESK_KEY=<contents of id_ed25519.pub>
PORT=21114
```

In the RustDesk client, set **API Server** to your `RUSTDESK_API_SERVER` and log in.

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

# Reject unknown devices at /api/sysinfo until deployed/approved.
RUSTDESK_REQUIRE_DEPLOYMENT=false

# Delete audit logs + alarms older than N days (0 = keep forever). Pruned daily by the scheduler.
RUSTDESK_AUDIT_RETENTION_DAYS=0
```

**Webhooks** (Slack / Telegram / generic) are configured in the console under **Webhooks** — no
env needed. Failed deliveries retry automatically if the scheduler cron is running; add it to
keep retries flowing:

```bash
* * * * * docker compose exec -T rustdesk-api php artisan schedule:run >> /dev/null 2>&1
```

## Common commands

```bash
docker compose logs -f rustdesk-api      # view logs
docker compose exec rustdesk-api php artisan rustdesk:user alice secret --admin   # add an admin
docker compose down                      # stop (data is kept in the volume)
docker compose pull && docker compose up -d   # update
```

---
Developers: see **[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)** for the build/test/lint workflow,
plus [AGENT.md](AGENT.md) and [docs/modernization/](docs/modernization/).
