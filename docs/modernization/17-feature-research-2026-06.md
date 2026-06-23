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
| 1 | **`is_pro` capability advertisement** | ★★★ | S | **Yes** | Advertise the Pro feature set so the client unlocks UI for things we now implement. |
| 2 | **Write coverage for `/api/v1`** (+ write scopes) | ★★★ | M | n/a | Device assign, strategy & user CRUD, AB CRUD, webhook mgmt — make the REST API two-way. |
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

## Why #1 is the recommended next build

`is_pro` (a.k.a. capability advertisement) is the highest ROI item left: **Small effort, high
value, zero client changes.** The RustDesk client decides whether to show several "Pro" panels
(shared address books tab, recording, strategy-driven UI, etc.) based on what the server
advertises. We now *actually implement* the features behind those panels — shared address
books (this wave), recording upload, strategy push, 2FA — but the client may still hide them
because the server hasn't said it supports them.

- **Anchor:** the client reads server capability/version metadata from `GET /api/` (and the
  login/`user/info` payloads). Confirm the exact key the target client builds gate on
  (`version`, an `is_pro`/`pro` flag, or a capability list) against `D:\git\rustdesk` before
  emitting it — do **not** guess; mis-advertising a capability we don't fully support degrades
  the client UX. This is the same discipline that fixed the `extra:{}` and empty-ack bugs.
- **Scope:** add the advertised flag(s) to the index/login responses behind a config toggle
  (`RUSTDESK_ADVERTISE_PRO`, default on now that the features exist), plus a test that pins the
  exact JSON shape.

## Suggested sequencing

1. **`is_pro` advertisement** (#1) — unlock the UI for everything already built. *Verify the key
   in client source first.*
2. **`/api/v1` write coverage + write scopes** (#2) — turns the read-only REST API into a real
   automation surface; pairs naturally with the OpenAPI spec just shipped.
3. **Webhook delivery log + retry** (#3) and **CSV export** (#4) — small, operational polish on
   the two subsystems added this wave.
4. Then platform depth: **metrics/observability** (#5), **quotas** (#7), **WoL** (#10).

## Guardrails (unchanged)

- **Wire-compatibility:** never rename the JSON keys / paths the client speaks; validate any new
  client-facing shape against `D:\git\rustdesk` parser code, not secondary docs.
- **No Vue/SPA**, English everywhere, log every change to the agent changelog.
- Each item ships with PHPUnit (and Playwright where it touches the console), Pint + PHPStan L5
  green.
