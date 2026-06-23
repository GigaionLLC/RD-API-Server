# 17 · Feature Research — what to build next (2026-06-22)

A fresh sweep of the RustDesk client contract ([02](02-client-api-contract.md)), the Pro
catalog ([03](03-pro-feature-catalog.md)), and the earlier opportunity deep-dive
([11](11-client-feature-opportunities.md)) **after** the latest build wave. Most of doc 11 has
now shipped — this doc captures what is *genuinely still open*, ranks it, and recommends a
shortlist.

> Ratings: **Value** ★ low → ★★★ high · **Effort** S/M/L/XL · **Client-ready?** = stock clients
> already speak it, so server-side work alone unlocks it.

## Just shipped (context)

Strategy/Settings push · preset auto-registration + `--assign` + default device group ·
force-disconnect / Live Sessions · client **and** admin 2FA · SMTP + email verification ·
device deploy/approval · session-recording upload · alarms + connection/file/console audit ·
granular access control + Admin Roles · OIDC · LDAP · RustDesk-client-style address-book
manager · device bulk-assign + live-search pickers · Client Config generator (config string +
QR + per-OS `--config` + installer) · scoped API keys + `/api/v1` · **OpenAPI + Postman/Bruno**
· **outbound webhooks (Slack/Telegram/generic)** · **shared / team address books** (collaborator
rules read / read-write / full).

## Ranked opportunities (still open)

| # | Feature | Value | Effort | Client-ready? | One-line |
|---|---------|:-----:|:------:|:-------------:|----------|
| 1 | **Write coverage for `/api/v1`** (+ write scopes) ✅ | ★★★ | M | n/a | Device assign, strategy & user create/update, AB book CRUD — make the REST API two-way. **Done 2026-06-22.** |
| — | ~~`is_pro` capability advertisement~~ | — | — | — | **Not needed — see correction below.** |
| 3 | **Webhook delivery log + retry/backoff** | ★★ | S-M | n/a | Persist deliveries, retry transient failures, show a per-hook history. |
| 4 | **Audit / device CSV export** | ★★ | S | n/a | One-click export of connection, file, login audit + device inventory. |
| 5 | **Dashboard metrics + `/metrics` (Prometheus)** | ★★ | M | n/a | Session/device/online trends in-console and a scrape endpoint. |
| 6 | **Bulk actions on users & address books** | ★★ | S-M | n/a | Mirror the device bulk-assign bar (enable/disable, group, delete; AB import/export). |
| 7 | **Per-AB max-peer + licensed-device quotas** | ★★ | M | partial | `ab/settings.max_peer_one_ab` is wired but always 0; enforce per-book/per-server caps. |
| 8 | **Notification routing rules** | ★★ | M | n/a | Route events to webhooks by device-group / severity, not just event type. |
| 9 | **Scheduled email digests / reports** | ★★ | S | n/a | Daily alarm + connection summary email (reuses SMTP + the new event layer). |
| 10 | **Wake-on-LAN orchestration** | ★★ | M | Yes | Relay a magic packet through an online same-LAN peer (`same_server` hint, doc 11 §12). |
| 11 | **Packaged server-management CLI** | ★★ | M | n/a | Thin wrapper over `/api/v1` (artisan + shell) for scripted ops. |
| 12 | **SSO provider presets (Azure / Okta / Authentik)** | ★ | S | n/a | Guided OIDC setup instead of raw endpoint entry. |
| 13 | **API-key hardening** (per-IP allowlist, last-used IP, rotation) | ★ | S | n/a | Tighten the new key model for shared/MSP environments. |
| 14 | **Audit retention / pruning policy** | ★ | S | n/a | Scheduled cleanup with a configurable window; keeps SQLite installs lean. |
| 15 | **Multi-relay / geo management** | ★ | L | needs hbbs | Manage the relay list via server-cmd; true geo lives in `hbbs`. |

## Correction — `is_pro` is **not** a feature we need to build

An earlier draft of this doc recommended advertising an `is_pro` capability flag so the client
would unlock Pro UI. Verifying against the client source
([`src/hbbs_http/sync.rs`](file:///d:/git/rustdesk/src/hbbs_http/sync.rs)) shows that was wrong:

- `is_pro()` is **inferred, not advertised**. The client's `PRO` flag starts `false` and flips
  to `true` when the server answers the sysinfo handshake — `POST /api/sysinfo` returning
  `"SYSINFO_UPDATED"` (sync.rs:219), or `/api/sysinfo_ver` responding (sync.rs:195).
- **Our server already implements both** (`SystemController::sysinfo` + `/api/sysinfo_ver`), so a
  logged-in device that uploads sysinfo already makes the client treat us as Pro.
- What `is_pro()` actually gates is small (e.g. `hide_cm` in `ipc.rs`). The big Pro **panels**
  (shared address-book tab, etc.) are driven by their own API responses
  (`/api/ab/shared/profiles`, now populated), not by a single pro flag.

Lesson reaffirmed: verify client-facing assumptions against `D:\git\rustdesk` parser/source code
before scheduling work — the same discipline that fixed the `extra:{}` and empty-ack bugs.

## #1 (done) — `/api/v1` write coverage

The genuinely high-value item was making the read-only REST API two-way. Shipped 2026-06-22:

- `PUT /api/v1/devices/{id}` — reassign owner / device group / strategy / alias (`devices.write`).
- `POST` + `PUT /api/v1/strategies[/{id}]` — create/update a strategy's options, bumping
  `modified_at` so the heartbeat pushes it (`strategies.write`).
- `POST` + `PUT /api/v1/users[/{id}]` — provision/update accounts (`users.write`).
- `POST /api/v1/address-books` + `DELETE /api/v1/address-books/{id}` — book CRUD (`address_book.write`).

## Suggested sequencing (remaining)

1. **Webhook delivery log + retry** (#3) and **CSV export** (#4) — small, operational polish on
   the two subsystems added this wave.
2. Then platform depth: **metrics/observability** (#5), **quotas** (#7), **WoL** (#10).

## Guardrails (unchanged)

- **Wire-compatibility:** never rename the JSON keys / paths the client speaks; validate any new
  client-facing shape against `D:\git\rustdesk` parser code, not secondary docs.
- **No Vue/SPA**, English everywhere, log every change to the agent changelog.
- Each item ships with PHPUnit (and Playwright where it touches the console), Pint + PHPStan L5
  green.
