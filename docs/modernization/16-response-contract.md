# 16 · Authoritative Response Contract & Mismatch Audit

**Purpose.** A per-endpoint *response-format* contract for every HTTP endpoint the RustDesk
client calls, comparing (a) what the client **expects** (object vs array, required keys/types,
how errors are signalled, what "empty success" must look like), (b) what the lantongxue OSS Go
server (`rustdesk-api-server-pro`) returns, and (c) what **our** Laravel server returns — flagging
every mismatch that can break the client.

All client citations are `file:line` from `D:\git\rustdesk` (commit in working tree). lantongxue
citations are from `D:\git\rustdesk-api-server-pro\backend`. Ours are from `D:\git\rustdesk-api`.

> **Resolution status.** The audit below was originally read-only; the P1/P1/P2 findings it
> surfaced have since been **fixed and tested** — see [§7 Resolution log](#7-resolution-log) for
> exactly what changed. The mismatch table reflects the pre-fix state; §7 records the after.

---

## 0. How the client decides "success vs error vs broken shape"

There are **four distinct parsers** in the client. Knowing which one an endpoint uses is the
whole game, because each one chokes on a *different* wrong shape.

### 0.1 Rust `HbbHttpResponse::parse` — used by OIDC + the Rust-side login path
`src/hbbs_http.rs:24-40`.
```rust
let map = serde_json::from_str::<Map<String, Value>>(body)?;   // (A) MUST be a JSON object
if let Some(error) = map.get("error") {                        // (B) "error" key ⇒ Error
    if error.as_str() ... Error(str) else ErrorFormat
} else {
    serde_json::from_value::<T>(...)  // (C) else deserialize into the typed struct (T)
}
```
- **(A)** A top-level JSON **array `[]` fails immediately** — `from_str::<Map>` errors → the whole
  `parse()` returns `Err`. A bare `null`, `""`, number, or string also fails here.
- **(B)** Any object with an `error` string key is treated as an error (even HTTP 200).
- **(C)** Success bodies are deserialized into a typed Rust struct. **Missing required fields fail.**
  The key landmine: `AuthBody.user: UserPayload` and `UserPayload.info: UserInfo` are **required**
  (no `#[serde(default)]`) — `account.rs:92,107`. A login/auth-query body that omits `user` or
  `user.info` parses as `DataTypeFormat` (silently dropped), not `Data`.

### 0.2 Flutter `_jsonDecodeRespMap` — body MUST be a JSON object `{}`
`ab_model.dart:1976-1987`, `group_model.dart:284-295` (identical). Calls `jsonDecode(body)` and
**assigns the result to `Map<String,dynamic>`**. If the body is a JSON **array `[]`**, the implicit
cast `Map<String,dynamic> json = jsonDecode(...)` throws `TypeError` → caught as a pull error.
Used by: `/api/ab/settings`, `/api/ab/personal`, `/api/ab/shared/profiles`, `/api/ab/peers`,
`/api/device-group/accessible`, `/api/users`, `/api/peers`.

### 0.3 Flutter `_jsonDecodeRespList` — body MUST be a JSON array `[]`
`ab_model.dart:1989-2000`. Assigns to `List<dynamic>`. If the body is an **object `{}`**, throws.
Used by **exactly one** endpoint: `POST /api/ab/tags/{guid}` (`_fetchTags`, `ab_model.dart:1515`).

### 0.4 Flutter `_jsonDecodeActionResp` — mutation responses
`ab_model.dart`. ⚠️ **CORRECTION (this doc previously got this wrong, which caused a real bug).**
Success is **`statusCode == 200 && resp.body.isEmpty`** — an **EMPTY body**, nothing else. The code:
```dart
if (resp.statusCode == 200 && resp.body.isEmpty) { /* ok */ }
else {
  try { errMsg = jsonDecode(resp.body)['error'].toString(); } catch (_) {}
  ... if (errMsg.isEmpty) { ...; if (resp.body.isNotEmpty) errMsg += resp.body; ... }
}
```
- A `{}` body does **NOT** pass: `jsonDecode("{}")['error']` is `null`, and `null.toString()` is the
  string **`"null"`** (it does **not** throw, so the `catch` never fires) → `errMsg = "null"` → shown
  as the error.
- A `[]` body does **NOT** pass either: `[]['error']` throws → caught → `errMsg` empty → then the
  `resp.body.isNotEmpty` branch appends the raw body → `errMsg = "[]"` → shown.
- **Only an empty 200 is success.** Errors must be `{"error":"..."}` (non-empty `error` string).
Used by all granular AB peer/tag **mutations** (`add/update/delete/rename`). Our `ack()` returns
`response('', 200)`; errors stay `{"error":...}`.

### 0.5 Endpoints the client does NOT parse the body of at all
- **`/api/heartbeat`** — Rust `serde_json::from_str::<HashMap<&str, Value>>` (`sync.rs:245`). Body
  MUST be a JSON **object**; an array makes the `from_str` fail and the whole response is ignored
  (`if let Ok(...)`). No keys are required; each key is independently `remove()`d.
- **`/api/sysinfo`, `/api/sysinfo_ver`** — compared as **plain strings** (`sync.rs:210-220`):
  `"SYSINFO_UPDATED"`, `"ID_NOT_FOUND"`, or the opaque ver string. Must NOT be JSON-wrapped.
- **`/api/audit/conn|file|alarm`** — **fire-and-forget**. `post_conn_audit`/`post_file_audit`/
  `post_alarm_audit` (`server/connection.rs:1357-1438`) send via `post_audit_async` and ignore the
  response entirely. **Response shape is irrelevant** — `[]`, `{}`, anything works.
- **`PUT /api/audit`** (audit *note*) — `dialog.dart:1664-1690` only checks `statusCode == 200`.
- **`/api/devices/deploy`** — parsed as JSON then reads `parsed["result"].as_str()`
  (`ui_interface.rs:1074`). Tolerant of unknown/missing → falls through. Needs an object with a
  string `result`.
- **`/api/record`** — `resp.json::<Map<String, Value>>()` then bails iff `m.get("error")` exists
  (`record_upload.rs:106-110`). If the body is **not** a JSON object the `.json::<Map>()` errors and
  the result is **ignored = treated as success**. So `{}` is correct; `[]` happens to be harmless
  (parse fails → treated as ok) but `{}` is the contract.

---

## 1. Per-endpoint contract & mismatch table

Legend for "Client parser": **R-obj** = §0.1 (Rust map, array breaks) · **R-hash** = §0.5 heartbeat ·
**F-map** = §0.2 (object required) · **F-list** = §0.3 (array required) · **F-action** = §0.4
(tolerant) · **str** = plain text · **none** = body ignored.

| # | Endpoint | Method | Client parser | Client expects (shape) | lantongxue returns | OURS returns | Mismatch | Fix |
|---|----------|--------|---------------|------------------------|--------------------|--------------|----------|-----|
| 1 | `/api/heartbeat` | POST | R-hash | JSON **object**; optional keys `sysinfo`(any), `disconnect`([i32]), `modified_at`(i64), `strategy`({config_options,extra}) | `{modified_at, strategy}` object | `$payload ?: (object)[]` → object (incl. `disconnect` when queued) | **OK.** `?: (object)[]` guarantees `{}` not `[]` even when payload empty. `SystemController.php:69` | none |
| 2 | `/api/sysinfo` | POST | str | plain text `"SYSINFO_UPDATED"` / `"ID_NOT_FOUND"` | `Text:"SYSINFO_UPDATED"` / `"ID_NOT_FOUND"` | `response('SYSINFO_UPDATED')` text/plain | **OK** `SystemController.php:122` | none |
| 3 | `/api/sysinfo_ver` | POST | str | opaque text ver string (compared `==`) | n/a (`PostSysinfo` only) | `response(config('app.version'))` text/plain | **OK** `SystemController.php:130` | none |
| 4 | `/api/login-options` | GET | custom (array) | JSON **array of strings**; iterates `jsonDecode(resp.body)` as list (`user_model.dart:228`) | `mvc.Response{}` (empty body!) | `response()->json([])` → `[]`, or `["common-oidc/…","oidc/…"]` | **OK.** Array is exactly right. (lantongxue's empty-body would make the client's `for in jsonDecode("")` throw, caught → returns `[]`.) `LoginController.php:37-53` | none |
| 5 | `/api/login` | POST | F-obj via `user_model.login` | JSON **object**; success ⇒ AuthBody `{access_token,type:"access_token",tfa_type,secret,user:UserPayload}`; 2FA step ⇒ `{type,tfa_type,secret}`; error ⇒ `{error}` (HTTP 200 or non-200) | `iris.Map{...}` (object) incl. `user{name,email,note,status,is_admin}` — **no `info`** | `authBody()` object incl. `user.info{...}` — `LoginController.php:204-262` | **OK** for Flutter. ⚠ See §2.1: lantongxue omits `user.info`; **ours includes it** (correct for the Rust path). | none |
| 6 | `/api/oidc/auth` | POST | R-obj `OidcAuthUrl{code,url}` | JSON **object** `{code:String, url:Url}`; error ⇒ `{error}` | (not implemented in api/) | `{code,url}` or `{error}` — `OauthController.php:52-55` | **OK** `url` must be a parseable URL (Rust `Url` type). | none |
| 7 | `/api/oidc/auth-query` | GET | R-obj wrapper | JSON object `{body:"<AuthBody json string>"}`; the **inner** body is then `HbbHttpResponse::parse`d as AuthBody (object; `error` while pending) | n/a | `{body: pollResult()}`; inner is full AuthBody **incl. `user.info`** or `{error:"No authed oidc is found"}` — `OauthController.php:91-98`, `OauthService.php:422-478` | **OK** — inner AuthBody includes required `user.info` (Rust-safe). | none |
| 8 | `/api/logout` | POST | none (Flutter ignores body, 2s timeout) | irrelevant; only that it returns | `Text:"ok"` | `response()->json((object)[])` → `{}` | **OK** `LoginController.php:185` | none |
| 9 | `/api/currentUser` | POST | F-obj `user_model.refreshCurrentUser` | JSON **object** = UserPayload `{name, display_name?, avatar?, email?, note?, status, is_admin, info?}`; `error` key ⇒ throw (`user_model.dart:85-91`) | `{name,email,note,status,is_admin}` (no `info`, no `display_name`) | full UserPayload incl. `display_name,avatar,third_auth_type,info` — `LoginController.php:191-262` | **OK** (Flutter UserPayload tolerates missing keys; ours is a superset). | none |
| 10 | `/api/user/info` | GET | (same as currentUser; web) | same UserPayload object | n/a | aliased to `currentUser` — `routes/api.php:59` | **OK** | none |
| 11 | `/api/ab/get` | POST | (legacy Sciter `ab.tis:650`) + Flutter `LegacyAb.pullAbImpl` | object `{data:"<json str>", licensed_devices?:int}`; body `"null"` ⇒ empty AB; `error` ⇒ throw (`ab_model.dart:1018-1039`) | `{licensed_devices, data:"<json str>"}` | `{data:"<json str>"}` — **no `licensed_devices`** — `AddressBookController.php:34-41` | ⚠ **Minor.** `licensed_devices` read in a `try/catch` (`ab_model.dart:1030`) so absence is tolerated; only affects legacy "max devices" gating. | (optional) add `licensed_devices` to `getLegacy` |
| 12 | `/api/ab` | POST | F-ish `LegacyAb.pushAb` | success = `200 + (empty body OR "null")`; else object, `error` ⇒ throw (`ab_model.dart:1070-1083`) | `mvc.Response{}` → `{}` | `response()->json((object)[])` → `{}` | **OK** — `{}` has no `error`, status 200 ⇒ success. `AddressBookController.php:63` | none |
| 13 | `/api/ab/personal` | POST | F-map | JSON **object** `{guid:String, ...}`; reads `json['guid']`; 404 ⇒ legacy mode; `error` ⇒ throw (`ab_model.dart:262-293`) | `{guid}` object | `{guid,name,owner,note,tag_colors}` object — `AddressBookController.php:71-82` | **OK** (object with `guid`). | none |
| 14 | `/api/ab/settings` | POST | F-map | JSON **object**; reads `json['max_peer_one_ab']`; 404 ⇒ skip; `error` ⇒ throw (`ab_model.dart:230-258`) | `{max_peer_one_ab}` object | `{max_peer_one_ab:0, allow_ab_personal}` object — `AddressBookController.php:87-93` | **OK** | none |
| 15 | `/api/ab/shared/profiles` | POST | F-map (paginated) | JSON **object** `{total:int, data:[AbProfile]}`; AbProfile `{guid,name,owner,note,rule,info}` (`ab_model.dart:295-357`) | `{total, data:[{guid,name,owner,note,rule}]}` | `{total:0, data:[]}` object — `AddressBookController.php:99-107` | **OK** (well-formed empty page; loops `while current*pageSize < total`, total 0 ⇒ one pass). | none |
| 16 | `/api/ab/peers` | POST | F-map (paginated) | JSON **object** `{total:int, data:[Peer]}`; Peer shape per `peer_model.dart` (`ab_model.dart:1432-1497`) | `{total, data:[peer…]}` incl. `forceAlwaysRelay`(string), `same_server` | `{total, data:[peerShape], tag_colors}` — `AddressBookController.php:112-133` | **OK.** See §3 for Peer field nuances (`forceAlwaysRelay` casing/string). | none |
| 17 | `/api/ab/tags/{guid}` | POST | **F-list** | JSON **array** `[{name, color:int}]` (`ab_model.dart:1499-1544`) | `Object: data` → **array** `[{name,color}]` | `response()->json($tags)` → **array** `[{name,color}]` — `AddressBookController.php:138-146` | **OK** — both return a top-level array. **This is the one endpoint where `{}` would break it; we correctly return `[]`.** | none |
| 18 | `/api/ab/peer/add/{guid}` | POST | F-action | success = empty body or `{}` (no `error`); `error` ⇒ message (`ab_model.dart:1547-1578`) | `Text:""` (empty) | `(object)[]` → `{}` | **OK** (tolerant). `AddressBookController.php:169` | none |
| 19 | `/api/ab/peer/update/{guid}` | PUT | F-action | empty/`{}` success; `error` ⇒ msg | `Text:""` | `{}` — `AddressBookController.php:191` | **OK** | none |
| 20 | `/api/ab/peer/{guid}` | DELETE | F-action | empty/`{}` success | `mvc.Response{}` → `{}` | `{}` — `AddressBookController.php:209` | **OK** | none |
| 21 | `/api/ab/tag/add/{guid}` | POST | F-action | empty/`{}` success | `Text:""` | `{}` — `AddressBookController.php:231` | **OK** | none |
| 22 | `/api/ab/tag/update/{guid}` | PUT | F-action | empty/`{}` success | `Text:""` | `{}` — `AddressBookController.php:249` | **OK** | none |
| 23 | `/api/ab/tag/rename/{guid}` | PUT | F-action | empty/`{}` success | `Text:""` | `{}` — `AddressBookController.php:290` | **OK** | none |
| 24 | `/api/ab/tag/{guid}` | DELETE | F-action | empty/`{}` success | `mvc.Response{}` → `{}` | `{}` — `AddressBookController.php:319` | **OK** | none |
| 25 | `/api/audit/conn` | POST | none (fire-and-forget) | body ignored | `mvc.Response{}` | `{}` for accepted and silently rejected events | **OK.** Writes require an existing approved device with exact `id + uuid`; malformed, oversized, or limited events no-op without changing the acknowledgement. | none |
| 26 | `/api/audit/file` | POST | none | body ignored | `mvc.Response{}` | `{}` for accepted and silently rejected events | **OK.** Same device binding and response-preserving rejection contract as row 25. | none |
| 27 | `/api/audit/alarm` | POST | none | body ignored | `{error:"11"}` (lantongxue stub) | `{}` for accepted and silently rejected events | **OK.** Same device binding and response-preserving rejection contract as row 25. | none |
| 28 | `PUT /api/audit` (note) | PUT | none (status only) | only `statusCode==200` matters (`dialog.dart:1664-1690`) | handled inside `PostAuditConn` (`note` branch) | bearer-authenticated route updates by server-issued `guid`; returns `{}` | **OK.** The current guid-based note flow is authenticated. The unauthenticated legacy POST note shape cannot prove the controlled device and no-ops unless it additionally supplies the exact UUID. | none |
| 29 | `/api/record` | POST | R-map (record_upload) | JSON **object**; bail only if `error` key present (`record_upload.rs:106-110`) | n/a | `$result ?: (object)[]` → `{}` or `{error}` — `RecordController.php:48` | **OK** — `{}` on success, `{error}` on failure. (A `[]` would be tolerated too, but `{}` is correct.) | none |
| 30 | `/api/devices/deploy` | POST | R-obj→`["result"]` | JSON **object** with string `result` ∈ {OK,NOT_ENABLED,INVALID_INPUT,ID_TAKEN} (`ui_interface.rs:1074`) | n/a | `response($result)` **text/plain** (e.g. `"OK"`) — `DevicesController.php:35` | ⚠ **CONFIRMED BUG.** Client does `serde_json::from_str(&text)` then `parsed["result"].as_str()`. A bare `OK` → `from_str` fails → `Value::Null` → `as_str()==""` → falls to `_ =>` arm which (text non-empty) returns `DeployResult::Error("OK")` (`ui_interface.rs:1109-1115`). **Deploy never registers OK and surfaces "OK" as an error.** | Return JSON `{"result":"OK"}` from `deploy()`. (See §4.) |
| 31 | `/api/users` | GET | F-map (paginated) | object `{total, data:[UserPayload]}`; UserPayload tolerant; special-cases `error=="Admin required!"` (`group_model.dart:160-222`) | `{total, data:[{name,email,note,status,is_admin}]}` | `{total, data:[{name,email,note,is_admin,status,info:{}}]}` — `GroupController.php:53-56` | **OK** | none |
| 32 | `/api/peers` | GET | F-map (paginated) | object `{total, data:[PeerPayload]}`; PeerPayload `{id,info{username,os,device_name},status,user,user_name,device_group_name,note}` (`group_model.dart:224-282`, `hbbs.dart` PeerPayload) | `{total, data:[{id,info{username,os,device_name},status,user,user_name}]}` | `{total, data:[{id,info{device_name,os,username},status,user,user_name,note,device_group_name}]}` — `GroupController.php:104-107` | **OK** (superset) | none |
| 33 | `/api/device-group/accessible` | GET | F-map (paginated) | object `{total, data:[{name}]}`; DeviceGroupPayload reads `json['name']` (`group_model.dart:103-158`) | n/a | `{total, data:[DeviceGroup model…]}` — `GroupController.php:135-138` | **OK** *iff* the serialized `DeviceGroup` model includes a `name` attribute (it does). Extra columns are harmless. | none |

---

## 2. Login / user payload deep notes

### 2.1 `user.info` is REQUIRED on the Rust deserialization path (login & oidc)
- Rust `UserPayload.info: UserInfo` — **no default** (`account.rs:92`). `AuthBody.user` — also no
  default (`account.rs:107`).
- The Rust client path that hits this: **OIDC `auth-query`** (`account.rs:183-201,264`) — it
  `HbbHttpResponse::parse`s the inner `body` into `AuthBody`. If `user` or `user.info` is missing,
  parse yields `DataTypeFormat` and the login silently never completes.
- **lantongxue omits `user.info`** entirely (`service/user.go:139-146`, controller `user.go:21-32`).
  That works for lantongxue only because their **Flutter** clients use `UserPayload.fromJson`
  (`hbbs.dart:35-49`) which tolerates a missing `info`. Their server is not exercised by the Rust
  OIDC path the same way.
- **`info` must be a JSON OBJECT — present, never an array.** "Required" means present; an empty
  object `{}` is valid (every `UserInfo` field has a default). What is NOT valid is a JSON **array
  `[]`**: `serde_json::from_value::<UserInfo>` expects a map, so `"info":[]` fails the whole
  `AuthBody` parse → `DataTypeFormat` → silent never-completes. In PHP this is the classic trap:
  an **empty** `['…'=>…]` that ends up empty, or `(array)`-casting, serializes to `[]`. Emit
  `(object)[]` (or a populated assoc array) and, if you round-trip through `json_decode`, decode to
  **objects** (`json_decode($s)` — not `json_decode($s, true)`), or `{}` becomes `[]` again.
- **OURS:** `LoginController::userPayload` sends a populated `info{email_verification,
  email_alarm_notification, login_device_whitelist}` (always an object); `OauthService::authBody`
  sends `info: (object)[]` → `{}` (SSO users default those fields). Both are objects — correct.

### 2.4 OIDC `auth-query` body must be the AuthBody at the TOP LEVEL (not only wrapped in `body`)
- The reference Go server (lejianwen/rustdesk-api `OidcAuthQuery`) returns the AuthBody **at the top
  level**: `c.JSON(LoginRes{access_token,type,user})` (ready) / `gin.H{message,error}` (pending) —
  **no `{"body":…}` wrapper**. Stable RustDesk clients parse the auth-query response **directly**
  as `serde AuthBody` (`HbbHttpResponse::parse(&resp)`).
- The `{"body":"<json string>"}` unwrap (`HttpResponseBody{body:String}` in `account.rs`) is a
  **recent master change** (client commit `4e30ee8d1`, 2026-04-03) **not yet in stable releases**.
  So a server that returns **only** `{"body":…}` makes stable clients run
  `from_value::<AuthBody>` on `{"body":…}` → no `access_token`/`user` at top level → silent hang.
- **OURS:** `OauthController::authQuery` returns the AuthBody (or `{error}`) **at the top level AND**
  mirrored under `body`. serde ignores unknown fields, so stable (top-level) and post-April nightly
  (`body`) clients both parse it. Do **not** revert to a `body`-only response.

### 2.2 Error signalling on login
- Client treats **any** body with a string `error` key as failure, on **HTTP 200 or non-200**
  (`user_model.dart:194-199`; Rust `hbbs_http.rs:27`). Ours returns `{error:…}` with HTTP 200 for
  bad credentials (`LoginController.php:90`) — **correct**; do not switch to 4xx-only without an
  `error` body or the Rust path loses the message.

### 2.3 `status` is an enum int, not a bool/string
- Rust `UserStatus`: `Disabled=0, Normal=1, Unverified=-1` (`account.rs:71-77`), parsed via
  `Deserialize_repr` → **must be an integer**. Flutter maps `status==0/-1/else`
  (`hbbs.dart:43-48`). Ours casts `(int) $user->status` — **OK**. A stringified status would break
  the Rust repr parse.

---

## 3. Address-book Peer / Tag JSON shape (the subtle ones)

Client Peer parser: `peer_model.dart:33-48`. Tag parser: `hbbs.dart` `AbTag.fromJson`.

| Field | Client expects | Notes / our handling |
|-------|----------------|----------------------|
| `id` | String | `?? ''` |
| `hash` | String | personal-AB hash password; `?? ''` |
| `password` | String | shared-AB password |
| `username`,`hostname`,`platform`,`alias` | String each | `?? ''` |
| `tags` | **JSON array** (`List<dynamic>`) | `json['tags'] ?? []`. Ours `peerShape` emits `(array)` — **OK**. Must NOT be a JSON-string. lantongxue decodes its stored string to a real array before sending (`ab_peer.go:64-78`). |
| `forceAlwaysRelay` | **String compared to `'true'`**: `json['forceAlwaysRelay'] == 'true'` (`peer_model.dart:42`) | ⚠ The client only treats the **string** `"true"` as true. **OURS emits a real boolean** `(bool) $peer->force_always_relay` (`AddressBookController.php:428`). When that bool is `true`, Dart `true == 'true'` is **false** → the flag is **lost on read**. lantongxue emits the **string** `"true"`/`"false"` (`ab_peer.go:59-62,79`). **Mismatch (data-fidelity, not a crash).** |
| `rdpPort`,`rdpUsername` | String (camelCase keys) | Ours uses camelCase keys — **OK**. |
| `loginName` | String (camelCase) | Ours emits `loginName` — **OK**. `peerShape` lacks `loginName`? It includes it (`AddressBookController.php:431`). |
| `device_group_name` | String (**snake_case**) | Not in our `peerShape` for `/api/ab/peers` (only group `/api/peers` has it). Tolerated (`?? ''`). |
| `note` | String | `note is String ? … : ''` — ours casts to string. OK. |
| `same_server` | bool? (snake_case) | `json['same_server']`. lantongxue emits `same_server`. **Ours omits it** in `peerShape` → Dart `null` (acceptable; means "unknown", sync logic guards on `!= true`). Minor. |
| Tag `color` | int (`AbTag.color = json['color'] ?? ''`) | Ours emits `(int)` — OK. lantongxue emits the int. |
| `tag_colors` (where present) | **JSON-string** map `"{\"tag\":12345}"`, later `jsonDecode`d (`ab_model.dart:689-691,1347-1350`) | Ours `tagColorsJson` returns a JSON **string** of an object — **OK**. Empty ⇒ `"{}"` (object), not `"[]"` — correct, because the client `jsonDecode`s it into `Map<String,dynamic>`. |

**Net AB-shape finding:** the only real fidelity bug is **`forceAlwaysRelay` boolean-vs-string**
(see §5 checklist). Everything else is compatible.

---

## 4. `/api/devices/deploy` — response format needs confirming

- **Client** (`ui_interface.rs:1071-1080`):
  ```rust
  let parsed: Value = serde_json::from_str(&text).unwrap_or(Value::Null);
  match parsed["result"].as_str().unwrap_or("") { "OK" => …, "NOT_ENABLED" => …, … }
  ```
  It **JSON-parses** the body and reads a string field `result`.
- **Ours** returns the **bare** result via `response($result)` as `text/plain`
  (`DevicesController.php:35`). The full match arm is now confirmed (`ui_interface.rs:1107-1116`):
  ```rust
  "INVALID_INPUT" => DeployResult::InvalidInput,
  "ID_TAKEN" => DeployResult::IdTaken(id_to_deploy),
  _ => { if text.is_empty() { Error("Unknown response.") } else { Error(text) } }
  ```
  With `$result = "OK"` (not JSON): `serde_json::from_str("OK")` fails → `Value::Null` →
  `parsed["result"].as_str() == ""` → `_ =>` arm → `text` is non-empty (`"OK"`) →
  **`DeployResult::Error("OK")`**. So deployment is reported as a **failure whose message is the word
  "OK"**. There is **no** bare-string tolerance.
- **Fix:** return JSON `response()->json(['result' => $result])` (and keep the same vocabulary
  `OK | NOT_ENABLED | INVALID_INPUT | ID_TAKEN`). This is the **highest-risk** item alongside the
  audit-note gap. (Recommend a quick check of the legacy Go `Deploy` handler only to confirm the
  exact JSON key is `result`, which the client code already dictates.)

---

## 5. Prioritized "fixes needed" checklist (file:line into our code)

Ordered by client impact. Items marked **OK** above are intentionally omitted.

### P1 — Functional gaps / likely breakage
1. **`PUT /api/audit` (note) route is missing.**
   - Symptom: end-of-connection operator notes silently dropped (client `PUT /api/audit` → 404/405;
     client only checks `statusCode==200`, so no error shown but the note never persists).
   - Files: `routes/api.php:45-47` (add `Route::put('/audit', [AuditController::class,'note'])`);
     `AuditController.php` (add a `note()` action, or extend `conn()` to also bind on `PUT /api/audit`).
   - The `conn()` method already has a `note`-update branch (`AuditController.php:48-59`) keyed on
     `session_id` — reuse it; client body is `{guid, note}` (`dialog.dart:1675-1678`) **and** the
     hbbs variant is `{id, session_id, note}` (lantongxue `audit.go:24`). Decide which key to match
     on (note: client sends `guid`, not `session_id`, in the Flutter note path — confirm what `guid`
     maps to server-side).

2. **`/api/devices/deploy` response is `text/plain` but the client JSON-parses it.**
   - Files: `DevicesController.php:24-36`; verify against `ui_interface.rs:1071-1100` and legacy Go
     `Deploy`. If legacy returns `{"result":"OK"}`, switch to
     `response()->json(['result' => $result])`. Do **not** change blindly — confirm first (§4).

### P2 — Data-fidelity bug (no crash, silent data loss)
3. **`forceAlwaysRelay` emitted as JSON boolean; client only honours the string `"true"`.**
   - File: `AddressBookController.php:428` (`'forceAlwaysRelay' => (bool) $peer->force_always_relay`).
   - Client: `peer_model.dart:42` does `json['forceAlwaysRelay'] == 'true'`. A real `true` ⇒ Dart
     `true == 'true'` ⇒ **false**. lantongxue emits the string (`ab_peer.go:59-62`).
   - Fix: emit `$peer->force_always_relay ? 'true' : 'false'` (string) in `peerShape()` **and** in
     `bookBlob()` (same `peerShape`, also used by legacy `/api/ab/get`). Note the round-trip on write
     already handles the string (`mapPeer` stores whatever comes in) — only the **read** side mis-types.

### P3 — Minor / cosmetic (tolerated by client, low priority)
4. **`/api/ab/get` omits `licensed_devices`** (`AddressBookController.php:34-41`). Read in a
   try/catch (`ab_model.dart:1030`); only affects legacy max-device gating. Add
   `'licensed_devices' => <int>` if legacy parity is desired.
5. **`/api/ab/peers` omits `same_server`** in `peerShape` (`AddressBookController.php:419-435`).
   Client reads `json['same_server']` (`peer_model.dart:48`); absence ⇒ `null` ⇒ sync logic treats
   as "not same server" (guards on `!= true`). Harmless; add for parity if shared-server detection
   is implemented.

### Confirmed-GOOD (do not regress)
- **All AB mutations return an EMPTY 200 body** (`ack()` → `response('', 200)`), never `{}` or `[]`.
  Both `{}` ("null") and `[]` ("[]") are shown to the user as a spurious error — see §0.4. This is
  the historically-dangerous spot; the empty body is the only correct ack.
- **`/api/ab/tags/{guid}` returns a top-level array `[]`** (`AddressBookController.php:145`) — the
  **only** endpoint that must be an array (F-list parser). Correct.
- **`/api/heartbeat` uses `?: (object)[]`** so an empty payload is `{}` not `[]`
  (`SystemController.php:69`). Correct — an array would make the Rust `from_str::<HashMap>` fail and
  the client would ignore strategy/disconnect pushes.
- **`user.info` is present** on both login and OIDC AuthBody (`LoginController.php:256`,
  `OauthService.php:472`). Required by the Rust deserializer — keep it.
- **`tag_colors` is a JSON-string of an object**, `"{}"` when empty (`AddressBookController.php:474-482`).
  Correct (client `jsonDecode`s into a Map).

---

## 6. Cross-reference: client parser → endpoints (quick lookup)

- **Must be JSON object `{}` (array breaks):** heartbeat, currentUser, user/info, login, oidc/auth,
  oidc/auth-query(outer+inner), ab/personal, ab/settings, ab/shared/profiles, ab/peers, users, peers,
  device-group/accessible, record.
- **Must be JSON array `[]` (object breaks):** `ab/tags/{guid}`, and `login-options` (string array).
- **Empty body or `{}` both OK (mutations):** ab/peer/add|update|delete, ab/tag/add|update|rename|delete,
  ab (legacy push), logout.
- **Plain text (not JSON):** sysinfo, sysinfo_ver, devices/deploy(⚠ see §4).
- **Body ignored entirely:** audit/conn, audit/file, audit/alarm, audit(note, status only).

---

---

## 7. Resolution log

All findings above are now resolved. Verified green: Pint, PHPStan L5, and the full PHPUnit suite
(157+ passing) — including regression tests in `tests/Feature/ApiResponseTest.php` and
`tests/Feature/OidcPkceTest.php`. (The original audit batch was 28 tests / 136 files; the suite has
grown substantially since.)

### P1 · `/api/devices/deploy` now returns a JSON object (row 30, §4)
`DevicesController::deploy` returned bare text `OK`; the client `serde_json::from_str`s the body
and reads `parsed["result"]`, so a bare string surfaced deploy success as a spurious error. Now
returns `response()->json(['result' => $result])` (vocabulary unchanged: `OK | NOT_ENABLED |
INVALID_INPUT | ID_TAKEN`). Test: `test_deploy_returns_a_json_object_with_a_result_field`.

### P1 · end-of-connection notes — `GET /api/audit/conn/active` + `PUT /api/audit` (row 28)
The audit found the missing `PUT /api/audit` note route, but the feature is **two** endpoints: the
controlling client first polls `GET /api/audit/conn/active?id=&session_id=&conn_type=` to fetch a
server-issued **guid** (a bare JSON string), caches it (`model.dart` `_queryAuditGuid`), and only
then `PUT /api/audit {guid, note}` (`dialog.dart`). Without the GET the PUT never fires (it's
guarded on a cached guid), so both were added:
- New nullable indexed `guid` column on `audit_conns` (migration `…100031`); a `Str::uuid()` is
  issued when a `new`-action conn audit is created (`AuditController::conn`).
- `AuditController::active()` looks the live session's guid up by `peer_id` + `session_id`
  (action `new`), returning `""` until the conn audit lands so the client's backoff retries.
- `AuditController::note()` updates the matching record's note by guid.
- Both routes sit behind `rustauth` (the operator's account bearer is always present — the client
  only queries when logged in). Test: `test_audit_active_guid_then_note_roundtrip`.

### P2 · `forceAlwaysRelay` emitted as a string (row 16, §3)
`peerShape()` emitted a JSON boolean, but the client does `json['forceAlwaysRelay'] == 'true'`, so
`true` was silently dropped on read. Now emits `'true'`/`'false'`. Test:
`test_peer_force_always_relay_is_serialised_as_a_string`.

### Follow-up · `POST /api/devices/cli` (`rustdesk --assign`) implemented
While auditing the deploy path, the gap analysis found one more client endpoint no OSS server
implements: `POST /api/devices/cli`, behind `rustdesk --assign --token …` (`core_main.rs`). It
registers/locates a device and applies owner / strategy / device-group / identity / address-book
presets in one token-authenticated call. Added `DeploymentService::assign` + `DevicesController::cli`
+ route; response is an empty 200 on success (client prints "Done!"), plain-text reason on failure.
Tests: `test_cli_assign_registers_device_and_applies_presets`, `test_cli_assign_rejects_a_bad_token`.

### P3 · intentionally not changed
`/api/ab/get` `licensed_devices` and `/api/ab/peers` `same_server` remain omitted — both are read
in client try/catch / null-guards, and we model neither a device-license cap nor shared-server
detection, so emitting hardcoded values would be misleading rather than helpful.

### P0 · OIDC client login hung at "Waiting account auth" (2026-06-23) — FIXED & verified live
Self-hosted client SSO never completed: the browser reached "Sign-in complete" (callback OK, token
stored) but the client polled `/api/oidc/auth-query` forever. Root-caused by diffing against the
working reference (lejianwen). Two real defects, both now fixed (see §2.4 + §2.1):
1. **Wrapper** — we returned only `{"body":"<json>"}`; stable clients parse the AuthBody at the
   **top level**. Now `OauthController::authQuery` returns it at the top level **and** under `body`.
2. **`info` type** — the SSO `info` was serialized as `[]` (empty array) after a
   `json_decode(…, true)` round-trip; the client's `UserInfo` needs an **object**. Fixed by sending
   `(object)[]` and decoding to objects in `authQuery`.
Also along the way: **PKCE** is now implemented for OIDC (`pkce_enable` was ignored — `code_challenge`
/`code_verifier` now sent), and the pending OIDC session was moved from the cache to a DB table
(`oauth_sessions`) so the callback + poll resolve the same session across **load-balanced
instances** (the deployment is multi-node behind `rdr-api-1`). Confirmed working on the live
Keycloak (`sso.corporatetools.com`) deployment. Tests: `tests/Feature/OidcPkceTest.php`
(PKCE, DB session, cross-instance poll, top-level + `body` shape, `info` is `{}` not `[]`).

---

*Client = `D:\git\rustdesk`; reference = `D:\git\rustdesk-api-server-pro`; target =
`D:\git\rustdesk-api`.*
