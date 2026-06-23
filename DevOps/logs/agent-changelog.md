# 📝 Agent Changelog

All changes made by AI agents are tracked chronologically below (newest first).
Format defined in [AGENT.md](../../AGENT.md) → Mandatory wrap-up protocol.

## [2026-06-22 22:30] - Feature: RustDesk-client-style admin address-book manager
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Http/Controllers/Admin/AddressBookController.php` (full peer + tag CRUD on ANY user's book: storePeer/updatePeer + storeTag/updateTag with hex→ARGB colour, tag rename propagation, blank-password-keeps-existing)
- `routes/web.php` (peer store/update, tag store/update routes)
- `resources/views/admin/address_books/show.blade.php` (rebuilt as a client-style manager: book switcher, Tags rail with colour dots + filter, peer cards with platform banner + ⋮ Edit/Delete, Add/Edit ID + tag Bootstrap modals)
- `app/Models/AddressBookPeer.php` (`@property array $tags`), `phpstan-baseline.neon` (dropped 3 now-resolved entries)
- `tests/Feature/AdminAddressBookTest.php` (NEW — 7 tests)
**Database/API Changes:** New admin routes (peer/tag create+update). No client-API change.
**Summary:** Replaced the read-mostly admin address-book page with a RustDesk-client-style manager — peer cards (platform-coloured banner, alias, tags, ⋮ menu), a Tags side panel with colour swatches and click-to-filter, and Add/Edit dialogs (ID, alias, note, tags, password). The admin operates directly on models, so it can fully manage **other users'** books, not just its own. Verified: Pint 144, PHPStan L5 0 errors, 54 PHPUnit passed, views compile.

## [2026-06-22 21:30] - Fix: address-book mutation ack must be an EMPTY 200 (not {})
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Http/Controllers/Api/AddressBookController.php` (new `ack()` → `response('', 200)`; all 8 mutation success returns + legacy push now use it; return types widened to `JsonResponse|Response`)
- `tests/Feature/ApiResponseTest.php` (assert empty body, not `{}`)
- `docs/modernization/16-response-contract.md` (CORRECTED §0.4 + confirmed-good note — the prior analysis that `{}` passes was wrong)
**Database/API Changes:** AB mutation success responses are now empty 200s (wire-fix). Errors unchanged (`{"error":...}`).
**Summary:** Reported live: adding/deleting an address-book entry showed a red **"null"** error on the client even though the change saved. Root cause: the client's `_jsonDecodeActionResp` treats success as **HTTP 200 with an EMPTY body**; for a `{}` body it does `jsonDecode("{}")["error"].toString()` → `null.toString()` → the string `"null"` (the `catch` never fires), and for `[]` it appends the raw `"[]"`. My earlier `[]`→`{}` change merely swapped the `"[]"` message for `"null"` — both are wrong. Correct ack is an empty 200. Verified by reading the actual client parser (not the secondary analysis doc, which I've now corrected). Pint 143, PHPStan L5 0 errors, 48 PHPUnit passed.

## [2026-06-22 20:40] - Docs: mark the project Beta + recommend established servers
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:** `README.md` (prominent Beta callout near the top)
**Database/API Changes:** None.
**Summary:** Added a Beta notice stating the project is young and heavily under test, expect rough edges/breaking changes, and recommending the established `lejianwen/rustdesk-api` and `lantongxue/rustdesk-api-server-pro` for production today (plain repo names, consistent with the existing Acknowledgements style).

## [2026-06-22 20:30] - Feature: default device group for new/ungrouped devices
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `database/migrations/2026_06_18_100032_add_is_default_to_device_groups_table.php` (NEW — `device_groups.is_default`)
- `app/Models/DeviceGroup.php` (`is_default` fillable+cast; `defaultId()` helper)
- `app/Http/Controllers/Admin/DeviceGroupController.php` (`setDefault` toggle — exclusive), `routes/web.php`, `resources/views/admin/device_groups/index.blade.php` (Default badge + Set/Unset button)
- `app/Http/Controllers/Api/SystemController.php` (heartbeat + sysinfo place ungrouped devices in the default group), `app/Services/DeploymentService.php` (deploy + assign do the same when no group preset)
- `tests/Feature/DefaultDeviceGroupTest.php` (NEW — 5 tests)
**Database/API Changes:** `device_groups.is_default` column; new `POST /admin/device-groups/{id}/default`. No client-API change.
**Summary:** Previously a freshly-registered device had `device_group_id = NULL` ("None") and matched no group-level strategy. An admin can now flag one device group as the **default** (star button on the Device Groups page); new — and currently ungrouped — devices are placed into it on their next heartbeat/sysinfo/deploy, so a group strategy applies automatically (an explicit `device_group_name` preset still wins). At most one default at a time; toggling it off leaves none. Verified: Pint 143, PHPStan L5 0 errors, 48 PHPUnit passed, views compile.

## [2026-06-22 19:45] - Fix: heartbeat strategy push dropped on client (extra:[] vs {})
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/StrategyService.php` (`heartbeatPayload`: cast `config_options` and `extra` to `(object)` so empty encodes as `{}` not `[]`)
- `tests/Feature/HeartbeatStrategyTest.php` (NEW — 2 tests: objects-not-arrays + no-push-when-in-sync)
**Database/API Changes:** None (response-shape correction; wire-compatible).
**Summary:** Diagnosed live on a production client (id 345890346, SSH to the Komodo stack): the device had the correct strategy assigned and its `strategy_timestamp` matched the server, yet the policy options never applied. Root cause: the heartbeat `strategy` block sent `"extra":[]` (and would send `"config_options":[]` when empty) because PHP's `(array)` casts an empty array to a JSON array. The client deserializes both into `HashMap<String,String>` (sync.rs `StrategyOptions`); an array fails serde, so the **whole** strategy is discarded — but `modified_at` is parsed separately and stored, leaving the client "in sync" yet never applying the options. Same `[]`-vs-`{}` class the response-contract audit fixed elsewhere but missed inside the nested strategy block. Verified: Pint 141, PHPStan L5 0 errors, 43 PHPUnit passed. NOTE: after deploy, bump a strategy's modified_at (re-save) so already-synced clients re-pull.

## [2026-06-22 17:30] - Strategy editor: full known-option catalog with toggles + selects
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `config/strategy_options.php` (NEW — curated catalog of ~55 client `config_options` keys grouped into Permissions / Security & access / Recording / Privacy & display / Network / Client UI lockdown, with accurate types and the verified select values for access-mode, approve-mode, verification-method, temporary-password-length)
- `resources/views/admin/strategies/edit.blade.php` (rewrite: grouped collapsible known-option controls — tri-state toggles, selects, number/text — pre-filled from the strategy, plus a Custom options section for any non-catalog keys)
- `app/Http/Controllers/Admin/StrategyController.php` (`edit` passes the catalog + computes non-catalog custom options; `update` merges `opt[<key>]` known options with the custom key/value rows, omitting empty = "client default"; ack now `{}`)
- `public/assets/js/app.js` (live-save serializer now also parses `name[key]` bracket notation into an object, so `opt[…]` posts as an associative array; `name[]` arrays unchanged)
- `tests/Feature/ApiResponseTest.php` (+2 tests: known/custom merge with empty-drop, edit page renders the catalog)
**Database/API Changes:** None (still writes the same `strategies.options` map → heartbeat `config_options`). Wire keys/values verified against the client (`libs/hbb_common/src/config.rs`, `password_security.rs`).
**Summary:** The strategy page was a bare key/value editor that didn't reveal what could be configured (and a save with no rows wiped everything). Deep-dived the RustDesk client config-option catalog and surfaced every policy-relevant option as a proper control grouped by purpose, with a tri-state Default/On/Off for toggles (matching `option2bool`'s default-on `enable-*` vs default-off `allow-*`/`hide-*` semantics) and correct enum choices for the selects — while keeping a Custom options section so any not-yet-cataloged key can still be pushed. Verified: Pint 140, PHPStan L5 0 errors, ESLint clean, 41 PHPUnit passed, all Blade views compile.

## [2026-06-22 16:30] - Admin console 2FA (TOTP) with post-password challenge
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/TwoFactorService.php` (added `verifyCode`, `generateSecret`, `provisioningUri`, `generateRecoveryCodes`, `verifyRecoveryCode`, `currentCode`; `verifyTotp` now delegates to `verifyCode`)
- `app/Http/Controllers/Admin/TwoFactorController.php` (NEW — enrollment show/enable/confirm/disable + session-gated challenge/verifyChallenge; rate-limited challenge)
- `app/Http/Controllers/Admin/AuthController.php` (defers login to the challenge when `two_factor_enabled`)
- `routes/web.php` (challenge routes outside `auth`; personal management routes inside the auth group)
- `resources/views/admin/two_factor/{show,challenge}.blade.php` (NEW), `resources/views/admin/partials/navbar.blade.php` (menu link)
- `tests/Feature/AdminTwoFactorTest.php` (NEW — 6 tests incl. render checks)
**Database/API Changes:** None (reuses existing `users.two_factor_*` columns). Enabling sets `login_verify='totp'`, so the same TOTP also protects the account's client login.
**Summary:** The client login already had TOTP/email 2FA but the web admin panel had none. Added TOTP enrollment (manual-key entry + otpauth URI — dependency-free, no QR lib/network needed) with one-time recovery codes, and a post-password challenge step: a correct password defers login (the user is logged back out and re-authenticated only after a valid code). Recovery codes and the challenge are rate-limited. Verified: Pint 139, PHPStan L5 0 errors, 38 PHPUnit passed (full suite) + all Blade views compile.

## [2026-06-22 15:40] - Security: brute-force throttling on both login surfaces
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Providers/AppServiceProvider.php` (new `api-login` named limiter: 10/min per account+IP + 30/min per IP, returns `{error}` 429)
- `routes/api.php` (`POST /api/login` → `throttle:api-login`)
- `app/Http/Controllers/Admin/AuthController.php` (in-controller throttle: 5 failed attempts/min per account+IP → redirect back with form error; clears on success)
- `tests/Feature/RateLimitTest.php` (NEW — 3 tests)
**Database/API Changes:** None (login responses unchanged on the happy path; throttled requests now return 429 `{error}` for the client API).
**Summary:** Closed the first research finding — there was **no** rate limiting anywhere, so `/api/login` and the admin login could be brute-forced unboundedly. Added a layered limiter for the client API (per-account+IP and a looser per-IP cap so an attacker can't cycle usernames) returning the `{error}` shape the client surfaces, and a Fortify-style in-controller throttle for the admin web login. Verified: Pint 137, PHPStan L5 0 errors, 33 PHPUnit passed.

## [2026-06-22 15:10] - New endpoint: `POST /api/devices/cli` (`rustdesk --assign`)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/DeploymentService.php` (new `assign()` — register/locate device + apply owner/strategy/device-group/identity/address-book presets)
- `app/Http/Controllers/Api/DevicesController.php` (new `cli()` action; empty 200 = success, plain-text reason on failure)
- `routes/api.php` (`POST /api/devices/cli`)
- `docs/modernization/02-client-api-contract.md` §7 (marked deploy+cli implemented, corrected cli field list), `docs/modernization/16-response-contract.md` (follow-up note)
- `tests/Feature/ApiResponseTest.php` (+2 tests)
**Database/API Changes:** New `POST /api/devices/cli` (deploy-token authenticated). No schema change.
**Summary:** Gap analysis of every `/api/*` the client calls vs our routes surfaced one unimplemented client endpoint — `POST /api/devices/cli`, behind `rustdesk --assign --token …`. It registers a device and assigns it to a strategy / device group / address book / owner in one token-authenticated CLI call (the named-preset vocabulary `applyPresets` already reads from sysinfo). No other OSS RustDesk server implements it. `/api/plugin-sign` was excluded (it's `/lic/web/...`, a Pro license-server path). Verified: Pint 136, PHPStan L5 0 errors, 30 PHPUnit passed.

## [2026-06-22 14:30] - Response-contract audit: fix 5 client-facing response-shape bugs
**Agent:** rustdesk-api (Claude Opus 4.8) + deep-dive sub-agent
**Files Modified:**
- `docs/modernization/16-response-contract.md` (NEW — authoritative per-endpoint response contract for all 33 client-called endpoints across the client's 4 parsers, + resolution log)
- Strategy edit wipe: `public/assets/js/app.js` (live-save serializer now gathers `name[]` inputs into real arrays instead of collapsing them)
- Empty-ack shape: `AddressBookController` (9×), `AuditController` (3×), `SystemController` (1×), `LoginController` logout — `response()->json([])` → `(object) []` so acks are `{}` not `[]` (left `loginOptions` + `ab/tags/{guid}` as arrays by design)
- Deploy: `DevicesController::deploy` now returns JSON `{"result": …}` (client JSON-parses + reads `result`)
- End-of-connection notes (2-endpoint feature): `AuditController::{active,note}`, `routes/api.php` (`GET /api/audit/conn/active`, `PUT /api/audit`, authed), `audit_conns.guid` migration + `AuditConn` fillable + guid issuance on new conns
- `AddressBookController::peerShape` `forceAlwaysRelay` now a string `'true'`/`'false'` (client compares `== 'true'`)
- Tests: `tests/Feature/ApiResponseTest.php` (NEW — 7 tests covering all of the above)
**Database/API Changes:** `audit_conns.guid` column; new `GET /api/audit/conn/active` + `PUT /api/audit`; deploy + AB/audit/system ack response shapes corrected (wire-compatible).
**Summary:** Fixed two reported bugs (strategy edit blanked all options — a frontend array-serializer collapse; address-book saves returned `[]` instead of `{}`) and, via a read-only deep dive of how the RustDesk client parses each endpoint, three further live bugs: deploy returned bare text the client couldn't parse (showed "OK" as an error), end-of-connection operator notes were an unimplemented 2-endpoint feature (guid query + note PUT), and `forceAlwaysRelay` was a boolean the client silently dropped. Verified: Pint 136, PHPStan L5 0 errors, ESLint clean, 28 PHPUnit passed.

## [2026-06-22 11:30] - Optional polish batch: login policy, console-op audit, RDP presets, tag colors
**Agent:** rustdesk-api (Claude Opus 4.8) + sub-agent
**Files Modified:**
- Login policy: `LoginController` + admin `AuthController` (disabled/unverified messages; `force_sso` local-login block), `users.force_sso` (migration + `User` model), `UserController` + `users/edit.blade.php` (Require SSO toggle)
- Console-operation audit: `console_audits` migration + `ConsoleAudit` model + `LogConsoleOperation` middleware (on the admin group) + `ConsoleAuditController` + view + route + sidebar nav (gated `audit.view`)
- Address book: client `AddressBookController` adds `tag_colors`; admin AB show surfaces RDP creds + tag-color dot
- Tests: `LoginPolicyTest` (3) + `ConsoleAuditTest` (2)
**Database/API Changes:** `users.force_sso`, new `console_audits` table, new `GET /admin/console-audit`; `tag_colors` added to AB responses (additive).
**Summary:** Built the doc-15 optional items. Login now gates disabled/unverified accounts and can require SSO; every admin write is recorded in a Console Operations log; address books surface RDP creds + per-tag colors. Verified: Pint 134, PHPStan 0, 21 PHPUnit + 5 E2E, plus HTTP confirmation of the unverified reject and the new pages.

## [2026-06-22 10:30] - Deep-scan features: alarm endpoint, session notes, live sessions + disconnect
**Agent:** rustdesk-api (Claude Opus 4.8) + 2 research sub-agents
**Files Modified:**
- `docs/modernization/{13-deepscan-connection,14-deepscan-management,15-deepscan-synthesis}.md` (research + decisions)
- `app/Http/Controllers/Api/AuditController.php` (`/api/audit/alarm` + 8 alarm types; operator session-note handling on `/api/audit/conn`); `routes/api.php`; `database/migrations/..._add_note_to_audit_conns_table.php`; `app/Models/AuditConn.php`
- `app/Http/Controllers/Api/SystemController.php` (heartbeat now delivers queued force-disconnects)
- `app/Http/Controllers/Admin/SessionController.php` + `resources/views/admin/sessions/index.blade.php`; `routes/web.php`; `app/Models/AdminRole.php` (sessions.view/edit perms); sidebar nav
- `tests/Feature/AuditSessionTest.php` (3 new tests)
**Database/API Changes:** New `POST /api/audit/alarm`; `audit_conns.note` column; new admin `/admin/sessions` + disconnect.
**Summary:** Deep-scanned the RustDesk client for Pro-tier/advanced features the panel lacked. Built the three clear stock-client wins: the connection-alarm endpoint (8 types), operator session notes, and Live Sessions + force-disconnect (via the heartbeat `disconnect` channel). Documented honest dead-ends (auto-update hardcoded to rustdesk.com, plugins/WoL/privacy-mode client-local). Verified: Pint 127, PHPStan 0, 16 PHPUnit + 5 E2E.

## [2026-06-21 14:05] - Phase 4 Layer 3 (Admin Roles) + Phase 5 (English README, Go retired)
**Agent:** rustdesk-api (Claude Opus 4.8) + sub-agent
**Files Modified:**
- Admin Roles: `database/migrations/..._create_admin_roles_table.php` + pivot; `app/Models/AdminRole.php`; `app/Services/PermissionService.php`; `app/Http/Middleware/CheckPermission.php`; `app/Http/Controllers/Admin/AdminRoleController.php` + views; `app/Models/User.php` (`adminRoles()`, `hasPermission()`); `EnsureAdmin` + admin `AuthController` (delegated admins); `permission:` applied across all admin routes; sidebar permission-gated; `tests/Feature/AdminRoleTest.php`
- `README.md` rewritten in English for the PHP project; `AGENT.md`/`CLAUDE.md` updated (Go retired)
- **Removed legacy Go**: `cmd/ model/ service/ http/ global/ lib/ utils/ debian/ systemd/ conf/ data/ runtime/`, `resources/{web,i18n,templates,version,public}/`, `docs/{admin,api,en_img}/`, `config/*.go`, root `*.go go.mod`, `build.{bat,sh}`, `README_EN.md`, legacy Dockerfiles/compose, `.github/workflows/build*.yml`, Go README images
**Database/API Changes:** New `admin_roles` + `admin_role_user` tables. `permission:` authz on admin routes.
**Summary:** Scoped Admin Roles (delegated console permissions; `is_admin` = full access, fully backward compatible) complete Phase 4. Then retired the entire Go codebase — the repository is now **single-stack PHP/Laravel**. Verified on the cleaned repo: Pint 124, PHPStan 0, ESLint OK, 13 PHPUnit, 5 E2E, and `docker compose up -d --build` deploys end-to-end (`/api/version` 200, `/admin/login` 200).

## [2026-06-21 13:10] - Phase 4 (batch 3, Layer 1): Access Control + client group API
**Agent:** rustdesk-api (Claude Opus 4.8) + sub-agent
**Files Modified:**
- `database/migrations/..._create_{user_group_access,device_group_access}_table.php`; `app/Models/{UserGroupAccess,DeviceGroupAccess}.php`
- `app/Services/AccessService.php`; `app/Http/Controllers/Api/GroupController.php`; `routes/api.php`
- `app/Http/Controllers/Admin/{Group,DeviceGroup}Controller.php` + `resources/views/admin/{groups,device_groups}/edit.blade.php` (access editors)
**Database/API Changes:** New `user_group_access` / `device_group_access` tables. New client endpoints `GET /api/users`, `/api/peers`, `/api/device-group/accessible` (previously missing).
**Summary:** Cumulative user-group + device-group access model (admins see all; users see own + grants; default-open) wired into the new client "group" endpoints. Verified: Pint 117, PHPStan 0, 10 PHPUnit + 5 E2E, a functional resolution test, and an HTTP round-trip.

## [2026-06-21 12:30] - Phase 4 (batch 2): OIDC/OAuth + LDAP/AD login
**Agent:** rustdesk-api (Claude Opus 4.8) + 2 sub-agents
**Files Modified:**
- Infra: `docker/Dockerfile.{toolchain,runtime}` (+ext-ldap), `composer.json` (+laravel/socialite)
- OIDC: `app/Services/OauthService.php`, `app/Http/Controllers/Api/OauthController.php`, `app/Http/Controllers/Admin/OauthProviderController.php` + views, `LoginController::loginOptions`, `config/services.php`, routes
- LDAP: `config/ldap.php`, `app/Services/LdapService.php`, `app/Http/Controllers/Admin/LdapController.php` + view, `LoginController` + admin `AuthController` integration, `.env.example`, routes
- `resources/views/admin/partials/sidebar.blade.php` (OAuth Providers + LDAP/AD nav)
- `docs/modernization/12-access-control-design.md` (design note for batch 3)
**Database/API Changes:** New client routes `/api/oidc/auth`, `/api/oidc/auth-query`, `/api/oauth|oidc/callback|msg`; admin routes for OAuth providers + LDAP. No schema change (providers use existing `oauth_providers`; LDAP is config-driven).
**Summary:** OIDC poll-based device login (Socialite for github/google, generic OIDC discovery) + provider admin CRUD; optional LDAP/AD login (service bind + user re-bind verification, group gating, sync) wired into client and admin login, **disabled by default**. Runtime image rebuilt with ext-ldap. All gates + 10 PHPUnit + 5 E2E green.

## [2026-06-18 23:50] - Phase 4 (batch 1): alarms, recording UI, deploy tokens + device approval
**Agent:** rustdesk-api (Claude Opus 4.8) + sub-agent
**Files Modified:**
- `app/Models/Alarm.php` + `database/migrations/..._create_alarms_table.php`; `app/Services/AlarmService.php`
- `app/Http/Controllers/Admin/{Alarm,Recording,DeployToken}Controller.php` + `resources/views/admin/{alarms,recordings,deploy_tokens}/*`
- `app/Http/Controllers/Api/AuditController.php` (raise alarm on new connection)
- `routes/web.php` (+9 admin routes), `resources/views/admin/partials/sidebar.blade.php` (nav; removed dead Live Sessions link)
- `database/seeders/MailTemplateSeeder.php` (alarm template now uses {$device}/{$message})
- Fixed a nullsafe-on-non-null PHPStan finding in AlarmService
**Database/API Changes:** New `alarms` table. New admin routes for recordings/alarms/deploy-tokens/pending-devices. AuditController now raises alarms.
**Summary:** Connection alarm notifications (+ email via the mail subsystem), recording playback (list/stream/download with path-traversal guard), and deploy-token management + pending-device approval. Re-ran all gates after integration: Pint 105, PHPStan 0, PHPUnit 10/10.

## [2026-06-18 23:15] - Quality gates + test suite + CI + polish — all green
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `pint.json`, `phpstan.neon` + `phpstan-baseline.neon` (Larastan, baselined Eloquent-cast false positives), `eslint.config.mjs`
- `composer.json` (larastan + lint/stan scripts), `package.json` (@playwright/test + scripts)
- `tests/Feature/SmokeTest.php` (10 feature tests; removed default ExampleTest)
- `playwright.config.ts`, `e2e/login.spec.ts` (5 E2E specs)
- `.github/workflows/ci.yml` (PHP gates+tests, JS lint, Playwright E2E jobs)
- `resources/views/admin/partials/navbar.blade.php` (real logged-in user; removed dead profile link)
**Database/API Changes:** None.
**Summary:** Established and passed all quality gates — Pint (99 files), PHPStan level 5 (0 errors via baseline), ESLint (0), PHPUnit (10 passed/26 assertions), Playwright E2E (5 passed). Added a GitHub Actions CI workflow running every gate, and polished the admin navbar.

## [2026-06-18 22:45] - One-command Docker deployment (PHP 8.5 + Apache runtime) — verified
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `docker/Dockerfile.runtime` (multi-stage: composer build → php:8.5-apache, public docroot)
- `docker/entrypoint.sh` (auto APP_KEY gen+persist, migrate, first-run seed, prod caches)
- `docker/apache-laravel.conf`, `docker-compose.yml` (root; SQLite default, single volume, one port)
- `QUICKSTART.md`, `.dockerignore` (exclude Go + stale compiled caches), `.gitattributes` (LF for *.sh)
- `routes/web.php` (`/` → `Route::redirect` so `route:cache` works), `DatabaseSeeder` (admin created only if absent)
- Quality-gate config: `pint.json`, `phpstan.neon`, `eslint.config.mjs`, `package.json`, `composer.json` (larastan + lint/stan scripts)
**Database/API Changes:** None (deployment only).
**Summary:** `docker compose up -d` now brings up the entire app at http://localhost:21114 with ZERO config — SQLite by default (no DB container), auto-generated persistent APP_KEY, auto-migrate + first-run admin seed. Verified via curl and a Playwright login against the production Apache image (PROD_LOGIN_OK). Fixed two real deploy bugs: CRLF entrypoint, and baking dev-only compiled caches (Pail provider) into the --no-dev image.

## [2026-06-18 22:10] - Integrated client API + admin console + mail (parallel agents) — verified end-to-end
**Agent:** rustdesk-api (Claude Opus 4.8) + 3 parallel sub-agents
**Files Modified:**
- Client API: `app/Http/Controllers/Api/{Login,AddressBook,Audit,Record,Devices}Controller.php`, `app/Http/Middleware/RustAuth.php`, `app/Services/{TwoFactor,Recording,Deployment}Service.php`, `routes/api.php`, `bootstrap/app.php`
- Admin: `app/Http/Controllers/Admin/*` (Dashboard, Device, User, Group, DeviceGroup, AddressBook, Strategy, Audit, Setting), `resources/views/admin/*` (CRUD pages), `routes/web.php` (46 admin routes)
- Mail: `app/Services/MailService.php`, `database/seeders/{MailTemplate,Demo,DatabaseSeeder}.php`
- Fixed `database/factories/UserFactory.php` + `DatabaseSeeder` to the reshaped users schema (username/admin seed)
**Database/API Changes:** Full client `/api/*` surface (login+2FA, address book, audit, record, deploy) + complete admin console. Seeds default admin + mail templates + demo strategy.
**Summary:** Three parallel agents built the breadth; integration verified on PHP 8.5 — `migrate:fresh --seed` clean, 78 routes resolve, all 89 compiled Blade views lint-clean, `/api/login` returns a valid AuthBody, sysinfo presets auto-assign a strategy, heartbeat pushes that strategy's config_options, and Playwright drove 6 admin pages with **zero HTTP errors**.

## [2026-06-18 21:20] - Data model + flagship differentiators (strategy-push, presets) on PHP 8.5
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `docker/Dockerfile.toolchain` (switched to PHP 8.5 via mlocati/php-extension-installer)
- `database/migrations/*` + `app/Models/*` (22 entities; users reshaped to real schema)
- `app/Models/User.php`, `app/Http/Controllers/Admin/AuthController.php`, `app/Http/Middleware/EnsureAdmin.php`, `app/Console/Commands/CreateUser.php`, `routes/web.php`, `bootstrap/app.php` (admin auth)
- `config/rustdesk.php`, `routes/api.php`, `app/Http/Controllers/Api/{IndexController,SystemController}.php`, `app/Services/StrategyService.php`
- `Wiki/core/06-design-system.md`
**Database/API Changes:** Full RustDesk schema (devices, address books, strategies+assignments, audit, mail, etc.). New endpoints: `/api`, `/api/version`, `/api/heartbeat`, `/api/sysinfo`, `/api/sysinfo_ver`.
**Summary:** Toolchain now PHP 8.5.7; complete data model; admin login verified end-to-end; and the two differentiators no other OSS server has — heartbeat Strategy-push (with modified_at change-detection) and sysinfo preset auto-registration (strategy/device-group/address-book) — built and verified.

## [2026-06-18 20:25] - Rebuild foundation: toolchain, Laravel scaffold, docs scaffold, dark theme
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `docker/Dockerfile.toolchain`, `docker/compose.toolchain.yml` (PHP 8.5 + Composer + Node + Playwright + MariaDB + Mailpit)
- `composer.json`, `artisan`, `app/`, `routes/`, `config/`, `bootstrap/`, `database/`, `public/index.php`, `vendor/` (Laravel 13.16 scaffold, merged in-repo)
- `AGENT.md`, `CLAUDE.md`, `HOW-TO.md`, `DESIGN.md`, `Wiki/`, `DevOps/`, `Skills/` (imported documentation/workflow scaffold; AGENT.md tailored; CLAUDE.md → AGENT.md)
- `public/assets/css/theme-dark.css` (modern dark dashboard theme — original CSS)
- `docs/modernization/07-rewrite-plan-php.md`, `08-build-log.md`, `09-port-status.md` (rebuild plan + tracking)
**Database/API Changes:** Laravel default migrations applied on SQLite smoke DB (users/cache/jobs). No RustDesk-facing schema yet.
**Summary:** Stood up the PHP/Laravel rebuild foundation — Docker build/test toolchain, in-repo Laravel 13 scaffold, imported documentation scaffold (Wiki/DevOps/Skills + AGENT/CLAUDE), and the dark admin theme; legacy Go retained as reference.
