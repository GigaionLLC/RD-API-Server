# 01 · Architecture & Current State of `rustdesk-api`

A precise inventory of what this server is **today**. This is the baseline every gap and
roadmap item is measured against.

## 1. Stack

- **Language/runtime:** Go 1.22
- **HTTP:** Gin v1.9
- **ORM:** GORM v1.25 — drivers for **SQLite, MySQL, PostgreSQL** (`config/gorm.go`)
- **Docs:** Swagger via `swaggo/swag` (two specs: client + admin)
- **Cache/lock:** pluggable file or Redis (`lib/cache`, `lib/lock`)
- **i18n:** `global/i18n.go`
- **Front‑end:** separate repo `rustdesk-api-web` (Vue), built into `resources/admin`
- **Web client:** bundled static flutter web client
- **Entry point / CLI:** `cmd/apimain.go` (Cobra)

## 2. Two API surfaces

### 2a. Client‑facing API — `/api/*` (`http/router/api.go`)
Auth: `middleware.RustAuth()` — `Authorization: Bearer <token>` validated against
`user_token` (or JWT if `jwt.key` set).

**Unauthenticated:**
- `GET  /api/` · `GET /api/version`
- `POST /api/heartbeat` — peer heartbeat (see §6, this is where Strategy push belongs)
- `GET  /api/login-options` — provider list / TLS warm‑up
- `POST /api/login` — password login
- `POST /api/oidc/auth`, `GET /api/oidc/auth-query` — OIDC device flow
- `GET  /api/oauth/callback|login|msg`, `/api/oidc/callback|login|msg`
- `POST /api/sysinfo`, `POST /api/sysinfo_ver` — device system info
- `POST /api/audit/conn`, `POST /api/audit/file` — audit ingestion from hbbs
- Web client: `POST /api/shared-peer`, `POST /api/server-config[-v2]`

**Authenticated (RustAuth):**
- `GET  /api/user/info`, `POST /api/currentUser`
- `POST /api/logout`
- `GET  /api/users`, `GET /api/peers`, `GET /api/device-group/accessible`
- `GET/POST /api/ab` — address book (legacy single‑book)
- Personal address book set: `/api/ab/personal`, `/api/ab/settings`,
  `/api/ab/shared/profiles`, `/api/ab/peers`, `/api/ab/tags/:guid`,
  `/api/ab/peer/add|update|:guid`, `/api/ab/tag/add|rename|update|:guid`

### 2b. Admin API — `/api/admin/*` (`http/router/admin.go`)
Auth: `middleware.BackendUserAuth()` (`api-token` header) + `middleware.AdminPrivilege()`
for privileged ops.

Groups of routes (CRUD unless noted):
- **login/config:** `login`, `captcha`, `logout`, `login-options`, `oidc/auth[-query]`,
  `user/register`, `config/admin|server|app`
- **user:** `current`, `changeCurPwd`, `myOauth`, `groupUsers`, `list`, `detail/:id`,
  `create`, `update`, `delete`, `changePwd`
- **group**, **device_group**, **tag** — CRUD
- **address_book** — CRUD + `batchCreate`, `batchCreateFromPeers`, `shareByWebClient`
- **address_book_collection**, **address_book_collection_rule** — CRUD (sharing)
- **peer** — CRUD + `batchDelete`, `simpleData`
- **oauth** — CRUD + `confirm`/`bind`/`bindConfirm`/`unbind`/`info`
- **login_log**, **audit_conn**, **audit_file** — list/delete/batchDelete
- **user_token** — list/delete/batchDelete (session tokens)
- **share_record** — list/delete/batchDelete
- **rustdesk** (server cmd): `sendCmd`, `cmdList`, `cmdCreate`, `cmdDelete`
- **my/**\* — per‑user views of address books, tags, collections, peers, login logs,
  share records

## 3. Data model (`model/*.go`)

| Model | Key fields | Purpose |
|-------|-----------|---------|
| `User` | username, email, password(bcrypt), nickname, avatar, group_id, **is_admin**, status, remark | Account. Only a single admin boolean — no granular roles. |
| `Peer` | id, uuid, cpu, hostname, memory, os, username, version, **user_id**, last_online_time/ip, **group_id**, alias | A device. |
| `AddressBook` | id, username, password, hostname, alias, platform, tags(json), hash, user_id, forceAlwaysRelay, rdpPort/Username, online, loginName, sameServer, **collection_id** | Address‑book entry. |
| `AddressBookCollection` | user_id, name | A named address book. |
| `AddressBookCollectionRule` | collection_id, **rule**(1 read/2 rw/3 full), **type**(1 user/2 group), to_id | Sharing ACL for a collection. |
| `Tag` | name, user_id, color, collection_id | Address‑book tag. |
| `Group` | name, **type**(1 default/2 share) | User group. |
| `DeviceGroup` | name | Device group (CRUD only; not yet used for access/strategy). |
| `AuditConn` | action(new/close), conn_id, peer_id, from_peer/name, ip, session_id, type, uuid, close_time | Connection audit. |
| `AuditFile` | from_peer/name, info, is_file, path, peer_id, type, ip, num | File‑transfer audit. |
| `LoginLog` | user_id, client, device_id, uuid, ip, type, platform, user_token_id | Login audit. |
| `Oauth` | op, oauth_type(github/google/oidc/webauth/linuxdo), client_id/secret, auto_register, scopes, issuer, pkce | Auth provider config. |
| `UserThird` | user_id, open_id, name, email, picture, union_id, oauth_type, op | Linked external identity. |
| `UserToken` | user_id, device_uuid/id, token, expired_at | **Session** bearer token (not a scoped API key). |
| `ShareRecord` | user_id, peer_id, share_token, password_type, password, expire | Guest web‑client share link. |
| `ServerCmd` | cmd, alias, option, explain, target(21115 id / 21117 relay) | Stored hbbs/hbbr command. |
| `Version` | version | Schema/version bookkeeping. |

## 4. Authentication & authorization

- **Password:** bcrypt (`utils`), can be disabled (`app.disable-pwd-login`).
- **OAuth/OIDC:** GitHub, Google, generic OIDC, Linux.do, WebAuth; PKCE (S256/plain);
  auto‑register; account binding (`service/oauth.go`, `config/oauth.go`).
- **LDAP/AD:** bind + search, attribute mapping, `admin-group` (promote) and `allow-group`
  (gate), optional user sync, LDAPS/StartTLS (`service/ldap.go`, `config/ldap.go`).
  Falls back to local password on LDAP failure. Authenticated directory users resolve only
  through a persisted provider + immutable subject (`entryUUID` by default; configure
  `objectGUID` for Active Directory). A matching local username is never auto-linked.
- **JWT:** optional — if `jwt.key` set, tokens are signed JWTs, else random hashes.
- **Anti‑abuse:** captcha threshold + IP ban threshold (`utils` login limiter).
- **Tokens:** `UserToken` is a session token with expiry + auto‑refresh (<1 day left).
  There is **no scoped personal‑access‑token / API‑key** concept.
- **Authz:** a single `is_admin` boolean (`AdminPrivilege`). No role/permission matrix,
  no per‑group delegation, no in‑session permission control.

## 5. Configuration surface (`conf/config.yaml`, `config/config.go`)

Sections: `lang`, `app` (web-client, register[-status], captcha/ban thresholds,
show-swagger, token-expire, web-sso, disable-pwd-login), `admin`
(title, hello, id/relay-server-port), `gin`, `gorm`, `mysql`, `postgresql`, `rustdesk`
(id/relay/api-server, key[-file], personal, webclient-magic-queryonline, ws-host),
`logger`, `proxy`, `jwt`, `ldap`, plus `cache`/`redis`/`oss`. Every key has a
`RUSTDESK_API_*` env override.

## 6. The two handlers that matter most for the gap

### `Heartbeat` — `http/controller/api/index.go:41`
Today it **only** updates `last_online_time`/`last_online_ip` (throttled to 30s) and
returns `{}`. It does **not** read `conns`, and never returns `disconnect`, `modified_at`,
`sysinfo`, or **`strategy`**. This is the exact spot where Settings‑sync, force‑disconnect,
and live‑session tracking must be added — the client is already sending and awaiting them
(see [02-client-api-contract.md](02-client-api-contract.md) §1).

### `SysInfo` — `http/controller/api/peer.go:26`
Creates/updates the `Peer` row and **always** returns `SYSINFO_UPDATED`. It ignores every
`OPTION_PRESET_*` field in the body (address‑book name/tag/alias/password/note, strategy
name, device‑group name, device username/name, note, username) and never returns
`ID_NOT_FOUND`. This is where **preset auto‑registration** and **deployment gating** belong.

## 7. CLI (`cmd/apimain.go`)

- `apimain [-c config.yaml]` — run server
- `apimain reset-admin-pwd <pwd>` — reset admin (user 1)
- `apimain reset-pwd <userId> <pwd>` — reset any user
- `go generate` (`generate_api.go`) — regenerate Swagger specs

## 8. Strengths to preserve while modernizing

- Clean controller/service/model separation; multi‑DB; env‑var config parity.
- Real OAuth/OIDC/LDAP already done — most auth plumbing exists to extend for 2FA.
- Address‑book sharing model (collections + rules) is more capable than it looks and is a
  good template for the access‑control work.
