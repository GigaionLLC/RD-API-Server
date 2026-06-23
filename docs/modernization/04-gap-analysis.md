# 04 · Gap Analysis

> ## 🟢 Current status (updated 2026-06-23)
>
> The table below is the **original baseline** from the start of the rewrite. Since then the
> vast majority has shipped. Accurate current picture:
>
> **Done ✅** — Strategy/Settings‑push (client‑Settings‑style editor, ~55 catalogued options,
> "set all", custom keys) · preset auto‑registration + `--assign` (`/api/devices/cli`) +
> default device group · force‑disconnect / Live Sessions · 2FA (client **and** admin console)
> · SMTP + email verification · device deploy/approval · session‑recording upload · alarms +
> connection/file/console audit · granular access control · Admin Roles · OIDC · LDAP ·
> address books (+ a **RustDesk‑client‑style admin manager** for any user's book, tags,
> end‑of‑connection notes) · device **bulk‑assign** + **live‑search** pickers ·
> **Client Config generator** (config string + mobile QR + per‑OS `--config` + installer) ·
> **scoped API keys + admin REST API** (`/api/v1`, see [../api/README.md](../api/README.md)) ·
> login brute‑force throttling.
>
> **Remaining roadmap (chosen 2026‑06‑23):**
> 1. ~~Scoped API keys + REST API~~ ✅ done · 2. **OpenAPI spec + Postman/Bruno** collection ·
> 3. **Webhooks / notifications** (alarms + events → Slack/Telegram/generic) ·
> 4. **Shared / team address books** (collaborators + R/RW/Full rules — contract captured in
> [11](11-client-feature-opportunities.md) §7).
>
> **New opportunities surfaced while building** (not yet scheduled): more `/api/v1` coverage
> (device/strategy create+update, address‑book tags) and write scopes · CSV/export of audit
> logs · bulk actions on users/address‑books · Wake‑on‑LAN (client‑local; needs a relay hook) ·
> dashboard metrics/observability · per‑AB max‑peer + licensed‑device quotas · a packaged
> server‑management CLI wrapping `/api/v1`.

`rustdesk-api` (have) vs. the client contract ([02](02-client-api-contract.md)) and Pro
catalog ([03](03-pro-feature-catalog.md)). Ratings are guidance, not gospel.

**Status:** ✅ have · ⚠️ partial · ❌ missing  
**Value:** ★★★ high · ★★ medium · ★ low  
**Effort:** S small · M medium · L large · XL very large  
"Client‑ready?" = stock clients already speak it, so the server side alone unlocks it.

| # | Feature | Status | Value | Effort | Client‑ready? | Notes |
|---|---------|:------:|:-----:|:------:|:-------------:|-------|
| 1 | **Strategy / Settings sync** | ❌ | ★★★ | M | **Yes** | Heartbeat returns `{}`. Add strategy model + assignment (device/user/device‑group priority) + `modified_at` handshake + `config_options` in heartbeat. Highest ROI. |
| 2 | **2FA — TOTP** | ❌ | ★★★ | M | **Yes** | Client sends `tfaCode`/`secret`, expects `tfa_type:"totp"`. Add TOTP enroll + backup codes. |
| 3 | **Email login verification** | ❌ | ★★ | M | **Yes** | `tfa_type:"email_check"`; depends on #4 (SMTP). |
| 4 | **SMTP / email subsystem** | ❌ | ★★★ | M | n/a | Foundational: unlocks #3, invitations, password reset, alarms (#8). |
| 5 | **Device deployment & approval** | ❌ | ★★ | M | Yes | `/api/devices/deploy`, `--deploy`, and `ID_NOT_FOUND` gating in sysinfo. |
| 6 | **Preset auto‑registration** | ⚠️→❌ | ★★ | S‑M | **Yes** | sysinfo already received; just parse `OPTION_PRESET_*` to auto‑assign address book / strategy / device group. Cheap once #1/#9 exist. |
| 7 | **Session‑recording upload** | ❌ | ★★ | M | **Yes** | Implement chunked `/api/record` (new/part/tail/remove) + storage (local/OSS) + admin browse/playback. |
| 8 | **Alarm logs + notifications** | ❌ | ★★ | M | Yes | New audit category on existing `/api/audit/*` ingestion + email via #4. |
| 9 | **Force‑disconnect / live sessions** | ❌ | ★★ | S‑M | **Yes** | Heartbeat already carries `conns`; track them, expose live sessions, return `disconnect`. |
| 10 | **Granular access control** (user‑group cross access, device‑group access) | ⚠️ | ★★★ | L | partial | Today only `is_admin` + simple groups. Build cumulative allow model. |
| 11 | **Control Role (in‑session perms)** | ❌ | ★★ | L | Yes(≥1.4.5) | 12 perms delivered via the strategy/option push (#1). Do after #1. |
| 12 | **Admin Role (scoped console)** | ❌ | ★★ | L | n/a | Replace boolean admin with role/permission matrix (Global/Individual/Group‑scoped). |
| 13 | **Scoped API tokens + CLI** | ⚠️ | ★★ | M | n/a | `UserToken` is a session token. Add API keys with scopes (Device/Audit/User/Group/Strategy/AB) + a CLI. |
| 14 | **Multiple relays + geo** | ⚠️ | ★ | L | needs hbbs | Single relay + `relay-servers` server‑cmd today. True geo lives in `hbbs`; API can manage the relay list. |
| 15 | **Custom Client Generator** | ❌ | ★★ | XL | n/a | Full white‑label build needs a signing/build pipeline. Realistic subset: config‑string + filename‑encoding + `--config` helpers. |
| 16 | **OIDC / SSO** | ✅ | — | — | — | GitHub/Google/OIDC/Linux.do/WebAuth + PKCE. Keep; add provider presets (Azure/Okta) UX. |
| 17 | **LDAP / AD** | ✅ | — | — | — | Bind+search, admin/allow groups, sync. Ahead of Pro (which lacks LDAP groups). |
| 18 | **Address books (personal + shared)** | ✅ | — | — | — | Collections + read/rw/full rules. Solid. |
| 19 | **Connection & file audit** | ✅ | — | — | — | Have. Extend with alarm + console‑operation categories. |
| 20 | **Server commands to hbbs/hbbr** | ✅ | — | — | — | Have (`relay-servers`, ip‑blocker, blacklist, bandwidth…). |
| 21 | **WebSocket / web client self‑host** | ✅ | — | — | — | `ws-host` + bundled web client. Modernize UX. |
| 22 | **Captcha / IP ban / login limiter** | ✅ | — | — | — | Have. |

## Reading the table

**Tier A — client‑ready, build the server and stock clients light up (do first):**
#1 Strategy, #6 Presets, #9 Force‑disconnect/live sessions, then #2 TOTP. These need **no
client changes** — every RustDesk user benefits immediately.

**Tier B — foundational / high value:** #4 SMTP (unblocks #3, #8, password reset), #5
deployment/approval, #7 recording, #8 alarms.

> **Reference code exists.** Items #2 (TOTP), #3 (email verification), #4 (SMTP), and #9
> (device/session tracking) are already implemented in `lantongxue/rustdesk-api-server-pro`
> — study it before building. See [06-reference-implementations.md](06-reference-implementations.md).
> #1 (Strategy settings‑push) and #6 (preset auto‑registration) are solved by *neither*
> open‑source server and remain our differentiators.

**Tier C — platform depth for teams/MSPs:** #10 access control, #12 admin roles, #11 control
roles, #13 scoped tokens + CLI.

**Tier D — heavy or hbbs‑bound:** #14 geo relay, #15 custom client generator. Scope down or
defer.

## Cross‑cutting modernization (not a single feature)
- **Go & deps:** bump from Go 1.22 to current; refresh Gin/GORM/JWT/swag.
- **Token model split:** separate *session token* (`UserToken`) from *scoped API key* (#13).
- **Authz refactor:** the `is_admin` boolean blocks #10/#11/#12 — introduce a
  role/permission layer early so later features slot in.
- **OpenAPI completeness & a Postman/Bruno collection** for the client + admin APIs.
- **Tests:** service‑layer + handler tests, esp. for the new heartbeat/strategy logic.
- **DX/onboarding:** English‑first quickstart, first‑run wizard, clearer env docs, sane
  defaults; the README is currently Chinese‑first.
- **Observability:** structured logs exist (logrus); add basic metrics + an admin dashboard
  of devices/sessions.
