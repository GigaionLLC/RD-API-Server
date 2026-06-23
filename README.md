# RustDesk API

A modern, self‑hosted **API server and admin console for the [RustDesk](https://rustdesk.com)
remote‑desktop client** — built in **PHP 8.5 / Laravel** with a clean **HTML + jQuery +
Bootstrap 5** dark dashboard. One command to run, SQLite by default, no external services
required.

> 🚧 **Beta — under heavy testing.** This is a young project and many features are still being
> tested and refined, so expect rough edges and occasional breaking changes. If you need
> something production‑ready today, we recommend the established, well‑tested RustDesk API
> servers **`lejianwen/rustdesk-api`** and **`lantongxue/rustdesk-api-server-pro`**.

> Implements the RustDesk client API contract and adds the features the client supports that
> most open‑source API servers don't — including **Strategy (Security‑Settings) push** and
> **device preset auto‑registration**.

## ✨ Features

**Client API (what the RustDesk client talks to)**
- Account login with **2FA** (TOTP + email codes), **OIDC/OAuth** (GitHub, Google, generic
  OIDC) and **LDAP/AD**
- **Heartbeat Strategy push** — remotely manage client security settings (the Pro
  "Settings sync"), with `modified_at` change‑detection and live force‑disconnect
- **Sysinfo + preset auto‑registration** — devices auto‑file into a strategy / device group /
  address book on first contact (`--assign` → `/api/devices/cli`, custom‑client presets)
- Personal **address books** (legacy + Flutter granular transports), tags, end‑of‑connection notes
- **Audit ingestion** (connections, file transfers, security alarms) and **session‑recording upload**
- **Device deployment** tokens + approval queue (`/api/devices/deploy`)
- Group endpoints (`/api/users`, `/api/peers`, `/api/device-group/accessible`)

**Admin console (dark dashboard)**
- Dashboard with live stats & charts; brute‑force‑throttled login with optional **TOTP 2FA**
- **Devices** — searchable list with **bulk‑assign** owner / device group / strategy, live‑search pickers
- **Strategies** editor laid out like the RustDesk client's **Settings** (General / Security / Network),
  ~55 catalogued `config_options` with tri‑state toggles + "set all", plus custom keys and
  device/user/group assignment
- **Address Books** — RustDesk‑client‑style manager (peer cards, tags, add/edit dialogs) for **any** user's book,
  with **shared / team books** (collaborators at read · read‑write · full‑control)
- **Webhooks / notifications** — push alarms and connection / device events to **Slack**, **Telegram**,
  or a generic **HMAC‑signed** JSON endpoint, with per‑hook test + delivery status
- **Client Config generator** — produce a server‑config string, mobile **QR**, per‑OS `--config`
  command, and renamed‑installer filename to roll out pre‑configured clients
- **API Keys** — scoped keys for the **admin REST API** (`/api/v1`)
- **Device groups** (with a designated default for new devices), Users, User Groups
- **Access control** (cumulative user‑group + device‑group grants) and **Admin Roles**
  (scoped, delegated console permissions; `is_admin` = full access)
- **Live Sessions** + force‑disconnect, **Alarms**, **Recordings**, **Deploy Tokens**, **Pending Devices**
- **OAuth providers**, **LDAP/AD**, **SMTP** settings; connection / file / login / console‑operation audit logs
- Email subsystem with DB‑managed templates + send logs

**Admin REST API (`/api/v1`)** — programmatic access with scoped API keys
(`Authorization: Bearer <key>` or `X-API-Key`): devices, users, strategies, audit, address‑book
peers. Ships an **OpenAPI 3.1 spec** plus **Postman** and **Bruno** collections. See
**[docs/api/README.md](docs/api/README.md)**.

## 🚀 Quick start

```bash
docker compose up -d
```

Then open **http://localhost:21114/admin** (default `admin` / `admin` — change it). The
client API base is `http://localhost:21114/api`. That's the whole setup — `docker-compose.yml`
pulls the published image (`ghcr.io/gigaionllc/rustdesk-api-server:latest`), SQLite by default,
all data in a Docker volume. See **[QUICKSTART.md](QUICKSTART.md)** for configuration (your
ID/relay/key, SMTP, MySQL for larger fleets).

In the RustDesk client, set **API Server** to your server's URL and log in.

**Compose files:** `docker-compose.yml` (pull the published image) · `docker-compose.dev.yml`
(build locally from source) · **[examples/full-stack.docker-compose.yml](examples/full-stack.docker-compose.yml)**
(full hbbs + hbbr + db + api stack) · `docker/compose.toolchain.yml` (dev toolchain for
composer/artisan/tests).

## 🧱 Stack

PHP 8.5 · Laravel 13 · Blade + jQuery + Bootstrap 5 (no SPA framework) · Eloquent ·
SQLite/MySQL · Apache (runtime image) · Mailpit (dev SMTP) · Playwright (E2E).

## 🛠️ Development

The host doesn't need PHP/Composer/Node — everything runs in a Docker toolchain image.

```bash
# build the dev/test toolchain (PHP 8.5 + Composer + Node + Playwright + linters)
docker build -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .

# dev stack (app + MariaDB + Mailpit)
docker compose -f docker/compose.toolchain.yml up -d
docker compose -f docker/compose.toolchain.yml run --rm app composer install
docker compose -f docker/compose.toolchain.yml run --rm app php artisan migrate --seed

# quality gates (CI runs these on every push)
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash -lc \
  './vendor/bin/pint --test && ./vendor/bin/phpstan analyse && php artisan test && npx eslint public/assets/js'
```

Add an admin from the CLI: `php artisan rustdesk:user <name> <password> --admin`.

## 📚 Documentation

- **[QUICKSTART.md](QUICKSTART.md)** — deployment & configuration
- **[AGENT.md](AGENT.md)** — the project's source‑of‑truth guide (architecture, conventions,
  task lookup); `CLAUDE.md` points here
- **[Wiki/](Wiki/)** — architecture knowledge base (design system, core docs)
- **[docs/modernization/](docs/modernization/)** — the research → plan → status that drove
  this build, including the [client API contract](docs/modernization/02-client-api-contract.md)

## 🔌 Compatibility

Built to the RustDesk client API contract. Pairs with a RustDesk rendezvous/relay
(`hbbs`/`hbbr`) — point clients at this server's API and your `hbbs`/`hbbr` for signaling and
relay. Set `RUSTDESK_ID_SERVER`, `RUSTDESK_RELAY_SERVER`, and `RUSTDESK_KEY` (see QUICKSTART).

## 📄 License

MIT. See [LICENSE](LICENSE). © Gigaion LLC.

## 🤖 AI‑enhanced project

RustDesk API is developed, modernized, and maintained with extensive use of AI coding agents.
The PHP 8.5 / Laravel rewrite, the wire‑compatible client API, the Strategy/Settings‑push and
preset auto‑registration features, the security hardening, the test suites (PHPUnit +
Playwright), and the documentation were all produced and verified with AI assistance. Agent and
contributor guidance follows the **[AGENT.md](AGENT.md)** standard.

## Acknowledgements

This project drew inspiration from the RustDesk client and ecosystem and from existing
open‑source RustDesk API panels (lejianwen/rustdesk-api and lantongxue/rustdesk-api-server-pro)
— thanks for the ideas and groundwork.
