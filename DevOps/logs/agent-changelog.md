# 📝 Agent Changelog

All changes made by AI agents are tracked chronologically below (newest first).
Format defined in [AGENT.md](../../AGENT.md) → Mandatory wrap-up protocol.

## [2026-07-17 18:55] - Prepare v1.0.0 and close HTTPS proxy recovery
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `config/app.php`, `.env.example`, `docker-compose.yml`, `docker-compose.dev.yml`
- `app/Http/Controllers/Api/IndexController.php`, `app/Http/Controllers/Api/SystemController.php`
- `tests/Feature/SmokeTest.php`, `examples/full-stack.docker-compose.yml`
- `README.md`, `QUICKSTART.md`, `CHANGELOG.md`, `docs/releases/v1.0.0.md`
- `Skills/Test-and-Deploy/SKILL.md`
- `docs/modernization/08-build-log.md`, `DevOps/logs/version-history.md`
- `DevOps/archive-plans/https-proxy-mixed-content.md`
**Database/API Changes:** No schema or RustDesk wire-shape change. The existing `1.0.0` version
response is now sourced from centralized, source-controlled `config('app.version')` instead of
duplicated controller fallbacks. It is not operator-overridable, preventing stale version reports
when retained deployment environments upgrade to a newer image.
**Summary:** Prepared the first stable v1.0.0 release with public notes, standard Semantic
Versioning policy, versioned-image and digest-pinning guidance, and synchronized version metadata. Expanded the
1Panel/OpenResty setup procedure and closed the mixed-content incident after production supplied
its exact application-observed proxy peer, enabled secure cookies, recreated the container, and
passed the fail-closed public HTTPS checker. The release matrix passed 538 PHPUnit tests / 3,051
assertions, 68 Playwright tests with 12 intentional screenshot-mode skips, Pint across 275 files,
PHPStan across 177 files, JavaScript/vendor integrity, Composer, Compose, shell, documentation,
runtime-image, and version-reporting checks.

## [2026-07-17 17:25] - Deliver HTTPS proxy source safeguards
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `DevOps/logs/agent-changelog.md`, `DevOps/logs/version-history.md`
- `DevOps/plans/https-proxy-mixed-content.md`
- `docs/modernization/08-build-log.md`
**Database/API Changes:** None. This delivery record changes no application behavior, database
state, route, or RustDesk response contract.
**Summary:** Pushed the separately revertible proxy-header security commit `52410f9` and HTTPS
recovery-tooling commit `4c28f08` to `origin/main` after reconfirming a clean worktree, GitHub
authentication, and the prior full Docker verification. GitHub CI run `29623089296` and Docker
Publish run `29623089305` both completed successfully. No production deployment was performed.
The public smoke check still fails on the HTTP login redirect, so the active plan remains open for
the production `TRUSTED_PROXIES` value, container recreation, and live verification.

## [2026-07-15 22:41] - Add HTTPS proxy diagnosis and recovery tooling
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `.env.example`, `docker-compose.yml`, `examples/full-stack.docker-compose.yml`
- `docker/entrypoint.sh`, `scripts/check-https-proxy.sh`
- `README.md`, `QUICKSTART.md`
- `DevOps/plans/https-proxy-mixed-content.md`
- `docs/modernization/08-build-log.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Database state, RustDesk client paths/keys, response bodies, and
application routes are unchanged.
**Summary:** Added secure-cookie passthrough/examples, a parsed-config startup warning, safe
proxy-network guidance, and a fail-closed public HTTPS smoke check. The runtime image and full
local matrix are green, but the live incident remains open until production supplies its actual
proxy IP/CIDR, recreates the API container, and passes the public probe. The completed source
changes were delivered to `origin/main` on 2026-07-17 as `52410f9` and `4c28f08`.

## [2026-07-15 22:40] - Restrict trusted proxy header surface
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `bootstrap/app.php`
- `tests/Feature/TrustedProxySecurityTest.php`
- `Wiki/core/15-security.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. The RustDesk wire contract is unchanged. Trusted proxies continue
to supply the forwarded client address and HTTPS scheme; forwarded host, port, and prefix values
are now deliberately ignored.
**Summary:** Limited Laravel's trusted proxy mask to `X-Forwarded-For` and
`X-Forwarded-Proto`, preventing a trusted OpenResty/Nginx hop from passing attacker-controlled URL
host/port/prefix overrides. Regression coverage includes HTTPS assets/redirects/cookies,
untrusted scheme spoofing, hostile forwarded URL headers, and sanitized nonstandard public ports.

## [2026-07-15 21:45] - CI: migrate GitHub Actions to Node 24 runtimes
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `.github/workflows/ci.yml`, `.github/workflows/docker-publish.yml`
- `DevOps/archive-plans/github-actions-node24.md`
- `DevOps/plans/github-actions-node24.md` (archived)
- `DevOps/logs/agent-changelog.md`, `DevOps/logs/version-history.md`
- `docs/modernization/08-build-log.md`
**Database/API Changes:** None. Workflow triggers, permissions, project Node version, application
behavior, database schema, and RustDesk client contract are unchanged.
**Summary:** Replaced every checkout/setup-node Node 20 action with exact immutable v7.0.0 SHA
pins whose manifests declare Node 24, and removed one pre-existing workflow shell-lint warning.
Local actionlint, pin, ESLint, vendor-integrity, and diff checks passed. GitHub CI run
`29470504915` and Docker Publish run `29470504901` both completed successfully with no Node 20
deprecation text in their logs; the latter published both architectures in 39m36s. Obsolete
publish run `29470043683` was cancelled to prevent a stale `latest`-tag overwrite.

## [2026-07-15 20:45] - Complete WebUI review remediation and security hardening
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `DevOps/archive-plans/webui-security-hardening.md`
- `DevOps/plans/webui-security-hardening.md` (archived)
- `DevOps/logs/agent-changelog.md`, `DevOps/logs/version-history.md`
- `docs/modernization/08-build-log.md`, `docs/modernization/09-port-status.md`
**Database/API Changes:** None in this documentation-only wrap-up. The separately committed and
logged MariaDB constraints/indexes preserve all RustDesk client paths, JSON keys, status codes,
and response shapes.
**Summary:** Closed the full WebUI functional/accessibility and security review after independent
commit-boundary and adversarial checks. The independently revertible series is the contents of
the user-authorized completion push to `origin/main`; no deployment or formal release was made.
Final Docker gates passed 532 PHPUnit tests / 3,018 assertions, Pint across 275 files, PHPStan with
no errors, and 68 Playwright tests with 12 intentional screenshot-mode skips. ESLint, 20-file
vendor integrity, Blade compilation, all Compose renders, strict Composer validation, Composer
audit, and npm audit were also green. The user-owned untracked `AGENTS.md` remained untouched.

## [2026-07-15 20:30] - Fix: enforce peer identity within each address book
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Models/AddressBookPeer.php`
- `app/Http/Controllers/Api/AddressBookController.php`
- `app/Http/Controllers/Api/V1/AddressBookController.php`
- `app/Http/Controllers/Admin/AddressBookController.php`
- `database/migrations/2026_07_15_100004_enforce_address_book_peer_identity.php`
- `tests/Feature/AddressBookPeerIdentityMigrationTest.php`
- `tests/Feature/AddressBookPeerConcurrencyTest.php`
- `Wiki/core/08-core-architecture.md`, `docs/modernization/08-build-log.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds the named MariaDB unique index
`address_book_peers_unique_book_rustdesk_id` on `(address_book_id, rustdesk_id)`. The migration
aborts before DDL if historical duplicate pairs exist; rollback drops only this index. Existing
client/admin API paths, JSON keys, status codes, and success/error response shapes are preserved.
**Summary:** Made the database the final authority for peer identity so simultaneous inserts
cannot create duplicate rows. Every single-peer writer retains its friendly precheck and maps a
confirmed late unique conflict to the established RustDesk, API-v1, admin-modal, or CSV-skip
result; confirmation reads from the writer. Legacy full replacement is transactional and
collapses repeated payload IDs through update-or-create, while existing preset writers become
durable through the same index. The migration reports bounded conflicting pairs without silently
choosing between credentials/metadata and rejects a colliding named index with the wrong
definition. Focused Docker suites passed 58 tests / 278 assertions; targeted Pint, PHPStan, and
diff checks were clean. The commit remains local on `main` pending final matrices, wrap-up, and
the authorized completion push.

## [2026-07-15 20:05] - Fix: enforce one personal address book per owner
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Models/AddressBook.php`, `app/Http/Controllers/Api/AddressBookController.php`
- `database/migrations/2026_07_15_100003_enforce_personal_address_book_singleton.php`
- `database/seeders/DemoShowcaseSeeder.php`
- `tests/Feature/PersonalAddressBookInvariantTest.php`
- `tests/Feature/PersonalAddressBookStateMigrationTest.php`
- `Wiki/core/08-core-architecture.md`, `docs/modernization/08-build-log.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds nullable `address_books.is_personal`, the named MariaDB CHECK
`address_books_personal_marker_valid`, and unique index
`address_books_one_personal_per_user`. Existing API paths, JSON keys, and response shapes remain
unchanged. Rollback removes only the marker/index/CHECK and preserves every collection after a
read-only compatibility preflight; it refuses ambiguous states before DDL.
**Summary:** Made the default personal collection a durable database identity instead of a
race-prone name lookup. Concurrent first-use requests now converge through Laravel's
create-or-first retry and the unique index. Migration backfill marks only the lowest-ID legacy
default-name match per owner, preserves every other book and all dependent data, validates any
partially applied marker/schema state before proceeding, installs the column/CHECK/index in one
ALTER on fresh databases, and safely resumes around MariaDB's auto-committing DDL. Rollback fails
closed if the old name resolver would choose a different book. Ordinary same-named books can no
longer hijack personal client requests, and the showcase seeder uses the same marker. Focused
Docker suites passed 33 tests / 134 assertions;
targeted Pint, PHPStan, and diff checks were clean. The commit remains local on `main` pending the
remaining peer-identity hardening, final matrices, and authorized completion push.

## [2026-07-15 19:50] - Test: stabilize address-book browser validation
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `e2e/gui.spec.ts`, `e2e/login.spec.ts`
- `e2e/accessibility.spec.ts`, `e2e/screenshots.spec.ts`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. The browser fixture still uses the public RustDesk client API and
the application behavior and wire contract are unchanged.
**Summary:** Removed Playwright's redundant submit-click navigation wait while retaining an
explicit completed-navigation wait, preventing a slow Docker response from timing out after a
successful sign-in. Address-book fixtures now add their peer to the exact numeric collection
returned by the preceding request, use a high-entropy numeric ID, and require the protocol's
empty success body so HTTP-200 business errors fail during setup rather than masquerading as UI
regressions. The previously failing desktop-dark validation scenario passed four consecutive
runs with two workers. The commit remains local on `main` pending the final full matrix and
authorized completion push.

## [2026-07-15 19:45] - Security: require an address for email verification
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/UserController.php`, `app/Http/Controllers/Api/V1/UserController.php`
- `app/Console/Commands/CreateUser.php`, `app/Services/LdapService.php`
- `resources/views/admin/users/create.blade.php`, `resources/views/admin/users/edit.blade.php`
- `database/migrations/2026_07_15_100002_require_email_for_email_verification.php`
- `tests/Feature/EmailVerificationAddressValidationTest.php`
- `tests/Feature/UserEmailVerificationStateMigrationTest.php`
- `tests/Feature/CreateUserCommandTest.php`, `tests/Feature/LdapIdentitySecurityTest.php`
- `tests/Feature/UserTwoFactorStateMigrationTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/08-build-log.md`
- `docs/modernization/09-port-status.md`, `docs/sqlite-to-mariadb.md`, `docker/README.md`
- `DevOps/plans/webui-security-hardening.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds the MariaDB CHECK constraint
`users_email_verification_requires_address`. Its read-only preflight aborts before DDL when an
exact `email` policy lacks a non-whitespace address and reports a bounded list of affected user
IDs. No column, RustDesk client path, JSON key, or wire shape changes. Admin and API v1 writes now
return validation errors for invalid combinations; rollback drops only the constraint.
**Summary:** Made a nonblank destination part of the email-verification state invariant. Admin
creation/editing, partial API updates, and the `rustdesk:user` command cannot enable or preserve
email verification while clearing its address. LDAP synchronization fails closed before any
linked-account mutation if the directory removes or malforms a required address. The console
explains the conditional requirement, while byte-exact database comparisons prevent
case-insensitive collation
from diverging from PHP policy handling. Invalid historical state is never silently downgraded to
password-only: operators must add an address or explicitly change policy before retrying the
quiesced migration. Focused Docker suites passed 37 tests / 285 assertions; targeted Pint/PHPStan,
Blade compilation, and diff checks were clean. Complete-suite results are recorded in the final
hardening wrap-up. The commit remains local on `main` and was not pushed.

## [2026-07-15 19:15] - Security: enforce canonical user TOTP state
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Models/User.php`, `app/Http/Controllers/Admin/UserController.php`
- `resources/views/admin/users/create.blade.php`, `resources/views/admin/users/edit.blade.php`
- `database/migrations/2026_07_15_100001_normalize_user_two_factor_state.php`
- `tests/Feature/AdminUserTwoFactorStateTest.php`
- `tests/Feature/UserTwoFactorStateMigrationTest.php`
- `tests/Feature/RecoveryCodeCallerStateTest.php`, `tests/Feature/RecoveryCodeSecurityTest.php`
- `tests/Feature/TotpSecretEncryptionTest.php`, `tests/Feature/TotpSecretMigrationTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/08-build-log.md`
- `docs/modernization/09-port-status.md`, `docs/sqlite-to-mariadb.md`, `docker/README.md`
- `DevOps/plans/webui-security-hardening.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds the MariaDB CHECK constraint
`users_two_factor_state_consistent` after a fail-before-write encrypted-seed preflight and
one-way repair. No table/column, `/api/*`, JSON-key, or RustDesk wire-shape change. Rollback drops
only the constraint and deliberately keeps repaired account state.
**Summary:** Made protected console self-service the only TOTP enrollment authority. Generic
account creation and editing no longer accept TOTP policy or factor material; active factors are
shown read-only and preserved under a user-row lock, while inactive `off`/`email` updates clear
orphan metadata. Factor fields must be entirely absent from generic requests and are defensively
removed before persistence, closing empty/null/array validation bypasses that could corrupt a
seed or recovery list. The migration decrypts and validates every stored seed with
current/previous application keys before changing any row, resolves historical split states by
strongest active intent, clears unusable/orphan state, normalizes unknown policy to `off`, and
uses byte-exact policy comparisons so MariaDB's case-insensitive text collation cannot admit a
value PHP would interpret differently. The invariant is durable in MariaDB. Focused Docker suites
passed 23 tests / 157 assertions; targeted Pint/PHPStan, Blade compilation, and diff checks were
clean. The complete-suite rerun is
recorded in the final hardening wrap-up. The commit remains local on `main` and was not pushed.

## [2026-07-15 18:00] - Security: require recent authentication for two-factor management
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Support/RecentAdminAuthentication.php`
- `app/Http/Middleware/EnsureCredentialVersion.php`
- `app/Http/Controllers/Admin/TwoFactorController.php`, `app/Http/Controllers/Admin/AuthController.php`
- `resources/views/admin/two_factor/show.blade.php`, `routes/web.php`, `config/auth.php`
- `.env.example`, `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `tests/Feature/AdminTwoFactorTest.php`, `tests/Feature/AdminSsoLoginTest.php`
- `tests/Feature/LdapIdentitySecurityTest.php`, `tests/Feature/AccountPasswordPolicyTest.php`
- `e2e/accessibility.spec.ts`, `e2e/gui.spec.ts`
- `Wiki/core/15-security.md`, `docs/modernization/08-build-log.md`
- `docs/modernization/09-port-status.md`, `DevOps/plans/webui-security-hardening.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Existing TOTP columns, encrypted seeds, recovery digests, client
login behavior, `/api/*` paths, and RustDesk wire shapes remain unchanged. The optional
`AUTH_TWO_FACTOR_MANAGEMENT_TIMEOUT` setting defaults to 300 seconds and is clamped to 60-900.
**Summary:** Personal authenticator setup and removal now require an encrypted, account-,
credential-, regenerated-session-ID-, and time-bound completed-sign-in marker. Enrollment state
cannot cross accounts, sessions, or authentication windows; stale sessions reveal no setup
secret; route-level session blocking prevents concurrent state resurrection; and row locking
prevents setup from overwriting an enabled factor. Removal now works for local, LDAP, and SSO
administrators through their normal application sign-in plus a throttled current authenticator or
single-use recovery code. A factor proved during local/LDAP sign-in carries assurance bound to
that exact enrollment, so the final recovery code can authorize removal without proof for an old
factor applying to its replacement; SSO continues to require a code. Added real setup
cancellation and sign-out/re-entry flows, documented provider-controlled SSO reauthentication,
and covered expiry boundaries, malformed/replayed state, credential/factor changes, concurrency,
throttling, recovery consumption, and all three sign-in paths. Focused Docker suites passed 50
tests / 534 assertions; targeted Pint/PHPStan, Blade compilation, route and Playwright discovery,
and diff checks were clean. The complete-suite rerun will be recorded in the final hardening
wrap-up. The commit remains local on `main` and was not pushed.

## [2026-07-14 23:10] - Build: require MariaDB
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `.env.example`, `config/database.php`, `config/queue.php`, `composer.json`, `composer.lock`
- `app/Support/MariaDbConnectionBoundary.php`, `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/Api/AddressBookController.php`
- `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `docker/Dockerfile.runtime`, `docker/Dockerfile.toolchain`, `docker/entrypoint.sh`
- `docker/compose.toolchain.yml`, `docker/e2e.sh`, `docker/demo-shots.sh`
- `.github/workflows/ci.yml`, `phpunit.xml`, `tests/TestCase.php`
- `tests/Feature/ApiResponseTest.php`, `tests/Feature/EmailLoginChallengeSecurityTest.php`
- `tests/Feature/EmailLoginChallengeMigrationTest.php`, `tests/Feature/MariaDbConnectionBoundaryTest.php`
- `README.md`, `QUICKSTART.md`, `AGENT.md`, `CLAUDE.md`, `docker/README.md`
- `docs/DEVELOPMENT.md`, `docs/sqlite-to-mariadb.md`, `docs/screenshots/README.md`
- `Wiki/core/08-core-architecture.md`, `Wiki/database/database-index.md`
- `docs/modernization/01-architecture-and-current-state.md`
- `docs/modernization/06-reference-implementations.md`, `docs/modernization/07-rewrite-plan-php.md`
- `docs/modernization/08-build-log.md`, `docs/modernization/09-port-status.md`
- `docs/modernization/README.md`, `DevOps/archive-plans/mariadb-only-support.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** MariaDB with InnoDB is now the sole runtime and test database contract.
Existing MariaDB deployments rename `DB_CONNECTION=mysql` to `mariadb` only after the documented
server/default-engine/table-engine audit; existing SQLite deployments must migrate manually on
the last compatible release before upgrading. `DB_URL` and every other driver are rejected. No
schema or RustDesk wire-key/path change was introduced; `forceAlwaysRelay` still returns its
required string shape after strict MariaDB boolean normalization.
**Summary:** Removed the SQLite runtime/test target, packages, fallback configuration, and active
support guidance. Added fail-closed cached-configuration and live first-query/reconnect guards for
the exact schema, MariaDB identity, InnoDB default, and InnoDB-only table set. Isolated PHPUnit,
E2E, and screenshot resets behind exact tmpfs-backed MariaDB schemas, mirrored those boundaries in
CI, hardened container readiness/TLS/socket/runtime-user checks, and documented the breaking
upgrade boundary. Verified 466 PHPUnit tests / 2,464 assertions, Pint across 262 files, PHPStan
across 172 files, clean Composer/npm audits, JavaScript/vendor/Blade/shell/Compose checks, runtime
image boot and negative boundaries, 68 Playwright passes / 12 intentional skips, and one dedicated
screenshot capture pass. The work is an independent local commit on `main` and was not pushed.

## [2026-07-14 22:04] - Web: close responsive browser QA gaps
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `resources/views/admin/partials/navbar.blade.php`
- `e2e/gui.spec.ts`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Named the account menu independently of its responsive label, made the bulk-action focus assertion target the exact launcher, and replaced a timing-sensitive live-save response delay with an explicit test gate. The fixes preserve production behavior while making mobile accessibility and asynchronous form-state coverage deterministic.

## [2026-07-14 21:39] - Style: normalize audit-ingestion test layout
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `tests/Feature/AuditIngestionSecurityTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Applied the repository's current Pint class-separation rule to the audit-ingestion regression suite; behavior is unchanged and the focused formatting check passes in Docker.

## [2026-07-14 21:36] - Web: preserve scoped read-only context
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/DeviceController.php`, `app/Http/Controllers/Admin/DeviceGroupController.php`
- `app/Http/Controllers/Admin/GroupController.php`, `app/Http/Controllers/Admin/StrategyController.php`
- `tests/Feature/AdminReadOnlyUiTest.php`, `tests/Feature/AdminResourceScopeTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Scoped view-only delegates now retain the readable labels for current group, device-group, and strategy relationships without receiving edit controls or crossing their resource boundary. Regression fixtures now exercise valid in-scope relationships, MariaDB UUID values, and independent authenticated browser sessions. Nine focused MariaDB tests / 141 assertions, Pint, targeted PHPStan, and diff checks passed in Docker.

## [2026-07-14 20:37] - Security: encrypt authenticator secrets at rest
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Support/TotpSecretFormat.php`, `app/Models/User.php`
- `app/Http/Controllers/Admin/TwoFactorController.php`
- `database/migrations/2026_07_14_100007_encrypt_two_factor_secrets.php`
- `tests/Feature/AdminTwoFactorTest.php`, `tests/Feature/TotpSecretEncryptionTest.php`, `tests/Feature/TotpSecretMigrationTest.php`
- `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `README.md`, `docker/README.md`, `Wiki/core/15-security.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** `users.two_factor_secret` becomes nullable `TEXT`; existing Base32 seeds are encrypted idempotently with the application key. The model and HTTP/API shapes are unchanged. Rollback decrypts only with the current or configured previous keys and aborts on unknown/corrupt values.
**Summary:** TOTP seeds now use the framework's encrypted model cast, enrollment candidates are explicitly encrypted inside database-backed sessions, valid in-flight plaintext sessions upgrade once, and corrupt values fail closed. Migration preflight prevents partial rewrites, setup/recovery responses are private/no-store, runtime Compose surfaces forward current/previous application keys, and deployment documentation covers coupled database/key backups, rotation, replicas, and maintenance migration. Twenty-seven focused MariaDB tests / 270 assertions, migration up/down/reapply, Pint, targeted PHPStan, all Compose validations, and diff checks passed in Docker.

## [2026-07-14 12:28] - Security: protect recovery codes at rest
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Support/RecoveryCodeProtector.php`, `app/Services/TwoFactorService.php`
- `app/Http/Controllers/Admin/TwoFactorController.php`
- `database/migrations/2026_07_14_100006_hash_two_factor_recovery_codes.php`
- `tests/Feature/AdminTwoFactorTest.php`, `tests/Feature/RecoveryCodeSecurityTest.php`
- `.env.example`, `Wiki/core/15-security.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Existing plaintext recovery-code JSON values are replaced with versioned keyed digests. Rollback invalidates protected recovery lists because their plaintext is intentionally unrecoverable; TOTP authenticators remain valid.
**Summary:** Recovery codes now use domain-separated HMAC-SHA-256 digests keyed by the current or previous application keys, legacy rows migrate idempotently under row locks, and malformed entries fail closed. Enrollment renders plaintext exactly once in a private no-store response instead of persisting it in the database session. Twenty-four related tests / 243 assertions, Pint, targeted PHPStan, SQLite/MariaDB migration up/down/reapply, and diff checks passed in Docker.

## [2026-07-14 12:21] - Security: serialize recovery-code consumption
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/TwoFactorService.php`
- `tests/Feature/RecoveryCodeSecurityTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Recovery-code verification now reloads and consumes the current database value under a transaction and row lock without dirtying the caller model. Concurrent or stale requests cannot reuse a consumed code, and a later unrelated caller save cannot resurrect one. Twenty related tests / 211 assertions, Pint, targeted PHPStan, and diff checks passed in Docker.

## [2026-07-14 12:15] - Security: bind pending 2FA to credential version
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/TwoFactorController.php`
- `tests/Feature/AdminTwoFactorTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Existing pending-challenge session keys and HTTP behavior are unchanged.
**Summary:** Extended the HMAC-protected admin two-factor login marker to bind the account's credential version as well as its password hash. A version-only credential revocation now invalidates a pending challenge even if the hash does not change. Eleven focused tests / 101 assertions, Pint, targeted PHPStan, and diff checks passed in Docker.

## [2026-07-14 12:06] - Security: protect CLI account password input
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Console/Commands/CreateUser.php`
- `tests/Feature/CreateUserCommandTest.php`
- `README.md`, `QUICKSTART.md`, `docs/DEVELOPMENT.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. The CLI keeps its deprecated positional password form for compatibility, while the safe default is a non-trimming hidden confirmation prompt and automation can use `--password-stdin`.
**Summary:** Prevented accidental password echo, console-markup injection, and state-changing reset side effects. CLI password assignments now share the 12–255 character policy, reject echoed terminal input and invalid usernames/email before mutation, preserve disabled state and existing administrator authority, treat `--admin` as grant-only, and roll back email/authority changes when a federated reset is denied. Twenty-three related tests / 153 assertions, focused Pint, targeted PHPStan, and diff checks passed in Docker.

## [2026-07-14 10:32] - Security: bind LDAP identities explicitly
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/LdapService.php`, `app/Models/LdapIdentity.php`
- `app/Http/Controllers/Admin/AuthController.php`, `app/Http/Controllers/Api/LoginController.php`
- `database/migrations/2026_07_14_100004_create_ldap_identities_table.php`
- `config/ldap.php`, `.env.example`
- `tests/Feature/LdapIdentitySecurityTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/01-architecture-and-current-state.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds `ldap_identities` with unique local-user and provider/subject bindings. Login response shapes are unchanged; ambiguous pre-existing usernames create a separate collision-safe LDAP account instead of being linked.
**Summary:** Closed local and delegated administrator takeover through LDAP username collisions. Login now uses only the exact persisted directory identity, requires an immutable configured subject, keeps links/linked usernames stable, and handles concurrent first login transactionally. Five focused tests / 54 assertions, 59 authentication regression tests / 390 assertions, Pint, targeted PHPStan, and migration up/down/reapply passed.

## [2026-07-14 10:30] - Security: fail closed on unknown device enrollment
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/SystemController.php`, `app/Services/DeviceAutoRegistrationGuard.php`
- `config/rustdesk.php`, `.env.example`
- `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `tests/Feature/DeviceAutoRegistrationSecurityTest.php` and related telemetry/default-strategy regression tests
- `QUICKSTART.md`, `Wiki/core/15-security.md`, `docs/modernization/02-client-api-contract.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Unknown devices now fail closed by default (`require_deployment=true`, `auto_register=false`) and retain the stock `{}` / `ID_NOT_FOUND` rejection shapes. Existing approved-device shapes are unchanged.
**Summary:** Prevented unauthenticated first-claim registration, strategy-secret disclosure, unbounded device/webhook growth, and disconnect consumption by unknown callers. Explicit legacy enrollment remains available with 30/source/minute, 100 global/minute, and 5,000 total-device defaults. Seventy-one related tests / 382 assertions, Pint, targeted PHPStan, all Compose validations, and diff checks passed.

## [2026-07-14 10:19] - Security: protect OAuth identity trust roots
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/OauthProviderController.php`, `app/Models/AdminRole.php`
- `resources/views/admin/oauth_providers/{index,edit}.blade.php`
- `tests/Feature/OauthProviderAuthorizationSecurityTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/12-access-control-design.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Delegates retain redacted `oauth.view`; provider creation, mutation, and deletion now require a full administrator. Deleting a provider also deletes its `UserThird` links transactionally.
**Summary:** Closed delegated full-admin impersonation through provider replacement. Linked identities can no longer cross silently to a different provider key, type, client, or issuer, and an old `op` cannot inherit links after deletion/recreation. Eleven focused SSO/read-only tests / 105 assertions, Pint, targeted PHPStan, and diff checks passed.

## [2026-07-14 10:12] - Security: bind stock-client TOTP challenges
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/LoginController.php`, `app/Services/TwoFactorService.php`
- `tests/Feature/TotpLoginChallengeSecurityTest.php`
- `Wiki/core/15-security.md`
- `docs/modernization/{02-client-api-contract,11-client-feature-opportunities,16-response-contract}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Reuses the hashed `verify_codes` challenge fields. The stock TOTP first response is now `type:email_check`, `tfa_type:tfa_check` with an opaque secret and user payload; the passwordless second request requires the exact bound challenge and matching `verificationCode`/`tfaCode`.
**Summary:** Restored the current Flutter TOTP handshake while preserving password-plus-TOTP custom clients. Bound the second step to the user, RustDesk ID, UUID, first-factor proof, five-minute lifetime, five row-local guesses, supersession, row-locked consumption, and replay rejection. Forty-six related authentication tests / 310 assertions, Pint, targeted PHPStan, and diff checks passed.

## [2026-07-14 10:08] - Security: harden recording uploads
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/RecordController.php`, `app/Services/RecordingService.php`
- `app/Http/Middleware/{AuthorizeRecordingUpload,ThrottleRecordingUploads}.php`
- `app/Models/Recording.php`, `config/recordings.php`, `routes/api.php`
- `database/migrations/2026_07_14_100002_bind_recording_uploads_to_source_ip.php`
- `tests/Feature/RecordingUploadSecurityTest.php`
- `.env.example`, `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `QUICKSTART.md`, `docs/modernization/{02-client-api-contract,11-client-feature-opportunities,16-response-contract}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds nullable `recordings.source_ip`. `/api/record` is disabled by default and requires an explicit source IP/CIDR or 32+ character upload token when enabled; accepted stock-client response shapes remain `{}` / `{error}`.
**Summary:** Replaced anonymous unbounded recording writes with a fail-closed upload boundary, per-source ownership and throttling, exclusive filename creation, sequential exact-length writes, protected completed recordings, and finite chunk/file/storage/file-count/active-upload quotas. Malformed and all-address CIDRs fail closed. Fifteen focused tests / 110 assertions, Pint, targeted PHPStan, route-order checks, and all Compose validations passed.

## [2026-07-14 10:02] - Security: authorize audit session notes
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/AuditController.php`
- `tests/Feature/AuditNoteAuthorizationTest.php`, `tests/Feature/ApiResponseTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/{02-client-api-contract,16-response-contract}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Denied GUID lookups remain HTTP 200 JSON `""`; denied or invalid note updates remain HTTP 200 `{}` no-ops.
**Summary:** Closed cross-account GUID disclosure and note modification by independently scoping both the lookup and update to devices accessible through ownership, delegated group grants, or full administration. Added bounds for peer, session, guid, and note inputs. Sixteen focused tests / 74 assertions, Pint, and targeted PHPStan passed.

## [2026-07-14 10:00] - Security: bind email login challenges
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/LoginController.php`, `app/Services/TwoFactorService.php`
- `app/Models/VerifyCode.php`
- `database/migrations/2026_07_14_100003_harden_email_login_challenges.php`
- `tests/Feature/EmailLoginChallengeSecurityTest.php`
- `Wiki/core/15-security.md`, `docs/modernization/{02-client-api-contract,16-response-contract}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds hashed challenge, attempt-budget, and consumption fields to `verify_codes`; retires legacy plaintext codes. The stock intermediate response is now `type:email_check` with an opaque secret and user payload.
**Summary:** Bound email verification to the exact user, RustDesk ID, UUID, and a 64-character per-attempt secret; stored only hashes; enforced five-minute expiry, five row-local guesses under lock, single-use consumption, replay rejection, and safe upgrade/rollback cleanup. Sixteen focused and related tests / 116 assertions, Pint, and targeted PHPStan passed.

## [2026-07-14 09:54] - Security: remove the production admin-password default
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Support/BootstrapAdminCredentials.php`, `config/bootstrap.php`
- `database/seeders/{DatabaseSeeder,DemoShowcaseSeeder}.php`
- `docker/{entrypoint,demo-shots}.sh`, `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `.env.example`, `README.md`, `QUICKSTART.md`, `docker/README.md`, `docs/DEVELOPMENT.md`
- `tests/Unit/BootstrapAdminCredentialsTest.php`, `tests/Feature/BootstrapAdminSeederTest.php`
- `phpstan-baseline.neon`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. New production databases require an explicit safe `ADMIN_PASS` to create the first administrator; existing administrators and local/test fixtures are unchanged.
**Summary:** Removed the known production credential, rejected short/default/placeholder/repeated/username-derived bootstrap values, made failed seeds stop startup without writing the install marker, cleared stale configuration before seeding, and removed the one-time password before caching configuration or starting Apache. Existing deployments may remove the variable after bootstrap. Twenty-two focused tests / 40 assertions, Pint, targeted PHPStan, shell syntax, and Compose validation passed.

## [2026-07-14 09:48] - Security: neutralize formulas in CSV exports
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/Concerns/ExportsCsv.php`
- `tests/Feature/ExportCsvTest.php`
- `Wiki/core/15-security.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. CSV contents remain otherwise unchanged; dangerous text cells gain a leading apostrophe.
**Summary:** Routed every admin CSV row through a shared cell encoder that detects formula prefixes even when hidden behind leading ASCII controls, whitespace, or repeated UTF-8 BOMs. Covered `=`, `+`, `-`, and `@` vectors. Eight focused tests / 28 assertions plus Pint passed.

## [2026-07-14 09:47] - Security: escape runtime values in HTML mail
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/MailService.php`
- `tests/Feature/MailTemplateSecurityTest.php`
- `Wiki/core/15-security.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Kept administrator-authored HTML template layout intact while centrally encoding every runtime placeholder as text, preventing usernames, device labels, audit messages, and links from injecting markup or event attributes into delivered and logged mail. One focused test / five assertions plus Pint passed.

## [2026-07-14 09:43] - Security: bind and bound audit ingestion
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/AuditIngestionGuard.php`, `app/Http/Controllers/Api/AuditController.php`
- `config/rustdesk.php`, `.env.example`
- `tests/Feature/{AuditIngestionSecurityTest,ApiResponseTest,AuditConnAuthTest,AuditSessionTest,WebhookTest}.php`
- `Wiki/core/15-security.md`, `docs/modernization/{02-client-api-contract,16-response-contract}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Audit response shapes remain `{}`. Connection, file, and alarm writes now require the exact ID/UUID of an approved device; the unauthenticated legacy note shape no-ops unless it adds that UUID.
**Summary:** Added body and field ceilings, source-IP and per-device rate limits, exact `u64` session-ID decoding, and side-effect-free rejection before database, alarm, mail, or webhook work. The authenticated guid-based note flow remains available. Thirty-eight focused tests / 150 assertions plus Pint passed; current upstream RustDesk payload construction was checked against the official source.

## [2026-07-14 09:37] - Security: bind and limit OIDC token polling
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/OauthService.php`, `app/Http/Controllers/Api/OauthController.php`
- `app/Models/OauthSession.php`
- `database/migrations/2026_07_14_100001_add_delivery_state_to_oauth_sessions_table.php`
- `tests/Feature/{OidcPkceTest,OidcDestinationSecurityTest}.php`
- `docs/modernization/02-client-api-contract.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds `oauth_sessions.delivery_count` and nullable `delivered_at`. OIDC poll response shapes are unchanged; polls now require the originating `id`/`uuid` and allow at most two deliveries in 15 seconds.
**Summary:** Removed the bearer-like poll code and token body from logs, bound token delivery to the device that began the flow, required bounded non-empty device identity, serialized delivery with a database lock, and erased the stored AuthBody after one short retry. Forty-nine focused OIDC/SSO tests / 129 assertions plus Pint passed.

## [2026-07-14 09:34] - Security: bind device telemetry to stored identity
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Api/SystemController.php`
- `tests/Feature/DeviceTelemetrySecurityTest.php`
- `docs/modernization/02-client-api-contract.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Heartbeat/sysinfo wire shapes are unchanged. Existing devices now require the exact stored UUID; deployment-gated and unapproved devices receive the existing empty/`ID_NOT_FOUND` responses without mutation or policy delivery.
**Summary:** Closed unauthenticated device-ID takeover and Strategy/disconnect disclosure paths by making telemetry fail closed on missing, oversized, blank legacy, or mismatched identities; stopped heartbeat from bypassing deployment gating; and withheld all mutation/policy/command behavior from unapproved devices. Thirty-five focused device and contract tests / 132 assertions plus Pint passed.

## [2026-07-14 09:30] - Security: require explicit trusted proxies
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `bootstrap/app.php`, `config/trustedproxy.php`, `.env.example`
- `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `tests/Feature/TrustedProxySecurityTest.php`
- `README.md`, `Wiki/core/15-security.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds optional `TRUSTED_PROXIES`; forwarded headers are ignored by default. Routes and wire formats are unchanged.
**Summary:** Removed wildcard proxy trust so direct clients cannot forge the IP used by login throttles and API-key allowlists. Operators behind a sanitizing reverse proxy can explicitly list exact IPs/CIDRs; wildcard, `REMOTE_ADDR`, malformed, and all-network entries are rejected. Verified the exploit before the fix and passed 20 focused security/rate-limit tests / 120 assertions, Pint, PHPStan, configuration caching, and all three Compose validations.

## [2026-07-14 09:25] - Security: restrict generic OIDC egress
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/{OauthService,OidcDestinationGuard,OidcDnsResolver}.php`
- `config/rustdesk.php`, `.env.example`
- `tests/Feature/{OidcDestinationSecurityTest,OidcPkceTest,AdminSsoLoginTest}.php`
- `Wiki/core/15-security.md`, `docs/modernization/08-build-log.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Adds optional `RUSTDESK_OIDC_ALLOWED_PORTS` configuration (default `443`). Inbound OIDC routes and response shapes are unchanged.
**Summary:** Added a fail-closed outbound boundary for generic OIDC issuer discovery, authorization, token, and userinfo endpoints: HTTPS/public-network validation, exact issuer assertion, per-hop DNS revalidation and cURL pinning, disabled redirects/proxies/reuse, and credential-safe failure logs. Public cross-host providers and PKCE remain supported, with 47 focused tests / 117 assertions plus clean Pint and PHPStan gates.

## [2026-07-14 09:23] - Security: bind deployment tokens and device assignment
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/DeploymentService.php`
- `tests/Feature/{ApiResponseTest,DeploymentSecurityTest}.php`
- `docs/modernization/02-client-api-contract.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Deployment and CLI assignment response formats are unchanged. Existing-device assignment now requires the stored UUID, and tokens stop authorizing when their owner is disabled or loses `deploy.edit`.
**Summary:** Closed the deploy-token device takeover path by requiring exact device identity, continuously revalidating token-owner status and permissions, and limiting cross-user assignment to full administrators. Added coverage for omitted/mismatched UUIDs, revoked privileges, disabled owners, delegated operators, and the retained full-admin workflow.

## [2026-07-14 09:21] - Security: suspend disabled address-book shares
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Models/AddressBook.php`
- `app/Http/Controllers/Api/AddressBookController.php`
- `tests/Feature/SharedAddressBookTest.php`
- `docs/modernization/12-access-control-design.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Shared profile and direct book access now honor the existing `is_shared` state; wire shapes are unchanged.
**Summary:** Made retained collaborator grants dormant whenever an owner pauses sharing, omitted disabled books from shared-profile discovery, and preserved collaborator rows for safe restoration when sharing is re-enabled. Focused coverage confirms former collaborators cannot list, read, or mutate a disabled book while owner access remains unchanged.

## [2026-07-14 09:17] - CI security: pin third-party GitHub Actions
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `.github/workflows/{ci,docker-publish}.yml`
- `docker/README.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Replaced every movable third-party GitHub Action tag with the exact upstream commit behind its intended major release, retained readable major-version comments, and documented the review and update process. Workflow behavior and action major versions are unchanged; all 14 references were syntax-checked and verified as full 40-character revisions.

## [2026-07-14 09:08] - Build security: pin Docker and toolchain inputs
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `docker/Dockerfile.{toolchain,runtime}`, `docker/compose.toolchain.yml`
- `docker-compose.yml`, `docker-compose.dev.yml`, `examples/full-stack.docker-compose.yml`
- `docker/README.md`, `README.md`, `docs/modernization/07-rewrite-plan-php.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Pinned every third-party Docker build and Compose input to an exact release plus multi-architecture digest, replaced the privileged NodeSource download-and-shell step with Node 24.18.0 from its verified official image, matched Playwright 1.61.0 to the lockfile, documented safe pin updates, and kept the first-party `latest` release channel overrideable for operators who require a tag-and-digest deployment lock.

## [2026-07-14 09:07] - Frontend supply chain: reproducible assets and notices
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `scripts/copy-admin-vendor.mjs`, `package.json`, `eslint.config.mjs`, `.gitattributes`
- `public/assets/vendor/` (generated runtime assets, full license texts, and third-party notice)
- `.github/workflows/{ci,docker-publish}.yml`
- `Wiki/core/06-design-system.md`, `docs/DEVELOPMENT.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Made checked-in browser dependencies byte-for-byte reproducible from the lockfile, removed dangling font/source-map references, included the complete redistribution notices and upstream license texts, added fail-closed inventory/drift verification, moved CI to locked script-disabled installs on supported Node 24.18.0, and made both CI and image publication block on vendor divergence.

## [2026-07-14 09:06] - Security: redact webhook credentials and delivery errors
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Support/WebhookUrlRedactor.php`
- `app/Models/{Webhook,WebhookDelivery}.php`
- `app/Services/WebhookService.php`
- `resources/views/admin/webhooks/{index,deliveries}.blade.php`
- `tests/Feature/WebhookCredentialRedactionTest.php`
- `Wiki/core/15-security.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Webhook delivery continues to use the stored raw destination internally; inbound routes and RustDesk client wire behavior are unchanged.
**Summary:** Centralized credential-safe webhook labels and error filtering, hid raw URL/secret/error fields from model serialization, sanitized transport failures before logging and persistence, re-sanitized legacy history at render time, and limited view-only delegates to redacted configuration/history without mutation or resend controls.

## [2026-07-14 08:54] - WebUI review: resilient workflows and permission-aware views
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `public/assets/{css/theme-dark.css,js/app.js}`
- `app/Http/Controllers/Admin/AddressBookController.php`
- `resources/views/admin/{address_books,deploy_tokens,device_groups,devices,groups,ldap,oauth_providers,partials,settings,strategies,users}/`
- `resources/views/admin/{client_config,recordings,sessions,two_factor}/`
- `e2e/{accessibility,gui,login,screenshots}.spec.ts`
- `tests/Feature/{AdminAddressBookTest,AddressBookImportExportTest,AdminReadOnlyUiTest}.php`
- `DESIGN.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Existing server-side permission boundaries and RustDesk client wire behavior are unchanged.
**Summary:** Corrected modal validation recovery, nested confirmation and Enter-submit behavior, live-save races, stale combobox requests, clipboard fallbacks, persistent navigation state, mobile drawer focus/inert handling, narrow-screen overflow, and decorative icon semantics. View-only delegates now receive complete, non-mutating device, group, strategy, settings, OAuth, deploy-token, and address-book screens instead of misleading write controls, with focused feature, responsive, interaction, and accessibility regressions.

## [2026-07-14 08:42] - Security: prevent webhook server-side request forgery
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/{WebhookDestinationGuard,WebhookDnsResolver,WebhookService}.php`
- `app/Http/Controllers/Admin/WebhookController.php`
- `config/rustdesk.php`, `.env.example`
- `composer.json`, `composer.lock`
- `tests/Feature/{WebhookDestinationSecurityTest,WebhookTest}.php`
- `Wiki/core/15-security.md`, `docs/api/README.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Requires PHP `ext-curl`; adds optional `RUSTDESK_WEBHOOK_ALLOWED_PORTS` configuration (default `80,443`). No schema or inbound/RustDesk wire changes.
**Summary:** Added send-time public-destination validation for every webhook attempt, rejected ambiguous/non-canonical IPs and non-public IPv4/IPv6/DNS answers, pinned cURL to the validated address, disabled redirects/proxies/connection reuse, and restricted transport protocols and ports. Blocked attempts are recorded as failed deliveries without reaching the HTTP transport, with retry, redirect, DNS-rebinding, and address-range regression coverage.

## [2026-07-14 08:38] - Security: separate alarm and recording deletion permissions
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Models/AdminRole.php`
- `routes/web.php`
- `resources/views/admin/alarms/index.blade.php`
- `resources/views/admin/recordings/index.blade.php`
- `tests/Feature/AlarmRecordingAuthorizationTest.php`
- `docs/modernization/12-access-control-design.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Added `alarms.edit` and `recordings.edit` console permissions. No schema, RustDesk client route, or wire-format changes.
**Summary:** Changed alarm and recording deletion from view permission to explicit edit permission, hid destructive controls from view-only delegates, preserved recording downloads for viewers, and added denial/allow/full-admin regression coverage. Existing delegated view roles do not silently gain deletion authority.

## [2026-07-14 08:30] - Security: harden client deployment command generation
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Services/ClientConfigService.php`
- `app/Http/Controllers/Admin/ClientConfigController.php`
- `app/Http/Controllers/Admin/StrategyController.php`
- `app/Http/Controllers/Api/V1/StrategyController.php`
- `resources/views/admin/client_config/index.blade.php`
- `routes/web.php`
- `tests/Feature/ClientConfigTest.php`
- `tests/Feature/StrategyOptionSecurityTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** Added a CSRF-protected `POST /admin/client-config` generation route. Existing `GET /admin/client-config` deep links remain supported for non-sensitive fields; RustDesk client wire routes are unchanged.
**Summary:** Moved unlock-PIN generation out of URLs, prevented PIN repopulation, added no-store/no-referrer responses, allowlisted deployment option keys and PIN characters, rejected control characters, and generated correctly quoted POSIX and PowerShell commands. Unsafe legacy strategy data now produces a refusal warning instead of executable output, with hostile-input, permission, CSRF, and API regression coverage.

## [2026-07-14 08:28] - Security: close delegated administrator escalation paths
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `app/Http/Controllers/Admin/{AdminRole,ApiKey,User}Controller.php`
- `app/Http/Controllers/Api/V1/UserController.php`
- `app/Http/Middleware/ApiKeyAuth.php`
- `app/Models/{AdminRole,ApiKey}.php`
- `resources/views/admin/{admin_roles,api_keys,users}/` and `resources/views/admin/partials/sidebar.blade.php`
- `routes/web.php`
- `tests/Feature/ApiKeyAuthorizationTest.php`
- `docs/api/{README.md,openapi.yaml}`
- `docs/modernization/{12-access-control-design,17-feature-research-2026-06}.md`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** The admin REST API no longer accepts `is_admin` on user writes and refuses mutation of privileged accounts. Added `api_keys.view` / `api_keys.edit` console permissions; RustDesk client wire routes are unchanged.
**Summary:** Closed the delegated-admin-to-full-admin escalation chain: API keys are owner-bound and cannot exceed or outlive their owner's current permissions, administrator promotion and privileged-account takeover are excluded from API writes, and only full administrators can assign or mutate administrative authority. Delegated user managers retain ordinary-account management, with server-side denial and permission-aware UI regression coverage.

## [2026-07-14 08:09] - Security: prevent stored XSS in admin script data
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `resources/views/admin/settings/index.blade.php`
- `resources/views/admin/strategies/edit.blade.php`
- `tests/Feature/AdminInlineScriptSerializationTest.php`
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Replaced unsafe custom `@json` flags with Laravel `Js::from()` in both stored-value script contexts and added hostile `</script>` regression coverage so settings or strategy values cannot terminate the enclosing script.

## [2026-07-13 22:56] - Full admin console WebUI modernization
**Agent:** rustdesk-api (OpenAI Codex / GPT-5)
**Files Modified:**
- `public/assets/css/theme-dark.css`, `public/assets/js/app.js`, `public/assets/vendor/`, `scripts/copy-admin-vendor.mjs`, `package.json`, `package-lock.json`
- `resources/views/layouts/admin.blade.php`, all admin/auth Blade views and shared partials under `resources/views/admin/`
- `playwright.config.ts`, `e2e/{login,gui,accessibility,screenshots}.spec.ts`, `tests/Feature/OauthPresetsTest.php`
- `DESIGN.md`, `Wiki/core/06-design-system.md`, `docs/modernization/{08-build-log,09-port-status}.md`, `docs/screenshots/` gallery and README
- `DevOps/archive-plans/web-ui-design-modernization.md`, `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None. Routes, permissions, schema, RustDesk JSON keys, and wire behavior are unchanged.
**Summary:** Rebuilt the complete Blade/jQuery/Bootstrap admin and authentication UI around a responsive warm-mineral dark/light design system with local pinned assets and accessible shared interactions; regenerated the gallery and verified 557 PHPUnit assertions, Pint (196 files), PHPStan (0 errors), JavaScript gates, Blade caching, a green 46-pass/6-skip Playwright matrix, and 320px reflow. Changes remain local, uncommitted, and unpushed for final review.
## [2026-07-13 21:17] - Design plan: replace AI-blue dark palette with warm mineral system
**Agent:** rustdesk-api (Codex / GPT-5)
**Files Modified:**
- `DevOps/plans/web-ui-design-modernization.md` (revised visual direction, provisional accessible dark-palette roles/values, color-distribution rule, and review question)
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Refined the proposed admin-console identity away from the generic blue-black/iris/cyan “AI dashboard” look toward warm carbon and mineral graphite surfaces, bone text, oxidized-copper actions, and restrained sea-glass/moss/ochre/clay semantic colors; an initial contrast calculation informed the provisional token values.

## [2026-07-13 20:52] - Plan: second-generation admin console UI/UX modernization
**Agent:** rustdesk-api (Codex / GPT-5)
**Files Modified:**
- `DevOps/plans/web-ui-design-modernization.md` (new proposed plan grounded in the current Blade views, design contract, screenshots, and UI test baseline)
- `DevOps/logs/agent-changelog.md`
**Database/API Changes:** None.
**Summary:** Audited the complete admin WebUI and created a review-gated modernization roadmap covering product direction, information architecture, independent visual identity, design tokens/components, shell/navigation, representative pilots, responsive and WCAG 2.2 AA-oriented behavior, local asset delivery, phased page migration, and visual/accessibility regression gates without changing the Laravel/Blade/jQuery stack or RustDesk wire contract.

## [2026-07-11 14:05] - License: adopt GNU AGPL-3.0-or-later
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `LICENSE`: full verbatim GNU Affero General Public License v3.0 text (fetched from gnu.org).
- `composer.json`: `"license"` → `AGPL-3.0-or-later` (SPDX; `composer validate` passes).
- `README.md`: License section now states **AGPL-3.0-or-later** with a plain-language note on the network-use copyleft (hosted/modified versions must offer their source); Trademark section wording updated to reference the AGPL.
**Database/API Changes:** None.
**Summary:** Adopted the **AGPL-3.0-or-later** to keep the project strongly copyleft — anyone who modifies it and offers it to others, including as a network service, must release their corresponding source under the same license. Scope was swept for every own-project license reference (`LICENSE`, `composer.json`, README; `package.json` has no license field; the only in-source "MIT" strings are vendored Tailwind attribution and unrelated third-party dep metadata in `package-lock.json`, left as-is).

## [2026-07-11 13:40] - Default DB → MariaDB (SQLite optional); README production-focus + RD-API-Server rename
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `docker-compose.yml`, `docker-compose.dev.yml`: **MariaDB is now the default** — an active `db` service (mariadb:11 + healthcheck + `rustdesk-db` volume) wired to the app via `DB_*` env with `depends_on: condition: service_healthy`. SQLite demoted to an opt-in (documented "Prefer SQLite?" note). Header explains the performance rationale (SQLite is single-writer; bottlenecks as devices scale).
- `config/database.php`: default connection `sqlite` → `mysql` (env-overridable).
- `.env.example`: `DB_CONNECTION=mysql` with host/port/db/user/pass; SQLite shown as a commented alternative.
- `docker/entrypoint.sh`: clarified the SQLite path is only the bare-`docker run` fallback (compose provides MariaDB).
- `README.md`: renamed to **RD-API-Server** ("RD" = RustDesk shorthand, to avoid trademark friction) with an explicit "this is a self-hosted API server for the RustDesk client" framing; reworked **Quick start** around the production MariaDB compose with a copy-paste example compose + a pointer to `examples/full-stack.docker-compose.yml`; **extracted the Development section** to `docs/DEVELOPMENT.md`; updated the Stack line (MySQL/MariaDB default, SQLite optional).
- `docs/DEVELOPMENT.md` (NEW — toolchain build, quality gates, runtime-image build, CLI/admin + screenshot-regen commands, docs map).
- `QUICKSTART.md`: MariaDB default + `DB_PASSWORD`; flipped the "bigger fleets → MySQL" section to "small setups → SQLite (< ~50 devices)"; dev pointer → `docs/DEVELOPMENT.md`.
**Database/API Changes:** Default DB connection is now **MySQL/MariaDB** (was SQLite). No schema/wire change. Existing SQLite deployments keep working by setting `DB_CONNECTION=sqlite`.
**Summary:** Per the user: made the **performant database the default** (SQLite recommended only for < ~50 devices, with the single-writer rationale documented), refocused the README on **spinning up a production environment** via `docker-compose.yml` (+ the copyable full-stack example, one shown inline) and moved developer instructions into their own doc, and set up the **RD-API-Server** name (README explains the RustDesk connection; the GitHub repo rename + description update are done separately via `gh`). Validated: all migrations (incl. the 1.4.9 `audit_conns` one) run clean on **MariaDB** via `migrate:fresh --seed`; all three compose files pass `docker compose config`. Gate: Pint 196 files clean, PHPStan L5 0 errors, **164 PHPUnit passed** (557 assertions).

## [2026-07-11 13:15] - Docs: not-affiliated disclaimer + demo screenshots of the admin console
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `README.md`: added a prominent **"Not affiliated with RustDesk"** banner near the top and a **Trademark & affiliation** subsection under License; added a **📸 Screenshots** section (dashboard hero + a 2×2 of devices / strategy editor / connection logs / webhooks) linking to the gallery.
- `AGENT.md`: added an **Affiliation** note (independent third-party project; never present the UI as an official RustDesk product).
- `resources/views/admin/login.blade.php`: login footer now reads "Self-hosted API & admin console for the RustDesk client" + a small "Independent open-source project — not affiliated with or endorsed by RustDesk" line.
- `database/seeders/DemoShowcaseSeeder.php` (NEW — realistic, fictional demo dataset: 14 devices, 5 users, user/device groups, 3 strategies, 63 connection-audit rows incl. the 1.4.9 auth fields, alarms, login logs, address books + tags, webhooks, API keys; NOT wired into the default DatabaseSeeder so production installs stay clean).
- `e2e/screenshots.spec.ts` (NEW — Playwright capture spec; excluded from the CI gate).
- `docker/demo-shots.sh` (NEW — boots the app on the default sqlite DB with the showcase seed, backs up/restores your dev DB, and captures the gallery).
- `docs/screenshots/` (NEW — `README.md` gallery + 13 PNGs: dashboard, devices, strategies, strategy-editor, address-books, connection-logs, webhooks, alarms, sessions, api-keys, client-config, users, settings).
**Database/API Changes:** None (docs, a UI footer line, and a dev-only seeder/capture tooling).
**Summary:** Per the user's request: made the project's independence explicit (**not affiliated with / endorsed by RustDesk — separate third-party open-source implementation**) across the README, AGENT.md and the admin login page, and produced a visual showcase. Screenshots are captured from a live run of the app seeded with fictional demo data (example.com hosts, RFC-5737 IPs) — the connection-logs shot doubles as a showcase of the new **1.4.9 Auth column** (primary-auth + 2FA badges + controller-attribution indicator). Regenerate with `docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash docker/demo-shots.sh`. Verified: Pint 196 files clean, PHPStan L5 0 errors, **164 PHPUnit passed** (557 assertions); Playwright capture spec passes.

## [2026-07-11 12:55] - Feat: RustDesk 1.4.9 connection-audit auth details + controller attribution
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `database/migrations/2026_07_11_100001_add_auth_details_to_audit_conns_table.php` (NEW — nullable `primary_auth` (tinyint), `two_factor` (tinyint), `conn_audit_ref` (string, indexed) on `audit_conns`).
- `app/Models/AuditConn.php`: added the three fields to `#[Fillable]`, cast `primary_auth`/`two_factor` to integer, added `PRIMARY_AUTH`/`TWO_FACTOR` label maps and `primaryAuthLabel()`/`twoFactorLabel()`/`authSummary()` helpers.
- `app/Http/Controllers/Api/AuditController.php`: `conn()` now parses the three optional 1.4.9 keys (kept null when omitted, distinguishing "not recorded" from an explicit value), enriches the new-connection alarm message with the auth summary, and adds `primary_auth`/`two_factor`/`conn_audit_ref` to the `connection.new`/`connection.closed` webhook payload. `ALARM_TYPES` gains code `9` → "Session-scope permission violation" (PR #15469).
- `app/Http/Controllers/Admin/AuditController.php`: connection-audit CSV export gains `primary_auth`, `two_factor`, `conn_audit_ref` columns.
- `resources/views/admin/audit/connections.blade.php`: new **Auth** column (primary-auth + 2FA badges) and a controller-attribution indicator (🔗) on the From cell when `conn_audit_ref` is present.
- `tests/Feature/AuditConnAuthTest.php` (NEW — 7 tests: persist auth details, backward-compat nulls, close carries none, alarm message includes the auth summary, alarm type 9 labelled, admin view renders labels, CSV includes the columns).
- `docs/modernization/02-client-api-contract.md`: added §8.1 documenting the three new wire keys (types/values/source PRs), the alarm type 9 addition, and the "audit is the only 1.4.9-affected surface" finding.
**Database/API Changes:** New nullable columns on `audit_conns`. `POST /api/audit/conn` now accepts optional `primary_auth` (int), `two_factor` (int), `conn_audit_ref` (string) — **no existing wire key renamed**; absent values stay null (backward-compatible with pre-1.4.9 clients and `close` events). `/api/audit/alarm` type `9` now labelled.
**Summary:** Implements the RustDesk **1.4.9** (2026-07-06) audit enrichments the user asked for — **#15456** (authentication details in the connection audit) and **#15407** (controller-user attribution). Wire keys were verified **verbatim against the raw PR diffs** (`patch-diff.githubusercontent.com/.../15456.diff`, `.../15407.diff`): `primary_auth` (1=Click, 2=TemporaryPassword, 3=PermanentPassword, 4=SwitchSides), `two_factor` (1=TOTP, 2=TrustedDevice) — both omitted client-side when `None`/0 — and the opaque `conn_audit_ref` token (stored verbatim; full user resolution additionally needs Pro **hbbs**-side work, out of scope per the PR, which only requires the API server to accept/store it). Also surfaced PR **#15469**'s new `SessionScopeViolation` alarm (type 9). Consistent with the OIDC wire-format guardrails: integers stay integers, omitted keys stay null (never `0`/`[]`). Verified: Pint 195 files clean, PHPStan L5 0 errors, **164 PHPUnit passed** (557 assertions; +7 in `AuditConnAuthTest`).

## [2026-06-23 18:20] - Docs: record the OIDC fix + audit other endpoints for the same pitfalls
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `docs/modernization/16-response-contract.md`: corrected §2.1 (`user.info` must be a present JSON **object** — `{}` is fine, `[]` is not; the PHP empty-array/`json_decode(...,true)` trap), added §2.4 (auth-query must return the AuthBody **at the top level**, not only `{body}` — the `{body}` unwrap is a post-2026-04 nightly-only client change), added a P0 resolution-log entry for the live-verified SSO fix (wrapper + `info` type + PKCE + DB session), refreshed the stale gate counts.
**Database/API Changes:** None (documentation).
**Summary:** Per the user's request after SSO started working live: documented the root cause + fix in the response-contract doc, and **audited every client-facing endpoint** for the same two pitfalls. Result — no other instances: all empty maps that the client parses already cast to `(object)` (heartbeat `config_options`/`extra`, `tag_colors`, `sysinfo`/`record` empty acks), the `{body}` wrapper existed only in auth-query (fixed), and password login sends a populated (object) `info`. The two general rules now in the doc — (a) emit empty maps as `{}` not `[]` (decode to objects, not assoc, on any round-trip), and (b) the auth-query AuthBody goes at the top level — are the durable guardrails for future endpoints.

## [2026-06-23 18:00] - Fix: keep user.info an object {} at top level (was re-encoded to [])
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Http/Controllers/Api/OauthController.php`: `authQuery` now `json_decode($json)` to **objects** (not assoc arrays) before adding `body`, so an empty `user.info` stays `{}` instead of being re-encoded as `[]`.
- `tests/Feature/OidcPkceTest.php`: assert the top-level response contains `"info":{}` and never `"info":[]`.
**Database/API Changes:** None beyond the prior commit; the top-level `user.info` is now correctly an object.
**Summary:** After moving the AuthBody to the top level (prior commit), the user's capture showed `"info":[]` at the top level — `json_decode(..., true)` had turned the empty `{}` into a PHP `[]`, which re-encoded as an array, and the client's serde `UserInfo` expects a map, so `from_value::<AuthBody>` still failed on `info:[]`. Decoding to objects preserves `{}`. The `body` mirror was always correct; this fixes the top-level copy the stable client actually reads. Verified: Pint 193 files clean, PHPStan L5 0 errors, **157 PHPUnit passed** (532 assertions); test now pins `"info":{}`.

## [2026-06-23 17:40] - Fix (real root cause): OIDC auth-query must return the AuthBody at top level
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Http/Controllers/Api/OauthController.php`: `authQuery` now returns the AuthBody (or `{error}`) **at the top level** AND mirrored under `body` (dual shape).
- `tests/Feature/OidcPkceTest.php` (+2: top-level + body for ready and pending).
**Database/API Changes:** `/api/oidc/auth-query` response shape: previously `{"body":"<json>"}` only; now `{<authbody fields>, "body":"<json>"}` (and `{error, body}` while pending).
**Summary:** Root-caused via a **verbatim diff against the working reference** (lejianwen/rustdesk-api), per the user's prompt. lejianwen's `OidcAuthQuery` returns `c.JSON(LoginRes{access_token,type,user})` and pending `gin.H{message,error}` — both **at the top level, no `body` wrapper**. The RustDesk client's `{body:String}` unwrap is a recent master change (client commit 4e30ee8d1, 2026-04-03) not yet in stable releases, so stable clients (and the reference) parse the auth-query response **directly** as `serde AuthBody`. Our server wrapped everything in `{"body":"…"}`, so the user's client ran `from_value::<AuthBody>` on `{"body":"…"}`, found no `access_token`/`user` at top level, failed deserialization, and polled forever — which is why the earlier `info:{}` change had no effect (the client never reached the inner body). Now we return the AuthBody at the top level (matching lejianwen + stable clients) and keep `body` for post-April clients; serde ignores unknown fields so both generations parse it. Verified: Pint 193 files clean, PHPStan L5 0 errors, **157 PHPUnit passed** (530 assertions; +2).

## [2026-06-23 17:10] - Fix: client OIDC rejected token (serde flatten) — shrink user.info to {}
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/OauthService.php`: OIDC `authBody` now sends `user.info` as an empty object `{}` (drops the `#[serde(flatten)]` UserSettings bools + `login_device_whitelist`); `auth_body` is stored as a raw JSON string and returned verbatim; the delivery log masks the token via string-replace so it shows the exact bytes (incl. `{}`) without mangling.
- `app/Models/OauthSession.php`: dropped the `auth_body => array` cast (store/return the JSON string verbatim, so `{}` survives instead of round-tripping to `[]`).
**Database/API Changes:** None (no schema change; `auth_body` column already JSON, now used as text). The OIDC auth-query token payload's `user.info` is now `{}`.
**Summary:** Diagnosed with the user's live data: **password login works** (parsed by the client's Dart layer) but **OIDC fails** with the byte-identical payload (parsed by the client's Rust `serde_json::from_value::<AuthBody>` via `HbbHttpResponse::parse`). The client received the full token on every poll yet kept polling — i.e. it silently failed to deserialize the body. The only serde-sensitive construct is the client's `UserInfo` `#[serde(flatten)] settings` — `flatten` + `from_value` is a long-standing serde failure mode for populated content. Shrinking `user.info` to `{}` (all client fields default) removes the flattened content so `from_value` succeeds; stored/returned as a raw string so the empty object isn't mangled by an array cast. Password login is untouched (separate `LoginController::userPayload`, Dart-parsed). Verified: Pint 193 files clean, PHPStan L5 0 errors, **155 PHPUnit passed**; delivery log confirms `"info":{}` in the exact bytes.

## [2026-06-23 16:50] - SSO: idempotent token delivery + diagnostics (client received token but kept polling)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/OauthService.php`: `pollResult` no longer **one-shot-deletes** the session — it keeps returning the token until the session expires (pruned by `beginAuth`), so a client re-poll / parse retry can't lose it; logs the delivered payload (token masked) for diagnosis. `handleCallback` logs the resolved user. `authBody` clamps `status` to the client's valid `UserStatus` set (-1/0/1) so a stray value can't break AuthBody deserialization.
- `tests/Feature/OidcPkceTest.php`: updated the cross-instance test to assert **idempotent** delivery (token returned on re-poll; row persists until expiry).
**Database/API Changes:** None. Behaviour: the OIDC auth-query keeps returning the token for the session's lifetime instead of consuming it on first read.
**Summary:** From the user's API access logs the callback succeeded (success page, 200) and one `auth-query` poll returned the full token (745 B) — yet the client kept polling, i.e. it received the token but didn't complete. The auth body is byte-identical in shape to `LoginController::userPayload` (which the client accepts for password login), so the shape isn't the cause; the OIDC-specific risks were the **one-shot delete** (only one delivery chance) and any field that fails the client's strict deserialization. Made delivery idempotent, hardened `status`, and added payload/user logging so the next capture pinpoints any field mismatch. Verified: Pint 193 files clean, PHPStan L5 0 errors, **155 PHPUnit passed** (522 assertions).

## [2026-06-23 16:20] - Docs: clarify "override" vs default settings (can't be server-pushed)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `resources/views/admin/strategies/edit.blade.php` (inline note: pushed options are soft defaults; true overrides need a signed custom client; gives the OSS lockdown recipe)
- `docs/modernization/17-feature-research-2026-06.md` (records the finding with client-source citations)
**Database/API Changes:** None (documentation / UI help only).
**Summary:** Investigated the advanced-settings "override" concept. Verified in client source that heartbeat strategies only set **soft defaults** (`config_options` → `Config::set_options`; `extra` is ignored), while locked **override-settings** come solely from the baked **custom-client** blob (`read_custom_client`), which is `sign::verify`'d against a hardcoded RustDesk public key — so a self-hosted server **cannot** forge override (locked) settings. Documented the OSS-equivalent lockdown (push the default + `hide-*-settings` + `disable-change-*` + `allow-remote-config-modification = N`, all already in the catalog) inline in the strategy editor and in the research doc. No false capability added. Verified: Pint 193 files clean, strategy-editor render tests pass.

## [2026-06-23 16:00] - Fix: client SSO hang ("Waiting account auth") on multi-instance deploys
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `database/migrations/2026_06_23_100004_create_oauth_sessions_table.php` (NEW — `oauth_sessions`, PK = polling `code`, carries op/ids/nonce/code_verifier/device + `auth_body` + `expires_at`)
- `app/Models/OauthSession.php` (NEW — string PK, `auth_body` json cast, `isExpired()`)
- `app/Services/OauthService.php` (the OIDC pending session now lives in the DB, not the cache: `beginAuth` inserts a row + prunes expired; `handleCallback` loads/updates it; `pollResult` reads + one-shot-deletes it; removed `Cache` + `cacheKey`)
- `tests/Feature/OidcPkceTest.php` (+2 — session persisted in DB; poll resolves across separate service instances)
**Database/API Changes:** New `oauth_sessions` table. No wire change — `/api/oidc/auth`, `/api/oauth/callback`, `/api/oidc/auth-query` behave identically, but the pending session is now shared storage.
**Summary:** Root-caused the client SSO hang from the user's screenshots: the browser reached our "Sign-in complete" page (so the callback succeeded and stored the AuthBody) yet the client stayed on "Waiting account auth" — i.e. the client's `/api/oidc/auth-query` poll never saw what the callback wrote. On a load-balanced deployment (host `rdr-api-1…`) the callback and the poll hit different instances/workers, and the pending session lived in a per-instance **cache**, so the poll missed it. Moved the pending OIDC session into the **database** (shared by every instance), which is also how a DB-backed reference server avoids this. Added a regression test that resolves the callback and the poll through **separate** OauthService instances. Verified: Pint 193 files clean, PHPStan L5 0 errors, **155 PHPUnit passed** (521 assertions; +2).

## [2026-06-23 15:30] - Client Config: deploy-time unlock PIN + Strategy→install-script generator
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/ClientConfigService.php` (`installScript()` — turns a config_options map + optional unlock PIN into per-OS `rustdesk --option <key> <value>` / `--set-unlock-pin` command blocks; quotes space-containing values)
- `app/Http/Controllers/Admin/ClientConfigController.php` (accepts `unlock_pin` + `strategy`; passes strategies list + selected strategy's script)
- `resources/views/admin/client_config/index.blade.php` (Default-unlock-PIN field + per-OS `--set-unlock-pin` card; Strategy picker + per-OS install-script card)
- `config/strategy_options.php` (align select values with the client: `approve-mode` adds explicit `password-click`; `verification-method` adds `use-both-passwords`)
- `tests/Feature/ClientConfigTest.php` (+4)
**Database/API Changes:** None. New optional query params on `/admin/client-config` (`unlock_pin`, `strategy`).
**Summary:** Verified against client source that the unlock PIN is stored encrypted per-device and is **not** strategy-pushable (only `disable-unlock-pin` is; the PIN is set via `rustdesk --set-unlock-pin`). So the Client Config generator now produces deploy-time CLI: a `--set-unlock-pin` command, and — turning a **Strategy** into a paste-ready install script — a block of `rustdesk --option <key> <value>` commands per OS (Linux/macOS/Windows), exactly matching how the user provisions clients at install. Also corrected two dropdowns to expose the client's real "both" values (`password-click`, `use-both-passwords`). Verified: Pint 191 files clean, PHPStan L5 0 errors, **153 PHPUnit passed** (517 assertions; +7).

## [2026-06-23 15:00] - Strategy catalog: add the missing advanced client settings
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `config/strategy_options.php` (+~22 options, audited against the client's `keys::KEYS_SETTINGS` + `keys::KEYS_BUILDIN_SETTINGS` in `libs/hbb_common/src/config.rs` — the canonical server-pushable policy sets): Performance — `enable-directx-capture`, `keep-awake-during-incoming-sessions`; Permissions — `enable-perm-change-in-accept-window`, `one-way-file-transfer`, `one-way-clipboard-redirection`, `file-transfer-max-files`; Password — `default-connect-password`, `remove-preset-password-warning`, `disable-unlock-pin`; Connection — `allow-insecure-tls-fallback`, `allow-hostname-as-id`, `register-device`, `allow-deep-link-password`, `allow-deep-link-server-settings`; Network — `disable-udp`, `allow-https-21114`, `use-raw-tcp-for-api`; new **Proxy** section — `proxy-url`/`proxy-username`/`proxy-password`; Restrictions — `main-window-always-on-top`, `allow-command-line-settings-when-settings-disabled`
- `tests/Feature/StrategyCatalogTest.php` (NEW, 3 — catalog completeness vs the new keys, editor render incl. Proxy section, save round-trip)
**Database/API Changes:** None (catalog/UI only; keys are the client's exact wire keys). Any key not in the catalog was already addable as a custom row — this just promotes the documented ones to first-class toggles/inputs.
**Summary:** Audited the strategy editor against RustDesk's advanced client settings (the rustdesk.com page 403s the fetcher, so grounded directly in the client source's `KEYS_SETTINGS`/`KEYS_BUILDIN_SETTINGS` — the authoritative list of what `config_options` can push). Added the ~22 documented policy options we were missing — proxy config, one-way transfer/clipboard, deep-link controls, insecure-TLS fallback, disable-UDP, DirectX capture, default connect password, unlock-PIN/ID restrictions, and more — each as the correct control type (toggle / number / text) using the client's exact keys. Verified: Pint 191 files clean, PHPStan L5 0 errors, **149 PHPUnit passed** (501 assertions; +3).

## [2026-06-23 14:35] - Sidebar: separate "Device Groups" from "User Groups"
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `resources/views/admin/partials/sidebar.blade.php` (added a **Device Groups** nav item — previously missing entirely, only reachable via cross-links; renamed the existing "Groups" → **User Groups**; grouped them next to Devices/Users; switched the Devices/Device Groups/Users/User Groups active-state checks to precise `str_starts_with` so `/admin/device-groups` no longer also highlights "Groups" and `/admin/devices` doesn't bleed onto device-groups)
**Database/API Changes:** None (navigation only).
**Summary:** Device Groups had no sidebar entry (hence "wasn't obvious"), and the old `str_contains($nav,'groups')` match made the user-Groups item light up on the device-groups page. Now the menu shows **Devices · Device Groups · Users · User Groups · Address Books** as distinct items (Device Groups gated by `device_groups.view`, with a distinct stack icon), and active highlighting is exact. Verified: 26 admin-page render tests pass (sidebar renders on every page); Pint clean.

## [2026-06-23 14:20] - Make default-group replacement explicit in the UI
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Http/Controllers/Admin/DeviceGroupController.php` (`index` passes the current `$defaultGroup` so the UI can name it)
- `resources/views/admin/device_groups/index.blade.php` (explanatory banner naming the current default; the "Set as default" button now carries a confirm that names the group it replaces and states the old default reverts to a normal group; badge reads "Default — new devices land here")
- `tests/Feature/DefaultDeviceGroupTest.php` (+1: index explains replacement)
**Database/API Changes:** None (UI/UX only).
**Summary:** Made it obvious that there is exactly **one** default device group and that choosing a new one replaces the current default. The list shows a banner ("Currently <name>… setting another replaces it; the previous default reverts to a normal group, its devices stay put") and the Set-as-default action confirms with the named current default before switching. Clarified the terminology after the user asked whether the old default becomes "shared" — it does not; "shared" is an address-book concept, device groups only have the single `is_default` flag, so the previous default simply becomes a normal group. Verified: Pint 190 files clean, PHPStan L5 0 errors, **146 PHPUnit passed** (470 assertions; +1).

## [2026-06-23 14:00] - Harden: enforce a single default device group
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Models/DeviceGroup.php` (`booted()` `saved` hook clears `is_default` on all other groups whenever one is saved as default — single-default invariant enforced on every code path, including direct `create`/`save`; `defaultId()` now `orderBy('id')` so the oldest wins deterministically if stray duplicates exist; `ensureDefaultId()` uses `firstOrCreate` to avoid duplicate "Default" groups under concurrent first-heartbeats)
- `tests/Feature/DefaultDeviceGroupTest.php` (+2: saving-as-default clears others; deterministic defaultId with stray duplicates)
**Database/API Changes:** None. Behaviour: at most one device group can be the default; if duplicates ever exist (manual DB edit), `defaultId()` deterministically picks the lowest id and the next save of any default collapses them to one.
**Summary:** Answered "what if multiple default groups exist?" by closing the gap. Previously the UI couldn't create duplicates (set-default clears-then-sets; `is_default` isn't form-editable), but a direct DB edit or a rare zero-groups registration race could. Now a model `saved` hook guarantees exactly one default regardless of path, `defaultId()` is deterministic, and the auto-create uses `firstOrCreate` to dodge the race. Verified: Pint 190 files clean, PHPStan L5 0 errors, **145 PHPUnit passed** (465 assertions; +2).

## [2026-06-23 13:45] - Auto-default device group (new devices never land in "None")
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Models/DeviceGroup.php` (`ensureDefaultId()` — returns the default group id, auto-provisioning one when none is designated: promotes the oldest group, else creates a "Default" group; gated by config)
- `config/rustdesk.php` (`devices.auto_default_group`, env `RUSTDESK_AUTO_DEFAULT_GROUP`, default true)
- `app/Http/Controllers/Api/SystemController.php` + `app/Services/DeploymentService.php` (device registration uses `ensureDefaultId()`)
- `app/Http/Controllers/Admin/DeviceGroupController.php` (`store` auto-marks the first/only group as default when none exists)
- `tests/Feature/DefaultDeviceGroupTest.php` (updated the "stay ungrouped" expectation to the new auto-default behavior; +3 tests: promote existing, create-when-none, disable flag, first-group-default)
**Database/API Changes:** None (no schema change). Behaviour change: with `auto_default_group` on (default), a new/ungrouped device now joins an auto-designated default group instead of "None"; set `RUSTDESK_AUTO_DEFAULT_GROUP=false` to keep the old "stay ungrouped unless a default is explicitly set" behaviour.
**Summary:** Followed up the manual "set default" device group with automatic defaulting, so devices never sit in "None" without admin action: on device registration (heartbeat / deploy) the server uses a default group, promoting the oldest existing group or creating a "Default" one if needed; and creating the first device group in the console now marks it default automatically. Opt-out via config. Pairs with the default-strategy fallback so a brand-new device gets both a group and a policy. Verified: Pint 190 files clean, PHPStan L5 0 errors, **143 PHPUnit passed** (461 assertions; +3).

## [2026-06-23 13:15] - Default-strategy fallback + "apply to all matching" device bulk
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Default strategy:** `database/migrations/2026_06_23_100003_add_is_default_to_strategies_table.php` (`is_default`), `app/Models/Strategy.php` (fillable + cast + `Strategy::default()`), `app/Services/StrategyService.php` (`resolveForDevice` now falls back to the default strategy when no device/user/device-group assignment matches — lowest priority), `app/Http/Controllers/Admin/StrategyController.php` (`setDefault`, single-default toggle), `routes/web.php`, `resources/views/admin/strategies/index.blade.php` (Default badge + star toggle)
- **Bulk "all matching":** `app/Http/Controllers/Admin/DeviceController.php` (`bulkUpdate` accepts `all=1`+`q`/`status` to apply across the whole filtered set, not just checked rows; reuses `devicesQuery`), `resources/views/admin/devices/index.blade.php` ("Select all N matching this filter" affordance + JS, sends the filter on submit)
- **Tests:** `tests/Feature/DefaultStrategyAndBulkAllTest.php` (NEW, 6)
**Database/API Changes:** `strategies` gains `is_default`. New admin route `POST /admin/strategies/{strategy}/default`. `POST /admin/devices/bulk` now also accepts `all`+`q`+`status`. Heartbeat strategy resolution gains a default fallback (no wire change).
**Summary:** Two requested fixes. (1) New devices with no assignment previously received no policy ("None"); now a strategy can be marked **Default** (star toggle on the Strategies list) and is pushed as the lowest-priority fallback — complementing the existing default **device group**. (2) The devices bulk-assign bar could only act on rows visible/selected on the current page; it now offers "Select all N matching this filter" to reassign owner / device group / strategy across the entire filtered set in one action. Verified: Pint 190 files clean, PHPStan L5 0 errors, ESLint clean, **140 PHPUnit passed** (450 assertions; +6).

## [2026-06-23 12:45] - Fix: implement OIDC PKCE (Keycloak login hung at "Waiting account auth")
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/OauthService.php` (PKCE end-to-end: `beginAuth` generates a verifier when `pkce_enable` and stores it in the pending session; `authorizationUrl` emits `code_challenge`+`code_challenge_method`; `oidcExchange` sends `code_verifier`; new `pkceVerifier()`/`pkceChallenge()`; `webAuthorizationUrl`/`webResolveUser` accept the verifier; **failure logging** added to discovery/token/userinfo so future failures are diagnosable)
- `app/Http/Controllers/Admin/AuthController.php` (admin SSO flow generates + stashes the PKCE verifier and passes it to the callback)
- `tests/Feature/OidcPkceTest.php` (NEW, 4)
**Database/API Changes:** None (wire shapes unchanged). The OIDC authorize URL now includes the PKCE challenge and the token request includes the verifier **when the provider has `pkce_enable`** — non-PKCE providers are unchanged.
**Summary:** Root-caused the client SSO hang ("Waiting account auth" never completing). The `oauth_providers` table carried `pkce_enable`/`pkce_method` (ported from the reference server) but our OIDC flow **ignored them** — it never sent `code_challenge`/`code_verifier`. A Keycloak client configured for PKCE therefore rejected the exchange, the callback never stored the AuthBody, and the client polled until timeout. Implemented PKCE for both the client device-login flow and the admin-console SSO flow, and added warning logs to the token/userinfo exchange (previously all failures were swallowed). Verified against the client contract (`account.rs` poll → `HbbHttpResponse::parse`); the inner/outer response shapes were already correct, so PKCE was the missing piece. Verified: Pint 188 files clean, PHPStan L5 0 errors, **134 PHPUnit passed** (431 assertions; +4).

## [2026-06-23 12:15] - Admin console SSO/OIDC sign-in (e.g. Keycloak)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Services/OauthService.php` (parametrized the redirect URI through `authorizationUrl`/`socialiteDriver`/`fetchOauthUser`/`oidcExchange`; new public `webAuthorizationUrl()`, `webResolveUser()`, `loginProviders()` for an interactive web flow)
- `app/Http/Controllers/Admin/AuthController.php` (`ssoRedirect` + `ssoCallback` — state-checked OIDC sign-in that resolves the local user, enforces console-access rules, establishes the session; injects OauthService; `showLogin` passes `$ssoProviders`)
- `routes/web.php` (`GET /admin/sso/{op}`, `GET /admin/sso/{op}/callback` — unauthenticated)
- `resources/views/admin/login.blade.php` ("Sign in with <provider>" buttons), `resources/views/admin/oauth_providers/create.blade.php` (notes the console callback URL to register alongside the client one)
- `tests/Feature/AdminSsoLoginTest.php` (NEW, 5)
**Database/API Changes:** New console routes `/admin/sso/{op}` + `/admin/sso/{op}/callback`. Reuses the existing `oauth_providers` configs. The console SSO callback (`/admin/sso/<op>/callback`) must also be registered as a valid redirect URI with the IdP (distinct from the client `/api/oauth/callback`).
**Summary:** The OAuth/OIDC providers (previously client-only) now also power **admin-console** sign-in. The login page shows a "Sign in with <provider>" button per enabled provider; the flow uses a session-stored CSRF state, exchanges the code via the same OauthService logic (now redirect-URI-parametrized), resolves the local user, and enforces the same console-access rules as password login (full admin or a delegated role). SSO is treated as the trust anchor, so the local TOTP challenge is skipped (the IdP owns MFA). Verified: Pint 187 files clean, PHPStan L5 0 errors, **130 PHPUnit passed** (418 assertions; +5).

## [2026-06-23 11:30] - SSO provider presets (Keycloak-first) + dashboard activity metrics
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **SSO presets (research #12):** `config/oauth_presets.php` (NEW — Keycloak first, then Authentik / Entra ID / Okta / Google / GitHub / generic OIDC; each carries type, scopes, PKCE, issuer placeholder + setup hint), `app/Http/Controllers/Admin/OauthProviderController.php` (`create` passes `$presets` + `$redirectUri`), `resources/views/admin/oauth_providers/create.blade.php` ("Quick setup" picker that prefills the form via JS + a copyable Redirect URI to register with the IdP)
- **Dashboard metrics (research #5 follow-up):** `app/Http/Controllers/Admin/DashboardController.php` (new-devices-per-day 14-day series + a period-over-period `trend()` delta on the Sessions card), `resources/views/admin/dashboard.blade.php` (two-series "Activity" chart + trend pill on stat cards), `public/assets/js/app.js` (`RD.areaChart` now accepts an array of colors — backward-compatible)
- `docs/modernization/17-feature-research-2026-06.md` (marked #12 + metrics follow-up done; backlog noted exhausted of clean server-side wins)
- **Tests:** `tests/Feature/OauthPresetsTest.php` (NEW, 3 — presets + redirect URI render, Keycloak first, dashboard activity renders)
**Database/API Changes:** None. The OAuth create form gains a guided-setup picker (UI only — login flow unchanged); the dashboard shows a second trend series + a sessions delta.
**Summary:** Guided SSO setup with a Keycloak emphasis — pick a provider and the form prefills type/scopes/PKCE and the issuer shape, and the exact Redirect URI to register is shown + copyable (Keycloak issuer `https://<host>/realms/<realm>`). Also enriched the dashboard: the activity chart now plots Connections **and** New devices over 14 days, and the Sessions (24h) card shows an up/down delta vs the prior 24h. Verified: Pint 186 files clean, PHPStan L5 0 errors, ESLint clean, **125 PHPUnit passed** (400 assertions; +3).

## [2026-06-23 10:30] - Per-book peer-quota override + Wake-on-LAN research correction
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Per-book quota override (research #7 follow-up):** `database/migrations/2026_06_23_100002_add_max_peers_to_address_books_table.php` (`max_peers` nullable), `app/Models/AddressBook.php` (fillable + cast + `effectiveMaxPeers()`/`isFull()` helpers), enforcement switched to the per-book cap in `app/Http/Controllers/Api/AddressBookController.php` (client), `app/Http/Controllers/Admin/AddressBookController.php` (storePeer + import + `updateSharing` now saves `max_peers`), `app/Http/Controllers/Api/V1/AddressBookController.php`, and the Share modal in `resources/views/admin/address_books/show.blade.php` (Max peers field)
- **Research correction:** `docs/modernization/17-feature-research-2026-06.md` — Wake-on-LAN de-scoped (verified client-local: `flutter_ffi.rs:2173` → `lan.rs:86`, server never contacted); per-book quota marked done
- **Tests:** `tests/Feature/AddressBookPerBookQuotaTest.php` (NEW, 3)
**Database/API Changes:** `address_books` gains `max_peers` (null = use server-wide default, 0 = unlimited, >0 = per-book cap). No new routes; peer-add enforcement across client API / admin / `/api/v1` / CSV-import now honours the per-book cap.
**Summary:** Per-book peer-cap overrides — a book can set its own `max_peers` (in the Share dialog) that wins over the global `RUSTDESK_AB_MAX_PEERS`, enforced everywhere peers are added. Also corrected the research backlog after verifying client source: RustDesk's Wake-on-LAN is entirely client-local (the controlling machine broadcasts the magic packet from its own LAN-discovery cache; the API server is never involved), so there is no wire-compatible server feature to build — de-scoped, same discipline as the `is_pro` correction. Verified: Pint 184 files clean, PHPStan L5 0 errors, **122 PHPUnit passed** (391 assertions; +3).

## [2026-06-23 09:30] - Audit retention + API-key hardening + address-book CSV import/export
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Audit retention (research #14):** `config/rustdesk.php` (`audit_retention_days`, env `RUSTDESK_AUDIT_RETENTION_DAYS`, 0 = keep forever), `app/Console/Commands/PruneAudit.php` (NEW — `audit:prune --days=`, prunes audit_conns/audit_files/login_logs/alarms), `routes/console.php` (scheduled daily)
- **API-key hardening (research #13):** `database/migrations/2026_06_23_100001_add_ip_controls_to_api_keys_table.php` (`last_used_ip`, `allowed_ips`), `app/Models/ApiKey.php` (fillable + `ipAllowed()`), `app/Http/Middleware/ApiKeyAuth.php` (enforce allowlist → 403, record `last_used_ip`), `app/Http/Controllers/Admin/ApiKeyController.php` (`allowed_ips` on create + `rotate` + `normalizeIps`), `routes/web.php` (`POST /admin/api-keys/{key}/rotate`), `resources/views/admin/api_keys/index.blade.php` (allowed-IPs field + last-used IP column + Rotate button)
- **Address-book CSV import/export (research #6 follow-up):** `app/Http/Controllers/Admin/AddressBookController.php` (`exportPeers` via the ExportsCsv trait + `importPeers` — skips existing IDs and over-cap rows), `routes/web.php` (export/import routes), `resources/views/admin/address_books/show.blade.php` (Export/Import buttons + import modal)
- `QUICKSTART.md` (audit retention env var), `docs/modernization/17-feature-research-2026-06.md` (marked #13/#14 + AB import/export done)
- **Tests:** `tests/Feature/AuditPruneTest.php` (NEW, 3), `tests/Feature/ApiKeyHardeningTest.php` (NEW, 4), `tests/Feature/AddressBookImportExportTest.php` (NEW, 3)
**Database/API Changes:** `api_keys` gains `last_used_ip` + `allowed_ips`. New scheduled command `audit:prune`. New admin routes: `POST /admin/api-keys/{key}/rotate`, `GET /admin/address-books/{book}/export`, `POST /admin/address-books/{book}/import`. API-key auth now rejects requests from non-allowlisted IPs (403) and records the source IP.
**Summary:** Operational hardening + DX. Audit logs/alarms can auto-prune to a retention window (keeps SQLite installs lean); API keys gain an optional source-IP allowlist (exact match), last-used-IP tracking, and in-place secret rotation (old secret dies immediately); and any address book can be exported to CSV and re-imported (round-trips the same id/alias/note/tags columns, skipping existing IDs and respecting the peer cap). Verified: Pint 182 files clean, PHPStan L5 0 errors, **119 PHPUnit passed** (380 assertions; +10).

## [2026-06-23 08:30] - Bulk user actions + per-AB peer quota + Prometheus /metrics
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Bulk user actions (research #6):** `app/Http/Controllers/Admin/UserController.php` (`bulkUpdate` — enable/disable/set-group/delete; the acting admin is skipped on disable+delete; `index` now passes `$groups`), `routes/web.php` (`POST /admin/users/bulk`), `resources/views/admin/users/index.blade.php` (row checkboxes + bulk-action bar + JS)
- **Per-AB peer quota (research #7):** `config/rustdesk.php` (`ab_max_peers`, env `RUSTDESK_AB_MAX_PEERS`, 0 = unlimited), enforced on add in `app/Http/Controllers/Api/AddressBookController.php` (client, `bookIsFull` helper) + `app/Http/Controllers/Admin/AddressBookController.php` + `app/Http/Controllers/Api/V1/AddressBookController.php` (422), and advertised via `/api/ab/settings` `max_peer_one_ab`
- **Prometheus /metrics (research #5):** `app/Http/Controllers/MetricsController.php` (NEW — token-gated; 404 when no token), `config/rustdesk.php` (`metrics_token`, env `RUSTDESK_METRICS_TOKEN`), `routes/web.php` (`GET /metrics`)
- `QUICKSTART.md` (new "Optional settings" section: AB cap, metrics token, scheduler cron for webhook retries), `docs/modernization/17-feature-research-2026-06.md` (marked #5/#6/#7 done)
- **Tests:** `tests/Feature/BulkUserActionTest.php` (NEW, 4), `tests/Feature/AddressBookQuotaTest.php` (NEW, 4), `tests/Feature/MetricsTest.php` (NEW, 3)
**Database/API Changes:** No schema change. New admin route `POST /admin/users/bulk`. New public `GET /metrics` (Prometheus text; token via `Authorization: Bearer`, disabled→404). `/api/ab/settings.max_peer_one_ab` now reflects the configured cap; peer-add is rejected once a book is full across the client API (error envelope), admin manager (validation error) and `/api/v1` (422).
**Summary:** Three more research-doc items. Bulk-manage users from the console (enable / disable / set group / delete, with self-protection on destructive actions); a configurable per-address-book peer cap enforced on every write path and advertised to clients; and a token-gated Prometheus `/metrics` endpoint exposing device/user/strategy/alarm/peer/failed-webhook gauges for monitoring. Verified: Pint 177 files clean, PHPStan L5 0 errors, **109 PHPUnit passed** (359 assertions; +11).

## [2026-06-23 07:30] - Webhook delivery log + retry + audit/device CSV export
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Webhook delivery log + retry (research #3):** `database/migrations/2026_06_22_100004_create_webhook_deliveries_table.php`, `app/Models/WebhookDelivery.php` (NEW — status pending/success/failed, attempts, next_attempt_at, MAX_ATTEMPTS=5), `app/Models/Webhook.php` (`deliveries()` relation), `app/Services/WebhookService.php` (records a delivery per send; `attempt()` does the HTTP + exponential backoff scheduling; `send()`/`postGeneric()`/`summarize()` extracted), `app/Console/Commands/RetryWebhooks.php` (NEW — `webhooks:retry`, redrives due failures + prunes old rows), `routes/console.php` (scheduled every 5 min), `app/Http/Controllers/Admin/WebhookController.php` (`deliveries`+`resend`), `resources/views/admin/webhooks/deliveries.blade.php` (NEW), `resources/views/admin/webhooks/index.blade.php` (Deliveries link), `routes/web.php`
- **CSV export (research #4):** `app/Http/Controllers/Admin/Concerns/ExportsCsv.php` (NEW trait — streams a filtered query to CSV via `cursor()`), `app/Http/Controllers/Admin/AuditController.php` (`exportConnections`/`exportFiles`/`exportLogins` + shared query builders), `app/Http/Controllers/Admin/DeviceController.php` (`export` + `devicesQuery`), `routes/web.php`, "Export CSV" buttons on the devices + 3 audit index views
- `docs/modernization/17-feature-research-2026-06.md` (marked #3/#4 done)
- **Tests:** `tests/Feature/WebhookTest.php` (+5: delivery recorded, retry scheduled, retry command due/not-due, resend), `tests/Feature/ExportCsvTest.php` (NEW, 4)
**Database/API Changes:** New `webhook_deliveries` table. New admin routes: `GET /admin/webhooks/{id}/deliveries`, `POST /admin/webhooks/deliveries/{id}/resend`; CSV exports `GET /admin/devices/export` and `GET /admin/audit/{connections,files,logins}/export` (all gated by the matching view permission, honour the active `q`/`status`/`action` filter). New scheduled command `webhooks:retry`.
**Summary:** Operational polish on the two subsystems from the prior wave. Every webhook send is now persisted as a WebhookDelivery (with the payload, so it can be resent); failures retry with exponential backoff via `php artisan webhooks:retry` (scheduled — needs the scheduler cron, harmless without it) or a manual Resend in the console's new per-webhook delivery history. Added one-click CSV export of the device inventory and all three audit logs, each respecting the current search filter and streamed via `cursor()` for memory safety. Verified: Pint 173 files clean, PHPStan L5 0 errors, **98 PHPUnit passed** (328 assertions; +9).

## [2026-06-23 06:30] - /api/v1 write coverage + is_pro research correction
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `app/Models/ApiKey.php` (new scopes `devices.write`, `users.write`, `strategies.write`; `address_book.write` label widened)
- `app/Http/Controllers/Api/V1/DeviceController.php` (`update` — reassign owner/group/strategy/alias), `.../UserController.php` (`store`+`update` — provision/update accounts; password auto-hashed), `.../StrategyController.php` (`store`+`update` — options write, bumps `modified_at`), `.../AddressBookController.php` (`store`+`destroy` — book create/delete, owner-scoped)
- `routes/api.php` (`PUT /api/v1/devices/{id}`, `POST`+`PUT /api/v1/users[/{id}]`, `POST`+`PUT /api/v1/strategies[/{id}]`, `POST /api/v1/address-books`, `DELETE /api/v1/address-books/{id}`)
- `docs/api/openapi.yaml` (write ops + `StrategyWrite` schema; 17 paths/11 schemas), `docs/api/postman_collection.json` (+4 write requests), `docs/api/bruno/Admin API/Create strategy.bru` (NEW), `docs/api/README.md` (scopes + endpoints tables updated)
- `docs/modernization/17-feature-research-2026-06.md` (**corrected**: `is_pro` is inferred from the sysinfo handshake — `sync.rs:195/219` — which we already answer, so it needs no work; reframed `/api/v1` write coverage as the shipped #1)
- `tests/Feature/ApiV1WriteTest.php` (NEW, 7)
**Database/API Changes:** No schema change. New write endpoints under `/api/v1` gated by the new write scopes; address-book writes stay scoped to the key owner (403 otherwise). Strategy writes bump `modified_at` so connected clients re-pull on the next heartbeat.
**Summary:** Turned the read-only admin REST API two-way (research doc #1). Added device reassignment, strategy + user create/update, and address-book create/delete, each behind a dedicated write scope; updated the OpenAPI spec + Postman/Bruno collections + reference to match. Also corrected the earlier `is_pro` recommendation after verifying the client source: `is_pro()` is inferred when the server answers `/api/sysinfo` (`SYSINFO_UPDATED`) / `/api/sysinfo_ver` — both already implemented — so it required no new work; the big Pro panels are driven by their own endpoints (e.g. `/api/ab/shared/profiles`). Verified: Pint 168 files clean, PHPStan L5 0 errors, **89 PHPUnit passed** (296 assertions; +7), OpenAPI/Postman parse-validated.

## [2026-06-23 05:30] - Webhooks + shared address books + OpenAPI/Postman/Bruno + research + AI-enhanced README
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- **Webhooks (roadmap #3):** `database/migrations/2026_06_22_100001_create_webhooks_table.php`, `app/Models/Webhook.php` (NEW — types generic/slack/telegram, event catalog), `app/Services/WebhookService.php` (NEW — best-effort synchronous delivery, no queue worker required; per-type payloads; HMAC `X-RustDesk-Signature` for generic; records last_status/last_triggered_at/failure_count), `app/Http/Controllers/Admin/WebhookController.php` (NEW — create/update/toggle/test/delete), `resources/views/admin/webhooks/index.blade.php` (NEW), `routes/web.php`, sidebar link, `app/Models/AdminRole.php` (`webhooks.view|edit` permissions), `resources/views/admin/partials/flash.blade.php` (error toast). Event hooks: `app/Services/AlarmService.php` (`alarm.raised`), `app/Http/Controllers/Api/AuditController.php` (`connection.new|closed`), `app/Http/Controllers/Api/SystemController.php` (`device.new`).
- **Shared / team address books (roadmap #4):** `database/migrations/..._add_sharing_to_address_books_table.php` (+`is_shared`,`note`), `..._create_address_book_collaborators_table.php`, `app/Models/AddressBookCollaborator.php` (NEW — ShareRule read=1/readWrite=2/full=3), `app/Models/AddressBook.php` (sharing fields, `collaborators()`, `ruleFor`/`canRead`/`canWrite`), `app/Http/Controllers/Api/AddressBookController.php` (`resolveBook` resolves shared books + write-gating on all 7 mutations; `sharedProfiles` now returns owned-shared + shared-with-me AbProfiles with `rule`), `app/Http/Controllers/Admin/AddressBookController.php` (`updateSharing`/`storeCollaborator`/`destroyCollaborator`), `resources/views/admin/address_books/show.blade.php` (Share modal + collaborator picker), `routes/web.php`.
- **OpenAPI + collections (roadmap #2):** `docs/api/openapi.yaml` (NEW — OpenAPI 3.1, 13 paths/10 schemas, admin v1 + client API), `docs/api/postman_collection.json` (NEW — v2.1, captures account token on login), `docs/api/bruno/**` (NEW — bruno.json, Local env, Admin/Client requests), `docs/api/README.md` (machine-readable specs + shared-book/webhook sections).
- **Research + docs:** `docs/modernization/17-feature-research-2026-06.md` (NEW — ranked open opportunities; recommends `is_pro` advertisement next), `docs/modernization/04-gap-analysis.md` (banner: roadmap all done, points to doc 17), `README.md` (AI‑enhanced section; Features: shared AB + webhooks; OpenAPI/Postman/Bruno mention).
- **Tests:** `tests/Feature/WebhookTest.php` (NEW, 6), `tests/Feature/SharedAddressBookTest.php` (NEW, 5).
**Database/API Changes:** New tables `webhooks`, `address_book_collaborators`; `address_books` gains `is_shared`+`note`. New admin pages `/admin/webhooks` (+ toggle/test) and address-book sharing routes. Client API: `POST /api/ab/shared/profiles` now returns real shared books with `rule`; AB mutations enforce collaborator write permission. No wire keys/paths renamed.
**Summary:** Completed the remaining three roadmap items in one wave — outbound webhooks/notifications (Slack/Telegram/generic, HMAC-signed, per-hook test), shared/team address books (collaborators with read/read-write/full rules, surfaced to the client via shared/profiles and gated server-side), and a machine-readable OpenAPI 3.1 spec + Postman + Bruno collections — plus a fresh feature-research doc and an "AI-enhanced project" note in the README. Webhook delivery is synchronous best-effort with a short timeout so it needs no queue worker. Verified: Pint 167 files clean, PHPStan L5 0 errors, **82 PHPUnit passed** (270 assertions; +11), OpenAPI/Postman parse-validated.

## [2026-06-23 03:00] - Docs: deep review/refresh after the feature wave
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `README.md` (Features section rewritten to current reality: client‑Settings strategy editor, address‑book manager, device bulk‑assign + live‑search, Client Config generator, API Keys, default device group, admin 2FA, login throttling; new "Admin REST API" subsection)
- `docs/api/README.md` (NEW — `/api/v1` reference: auth, scopes, endpoints, curl examples)
- `docs/modernization/04-gap-analysis.md` (top "Current status 2026‑06‑23" banner: done ✅ / remaining roadmap / new opportunities)
- `docs/modernization/09-port-status.md` (banner: Go retired, port complete, file now historical)
- Client Config generator: auto‑fill server host/relay/api/**public key** from env / mounted key file (`ClientConfigController::serverDefaults`), and per‑OS `--config` examples (Windows/macOS/Linux)
**Database/API Changes:** None (docs + Client Config UX).
**Summary:** Brought the documentation up to date after a large feature wave and made the Client Config generator pre‑fill this deployment's servers + key automatically with copy‑ready per‑OS command lines. Catalogued the remaining roadmap (OpenAPI/Postman, webhooks, shared address books) and newly‑surfaced opportunities (more `/api/v1` coverage + write scopes, audit export, bulk user/AB actions, WoL, dashboard metrics, per‑AB quotas, a CLI) in doc 04.

## [2026-06-23 02:30] - Feature: scoped API keys + admin REST API (/api/v1)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `database/migrations/..._create_api_keys_table.php` + `app/Models/ApiKey.php` (hashed secret, prefix, scopes, expiry; `hasScope`, `generateSecret`)
- `app/Http/Middleware/ApiKeyAuth.php` (NEW, alias `apikey:<scope>`), `bootstrap/app.php`
- `app/Http/Controllers/Api/V1/{Device,User,Strategy,Audit,AddressBook}Controller.php` (NEW), `routes/api.php` (`/api/v1/*`)
- `app/Http/Controllers/Admin/ApiKeyController.php` (NEW), `routes/web.php`, `resources/views/admin/api_keys/index.blade.php` (NEW), sidebar link
- `tests/Feature/ApiKeyTest.php` (NEW, 7)
**Database/API Changes:** New `api_keys` table; new admin REST API `GET /api/v1/{devices,users,strategies,audit/connections,address-books}` (+ AB peer read/write), authenticated by `Authorization: Bearer <key>` or `X-API-Key`, each gated by a scope. New admin page `/admin/api-keys`.
**Summary:** Implemented the Pro-style programmatic API (roadmap #1). Admins mint scoped keys (devices/users/strategies/address_book.read|write/audit.read; secret shown once, SHA-256-stored, optional expiry) and call `/api/v1` endpoints; AB writes are scoped to the key owner's books. The API Keys page shows a curl quickstart. Verified: Pint 158, PHPStan L5 0 errors, 71 PHPUnit passed.

## [2026-06-23 01:30] - Feature: Client Config generator (config string + mobile QR + installer)
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `composer.json` / `composer.lock` (added `endroid/qr-code` for server-side SVG QR, no GD needed)
- `app/Services/ClientConfigService.php` (NEW — encodes the RustDesk server-config string, QR payload, installer filename, SVG QR)
- `app/Http/Controllers/Admin/ClientConfigController.php` (NEW), `routes/web.php`, `resources/views/admin/client_config/index.blade.php` (NEW), `resources/views/admin/partials/sidebar.blade.php` (nav link)
- `tests/Feature/ClientConfigTest.php` (NEW — 4, incl. client-exact round-trip)
**Database/API Changes:** New admin page `GET /admin/client-config`. No client-API change.
**Summary:** Implemented the open-source equivalent of RustDesk Pro's client generator (the explicit ask). An admin enters ID/relay/API/key and gets: the **config string** (desktop "Import Server Config" / `rustdesk --config`), a **mobile QR** (payload `config=…`, scanned in the mobile app), and the **renamed-installer filename** (`rustdesk-host=…,key=….exe`). Encoding verified against the client's own `ServerConfig.decode` / `custom_server.rs` (`reverse(url-safe-base64-no-pad(json{host,relay,api,key}))`) — one string satisfies every client import path. Verified: Pint 148, PHPStan L5 0 errors, 64 PHPUnit passed.

## [2026-06-23 00:45] - Device bulk-assign + reusable live-search combobox + strategy "set all"
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `public/assets/js/app.js` (NEW `RD.bindCombobox` — reusable server-backed searchable picker), `public/assets/css/theme-dark.css` (`.rd-combo` styles)
- `app/Http/Controllers/Admin/DeviceController.php` (`bulkUpdate` — bulk owner/group/strategy; `search` endpoint; edit() no longer loads all users), `app/Http/Controllers/Admin/UserController.php` (`search` endpoint)
- `routes/web.php` (`devices/bulk`, `devices/search`, `users/search`)
- `resources/views/admin/devices/index.blade.php` (row checkboxes + bulk-assign bar with combo user picker), `resources/views/admin/devices/edit.blade.php` (owner → combo)
- `resources/views/admin/strategies/edit.blade.php` (assignment device+user pickers → combos; "Apply to this tab: All on / All off / All default" toolbar), `app/Http/Controllers/Admin/StrategyController.php` (edit() loads only assigned targets for labels, not every device/user)
- Tests: `tests/Feature/DeviceBulkAndSearchTest.php` (NEW, 6) + `e2e/gui.spec.ts` (+set-all + bulk-bar specs)
**Database/API Changes:** New admin endpoints `POST /admin/devices/bulk`, `GET /admin/devices/search`, `GET /admin/users/search`. No client-API change.
**Summary:** Three requested admin improvements: (1) bulk-assign owner / device group / strategy from the devices list via row checkboxes + an action bar; (2) a reusable live-search combobox (`RD.bindCombobox`) that replaces the huge device/user `<select>`s — the strategy-assignment device picker no longer loads thousands of rows, querying `/admin/devices|users/search` as you type — also applied to the device-edit owner field and the bulk-assign user picker; (3) a per-tab "All on / All off / All default" toolbar on the strategy editor. Verified: Pint 145, PHPStan L5 0 errors, ESLint clean, 60 PHPUnit + 8 Playwright passed.

## [2026-06-22 23:30] - Strategy editor reskinned as client Settings + dark modals + Playwright
**Agent:** rustdesk-api (Claude Opus 4.8)
**Files Modified:**
- `config/strategy_options.php` (restructured to client-Settings tabs → sections → options: General / Security / Network / Client UI; verified keys/values only)
- `resources/views/admin/strategies/edit.blade.php` (rebuilt as a client-Settings layout: left sub-nav + sectioned panes, tri-state controls, override highlight)
- `app/Http/Controllers/Admin/StrategyController.php` (`edit` flattens `tabs`→sections→options for the custom-key diff)
- `public/assets/css/theme-dark.css` (dark-theme Bootstrap modals + dropdowns — they were defaulting to white)
- `e2e/gui.spec.ts` (NEW — Playwright: strategy sub-nav renders + pane switching; AB manager Add ID modal opens and is dark, not white)
**Database/API Changes:** None (same `config_options` map; wire keys/values unchanged).
**Summary:** Per the user's request to make the admin feel like the RustDesk client, rebuilt the Strategy editor to mirror the client's Settings window — a left sub-nav (General/Security/Network/Client UI) with section cards (Permissions, Password, Connection security, …) in the client's wording and order; kept the policy tri-state (Default/On/Off) since a policy needs a "leave client default". Also fixed the reported **white** Bootstrap modals/dropdowns (global dark-theme override). Validated the GUI with Playwright (2 specs pass: editor sub-nav + pane switching, and the dark Add ID dialog). Verified: Pint 144, PHPStan L5 0 errors, 54 PHPUnit + 2 Playwright passed.

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
