# 07 · Master Plan — PHP/Laravel Rewrite (auto‑approved)

This is the approved execution plan for converting `rustdesk-api` into a modern,
**pure‑PHP** project with an **HTML/jQuery/CSS** admin (modern dark dashboard), **all in
English**, implementing the best features from the reference repos. Every aspect is
redesigned from scratch for a modern, intuitive, feature‑rich result.

Decisions (locked by the user 2026‑06‑18):
- **Backend → PHP** (full rewrite, replacing Go).
- **Frontend → HTML + jQuery + CSS** (Blade server‑rendered, **no Vue**), modern dark dashboard.
- **Language → English everywhere**, including identifiers.
- **Build/verify → Docker** (toolchain image with Composer, Node, **Playwright**, linters).

## 1. Target architecture

```
rustdesk-api/                     (repo root becomes the Laravel app at parity)
├─ app/
│  ├─ Http/Controllers/Api/       client-facing /api/* (RustDesk contract — doc 02)
│  ├─ Http/Controllers/Admin/     admin console controllers
│  ├─ Http/Middleware/            RustAuth (bearer), AdminAuth, permissions
│  ├─ Models/                     Eloquent models (User, Peer/Device, AddressBook, …)
│  ├─ Services/                   business logic (Mail, TwoFactor, Strategy, AddressBook…)
│  └─ Console/Commands/           CLI: user:create, admin:reset-password, scheduler jobs
├─ resources/views/
│  ├─ admin/                      Blade pages (dark dashboard): dashboard, devices, users…
│  └─ layouts/                    base layout, sidebar, navbar
├─ public/assets/                 css/, js/ (jQuery, ApexCharts), icons, theme
├─ routes/  api.php  web.php  console.php
├─ database/migrations/           schema (mirrors + modernizes the Go model)
├─ config/  rustdesk.php          id/relay/api server, key, presets
├─ tests/   Feature/ Unit/        PHPUnit
├─ e2e/                           Playwright specs (login, devices, users, audit, strategy)
└─ docker/  Dockerfile  Dockerfile.toolchain  compose.toolchain.yml
```

**Stack:** Laravel 13 (PHP 8.5) · Eloquent + migrations · Blade + jQuery 3 + Bootstrap 5 ·
ApexCharts (charts) · Remix/Tabler icons (open‑source icon set) · Laravel Mail (SMTP) ·
`pragmarx/google2fa` or `spomky-labs/otphp` (TOTP) · Sanctum‑style bearer tokens for the
client API. Dev DB MariaDB (compose) and SQLite (lightweight local), both via migrations.

**Why Laravel:** routing, Eloquent, migrations, validation, Mail, queue/scheduler (the
device‑offline job), Blade, and auth are batteries‑included — maximum leverage to reach
RustDesk parity, and well‑known to contributors and tooling.

## 2. Theme (modern dark dashboard)

A modern dark admin UI built as **original CSS on Bootstrap 5** — no third‑party template
assets are used or copied. The look: dark sidebar, rounded surface cards, ApexCharts, clean
forms, inline "live‑save" controls, an open‑source icon set, and stat tiles. Deliverables: a
`theme-dark.css`, a base layout, reusable Blade partials (stat‑card, chart‑card, data‑table,
form‑row with inline save), and a small `app.js` (jQuery) for AJAX save + toasts.

## 3. Build / test environment (Docker)

- `docker/Dockerfile.toolchain` — PHP 8.5 + ext (pdo_mysql, pdo_sqlite, intl, gmp, bcmath,
  zip, gd, sockets, opcache) + Composer + Node 24 LTS + Playwright (Chromium) + mysql/sqlite
  clients. Used for composer/artisan/phpunit/pint/phpstan/eslint and E2E.
- `docker/compose.toolchain.yml` — `app` (toolchain) + `db` (MariaDB 11) + `mail` (Mailpit, SMTP
  on 1025 / UI on 8025 to verify the mail subsystem). Kept separate from the legacy Go
  `docker-compose.yaml`.
- `docker/Dockerfile` (later) — slim multi‑stage **runtime** image (php‑fpm/nginx or
  FrankenPHP) serving the API + admin from a single container.

**Quality gates (run in the toolchain image):**
- `composer pint` (Laravel Pint) — PHP style.
- `composer phpstan` (Larastan) — static analysis.
- `php artisan test` (PHPUnit) — unit/feature.
- `npx eslint public/assets/js` — JS lint.
- `npx playwright test` — full‑stack E2E against the dev stack.

## 4. Feature scope (from the gap analysis + reference repos)

Port everything this repo already does **and** add what doc 04 flags. Priority order:
1. **Parity:** client `/api/*` contract (doc 02) — heartbeat, sysinfo, login, OIDC,
   address book, audit ingest; admin CRUD for users/devices/address‑books/tags/groups.
2. **Borrowed wins** (reference: `lantongxue/rustdesk-api-server-pro`, doc 06):
   **Mail/SMTP** (DB templates + send logs), **2FA (TOTP) + email verification**,
   **AuthToken rotation + session management**, **version‑capability gating**.
3. **Differentiators** (neither OSS server has these): **Strategy settings‑push** (doc 02
   §1, doc 05 §1.1) and **preset auto‑registration** (`OPTION_PRESET_*`).
4. **Auth breadth** (keep from Go repo): OIDC/OAuth providers, **LDAP/AD**, captcha, IP ban.
5. **Platform depth:** access control, roles, scoped API tokens, alarms, recording upload.

## 5. Workflow — parallel agents

Work is partitioned by directory so parallel agents don't collide. After the Laravel
skeleton + conventions exist, dispatch focused agents:
- **A — Client API:** `app/Http/Controllers/Api/*`, `routes/api.php`, request validation,
  built strictly to doc 02. Returns JSON exactly as the client expects.
- **B — Admin + Theme:** `resources/views/admin/*`, `public/assets/*`, admin controllers —
  the dark dashboard UI, charts, live‑save.
- **C — Models/Migrations:** `database/migrations/*`, `app/Models/*` — schema for all
  entities, seeders, the `user:create`/`admin:reset-password` commands.
- **D — Mail/2FA/Strategy services:** `app/Services/*` — borrowed designs from doc 06.
- **E — Translation:** sweep remaining Chinese in docs/strings/comments → English.

Integration, routing wiring, and verification (lint + tests + E2E in Docker) are done on the
main thread. Agents return diffs/changes for review, not silent commits.

## 6. Migration & safety policy

- Build the PHP app **alongside** the Go code; the Go source is the behavioral oracle.
- No destructive deletion of the Go codebase until the PHP app reaches verified parity and
  the user signs off on retiring it. Track parity in [09-port-status.md](09-port-status.md).
- Preserve JSON keys, API paths, and DB column names the **client** depends on (English
  rename applies to PHP identifiers, not the wire contract the client speaks).
- Keep `docs/modernization/*` as the living spec.

## 7. Definition of done (per slice)

Matches the client contract (doc 02) where client‑facing · admin page in the dark theme
where user‑facing · migration + model + service + controller + route · PHPUnit + a Playwright
case · Pint/PHPStan/ESLint clean · docs updated · runs green in `docker/compose.toolchain.yml`.

## 8. Status

Live progress is tracked in [08-build-log.md](08-build-log.md) (what was built/verified) and
[09-port-status.md](09-port-status.md) (parity checklist vs the Go implementation).
