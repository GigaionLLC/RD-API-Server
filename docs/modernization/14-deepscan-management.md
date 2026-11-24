# 14 · Deep Scan — Account / Distribution / Management (net-new findings)

A read-only deep scan of the RustDesk client (`D:\git\rustdesk`, Rust + Flutter) hunting for
**net-new, server-implementable** features in the *account / distribution / management* area —
specifically things **NOT already covered** by docs `02` (client-API contract), `10` (config
keys / Strategy) and `11` (client feature opportunities). Anything those docs already own
(Strategy push, sysinfo presets, login 2FA, OIDC, LDAP, address books, audit ingestion,
recording upload, device deploy/CLI, access control, admin roles, alarms, `same_server` WoL
hint, `is_pro()` advertisement, login-device-whitelist / email-verification / email-alarm) is
**deliberately not re-reported** here except where this scan adds a materially new detail.

All client citations are `file:line` in `D:\git\rustdesk` (read-only). Effort: **S** ≈ 1–2 d ·
**M** ≈ 3–7 d · **L** ≈ 1–3 wk (server side only). "Server-influenceable?" is the honest column:
many candidate ideas turn out **client-only** or **hardcoded to rustdesk.com** and cannot be
delivered to a *stock* client without a client change — those are called out plainly.

---

## Ranked findings

| # | Finding | Net-new? | Server-influenceable on a **stock** client? | Effort | Value |
|---|---------|----------|---------------------------------------------|--------|-------|
| ~~1~~ | ~~**Custom plugin registry + `plugin-sign` license endpoint**~~ — **dropped: upstream deleted the plugin framework in 1.5.0 (#15854)**; `src/plugin/` is empty at `a0c0963c`, so nothing calls `plugin-sign` | — | ❌ No — the subsystem no longer exists | — | — |
| 2 | **`UserStatus::Unverified (-1)` gating + `third_auth_type` round-trip** | ✅ Yes (doc 11 #3 covered whitelist/email, not these) | ✅ Yes — pure server policy | S | ★★★★☆ |
| 3 | **Per-tag address-book colors (`tag_colors`) round-trip** | ✅ Yes (doc 11 #7 listed AB but not colors) | ✅ Yes — server stores/returns | S | ★★★☆☆ |
| 4 | **Console-operation / admin-action audit log** (new audit *category*, not client-driven) | ✅ Yes | n/a (panel-internal) | S–M | ★★★★☆ |
| 5 | **`UserInfo.other` free-form map** (login payload extension point) | ✅ Yes | ⚠️ Carried but not consumed by client today | S | ★★☆☆☆ |
| 6 | **Custom auto-update channel** (host signed builds / version feed) | ✅ Yes | ❌ **No** — hardcoded to `api.rustdesk.com/version/latest` | — | ★★★★☆ *(blocked)* |
| 7 | **Generic server file-download / distribution endpoint** (`downloader.rs`) | partial | ❌ No client trigger hits the api-server | — | ★★☆☆☆ *(blocked)* |
| 8 | **Live online-peers feed / websocket** | ✅ Yes (as a panel feature) | ❌ Not an API feature in the stock client (online comes from rendezvous/hbbs, not HTTP) | M | ★★★☆☆ *(panel-only)* |
| 9 | **Guest / temporary share links (`ShareRecord`)** | ✅ Yes (model exists, unbuilt) | ❌ Web-client-only; **not** a stock-desktop-client flow | M–L | ★★★☆☆ *(web-client-only)* |
| 10 | **Wake-on-LAN orchestration** | — (doc 11 #12) | ❌ Client-LAN-only (magic packet from local config) | — | ★★☆☆☆ *(confirmed client-only)* |

**Headline honesty:** the two most attractive-sounding items — a **custom auto-update channel**
(#6) and a **plugin distribution store** (#1) — are both dead. #6 is **hardcoded** to
rustdesk.com and cannot be redirected on a stock client. #1 no longer exists at all: upstream
deleted the plugin framework in 1.5.0 (#15854), so there is no client-side caller left. The
genuinely *free, stock-client* wins are the small server-policy items (#2, #3, #4).

---

## 1. Plugin system — **dropped** (was: custom registry + `plugin-sign` license endpoint)

**Status: not buildable — the upstream plugin framework is gone.** `d1da05c4` (#15854,
"refact: remove feature plugin-framework", first tag **1.5.0**) deleted the whole `src/plugin/`
tree: `git ls-tree a0c0963c src/plugin/` is empty. That removes every anchor this plan rested on:

- `src/plugin/manager.rs` (plugin sources, registry `meta.toml`, zip install path) — deleted.
- `src/plugin/callback_msg.rs:283-296` — the `{api-server}/lic/web/api/plugin-sign` call — deleted.
  It was the only client→API endpoint besides `/api/*` and the only one removed since 1.4.9.
- the `plugin_framework` Cargo feature — gone with the code.

So a plugin registry, an allowlist and a signing endpoint would have **no caller on any client**,
1.4.9's plugin builds included (those are the versions we would be signing for, and they also
resolve `plugin-sign` against the configured API server — but 1.5.0+ fleets cannot use it at all).

**Server-influenceable:** ❌ No. **Beats OSS:** moot. Do not implement; this document previously
ranked it as a forward-looking differentiator, which the upstream removal invalidates.

---

## 2. `UserStatus::Unverified (-1)` gating + `third_auth_type` round-trip  ✅ net-new (S, ★★★★☆)

Doc 11 #3 covered `login_device_whitelist`, `email_verification`, `email_alarm_notification`.
This scan adds the two fields **that doc did not call out**, both already deserialised and
carried by the client:

- **`UserStatus`** is a tri-state `repr(i64)`: `Disabled = 0`, `Normal = 1`, **`Unverified = -1`**
  (`src/hbbs_http/account.rs:71-77`). The client persists `status` into its cached `user_info`
  on login (`account.rs:277`) and the Sciter UI branches on it. The default is `Normal`
  (`account.rs:129-133`).
- **`third_auth_type: Option<String>`** on `UserPayload` (`account.rs:96`) — the provider name
  when the account was created/linked via SSO (OIDC/LDAP).

**What the server would build.**
- **Unverified-user flow.** Return `status: -1` for accounts that registered but have not
  completed email verification (or admin approval). Pair it with the existing
  `tfa_type:"email_check"` round-trip (doc 02 §4) so the client drives the verify step, and
  **deny token issuance / AB access** until the account flips to `1`. This is the "new accounts
  must verify before use" policy, enforced entirely server-side — the client already understands
  the `-1` value.
- **`third_auth_type` surfacing.** Populate it for SSO accounts so the client shows "logged in
  via \<provider\>" and so the panel can **forbid local-password login** for SSO-only accounts
  (a real security hardening: an SSO user shouldn't also have a guessable local password). Pure
  server logic; the field is a free round-trip.

**Server-influenceable:** ✅ pure server policy, zero client change. **Effort S.** **Value high**
— closes an account-lifecycle gap (unverified state) that neither OSS server models.

---

## 3. Per-tag address-book colors (`tag_colors`) round-trip  ✅ net-new detail (S, ★★★☆☆)

Doc 11 #7 enumerated AB peer fields and the granular routes but **did not** mention tag colors.
The client round-trips them in two shapes the server must persist verbatim:

- **Legacy blob:** the `/api/ab` payload carries `"tag_colors"` as a JSON string
  (`flutter/lib/models/ab_model.dart:628`, parsed at `:689-691` and `:1347-1349`,
  `Map<String,int>` of tag → ARGB int).
- **Granular:** `setTagColor(tag, color)` persists per-tag color; tags come back with a `color`
  on the `AbTag` model and are re-applied to `tagColors` (`ab_model.dart:522-523`,
  `:1284-1295`, `:1426-1428`). Per-profile tags route is `/api/ab/tags/{guid}`
  (`ab_model.dart:1500`).

**What the server would build.** Store a `color` (int/ARGB) per tag per address book, return it
in the legacy `tag_colors` blob **and** on each granular tag object. Trivial column addition; if
omitted, the client falls back to a hash-derived color (`str2color2`, `ab_model.dart:738-742`),
so missing colors aren't fatal — but **persisting them is a visible polish item** users notice
immediately when colors reset on every device. Bundle into the AB work, not a standalone effort.

**Server-influenceable:** ✅. **Effort S.**

---

## 4. Console-operation / admin-action audit log  ✅ net-new (S–M, ★★★★☆)

This is **not** a client-driven endpoint — it is the missing *category* of audit. The existing
audit ingestion (`/api/audit/conn`, `/api/audit/file`; doc 02 §8) records what **clients** do.
RustDesk Pro additionally keeps a **console-operation log**: every **admin action in the panel**
(create/disable user, edit strategy, revoke token, change access rule, delete AB peer, issue a
share link, etc.).

**What the server would build.** A panel-internal `operation_logs` table + middleware that
records `{actor_user_id, action, target_type, target_id, before/after diff, ip, ua, ts}` on every
mutating admin request, with a filterable admin UI. No client involvement; it is an
audit/compliance feature the fork's current audit screen does not cover (it shows only
connection/file events). Pairs naturally with the alarm work (doc 11 already references Pro's
"console-operation logs" in doc 02 §8 as a *future* category — this is the build-out).

**Server-influenceable:** n/a (panel-internal). **Effort S–M** (mostly UI + a generic logging
trait around controllers). **Value high** for any team that needs accountability.

---

## 5. `UserInfo.other` free-form map — login-payload extension point  ✅ net-new (S, ★★☆☆☆)

`UserInfo` has, besides `settings` and `login_device_whitelist`, an **`other: HashMap<String,
String>`** with `#[serde(default)]` (`src/hbbs_http/account.rs:54-61`). It is **deserialised and
carried but not consumed** by any client code path at this commit (mirror of the heartbeat
`StrategyOptions.extra` situation documented in doc 10 §4).

**Implication.** It is a **forward-compatible extension slot** in the login `user.info` object:
the server may return arbitrary `other` key/values without breaking the client. Useful only if a
future client path (or our own web-client) reads it. **Do not build around it today** — like
`extra`, it is inert in the stock client. Logged here for completeness so it isn't mistaken for a
live capability.

**Server-influenceable:** ⚠️ carried, not acted upon. **Effort S** but **no current payoff.**

---

## 6. Custom auto-update channel — **BLOCKED on a stock client** (★★★★☆ value, not deliverable)

This was a primary scan target; the honest answer is **the panel cannot host the update feed for
a stock client.**

**The version-check flow (cited):**
1. `updater.rs` runs a background loop; on `OPTION_ALLOW_AUTO_UPDATE` (or a manual check) it
   calls `do_check_software_update()` (`src/updater.rs:120-129`).
2. `do_check_software_update()` builds the request via
   `hbb_common::version_check_request(VER_TYPE_RUSTDESK_CLIENT)` and **POSTs to a hardcoded
   constant** `const URL = "https://api.rustdesk.com/version/latest"`
   (`libs/hbb_common/src/lib.rs:495-496`; the call site even comments *"the url is always
   https://api.rustdesk.com/version/latest"* at `src/common.rs:952-953`).
3. The response is `VersionCheckResponse { url }` (`lib.rs:486-490`); if the parsed version > the
   running `VERSION`, the client stores `SOFTWARE_UPDATE_URL` (`common.rs:982-998`).
4. `updater.rs` then derives the **download URL from that returned URL** by string-replacing
   `tag`→`download` and appending `rustdesk-{version}-x86_64.{msi|exe}`
   (`updater.rs:135-147`) — i.e. it downloads the binary from **whatever host
   api.rustdesk.com returned** (GitHub releases), not from our api-server.

**Why it's blocked.** Neither the version-check host **nor** the download host is derived from the
configured `api-server`. There is no config key to redirect `version/latest`. So a self-hosted
panel **cannot** advertise "latest version per platform" or host signed builds for a *stock*
client. This would require a client patch (make the version-check URL derive from `api-server`).

**What the panel *can* still do (no client change):** generate the **renamed/­config-string
installer** for first-install (doc 11 #6) and host those files for admins to distribute manually —
but that is onboarding, not an *auto-update channel*. Record this item as **"would be ★★★★ if the
client supported it; today it does not."** Do not build a `/version/latest` responder expecting
stock clients to hit it — they won't.

---

## 7. Generic download / distribution endpoint (`downloader.rs`)  partial / blocked (★★☆☆☆)

`src/hbbs_http/downloader.rs` is a **general-purpose async file downloader** (HEAD for size →
GET chunked to disk/memory, cancellable, dedup by URL — `downloader.rs:50-160`, `162-272`). It is
**generic** and **not bound to the api-server**: callers pass an arbitrary `url`. In practice the
only server-fed URL that flows into a download is the **auto-update binary** (#6), which originates
from rustdesk.com. There is no client code path where the api-server hands the client a URL to
fetch *custom payloads* (config bundles, branding, etc.).

**Conclusion.** A panel **could** host a download endpoint, but **no stock-client trigger consumes
it** beyond the (blocked) update path. So "host a download/distribution endpoint" is **not** a
net-new *stock-client* feature — it would only serve our own web-client/admin UI or a custom
client. Honest verdict: **not actionable for stock clients.**

---

## 8. Live online-peers feed / websocket  panel-only (M, ★★★☆☆)

**Finding:** in the **desktop** client, peer "online" status does **not** come from the API
server. The AB model merely *restores* previously-known online IDs from a cache
(`flutter/lib/models/ab_model.dart:1327,1342-1345`); the authoritative online signal is the
**rendezvous (hbbs TCP) punch-hole query**, not an HTTP endpoint. There is **no** "magic query
online" / `__cm` / websocket online-status call to the api-server anywhere in `src/hbbs_http`
(searched: no `online`/`ws://`/`websocket` matches). The earlier "is online" concept lives at the
hbbs protocol layer, outside the API server.

**What the server *can* build (panel-only).** Because every device POSTs `/api/heartbeat` (doc
02 §1), the **panel already has live presence data**. It can expose its **own** websocket / SSE
feed of online devices **to the admin web UI** — a "live fleet view." That is a genuine and
valuable panel feature, but understand its scope: it feeds **our dashboard**, not the stock
desktop client's AB online dots (those still rely on hbbs). Build it for the admin console; don't
expect to drive client UI with it.

**Server-influenceable:** ❌ for stock-client AB dots; ✅ as a panel/admin feature. **Effort M.**

---

## 9. Guest / temporary share links (`ShareRecord`)  web-client-only (M–L, ★★★☆☆)

**Finding:** there is **no guest/temporary-share-link flow in the stock desktop client.** A search
for `share`/`guest`/`ShareRecord`/`temporary link` across `src/` finds only **shared-address-book
password** plumbing (`PasswordSource::SharedAb`, `client.rs:1680-1708,3515-3548`) and
**RDP-share** toggles (`main_set_share_rdp`, `flutter_ffi.rs:2332-2336`) — neither is a
"temporary access link to a device." The lejianwen-style guest sharing is a **web-client** feature
(a browser connects to a device via a tokenised URL); it is not part of the Rust desktop client's
API vocabulary.

**Our repo already has the skeleton, but the flow is unbuilt:**
- Model `app/Models/ShareRecord.php` — fields `user_id, peer_id, share_token, password_type,
  password, expires_at` (password hidden).
- Migration `database/migrations/2026_06_18_100015_create_share_records_table.php` — unique
  `share_token`, indexed `peer_id`, nullable `expires_at`.
- **No controller, no route** (`routes/api.php` has no `/share*` route), so nothing issues or
  redeems a token today.

**What the server would build (only worthwhile if/when a web-client ships).** A `ShareController`
to **mint** a `ShareRecord` (token + expiry + optional one-time password) and a **redeem** path
that a browser-based web-client uses to connect to `peer_id` with the embedded credential. Because
the panel dropped the web-client (per recent commits removing webclient2 for DMCA), this is
**latent**: keep the model, but **do not invest in the redeem flow until a web-client target
exists**. As an interim, the same `ShareRecord` shape could back a "temporary connect password
that auto-expires" surfaced in the admin UI (server mints, admin shares the id+password manually),
which *is* usable with the stock client via the existing permanent/temporary-password mechanism.

**Server-influenceable:** ❌ no stock-client redeem path; ✅ as a panel-issued temporary credential.
**Effort M–L.**

---

## 10. Wake-on-LAN — confirmed client-LAN-only (★★☆☆☆, no new server lever)

Re-verified per the prompt. `send_wol(id)` reads peers from **local** config
(`config::LanPeers::load()`) and broadcasts a magic packet over the host's own interfaces
(`src/lan.rs:86-105`); exposed via `main_wol` (`flutter_ffi.rs:2173-2176`) and the Sciter
`send_wol` (`src/ui.rs:489-490`). There is **no** "wake a peer through another peer" relay in the
client and **no** server call. The only server lever remains the **`same_server` AB hint**
(already doc 11 #12) plus storing MAC/subnet in the sysinfo inventory so the *local* client can
offer WoL. **No new finding** — included to close the loop: a panel cannot orchestrate WoL to a
remote subnet with a stock client.

---

## Recommended to build (shortlist)

Ranked by **value ÷ effort**, restricted to things that actually work on a **stock client** or are
panel-internal:

1. **#2 Unverified-user gating + `third_auth_type`** — S effort, pure server policy, real
   account-lifecycle/security win. **Build first.**
2. **#4 Console-operation / admin-action audit log** — S–M, panel-internal, high
   compliance value, no client dependency.
3. **#3 Tag colors round-trip** — S, fold into the address-book work; visible polish.
4. **#8 Admin-console live online feed (panel websocket/SSE off heartbeat data)** — M, genuinely
   useful fleet view; scope it as an *admin* feature, not client AB dots.
**Explicitly do NOT build (blocked / no stock-client payoff):**
- **#1 plugin registry + `plugin-sign`** — the upstream plugin framework was deleted in 1.5.0
  (#15854); no client calls the endpoint any more.
- **#6 custom auto-update channel** — hardcoded to `api.rustdesk.com/version/latest`; impossible
  on a stock client without a client patch.
- **#7 generic distribution endpoint** — no stock-client trigger consumes it.
- **#9 share-link redeem flow** — web-client-only; keep the `ShareRecord` model latent until a
  web-client target exists (an expiring temporary-password surfaced in the admin UI is the only
  stock-client-usable slice).
- **#10 remote WoL orchestration** — client-LAN-only by design.

---

## Client source anchors (all read-only, `D:\git\rustdesk`)
- `src/hbbs_http/downloader.rs` — generic async file downloader (not api-server-bound).
- `src/updater.rs` — auto-update loop; download URL derived from the version-check response.
- `src/common.rs:942-1001` — `do_check_software_update`; comment pins the hardcoded URL.
- `libs/hbb_common/src/lib.rs:486-512` — `VersionCheckResponse`, `version_check_request`,
  `const URL = "https://api.rustdesk.com/version/latest"`.
- `src/hbbs_http/account.rs:46-97` — `UserInfo { settings, login_device_whitelist, other }`,
  `UserStatus { Disabled, Normal, Unverified=-1 }`, `UserPayload.third_auth_type`.
- `src/plugin/` — **deleted** at `a0c0963c` (#15854, first tag 1.5.0). The 1.4.9 anchors were
  `src/plugin/manager.rs:42-46,58-111,225-241` and `src/plugin/callback_msg.rs:270-296`
  (`{api-server}/lic/web/api/plugin-sign`).
- `src/lan.rs:86-105` — Wake-on-LAN magic packet (local config, local interfaces).
- `flutter/lib/models/ab_model.dart:233,251,260-297,522-523,628,689-691,1284-1295,1327,
  1342-1349,1500` — AB settings/personal/shared routes, `tag_colors`, per-tag color, online cache.
- `flutter/lib/models/peer_model.dart:8-68` — peer fields (already in doc 11 #7).

## Panel anchors (`D:\git\rustdesk-api`)
- `app/Models/ShareRecord.php`, `database/migrations/2026_06_18_100015_create_share_records_table.php`
  — share-record skeleton (no controller/route).
- `routes/api.php` — current client-API surface (no `/share*`, no plugin, no `/version/latest`).
