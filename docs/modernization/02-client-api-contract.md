# 02 · The RustDesk Client HTTP API Contract

This is the **implementation spec**: the endpoints, JSON shapes, and behaviors the RustDesk
client (the `rustdesk` Rust repo) actually speaks to its API server. If we want a feature to
"just work" in stock clients, we build the server to match what is written here.

**Verification legend** — ✅ read directly from client source during this dive ·
🔎 reported from client source by research agent (cross‑checked against existing routes).

Client source anchors:
- `src/hbbs_http/sync.rs` — heartbeat, sysinfo, strategy ✅
- `src/hbbs_http/account.rs` — login/OIDC, auth body, 2FA fields ✅
- `src/hbbs_http/record_upload.rs` — session recording upload ✅
- `src/auth_2fa.rs` — TOTP/2FA ✅
- `libs/hbb_common/src/config.rs` (`keys` module) — `OPTION_PRESET_*` 🔎
- `src/ui_interface.rs`, `src/core_main.rs` — device deploy / CLI assign 🔎
- `src/common.rs` — `get_api_server`, `is_public`, `is_pro` ✅

---

## 1. Heartbeat & Strategy push ✅ — `POST /api/heartbeat`

Sent every ~3s base interval; full heartbeat at least every 15s, and immediately while
connections are active. `sync.rs:86‑274`.

**Request body:**
```json
{
  "id":   "<device id>",
  "uuid": "<base64 uuid>",
  "ver":  123456,                 // numeric version
  "conns": [12, 34],              // active connection ids (omitted if none)
  "modified_at": 1700000000       // client's last-known strategy timestamp
}
```

**Response — every field is optional and acted upon by the client:**
```json
{
  "sysinfo": true,                // force the client to re-POST /api/sysinfo
  "disconnect": [12],             // connection ids the client must drop
  "modified_at": 1700000123,      // server strategy timestamp; if newer, client stores it
  "strategy": {
    "config_options": { "<key>": "<value>", ... },   // pushed into client config
    "extra":          { "<key>": "<value>", ... }
  }
}
```

**Behavioral rules the server must honor:**
- The client tracks `strategy_timestamp` locally. It sends it as `modified_at`. When the
  server returns a **different** `modified_at`, the client adopts the new value and applies
  any `strategy.config_options`. This is the change‑detection handshake — a strategy edit
  must bump the timestamp so clients pull within one heartbeat (Pro propagates in ≤30s).
- `config_options` are merged into client settings. Empty value + empty default ⇒ option is
  removed (falls back to built‑in default); otherwise the (possibly empty) value is set.
  See `handle_config_options` `sync.rs:287`.
- Returning `disconnect: [ids]` force‑drops those sessions on the client.
- Returning `"sysinfo": true` makes the client clear its sysinfo hash and re‑upload.
- Existing devices must present the exact stored non-empty `uuid` for their `id`. A mismatch
  receives `{}` without inventory mutation, Strategy disclosure, or consumption of queued
  disconnects. Unknown devices fail closed by default and receive no commands or policy.
  Token deployment/approval is the normal enrollment path.
- **Implemented:** the server records liveness, resolves the effective Strategy, pushes changed
  options/timestamps, and delivers queued disconnect commands after the identity checks above.

---

## 2. System info & device registration ✅ — `POST /api/sysinfo`, `POST /api/sysinfo_ver`

`sync.rs:125‑230`. The client uploads a sysinfo document and skips re‑upload while a hash
matches; `/api/sysinfo_ver` lets it confirm the server still has the same version.

**`/api/sysinfo` body** (core device fields + optional presets):
```json
{
  "id": "...", "uuid": "...", "version": "...",
  "cpu": "...", "hostname": "...", "memory": "...", "os": "...", "username": "...",

  // OPTION_PRESET_* — present only when baked into the client (custom client / --assign):
  "address_book_name": "...",
  "address_book_tag": "...",
  "address_book_alias": "...",
  "address_book_password": "...",
  "address_book_note": "...",
  "username": "...",            // preset login username (OPTION_PRESET_USERNAME)
  "strategy_name": "...",       // assign device to a named strategy
  "device_group_name": "...",   // assign device to a device group
  "device_username": "...",     // override displayed username
  "device_name": "...",         // override hostname
  "note": "..."
}
```

**Responses the client understands:**
- `"SYSINFO_UPDATED"` — accepted/stored.
- `"ID_NOT_FOUND"` — device unknown ⇒ client retries on next heartbeat. **This is the hook
  for "require deployment for new devices"**: a Pro server returns this until the device is
  approved/deployed.

**`/api/sysinfo_ver`** returns an opaque version string used to short‑circuit uploads.

The server stores core fields and applies recognized preset keys only after the request's
`id`/`uuid` pair matches an approved device. **Since v1.7.0 presets are an enrollment-time
action:** they are honoured only on the device's first accepted sysinfo upload
(`devices.presets_applied_at` closes the window), they only fill blanks (an existing direct
strategy, a non-default device group, or an admin-set name/note is never overwritten), and
`strategy_name` / `device_group_name` are matched against **existing** rows only — unknown names
are ignored and no device group is ever created from device input. The response is unchanged
(`SYSINFO_UPDATED`). To re-file an enrolled device, use the console or `rustdesk --assign`
(`/api/devices/cli`, deploy-token authenticated). A mismatched, unknown, or unapproved device receives
`ID_NOT_FOUND` and cannot change inventory, assignments, groups, or address books. Legacy
first-seen registration requires the explicit combination
`RUSTDESK_REQUIRE_DEPLOYMENT=false` + `RUSTDESK_AUTO_REGISTER=true`; it retains first-caller trust
but is bounded by per-IP and global one-minute limits plus a total-device quota (defaults 30,
100, and 5,000). Rejected attempts create no device/group/address-book/webhook side effects.

The set of `OPTION_PRESET_*` keys (from `hbb_common` `keys`): preset address‑book
name/tag/alias/password/note, preset username, preset strategy name, preset device‑group
name, preset device username, preset device name, preset note. These are produced by the
**Custom Client Generator** and by `rustdesk.exe --assign` (see catalog §13).

---

## 3. Account login & OIDC ✅ — `POST /api/login`, `POST /api/oidc/auth`, `GET /api/oidc/auth-query`

### 3a. OIDC device flow (`account.rs:160‑320`)
1. `POST /api/oidc/auth`
   ```json
   { "op": "<provider>", "id": "...", "uuid": "...",
     "deviceInfo": { "os": "...", "type": "client|browser", "name": "..." } }
   ```
   → `{ "code": "...", "url": "<provider auth url>" }`
2. Client opens `url`, then polls `GET /api/oidc/auth-query?code=&id=&uuid=` (1s, ≤3min).
   While pending the server returns an error containing `"No authed oidc is found"`.
   On success it returns an **AuthBody** (below). All routes already exist in this repo.

The poll code is a bearer-like secret and is never written to logs. A successful poll must also
match the exact device `id` and `uuid` stored when the flow began. The AuthBody may be delivered
twice within a 15-second retry window for a dropped response; it is then erased from the pending
session and further polls return the normal pending error, limiting token replay without changing
the client response shape.

**Approval step (server-side, since v1.7.0; invisible to the client).** The provider redirects the
browser to `/api/oauth/callback` (alias `/api/oidc/callback`). The server resolves the account but
issues **no token yet**: it renders an approval page showing the requesting device's reported
name/OS, RustDesk id, the source IP of the `POST /api/oidc/auth` call and its time. Only
`POST /api/oidc/confirm` (form fields `state`, `nonce`, `decision=approve|deny`, plus the
`rd_oidc_approval` HttpOnly SameSite=Strict cookie set with the page) issues the token and makes the
AuthBody available to the poll. Until then the poll keeps returning the pending error, so the stock
client needs no change. This closes device-login consent phishing (an attacker's own `code`/`url`
sent to a victim). The pending row is keyed by `sha256(code)` and the stored AuthBody is encrypted
with the application key. `POST /api/oidc/auth` is throttled per source (20/min, IPv6 per /64), and
the runtime Nginx access log records `/api/oidc/auth-query` and the callback without their query
strings.

### 3b. AuthBody / UserPayload — the shape every login must return
```json
{
  "access_token": "...",
  "type": "access_token",
  "tfa_type": "",            // "" on success; challenge responses use email_check/tfa_check
  "secret": "",              // opaque login-challenge secret when a second step is required
  "user": {
    "name": "...",
    "display_name": "...",
    "avatar": "...",
    "email": "...",
    "note": "...",
    "status": 1,             // 1 Normal | 0 Disabled | -1 Unverified
    "is_admin": false,
    "third_auth_type": "",   // provider if logged in via SSO
    "info": {
      "email_verification": false,
      "email_alarm_notification": false,
      "login_device_whitelist": [
        { "data": "<ip|uuid>", "info": {"os":"","type":"","name":""}, "exp": 0 }
      ]
    }
  }
}
```

> The server populates `tfa_type`, a per-attempt `secret`, and `user.info.*` for the implemented
> email/TOTP negotiation paths. A challenge is not a completed AuthBody and therefore has no
> `access_token`; its exact intermediate shape is specified below.

---

## 4. Two‑factor authentication ✅ — `src/auth_2fa.rs` + login flow

- **TOTP:** SHA1, 6 digits, 30s step. After validating the password, the server returns
  `{type:"email_check", tfa_type:"tfa_check", secret, user}`. The stock Flutter client then
  re-posts `type:"email_code"` with equal `verificationCode` and `tfaCode` values plus the
  opaque `secret`; it does not repeat the password.
- **Email verification:** after validating the password, the server returns
  `{type:"email_check", tfa_type:"email_check", secret, user}`. The stock Flutter client switches
  on `type`, uses `user.name` as the second request's username, and echoes the opaque `secret`.
- The stock email second step is
  `{type:"email_code", username, id, uuid, verificationCode, secret, deviceInfo}`. All of
  `username`, `id`, `uuid`, and `secret` must match the issued challenge; password is intentionally
  omitted because the first factor has already succeeded.
- Each email challenge is bound to one user + RustDesk id + UUID, expires after five minutes, and
  is consumed on success. Only SHA-256 of the 64-character random challenge and a password hash of
  the six-digit code are persisted. Five wrong codes disable that challenge under a row lock, so
  its attempt budget remains effective even when source IPs rotate. A new challenge for the same
  user/device supersedes the previous one.
- TOTP uses the same user/id/UUID binding, five-minute lifetime, row lock, five-guess budget,
  supersession, and single-use consumption. Only the opaque challenge hash is stored; a valid
  live TOTP without a challenge cannot skip the password first step. Custom clients that submit
  the password and `tfaCode` together remain supported.
- Pro detail (for parity): TOTP enrollment yields 6 single‑use backup codes; 2FA secret
  default expiry 180 days; enabling TOTP supersedes email verification.

---

## 5. Session recording upload ✅ — `POST /api/record`

`record_upload.rs`. Chunked upload driven by query params; body is raw bytes.

| Phase | Query | Body |
|-------|-------|------|
| start | `?type=new&file=<name>` | empty |
| chunk | `?type=part&file=<name>&offset=<n>&length=<m>` | bytes `[offset, offset+length)` |
| finish| `?type=tail&file=<name>&offset=0&length=<headerLen>` | first ≤1024 header bytes |
| abort | `?type=remove&file=<name>` | empty |

Response: `{}` on success, `{ "error": "<msg>" }` on failure (any error aborts the upload).
Note the in‑session **"Recording Session"** permission is a separate Control‑Role concept
(catalog §5).

The stock uploader sends no account token or device identity. The API therefore keeps this
route disabled by default (`RUSTDESK_RECORDING_UPLOAD_ENABLED=false`) and still requires one
explicit authorization path when enabled:

- source IP/CIDR allowlisting supports the unmodified stock client; or
- a 32+ character secret in `Authorization: Bearer` / `X-Recording-Token` supports a custom
  client or a trusted proxy that strips inbound copies before injecting the header.

The source address that starts an upload owns its remaining phases. `new` uses exclusive
creation and never truncates or reuses a filename; `part` requires an exact body length and the
current end-of-file offset; `tail` can only patch offset zero with at most 1024 bytes; and
`remove` can delete only an unfinished upload from the same source. Finished recordings cannot
be removed through the client route. Per-source request/active-upload limits and finite defaults
of 8 MiB/chunk, 2 GiB/file, 10 GiB total, and 5,000 tracked files bound storage consumption.
Every rejection, including HTTP 403/413/429, retains the required JSON `error` key so the client
aborts instead of mistaking a rejected chunk for success. Deployment settings are documented in
`.env.example` and `config/recordings.php`.

---

## 6. Address book ✅ (routes) / 🔎 (legacy shapes)

The implemented client routes in this repo are the canonical target: `GET/POST /api/ab`
plus the personal set (`/api/ab/personal`, `/api/ab/settings`, `/api/ab/shared/profiles`,
`/api/ab/peers`, `/api/ab/tags/:guid`, `/api/ab/peer/*`, `/api/ab/tag/*`). Older Sciter
clients used `POST /api/ab/get` + `POST /api/ab` with a single `{ "data": "<json string>" }`
blob (tags + peers); newer Flutter clients use the granular per‑collection routes. Keep both
working; the granular set is the future. The notable peer fields the client round‑trips:
`id, username, hostname, platform, alias, tags, forceAlwaysRelay, rdpPort, rdpUsername,
loginName, password/hash`.

---

## 7. Device deployment & CLI assignment ✅ — `POST /api/devices/deploy`, `POST /api/devices/cli`

Reported from `src/ui_interface.rs` and `src/core_main.rs`; these back `rustdesk.exe
--deploy` / `--assign`. **Both implemented** (`DevicesController` + `DeploymentService`).

- `POST /api/devices/deploy` — `{ id, uuid, pk }` with a deployment **token**. Returns a JSON
  object `{"result": …}` whose value is one of `OK | NOT_ENABLED | INVALID_INPUT | ID_TAKEN`
  (the client JSON-parses the body and reads `result` — see
  [16-response-contract.md](16-response-contract.md) §4). Used when "Require deployment for new
  devices" is on.
- `POST /api/devices/cli` — `{ id, uuid, user_name?, strategy_name?, device_group_name?,
  address_book_name?, address_book_tag?, address_book_alias?, address_book_password?,
  address_book_note?, note?, device_username?, device_name? }` with a token (note: `--assign`
  sends **no `pk`**). Registers/locates the device and applies the named presets — owner
  (`user_name`, else the token owner), strategy, device group (created on demand), identity, and
  address-book filing. Returns an **empty** 200 on success (the client prints "Done!") or a
  plain-text reason it prints verbatim. No other OSS server implements this.

Deployment tokens are continuously authorization-bound: their owner must remain active and
retain `deploy.edit`. CLI assignment requires a non-empty UUID and an exact match before an
existing device can be changed. A delegated deployment operator may assign devices only to the
token owner; the optional cross-user `user_name` override is reserved for a full administrator.

---

## 8. Audit ingestion ✅ — `POST /api/audit/conn`, `/file`, `/alarm`

Already implemented. hbbs/clients post connection (`new`/`close`) and file‑transfer events.
For parity with Pro's **alarm** logs and **console‑operation** logs we add new categories
on top of this ingestion path (see catalog §15).

### Audit-ingestion trust boundary

These routes remain unauthenticated at the HTTP layer because the RustDesk fire-and-forget
writers do not send an account bearer. The current client does, however, add `id` and `uuid` to
all three event shapes in `connection.rs` (`post_conn_audit`, `post_file_audit`, and
`post_alarm_audit`). The API requires an exact match to an existing approved device before an
event can create an audit row, raise an alarm, send mail, or dispatch a webhook.

Rejected identity, invalid fields, oversized bodies, and rate-limited events still receive the
wire-compatible `{}` acknowledgement they have always received; they have no side effects.
**A stored event is different since client 1.5.0 — see §8.2: the success response is 200 with an
empty body.** Connection and alarm bodies are capped at 16 KiB; file bodies at 64 KiB. Default
one-minute ceilings are 300 invalid events/source IP, 12,000 valid events/source IP, and 240
connection, 1,200 file, or 60 alarm events/device. The corresponding `RUSTDESK_AUDIT_*`
environment settings are documented in `.env.example`.

`session_id` is a random JSON `u64`; ingestion decodes it with big-integer preservation before
storing the exact decimal value. The host's post-auth connection event intentionally omits
`action`, so omission continues to mean `new`.

The legacy controlling-side note POST (`{id,session_id,note}`) does **not** contain a UUID and
therefore cannot satisfy this device binding; it now acknowledges without mutating a session.
The application also supports the account-bearer-authenticated `GET /api/audit/conn/active`
followed by `PUT /api/audit` with the server-issued guid. Both operations independently require
the authenticated account to have owner, delegated group, or administrator access to the target
device; a known guid alone is not write authority. Denied lookups return the normal empty string
and denied updates retain the normal `{}` no-op. A client still using the exact legacy POST will
not persist a note unless upstream adds an attributable credential. A compatibility caller may
add the exact device UUID, in which case the update is additionally scoped to `peer_id +
session_id`.

### 8.1 RustDesk 1.4.9 auth‑detail additions (PR #15456, #15407, #15469) ✅

RustDesk **1.4.9** (released 2026‑07‑06) enriched the connection‑audit payload the controlled
client POSTs to `/api/audit/conn` with three **optional** keys. All three are absent for
pre‑1.4.9 clients and on `close` events, so the ingest stays backward‑compatible; we persist
them on `audit_conns` (migration `2026_07_11_100001`) and surface them in the admin
Connection Logs view + CSV.

| Wire key | Type | Source | Meaning (values) |
|----------|------|--------|------------------|
| `primary_auth` | int enum, optional (omitted when `None`/0) | PR #15456, `connection.rs` `send_logon_response_and_keep_alive()` → `audit["primary_auth"]` | First‑factor method: `1`=Click (host accepted, no password), `2`=TemporaryPassword (one‑time), `3`=PermanentPassword (stored/preset), `4`=SwitchSides (account side‑switch). |
| `two_factor` | int enum, optional (omitted when `None`/0) | PR #15456, `audit["two_factor"]` | Second factor: `1`=TOTP, `2`=TrustedDevice (2FA bypassed via a trusted device). |
| `conn_audit_ref` | string, optional | PR #15407, `audit["conn_audit_ref"]` | Opaque **controller‑user attribution** token minted by hbbs and echoed back by the controlled peer. We store it verbatim; resolving it to a user account additionally needs Pro **hbbs**-side work (token generation + identity cache), out of scope for the API server, which per the PR must at minimum accept/store it. Never sent for direct‑IP connections. |

Wire‑format notes (per the OIDC guardrails in `16-response-contract.md`): both integer keys
arrive as **integers, not strings**, and are **omitted** rather than sent as `0`/`null`, so we
keep them nullable and distinguish "not recorded" from an explicit value. Out‑of‑range codes
are treated defensively (no label rendered, raw value still stored).

PR **#15469** adds a new `AlarmAuditType` variant **`SessionScopeViolation = 9`** posted through
the standard `/api/audit/alarm` wrapper (`{id, uuid, typ, info}`) when an authenticated session
attempts an action outside its granted scope. The event-specific detail is serialized inside
`info`. Our alarm ingest accepts the wire enum range and adds a human label for code `9`
("Session‑scope permission violation").

**Not affected by 1.4.9:** login/2FA/OIDC endpoints, heartbeat/strategy, address book,
sysinfo, devices/deploy, and recording are all unchanged — audit ingestion is the only
surface the 1.4.9 client→API contract touched. (`#15524 "remove feature cli"` removes the
client's Cargo build feature, **not** the `/api/devices/cli` endpoint.)

### 8.2 RustDesk 1.5.0 audit retry + nonce contract (PR #15759) ✅

RustDesk **1.5.0** (PR #15759, "audit retry nonce") turned the three fires on this path into
retried, deduplicated requests. Read from rustdesk master `a0c0963c`
(`src/server/connection.rs`):

- every body now carries **`nonce`**, a fresh UUID v4 per record and per endpoint
  (`:1605` conn, `:1655` file, `:1677` alarm);
- success is a **2xx whose body is empty** — `if text.trim().is_empty() { return Ok(text); }`
  (`:1746`). Any other non-empty 2xx body is treated as *not stored* and retried, and the client
  specifically recognises a `{"error": …}` body as a retryable server-side failure;
- retries on transport errors, on 5xx/408/429, and on the non-empty-2xx case above; backoff
  **10 s then 30 s**, `RETRY_DEADLINE` **120 s** (`:1726-1728`);
- the client states the server half of the contract in the same comment block: "the api server
  only remembers a record's nonce for five minutes" and "the server releases the record's nonce
  when its write fails".

Our side (migration `2026_11_24_100001_add_client_nonce_to_audit_tables`):

| Aspect | Behaviour |
|--------|-----------|
| Success body | **200, empty** (`Content-Length: 0`) from `/api/audit/conn`, `/file`, `/alarm`. Pre‑1.5.0 clients are unaffected: at tag `1.4.9` `post_audit_async()` is `crate::post_request(url, v.to_string(), "")` behind `allow_err!`, i.e. the body and the status are discarded. |
| Rejection body | Unchanged `{}` for failed device binding, invalid fields, oversized bodies, and rate limits — no side effects, no new information for an unauthenticated caller. |
| Write failure | 200 `{"error":"Audit record could not be stored"}` — generic text, no internals. The client retries this shape exactly as it retries 5xx, and the failed write's transaction rolled back, so the retry stores the record. Chosen over 5xx so one failed insert is not reported as an upstream outage by proxies/monitors in front of us (and so a proxy cannot substitute its own error page for the body). |
| `nonce` | Optional string, ≤ 64 chars, loosely validated (`[A-Za-z0-9._-]{8,64}` — a UUID v4 today). Garbage is dropped as a normal validation rejection. Stored as `client_nonce` on `audit_conns`, `audit_files`, and `alarms`. |
| Duplicate nonce | The same device re-sending the same nonce answers the same empty 200, creates no second row, and fires **no** alarm, mail, notification, or webhook. |
| Uniqueness scope | `unique(peer_id, client_nonce)` on `audit_conns`/`alarms` and `unique(from_peer, client_nonce)` on `audit_files` — scoped to the reporting device, so one device can never suppress another device's record. A global nonce-only index plus a "same device?" check would have added an error branch for a case that cannot occur (a UUID v4 minted on another host is unguessable) and would let a rejected cross-device collision look like an authorization failure. |
| Ordering | Device identity binding and every rate limit run **before** the dedupe write, so a duplicate cannot be used as an oracle across devices: a wrong `id`/`uuid` is rejected identically whether or not the nonce exists. |
| Rate-limit headroom | Unchanged. A retried duplicate consumes one more slot of the per-device and per-source-IP budgets, which is deliberate (the limits must run before dedupe). Headroom: with success now being an empty body, the 10 s/30 s retry only happens after a real failure — worst case a device's 240 conn/1,200 file/60 alarm events per minute become 3× that many requests for 120 s, far inside 12,000 valid events/source IP/min. No default needs changing; raise `RUSTDESK_AUDIT_*` only for a deployment with many devices behind one NAT address. |
| Requests without a nonce | Pre‑1.5.0 behaviour exactly: nothing to dedupe, and `NULL` values are not constrained by a unique index, so two identical events stay two events. |
| Alarm type 10 | `AlarmAuditType::IdWhitelist = 10` (`a0c0963c:src/server/connection.rs:6697`, posted from `:1507` as `{id, ip, name}`) renders as "ID whitelist blocked connection". |
| `conn_audit_ref` on alarms | Since 1.5.0 the `IpWhitelist` and `IdWhitelist` alarms additionally carry the same controller-attribution token as the connection feed (`:1678-1684`), so `/api/audit/alarm` accepts it and stores it in `alarms.conn_audit_ref` (nullable). |

**Not part of this contract:** `PUT /api/audit` (operator note) is not retried and sends no
nonce — the Flutter client only checks `statusCode == 200`
(`flutter/lib/common/widgets/dialog.dart:1668` at `a0c0963c`), so it keeps its `{}` success body.

---

## 9. Server resolution & "Pro" detection ✅ — `src/common.rs`, `sync.rs:308`

- `get_api_server`: explicit `api-server` → else derive from `custom-rendezvous-server`
  (port −2) → else `https://admin.rustdesk.com`.
- `is_public(url)`: true for `*.rustdesk.com`. The client **skips** heartbeat/sysinfo when
  the API is public, so self‑host is required for any of this.
- `is_pro()`: the client flips an internal `PRO` flag to **true** when `/api/sysinfo`
  returns `SYSINFO_UPDATED` and `/api/sysinfo_ver` responds. Implication: behaving like the
  sysinfo/heartbeat contract above is literally how a server advertises "Pro‑class"
  behavior to the client UI.

---

## 10. Endpoint summary (client → server)

| Endpoint | Method | Auth | Implemented here? |
|----------|--------|------|-------------------|
| `/api/login-options` | GET | no | ✅ |
| `/api/login` | POST | no | ✅ (password + email/TOTP negotiation) |
| `/api/oidc/auth` | POST | no | ✅ |
| `/api/oidc/auth-query` | GET | no | ✅ |
| `/api/logout` | POST | yes | ✅ |
| `/api/currentUser` · `/api/user/info` | POST/GET | yes | ✅ |
| `/api/heartbeat` | POST | no | ⚠️ stub (no strategy/disconnect) |
| `/api/sysinfo` | POST | no | ⚠️ no presets / no gating |
| `/api/sysinfo_ver` | POST | no | ✅ |
| `/api/ab*` | GET/POST/PUT/DELETE | yes | ✅ |
| `/api/audit/conn` · `/api/audit/file` | POST | no | ✅ |
| `/api/record` | POST | explicit IP/CIDR or upload token; disabled by default | ✅ |
| `/api/devices/deploy` · `/api/devices/cli` | POST | token | ❌ |
