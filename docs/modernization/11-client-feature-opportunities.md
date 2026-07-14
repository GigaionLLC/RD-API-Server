# 11 · Client Feature Opportunities (Deep Dive)

What the **stock RustDesk client already does** that a self-hosted API server can light up to match or
beat RustDesk Server **Pro** — focused on features that the two leading open-source servers
(`lejianwen/rustdesk-api`, `lantongxue/rustdesk-api-server-pro`) do **not** implement, or implement only
partially.

All citations are to the client repo `D:\git\rustdesk` (read-only). Doc 02 already covers the basic HTTP
contract (heartbeat/sysinfo/login/OIDC/audit/ab routes); this doc deliberately does **not** repeat those
shapes and instead digs into the *deeper, implementable* opportunities and the exact request/response
contracts the client enforces.

Effort legend: **S** ≈ 1–2 days · **M** ≈ 3–7 days · **L** ≈ 1–3 weeks (server side only).

---

## Ranked opportunity table

| # | Opportunity | What the server implements | Effort | Value | Notes / why it beats existing OSS |
|---|-------------|----------------------------|--------|-------|-----------------------------------|
| 1 | **Strategy push = remote permission/policy control** | `/api/heartbeat` returns `strategy.config_options` keyed by the full `keys::OPTION_*` set | **M** | ★★★★★ | The single highest-leverage feature. One endpoint remotely controls terminal/camera/file-transfer/recording/privacy/printer permissions, IP whitelist, approve-mode, auto-disconnect, branding. This is the *core* of Pro. |
| 2 | **Device deployment & CLI assignment** (`--deploy` / `--assign`) | `POST /api/devices/deploy`, `POST /api/devices/cli` + bearer deploy tokens | **M** | ★★★★★ | Verified exact contracts below. Enables unattended mass-rollout + "require deployment for new devices". Neither OSS server has it. |
| 3 | **Login device whitelist + email alarm + email verification** | Populate `user.info.*`, enforce server-side at `/api/login`; `tfa_type:"email_check"` round-trip | **M** | ★★★★☆ | Pure server-side enforcement; client already round-trips the fields. Big security selling point. |
| 4 | **Two-factor: TOTP + Telegram-bot + backup codes** | `tfa_type:"totp"`, verify `tfaCode`/`secret`; account-login 2FA distinct from connection-2FA | **M** | ★★★★☆ | `tfa_check` response type + `tfa_code` request type already exist in the client. |
| 5 | **Session recording upload + playback** | `POST /api/record` chunked uploader, storage, playback UI | **L** | ★★★★☆ | Server-side recording archive — a flagship Pro feature. Contract fully verified below. |
| 6 | **"Deployment helper" config-string / renamed-installer generator** | Generate the `host=,key=,api=,relay=` config string and the encoded filename | **S** | ★★★★☆ | Tiny effort, huge UX. Lets admins download a pre-configured installer. Parser is in `custom_server.rs`. |
| 7 | **Shared address books, rules, max-peer, device groups** | `/api/ab/settings` (`max_peer_one_ab`), `/api/ab/shared/profiles`, per-profile `rule` (R/RW/Full) | **M** | ★★★★☆ | The granular AB routes + sharing model + licensed-device quota. Round-tripped peer fields verified. |
| 8 | **Strategy change-detection handshake (`modified_at`) + forced `disconnect`/`sysinfo`** | Bump `modified_at` on edit; return `disconnect:[ids]`, `"sysinfo":true` | **S** | ★★★★☆ | Cheap to add once #1 exists; gives near-real-time policy propagation + remote kick. |
| 9 | **`is_pro()` advertisement → unlock client Pro UI** | Answer `/api/sysinfo` `SYSINFO_UPDATED` + `/api/sysinfo_ver` consistently | **S** | ★★★☆☆ | Flipping the client's internal PRO flag is literally how a server "becomes Pro" to the UI. |
| 10 | **Address-book preset baking via sysinfo/CLI** (`OPTION_PRESET_*`) | Read preset keys from `/api/sysinfo` & `/api/devices/cli`, auto-create AB entries/tags | **M** | ★★★☆☆ | Auto-enroll deployed devices into the right address book/tag/group with a baked password. |
| 11 | **`NOT_DEPLOYED` gating on registration** | Coordinate hbbs `RegisterPkResponse=NOT_DEPLOYED` with `/api/devices/deploy` approval | **M** | ★★★☆☆ | "Approve before a device may register" — needs hbbs cooperation, but the API server owns the approval table. |
| 12 | **Wake-on-LAN orchestration hint (`same_server`)** | Set peer `same_server` so the client offers WoL to same-LAN peers | **S** | ★★☆☆☆ | WoL itself is client-LAN only; the server's job is the `same_server` hint + storing MAC in sysinfo. |

---

## 1. Strategy push — remote permission & policy control  ★★★★★ (M)

**What the client does.** Every heartbeat the client sends `modified_at` (its last-known strategy
timestamp) and, when the server replies with a different timestamp, it adopts the new value and applies
`strategy.config_options` straight into its live config via `handle_config_options`.

- `D:\git\rustdesk\src\hbbs_http\sync.rs:44-50` — `StrategyOptions { config_options, extra }`.
- `D:\git\rustdesk\src\hbbs_http\sync.rs:256-268` — `modified_at` compare + `strategy` apply.
- `D:\git\rustdesk\src\hbbs_http\sync.rs:287-304` — merge semantics: empty value **and** empty default ⇒ option
  removed (falls back to built-in default); otherwise the (possibly empty) value is set.

**Why this is the crown jewel.** The `config_options` map is keyed by the client's full option vocabulary
(`libs/hbb_common/src/config.rs`, `keys` module, `:2854-3027`). That vocabulary already includes every
in-session **permission** and **policy** that Pro exposes as a "strategy". The server doesn't need any new
client capability — just to *return the right keys*. The high-value, server-pushable keys:

| Capability | Key (`config.rs`) | Line |
|---|---|---|
| Permission: keyboard/mouse | `enable-keyboard` | 2899 |
| Permission: clipboard | `enable-clipboard` | 2900 |
| Permission: file transfer | `enable-file-transfer` | 2901 |
| Permission: **camera** | `enable-camera` | 2902 |
| Permission: **terminal** | `enable-terminal` (+ `terminal-persistent`) | 2903-2904 |
| Permission: audio | `enable-audio` | 2905 |
| Permission: **TCP tunnel / port-forward** | `enable-tunnel` | 2906 |
| Permission: remote restart | `enable-remote-restart` | 2907 |
| Permission: **record session** | `enable-record-session` | 2908 |
| Permission: block input | `enable-block-input` | 2909 |
| Permission: **privacy mode** | `enable-privacy-mode` | 2910 |
| Permission: **remote printer** | `enable-remote-printer` | 2863 |
| Access mode preset (full/view) | `access-mode` | 2898 |
| Approve mode (password/click) | `approve-mode` | 2930 |
| Verification method | `verification-method` | 2931 |
| **IP whitelist** (enforced client-side on incoming conns) | `whitelist` | 2918 |
| Auto-disconnect idle | `allow-auto-disconnect` / `auto-disconnect-timeout` | 2919-2920 |
| Auto-record incoming/outgoing | `allow-auto-record-incoming` / `-outgoing` | 2922-2923 |
| Direct access port | `direct-server` / `direct-access-port` | 2916-2917 |
| Permanent-password / ID lockdown | `disable-change-permanent-password`, `disable-change-id`, `disable-unlock-pin` | 3007-3009 |
| Branding / UI lockdown | `hide-security-settings`, `hide-network-settings`, `hide-server-settings`, `hide-proxy-settings`, `hide-tray`, `hide-powered-by-me` | 2980-3005 |
| Default connect password | `default-connect-password` | 2995 |
| Trusted devices | `enable-trusted-devices` | 2948 |

**Server implements:** a `strategies` table (named policy → key/value map), assignment of devices to a
strategy (by sysinfo `strategy_name`, device group, or user), a monotonically-increasing `modified_at`
bumped on any edit, and the `/api/heartbeat` responder that returns the merged `config_options` whenever the
device's effective strategy timestamp is newer than the client's `modified_at`.

**Beats OSS:** `lejianwen` returns a near-empty heartbeat; neither OSS server pushes the permission/policy
key set. This is the difference between "a relay with a web UI" and "Pro".

> **Important nuance — `OVERWRITE` vs `DEFAULT`.** The client distinguishes pushed *defaults* (user can
> still change) from *forced* settings (`is_option_fixed`, greyed-out in UI). See
> `config.rs:76-82` (`DEFAULT_SETTINGS`/`OVERWRITE_SETTINGS`/`HARD_SETTINGS`) and
> `src/ui/index.tis:325` (`is_option_fixed`). `config_options` pushed via heartbeat land in the
> *default/overwrite* layer; to make a permission **unchangeable** on the endpoint it must be baked at
> install time (custom client) — heartbeat push alone is "soft". Worth surfacing in the admin UI so admins
> know which controls are hard vs soft.

---

## 2. Device deployment & CLI assignment  ★★★★★ (M)

These back `rustdesk.exe --deploy` and `rustdesk.exe --assign`, used for unattended mass rollout. Both
require the client to be **installed + running as root/admin**, and both send a **bearer deploy token**.

### 2a. `POST /api/devices/deploy`  — verified

`D:\git\rustdesk\src\ui_interface.rs:1048-1117` (and CLI dispatch `src/core_main.rs:642-675`).

Request:
```
Authorization: Bearer <deploy-token>
POST {api}/api/devices/deploy
{ "id": "<id-to-deploy>", "uuid": "<base64 uuid>", "pk": "<base64 public key>" }
```
Response — the client matches on `parsed["result"]` (`ui_interface.rs:1075-1115`):
```json
{ "result": "OK" | "NOT_ENABLED" | "INVALID_INPUT" | "ID_TAKEN" }
```
Behaviors the server must honor:
- `OK` → client persists the (optional) new id locally and notifies deployed state. Optional `--id` lets the
  admin **assign a specific RustDesk ID** at deploy time (`ui_interface.rs:1077-1091`).
- `NOT_ENABLED` → server doesn't require deployment (CLI exits 3).
- `ID_TAKEN` → the chosen id is already bound to a different machine.
- Gated off entirely when `Config::no_register_device()` or `is_outgoing_only()` (`core_main.rs:643-646`).

### 2b. `POST /api/devices/cli`  — verified

`D:\git\rustdesk\src\core_main.rs:537-641`. Registers + assigns presets in one call. At least one of
`user_name / strategy_name / address_book_name / device_group_name / note / device_username / device_name`
is required.

Request:
```
Authorization: Bearer <token>
POST {api}/api/devices/cli
{
  "id": "<id>", "uuid": "<base64 uuid>",
  "user_name": "...",            // assign device to a user
  "strategy_name": "...",        // assign a named strategy (ties into #1)
  "address_book_name": "...",    // + address_book_tag / _alias / _password / _note
  "address_book_tag": "...",
  "address_book_alias": "...",
  "address_book_password": "...",
  "address_book_note": "...",
  "device_group_name": "...",
  "note": "...",
  "device_username": "...",      // override displayed username
  "device_name": "..."           // override hostname
}
```
Client prints whatever non-empty body the server returns, else `Done!` (`core_main.rs:626-632`). So the
server can return `""`/`{}` on success or an error string.

**Server implements:** a `deploy_tokens` table (scoped, revocable, with optional default strategy/group),
the two endpoints above, the device↔user/strategy/group/AB bindings, and id-collision handling. This is the
single biggest "enterprise rollout" gap in both OSS servers.

---

## 3. Login device whitelist + email alarm + email verification  ★★★★☆ (M)

**What the client does.** On login the client deserialises a rich `UserInfo` and round-trips it back, but it
does **not** enforce these — they are *server-side* policies. The client merely carries them:

- `D:\git\rustdesk\src\hbbs_http\account.rs:46-69` — `UserInfo { settings: UserSettings, login_device_whitelist, other }`,
  `UserSettings { email_verification, email_alarm_notification }`.
- `account.rs:46-51` — `WhitelistItem { data: "<ip|uuid>", info: DeviceInfo{os,type,name}, exp }`.
- The device descriptor the client supplies on every auth: `account.rs:30-44` `DeviceInfo { os, type:"client|browser", name }`, attached as `deviceInfo` (`account.rs:171`).

**Server implements (all server-side enforcement):**
- **`email_verification`** — return `tfa_type:"email_check"` (client const `kAuthResTypeEmailCheck`,
  `flutter/lib/common/hbbs/hbbs.dart:18`); client re-POSTs `/api/login` with `type:"email_code"` +
  `verificationCode` (`hbbs.dart:14,140,163`).
- **`login_device_whitelist`** — on each login compare the supplied `deviceInfo`/IP against stored
  `WhitelistItem`s; block or force-verify unknown devices; honor `exp`. The data model is *given to you* by
  the client struct; you just store and enforce it.
- **`email_alarm_notification`** — send an email when a login arrives from a new/blacklisted device. Pure
  server-side; the client only carries the boolean.

**Beats OSS:** neither OSS server populates `user.info.*` or enforces a device whitelist / new-device email
alarm. This is a concrete, well-specified security feature with zero client changes needed.

---

## 4. Two-factor authentication (account login)  ★★★★☆ (M)

Two **distinct** 2FA systems exist; don't conflate them:

**(a) Account-login 2FA** (this is the server's job). The login response/request vocabulary is already
defined client-side:
- `flutter/lib/common/hbbs/hbbs.dart:10-20` — request types `kAuthReqTypeEmailCode`, `kAuthReqTypeTfaCode`;
  response types `kAuthResTypeToken`, `kAuthResTypeEmailCheck`, `kAuthResTypeTfaCheck`.
- `hbbs.dart:133-178` — `LoginRequest` carries `verificationCode`, `tfaCode`, `secret`, `type`.
- `account.rs:99-108` — `AuthBody { access_token, type, tfa_type, secret, user }`.

Flow: `/api/login` returns `tfa_type:"tfa_check"` + `secret` → client prompts for TOTP → re-POSTs with
`type:"tfa_code"`, `tfaCode`, `secret` → server verifies → returns `access_token`. The server stores the
per-user TOTP secret (and, for parity with Pro, 6 single-use backup codes + a configurable secret expiry,
e.g. 180 days).

**(b) Connection 2FA** (`src/auth_2fa.rs`) is *client-local* and runs at incoming-connection time — the
controlled machine prompts the controller for a TOTP/Telegram code. This is **not** something the server
implements, but it reveals two reusable building blocks the admin server can offer:
- **TOTP**: SHA1, 6 digits, 30 s step, issuer `RustDesk Connection` (`auth_2fa.rs:17-40`). Same algorithm
  the server should use for account 2FA — implementations are interchangeable.
- **Telegram-bot 2FA** (`auth_2fa.rs:114-204`): the client resolves a `chat_id` from a bot token via
  `getUpdates` and sends codes via `sendMessage` to `https://api.telegram.org/bot<token>/...`. A Pro-grade
  admin server can offer the **same** Telegram channel for login alarms / 2FA codes, reusing this exact API
  shape. (Stored as `{ token (encrypted), chat_id }`.)

**Server implements:** per-user TOTP enrollment + verify, optional Telegram/email code delivery, backup
codes, and the `tfa_type`/`secret`/`tfaCode` round-trip in `/api/login`.

---

## 5. Session recording upload + server-side playback  ★★★★☆ (L)

**What the client does.** When **"Recording Session"** is enabled (a Control-Role permission distinct from
the `enable-record-session` toggle) the client streams the recording to the server in chunks. Verified
contract (`D:\git\rustdesk\src\hbbs_http\record_upload.rs`):

| Phase | Method+query | Body | Code |
|---|---|---|---|
| start | `POST /api/record?type=new&file=<name>` | empty | `:117-133` |
| chunk | `POST /api/record?type=part&file=<name>&offset=<n>&length=<m>` | bytes `[offset, offset+len)` | `:135-175` |
| finish | `POST /api/record?type=tail&file=<name>&offset=0&length=<headerLen>` | first ≤1024 header bytes | `:177-202` |
| abort | `POST /api/record?type=remove&file=<name>` | empty | `:204-210` |

Triggers: ≥1 s elapsed **or** ≥1 MiB pending (`:16-17,136-146`). Success ⇒ `{}`; any `{"error": ...}` aborts
the whole upload (`:106-114`). The endpoint is unauthenticated by token here — it uses the configured
`api-server` (`:29-34`); the server must associate uploads with a device via the filename/connection
metadata, or add a token. The tail-rewrite (header written last) means the server must **patch the file
header at `offset 0` after the body** to produce a playable file.

**Server implements:** a fail-closed `/api/record` ingestion endpoint (append chunks to per-file storage,
apply the tail header), a recordings table, bounded storage, and a **playback UI**. Because the stock
uploader sends no account token, uploads are disabled by default and must be authorized through an
explicit source-IP/CIDR allowlist; a dedicated secret header is also available to trusted proxies/custom
clients. Active uploads are source-bound and append-only, filenames are created exclusively, abort cannot
delete completed recordings, and finite chunk/file/global/file-count limits plus per-source throttles
contain storage abuse. This is one of the most-requested Pro features; no OSS server has it.

---

## 6. "Deployment helper" — config-string / renamed-installer generator  ★★★★☆ (S)

**What the client does.** `rustdesk.exe --config <string|filename>` and the click-to-install path both feed
`custom_server::get_custom_server_from_string`, which extracts `host`, `key`, `api`, `relay` from either:
1. an **encoded/signed config string** (reversed + URL-safe base64, optionally Ed25519-signed with the
   embedded RustDesk public key) — `D:\git\rustdesk\src\custom_server.rs:21-37`; or
2. a **renamed installer filename** of the form
   `rustdesk-host=server,key=...,api=...,relay=....exe` (comma-delimited, case-insensitive, tolerant of
   Windows `(1)` duplicate suffixes) — `custom_server.rs:39-108`.

`--config` then writes `key` / `custom-rendezvous-server` / `api-server` / `relay-server` into config
(`src/core_main.rs:496-520`).

**Server implements:** a one-click **"download configured installer / copy config string"** generator in the
admin UI. The server already knows its own host/key/api/relay, so it can:
- emit the comma-delimited filename (no signing needed — the plain JSON-or-filename path is accepted at
  `custom_server.rs:29-31` and `:59-87`), and/or
- emit the reversed-base64 config string to paste into `--config`.

Effort is tiny (string formatting) and it removes the #1 onboarding friction. Both OSS servers leave admins
to hand-craft this.

---

## 7. Shared address books, rules, max-peer quota, device groups  ★★★★☆ (M)

Beyond the basic `/api/ab` blob, the **Flutter** client speaks a granular, multi-address-book protocol with
sharing rules and quotas. Verified routes/fields:

- **Settings / quota:** `POST /api/ab/settings` → `{ "max_peer_one_ab": <int> }` (`ab_model.dart:233,251`).
  404 ⇒ client falls back to legacy single-AB mode (`:239-242`).
- **Personal AB discovery:** `POST /api/ab/personal` → `{ "guid": "..." }`; 404 ⇒ legacy mode
  (`:265,271-284`).
- **Shared profiles:** `POST /api/ab/shared/profiles` (`:297`). Each `AbProfile { guid, name, owner, note,
  rule, info }` (`hbbs.dart:258-275`).
- **Sharing rules:** `ShareRule { read=1, readWrite=2, fullControl=3 }` (`hbbs.dart:210-256`); client gates
  add/edit/delete on the rule (`ab_model.dart:1394-1407`).
- **Licensed-device quota:** `licensed_devices` returned from the personal AB (`ab_model.dart:1030`,
  `:974`).
- **Granular peer/tag routes:** `/api/ab/peers`, `/api/ab/tags/<guid>`, `/api/ab/peer/add|update/<guid>`
  (`ab_model.dart:1433,1500,1551,1584,1610,1631`).

**Round-tripped peer fields** (`flutter/lib/models/peer_model.dart:8-68`): `id, hash` (personal-AB password
hash), `password` (shared-AB password), `username` (PC username), `hostname, platform, alias, tags[],
forceAlwaysRelay` (string `"true"`/`"false"`), `rdpPort, rdpUsername, loginName, device_group_name, note,
same_server`. Note the **two different secret fields**: `hash` for personal ABs vs `password` for shared
ABs — the server must store/return them per AB type.

**Server implements:** multiple address books per user, ownership + share rules (R/RW/Full), per-AB max-peer
and per-account licensed-device quotas, device groups, and faithful round-trip of every peer field above
(esp. `rdpPort`/`rdpUsername` for one-click RDP, and `same_server`). `lejianwen` has partial AB support;
the sharing-rule + quota + device-group layer is where Pro pulls ahead.

---

## 8. Strategy change-detection + forced disconnect / sysinfo refresh  ★★★★☆ (S)

The same heartbeat responder (#1) can also do **real-time control** — all client-honored, all optional:

- `D:\git\rustdesk\src\hbbs_http\sync.rs:251-255` — `disconnect: [conn_ids]` force-drops those live sessions
  (broadcast to the in-process kill channel). Admin "kick session" with no extra endpoint.
- `sync.rs:246-250` — `"sysinfo": true` makes the client clear its sysinfo hash and immediately re-upload
  (force a device-info refresh).
- `sync.rs:256-268` — `modified_at` is the change-detection handshake: bump it whenever a device's strategy
  changes and the client pulls within one heartbeat (≈3 s). The client persists it as `strategy_timestamp`.

**Server implements:** store a per-device "pending disconnect" + "force sysinfo" flag and a monotonic
strategy timestamp; emit them in the heartbeat reply. Trivial once #1 exists, and it delivers **remote
session kill** and **on-demand inventory refresh** — both Pro features.

---

## 9. `is_pro()` advertisement — unlock the client's Pro UI  ★★★☆☆ (S)

`D:\git\rustdesk\src\hbbs_http\sync.rs:181-219` and `:308-310`: the client flips an internal `PRO` flag to
**true** the moment `/api/sysinfo` answers `SYSINFO_UPDATED` *and* `/api/sysinfo_ver` responds. The flag is
also driven from `is_public(url)` (the client **skips** heartbeat/sysinfo entirely on `*.rustdesk.com`, so
self-host is mandatory — `sync.rs:276-285`, `common.rs`).

**Implication:** simply implementing the sysinfo/heartbeat contract correctly is *how a server advertises
"Pro-class" behavior* to the client. The server should: (a) answer `SYSINFO_UPDATED` only after actually
storing the device, (b) keep `/api/sysinfo_ver` consistent with the stored hash so the client can
short-circuit uploads, and (c) never present itself on a `*.rustdesk.com` host. Cheap, and it makes the
client's UI behave as if talking to Pro.

---

## 10. Address-book preset baking (`OPTION_PRESET_*`) via sysinfo & CLI  ★★★☆☆ (M)

The client uploads baked-in preset keys on `/api/sysinfo` when a custom client / `--assign` set them
(`D:\git\rustdesk\src\hbbs_http\sync.rs:135-178`). The full preset vocabulary
(`libs/hbb_common/src/config.rs:2937-2978`):

`preset-address-book-name / -tag / -alias / -password / -note`, `preset-device-username`,
`preset-device-name`, `preset-note`, `preset-user-name`, `preset-strategy-name`,
`preset-device-group-name`.

**Server implements:** when sysinfo (or `/api/devices/cli`, #2b) carries these, auto-create/assign the AB
entry, tag, device group, strategy, and displayed username/hostname — so a freshly-deployed device shows up
in the right place with a working saved password, with **zero** manual admin steps. This is the glue that
makes mass deployment (#2) actually self-organize.

---

## 11. `NOT_DEPLOYED` registration gating  ★★★☆☆ (M)

The client supports "a device may not register until approved." On the **rendezvous/hbbs (TCP)** path the
client honors `RegisterPkResponse::NOT_DEPLOYED` by backing off and surfacing a "needs deployment" state
(`D:\git\rustdesk\src\rendezvous_mediator.rs:44-88,354-365,751-775`), while `--deploy` against
`/api/devices/deploy` (#2a) is the approval action that clears it. The CLI/flags side already wires
`Config::no_register_device()` / `register-device` (`config.rs:2951`, `core_main.rs:538,643`).

**Server implements:** an "unapproved devices" queue + the deploy-approval table shared between the API
server and hbbs. The API server owns the approval record; hbbs reads it to decide OK vs NOT_DEPLOYED. (Needs
hbbs cooperation, hence M.) This is the "require deployment for new devices" Pro toggle.

---

## 12. Wake-on-LAN orchestration hint (`same_server`)  ★★☆☆☆ (S)

WoL itself is **client-side LAN broadcast** — the client sends a magic packet to a peer's MAC over the local
subnet (`D:\git\rustdesk\src\lan.rs:86-97`, `flutter_ffi.rs:2173-2176`, exposed in the AB context menu
`src/ui/ab.tis:332,433-434`). The server cannot wake a remote device directly.

**What the server *can* do:** the AB peer field `same_server` (`peer_model.dart:24,48,66`) tells the client
whether a peer is reachable on the same server/LAN context, which gates whether WoL/relay choices are
offered. By setting `same_server` correctly (and storing MAC/subnet in the sysinfo inventory), the server
makes the client's built-in WoL usable and can build a "wake via an online same-LAN agent" feature on top.
Low priority, but a differentiator once the inventory (#1/#10) exists.

---

## Cross-cutting notes

- **Everything here is server-only.** No client modification is required for any opportunity — the client
  already speaks all of these. The work is entirely in the API server + admin UI.
- **Dependency order.** #1 (strategy/heartbeat) and the sysinfo inventory are the foundation; #8, #9, #10,
  #11 build on them. #2 (deploy/assign) + #6 (config generator) form the "rollout" track. #3/#4 form the
  "account security" track. #5 (recording) and #7 (shared AB) are standalone flagship features.
- **Token model.** #2 uses revocable bearer **deploy tokens**. The stock recording uploader sends no
  bearer, so #5 remains fail-closed until an operator explicitly allowlists its source network; trusted
  proxies/custom clients can instead use the dedicated recording-upload header secret.
- **Soft vs hard settings.** Reiterating #1's nuance: heartbeat-pushed options are *soft* (user-changeable
  unless baked at install). Make the admin UI distinguish "policy (soft)" from "enforced (requires custom
  client)" so expectations match reality (`config.rs:76-82`, `src/ui/index.tis:325 is_option_fixed`).

### Key client source anchors (all read-only, repo `D:\git\rustdesk`)
- `src/hbbs_http/sync.rs` — heartbeat / sysinfo / strategy / disconnect.
- `src/hbbs_http/account.rs` — login `UserInfo`, whitelist, email settings, `AuthBody`.
- `src/hbbs_http/record_upload.rs` — `/api/record` chunked upload.
- `src/auth_2fa.rs` — TOTP + Telegram-bot 2FA building blocks.
- `src/core_main.rs` — `--config`, `--option`, `--assign`, `--deploy` dispatch.
- `src/ui_interface.rs` — `deploy_device`, `DeployResult`.
- `src/custom_server.rs` — config-string / renamed-installer parsing.
- `src/rendezvous_mediator.rs` — `NOT_DEPLOYED` / needs-deploy gating.
- `src/lan.rs` — Wake-on-LAN magic packet.
- `libs/hbb_common/src/config.rs` (`keys` module, `:2854-3027`) — full pushable option vocabulary.
- `flutter/lib/common/hbbs/hbbs.dart`, `flutter/lib/models/peer_model.dart`,
  `flutter/lib/models/ab_model.dart` — login/2FA types, AB peer fields, granular AB routes & rules.
