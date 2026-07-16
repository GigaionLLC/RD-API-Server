# 09 · Port Status — Parity Checklist (Go ➜ PHP)

> ## ✅ Port complete — this checklist is historical
> The Go server has been **retired**; the repo is single‑stack PHP. The entire client API
> (`/api/*`), the admin console, and the Pro‑parity feature set have shipped and are verified
> green (Pint, PHPStan L5, PHPUnit + Playwright). The ⬜/🟦 marks in the **PHP** columns below
> are from the in‑progress rewrite and are **out of date** — for the accurate current feature
> status and remaining roadmap, see **[04-gap-analysis.md](04-gap-analysis.md)** (top section).
> This file is kept for history only.

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

## Current admin UI status (2026-07-13)

The historical table below predates the completed full-surface WebUI modernization. The
current admin console is a server-rendered Blade + jQuery + Bootstrap 5 application with a
warm-mineral dark/light theme, locally vendored frontend assets, a responsive grouped
navigation shell, and shared accessible confirmation, toast, combobox, and chart behavior.
Playwright now covers desktop dark, desktop light, tablet dark, and mobile dark projects, with
axe scans for login and representative authenticated pages. This visual refresh does not
change the database, client API, or RustDesk wire contract. Its final full Docker and
four-project browser gates are still in progress at the time of this documentation sync.

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
