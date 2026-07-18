# 09 · Port Status — Parity Checklist (Go ➜ PHP)

> ## ✅ Port complete — this checklist is historical
> The Go server has been **retired**; the repo is single‑stack PHP. The entire client API
> (`/api/*`), the admin console, and the Pro‑parity feature set have shipped and are verified
> green (Pint, PHPStan L5, PHPUnit + Playwright). The ⬜/🟦 marks in the **PHP** columns below
> are from the in‑progress rewrite and are **out of date** — for the accurate current feature
> status and remaining roadmap, see **[04-gap-analysis.md](04-gap-analysis.md)** (top section).
> This file is kept for history only.

## Current production-runtime candidate (2026-07-18)

The application source now contains a benchmark-gated Nginx + PHP-FPM container candidate. It is
a drop-in runtime change rather than a RustDesk API port change: container port `80`, persistent
`/var/www/html/storage`, MariaDB initialization, application environment variables, reverse-proxy
trust, client paths, JSON keys, and response shapes remain unchanged.

Nginx talks to PHP-FPM only through the permission-restricted
`/run/php/rustdesk-api.sock`; there is no live or published TCP FastCGI port. Startup rejects
invalid optional worker, connection, access-log, body-limit, slow-log, and drain values before
database migrations. Nginx derives its process count from the tighter visible-CPU/cgroup quota by
default. The body ceiling follows the recording chunk plus headroom, Nginx owns the single
toggleable access log, and a peer supervisor fails the container if either server dies. The image keeps an eight-second
drain for unchanged Compose compatibility, while bundled Compose files pair a 30-second drain with
a 35-second orchestrator stop grace.

Native AMD64/ARM64 publication jobs now require a real MariaDB-backed runtime smoke covering
syntax, socket/TCP isolation, HTTP/API/proxy parity, secure cookies, request and path boundaries,
secret/build-tool removal, child-process failure, and graceful in-flight shutdown. A separate
fixed-resource Apache/Nginx harness supplies capacity evidence. Those capacity trials and the
public reverse-proxy canary are not complete, so `latest` remains the published Apache-based
v1.0.1 image and no Nginx/PHP-FPM release promotion is recorded here.

A short local, payload-matched 300-heartbeat-RPS tuning run passed both keep-alive and no-reuse
profiles for both runtimes with zero failures, drops, or wire mismatches and roughly 7 ms p95
latency. The quota-aware Nginx candidate used less sampled CPU and app memory in that run. This
supports continuing the candidate but does not replace the full steady, recovery, background-route,
native-CI, or public 1Panel gates.

## Current database support (2026-07-14)

The current PHP application supports **MariaDB with InnoDB only**. Runtime, development,
PHPUnit, CI, browser tests, and screenshot fixtures use MariaDB; destructive fixtures have
dedicated tmpfs-backed schemas and cannot reuse the persistent development database. Other
database engines are rejected before migrations.

This is a breaking deployment boundary. Existing MariaDB operators with an explicit
`DB_CONNECTION=mysql` setting first back up and pass the read-only
[InnoDB engine audit](../../Wiki/database/database-index.md#upgrade-boundary), then rename it to
`DB_CONNECTION=mariadb` without moving or rewriting compliant InnoDB data.
An installation using the retired SQLite path must complete the
[manual SQLite-to-MariaDB migration boundary](../sqlite-to-mariadb.md) on the last compatible
release before upgrading, or remain on that release. No automated converter is included. The
parity tables below describe the historical Go-to-PHP rewrite and are intentionally unchanged.

## Current admin two-factor status (2026-07-15)

Personal authenticator setup and removal now require a completed console sign-in from the last
five minutes. The encrypted proof is account-, credential-, and regenerated-session-ID-bound;
stale sessions can review status but cannot reveal a pending setup secret or change two-factor
state, and competing management requests are session-serialized. This works for local, LDAP, and
SSO administrators through their normal application sign-in path. Removal requires the current
authenticator or an unused recovery code unless that exact enrollment was just verified during
local/LDAP sign-in; the carried proof cannot survive factor replacement. Console SSO neither
layers local TOTP onto the callback nor guarantees that the provider prompts again, so provider
MFA/step-up policy remains the operator's responsibility and an SSO removal still requires a
factor code.

TOTP enrollment is available only to accounts with console access through their protected
personal settings. Generic user create/edit screens and requests can select only `off` or `email`
for inactive accounts; an active authenticator is read-only and its seed, confirmation time, and
recovery list are preserved. A MariaDB repair migration validates all encrypted seeds before
writing, normalizes historical partial states, and enforces the canonical active/inactive
invariant with a named CHECK whose byte-exact policy comparisons cannot be weakened by MariaDB's
case-insensitive text collation. This changes no RustDesk client keys or paths.

Email verification now has the same durable state discipline: its exact policy requires a
nonblank challenge destination in admin, API v1, CLI, and LDAP synchronization paths, backed by a
named MariaDB CHECK. The migration aborts before DDL and reports affected account IDs instead of
silently changing configured second-factor intent to password-only. Operators explicitly repair
the address or policy before retrying with legacy writers quiesced.

## Current address-book integrity status (2026-07-15)

Personal address books now have an explicit nullable marker backed by a one-per-owner MariaDB
unique index. Migration backfill preserves ordinary same-named books and dependent data, while
read-only preflights reject ambiguous or incompatible historical state before DDL. A separate
unique index on `(address_book_id, rustdesk_id)` prevents duplicate peer identity within one book;
its preflight reports historical duplicate pairs instead of silently choosing between their
credentials or metadata. Existing RustDesk routes, keys, status codes, and response shapes are
unchanged. These constraints protect identity only; the separate `max_peers` quota check is not
claimed as concurrency-durable.

## Current admin UI status (2026-07-15)

The historical table below predates the completed full-surface WebUI modernization. The
current admin console is a server-rendered Blade + jQuery + Bootstrap 5 application with a
warm-mineral dark/light theme, locally vendored frontend assets, a responsive grouped
navigation shell, and shared accessible confirmation, toast, combobox, and chart behavior.
Playwright now covers desktop dark, desktop light, tablet dark, and mobile dark projects, with
axe scans for login and representative authenticated pages. This visual refresh does not
change the client API or RustDesk wire contract. The final Docker gates passed 532 PHPUnit tests /
3,018 assertions, Pint across 275 files, PHPStan with no errors, JavaScript/vendor/Blade/Compose
checks, and dependency audits with no advisories. The 80-case four-project Playwright matrix
passed 68 tests with 12 intentional screenshot-mode skips.

Tracks parity of the PHP rewrite against the legacy Go implementation and the client
contract. Status: ⬜ not started · 🟦 in progress · ✅ done+verified.

## Client API (`/api/*` — doc 02) — wire‑compatible, do not rename JSON keys/paths
| Endpoint | Go | PHP | Notes |
|----------|:--:|:---:|------|
| `GET /api/` · `/api/version` | ✅ | ⬜ | |
| `POST /api/login` (+2FA) | ✅(no 2FA) | ⬜ | add TOTP/email per doc 02 §3–4 |
| `GET /api/login-options` | ✅ | ⬜ | |
| `POST /api/oidc/auth` · `GET /api/oidc/auth-query` | ✅ | ⬜ | |
| `POST /api/logout` · `currentUser` · `user/info` | ✅ | ⬜ | |
| `POST /api/heartbeat` | ⚠️ stub | ✅ | strategy-push + change-detection verified |
| `POST /api/sysinfo` · `sysinfo_ver` | ⚠️ | ✅ | presets + ID_NOT_FOUND gating verified |
| `GET/POST /api/ab*` (personal+shared) | ✅ | ⬜ | |
| `POST /api/audit/conn` · `audit/file` | ✅ | ⬜ | |
| `POST /api/record` | ❌ | ⬜ | new (recording upload) |
| `POST /api/devices/deploy` · `devices/cli` | ❌ | ⬜ | new (deployment) |

## Admin console
| Area | Go | PHP | Notes |
|------|:--:|:---:|------|
| Auth/login + captcha | ✅ | 🟦 | login page renders (dark theme); auth logic next |
| Dashboard (stats/charts) | ⚠️ | 🟦 | shell + ApexCharts render w/ demo data; wire real stats |
| Users | ✅ | ⬜ | |
| Devices/Peers | ✅ | ⬜ | + live online/sessions |
| Address books (+collections/rules) | ✅ | ⬜ | |
| Tags · Groups · Device groups | ✅ | ⬜ | |
| OAuth providers | ✅ | ⬜ | |
| LDAP/AD | ✅ | ⬜ | |
| Audit logs (conn/file) | ✅ | ⬜ | + alarm, console-op |
| Login logs | ✅ | ⬜ | |
| Share records (guest) | ✅ | ⬜ | |
| Server commands (hbbs/hbbr) | ✅ | ⬜ | |
| Tokens / API keys | ⚠️ session | ⬜ | scoped keys |

## New / borrowed features (doc 04/06)
| Feature | PHP | Notes |
|---------|:---:|------|
| Mail/SMTP (templates + logs) | ⬜ | ref: lantongxue |
| 2FA TOTP + email verification | ⬜ | ref: lantongxue |
| Session management | ⬜ | auth-token rotation |
| Version-capability gating | ⬜ | ref: lantongxue |
| Strategy settings-push | ✅ | heartbeat config_options push, priority device>user>group, verified |
| Preset auto-registration | ✅ | sysinfo OPTION_PRESET_* → strategy/device-group/address-book, verified |
| Access control / roles | ⬜ | teams/MSP |

## Cross-cutting
| Item | Status |
|------|:------:|
| English: identifiers/comments | ⬜ |
| English: docs/README | ⬜ |
| Docker dev stack green | ✅ |
| Admin shell renders (login+dashboard, verified) | ✅ |
| Playwright E2E | ⬜ |
| Pint/PHPStan/ESLint gates | ⬜ |
