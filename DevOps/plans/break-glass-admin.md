# 📦 Parcel Plan: Protected break-glass administrator

## 📊 State Dashboard
| Metric | Value |
| :--- | :--- |
| **Status** | `IMPLEMENTED` |
| **Version** | `v1.0.0` |
| **Active Persona** | `Architect` |
| **Last Updated** | 2026-07-25 |

---

## 1️⃣ Phase 1: Expansion & Scoping
* **Intent:** Guarantee there is always a way back into the console when the identity provider is
  wrong, unreachable, or has revoked the operator. Concretely: one designated administrator that no
  directory or SSO provider can demote, disable, or lock out, whose password is generated rather
  than configured, and which can be recovered from a shell.
* **In Scope:**
  - A protected-administrator flag that shields one account from demotion, deletion, disabling,
    `force_sso`, LDAP attribute sync, and identity linking.
  - Closing the existing path by which a directory silently demotes a local administrator.
  - A generated bootstrap password, with `ADMIN_PASS` retained as an optional override.
  - A CLI recovery command that can reset the password, and — only on explicit request — unlink
    federated identities, clear `force_sso`, and clear a lost second factor.
* **Out of Scope:**
  - A global "at least one administrator must survive" count invariant. Decided against: the
    protected account cannot be demoted, deleted, or disabled, so a lockout requires deliberately
    running `unprotect --i-understand` or `transfer` first. See the residual risk in §5.
  - Removing `ADMIN_PASS`. Decided against: E2E, screenshot fixtures, the performance harness and
    the runtime smoke all require a deterministic password, and every documented install uses it.
  - A forced password change at first login. The self-deleting credential file already bounds the
    generated secret's exposure, and the middleware would have to be sequenced after the TOTP
    challenge to avoid becoming a first-factor-only password reset.
  - Any new HTTP surface. Every escape hatch is CLI-only by design.

## 2️⃣ Phase 2: Requirements & Context

### The bug this starts from
`app/Services/LdapService.php:258` (`syncLinkedAttributes`) writes `$user->is_admin =
$attrs['is_admin']` on every login when `LDAP_SYNC=true`. `isAdmin()` returns **false whenever
`LDAP_ADMIN_GROUP` is unset**, which is its default. An LDAP-linked administrator is therefore
silently demoted at their next sign-in, and the default configuration makes this more likely rather
than less. `app/Services/LdapService.php:220` does the same on first provisioning.

This is the exact hazard the request describes, and it exists today independent of the new SSO role
mapping — which does already skip `is_admin` accounts and cannot write the flag.

### What already works and must not be rebuilt
- `app/Console/Commands/CreateUser.php` (`rustdesk:user`) already creates **or resets** a password,
  with a hidden prompt, `--password-stdin`, tty refusal, and `hash_equals` confirmation.
- `app/Services/AccountCredentialService.php:33` `replacePassword()` already bumps
  `credential_version`, rotates the remember token, revokes `AuthToken` rows, deactivates
  `VerifyCode` rows, deletes `ApiKey` and `DeployToken` rows, and cleans database sessions.
- `app/Support/BootstrapAdminCredentials.php` already rejects missing, short, known, placeholder,
  repeated and username-derived passwords.

### The gaps that make recovery impossible today
1. `AccountCredentialService.php:40` refuses any account with an `LdapIdentity` or `UserThird` row,
   so the CLI cannot reset exactly the account whose provider just broke.
2. **A lost TOTP device with no recovery codes is unrecoverable by any path.** `TwoFactorController`
   is self-service only, and no command clears enrollment.
3. `UserController::bulkUpdate()` performs mass builder `update()`/`delete()` calls, which fire no
   model events — so an observer-based guard would be silently bypassed there.

## 3️⃣ Phase 3: User Clarification
* **Open Questions:**
  - `[x]` Flag column, count invariant, or both? → **Answer:** column only, no count invariant.
  - `[x]` Fate of `ADMIN_PASS`? → **Answer:** keep as an optional override; generate by default.
  - `[x]` How does the generated password reach the operator? → **Answer:** one-time stdout banner
    plus a root-owned `0400` file that deletes itself at first successful login.

## 4️⃣ Phase 4: Detailed Execution Plan

### A. Schema
- `users.is_protected_admin` boolean, default false, after `is_admin`.
- A generated column `protected_admin_slot TINYINT AS (IF(is_protected_admin, 1, NULL)) VIRTUAL`
  with a `UNIQUE` index, so "at most one protected admin" is a database fact rather than an
  application convention, and concurrent writes cannot both win.
- **Never fillable.** It is written only by `forceFill` inside the seeder and the dedicated
  commands, so no mass-assignment path can reach it.
- Backfill: the lowest-`id` account with `is_admin = 1` and no `ldap_identities` / `user_thirds`
  row. If none qualifies, set nothing and log a startup warning naming the remedy command. Do not
  guess — silently protecting the wrong account is a durable surprise.
- `DemoShowcaseSeeder` must never set the flag.

### B. What the flag refuses
For the protected account, refuse with an explicit error (never a silent no-op):

| Path | File |
|---|---|
| Demote, disable, set `force_sso`, delete | `app/Http/Controllers/Admin/UserController.php` — `update()`, `destroy()`, and the three mass writes in `bulkUpdate()` |
| Same, over REST | `app/Http/Controllers/Api/V1/UserController.php` |
| LDAP attribute demotion | `app/Services/LdapService.php:220`, `:258` — guard both writes |
| Linking a federated identity | `LdapIdentity` / `UserThird` creation for that `user_id` |

`SsoRoleSyncService`'s existing `is_admin` skip is already correct and stays as-is.

**`bulkUpdate()` needs converting** from mass builder writes to event-firing iteration, or a
pre-flight guard query. This is the single most likely place for the guard to be quietly bypassed.

### C. Escape hatches (CLI only, never over HTTP)
- `php artisan rustdesk:admin:transfer {username}` — atomically move the flag to another account.
- `php artisan rustdesk:admin:unprotect {username} --i-understand` — clear it, with a loud warning
  naming the consequence.

### D. Generated bootstrap password
- New `App\Support\GeneratedAdminPassword`: `random_int()` over the unambiguous alphabet
  `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no `0/O`, `1/I/l` — it will be transcribed by hand), length 20
  (~99 bits), hyphenated in groups of five for display. Generate, validate against
  `BootstrapAdminCredentials`, regenerate on rejection up to five attempts, then hard-fail.
- `BootstrapAdminCredentials::resolvePassword()`: when the password is missing **and** production,
  generate instead of throwing. Every other rule for an explicitly supplied `ADMIN_PASS` is
  unchanged. This turns today's fail-closed startup abort into a safe default.
- `DatabaseSeeder::run()`: wrap the existence check and the create in one transaction with
  `lockForUpdate()`, closing the current TOCTOU window. Generate **only** inside the create branch.
  **Never rotate an existing account's password, under any condition** — a boot that silently
  rotates the admin password is the worst possible regression here, and gets its own test.
- Delivery: a framed one-time banner on stdout, plus `storage/app/.initial-admin-password` written
  **after** the `chown -R www-data:www-data storage` at `docker/entrypoint.sh:214`, owned
  `root:root`, mode `0400`, never under `storage/app/public/`. Deleted automatically at that
  account's first successful authentication; a console banner shows while it exists.
- Do not write it to `storage/logs/` (same volume, chowned to www-data, shipped by log collectors),
  email it, put it in the database, or expose it on any route.

### E. CLI recovery
```
php artisan rustdesk:admin:reset {username}
    [--password-stdin] [--generate]        # otherwise a hidden confirmed prompt
    [--unlink-federated-identities]        # deletes ldap_identities / user_thirds rows
    [--clear-force-sso]
    [--clear-2fa]                          # the only path out of a lost TOTP device
```
- **No positional password argument** — do not carry `CreateUser`'s deprecated affordance forward.
- Every optional flag is off by default and individually audited. Without the relevant flag, refuse
  with a message naming it; never silently strip a security control.
- Route through `AccountCredentialService::replacePassword()` so revocation comes for free, and
  implement the unlink inside that service next to `hasFederatedIdentity()`, so
  `FEDERATED_IDENTITY_MESSAGE`'s promise of "an explicit unlink/conversion flow" becomes true.
- Print the count of deleted API keys and deploy tokens — that collateral must not vanish silently.
- Write a `ConsoleAudit` row (`method = 'CLI'`, `route_name = 'cli.admin.password-reset'`) plus a
  `Log::warning`, neither containing the password. CLI credential changes are currently invisible.
  Audit failure warns loudly but never aborts the reset.

## 5️⃣ Phase 5: Product Owner Review
* **Status:** `PENDING`
* **Findings:**
  - ✅ **Vision & Scope** — delivers guaranteed break-glass and closes a live demotion bug.
  - ⚠️ **Residual risk accepted by choice** — with no count invariant, an operator who runs
    `unprotect --i-understand` and then deletes the remaining administrators locks themselves out.
    That now requires two deliberate acts, one of which prints a warning naming the consequence.
  - ⚠️ **`--clear-2fa` is a real capability transfer.** Anyone with `docker exec` can strip an
    administrator's second factor. That population already holds `APP_KEY`, the database
    credentials, and root in the container, so it grants no new authority — but it must be audited,
    and the docs must say so plainly rather than presenting it as routine.

## 6️⃣ Phase 6: Senior Dev Hygiene Review
* **Status:** `PENDING`
* **Findings:**
  - ✅ **DRY** — reuses `replacePassword()` and `CreateUser`'s input handling rather than
    reimplementing revocation or password collection.
  - ✅ **Secret Management** — generated password never reaches the database, logs, or HTTP.
  - ⚠️ **Idempotency is the highest-risk property.** The database row, not
    `storage/app/.installed`, must be the source of truth: the marker lives in the storage volume
    while the account lives in the database volume, and they can diverge in both directions.

## 7️⃣ Phase 7: Implementation Checklist
- `[x]` Migration: `is_protected_admin` + unique stored slot + backfill.
- `[x]` Guard every mutation path in §B, including `bulkUpdate()`'s mass writes.
- `[x]` Guard the `LdapService` demotion write. Identity linking needed no guard: both
      `LdapIdentity` and `UserThird` rows are only ever created for a freshly provisioned account,
      so an existing administrator can never be linked.
- `[x]` `GeneratedAdminPassword`, seeder transaction, never-rotate guarantee.
- `[x]` Entrypoint banner + `0400` credential file + delete-on-first-login.
- `[x]` `rustdesk:admin:reset` and `rustdesk:admin:protect` (show/transfer/clear), with audit.
- `[x]` Tests per §8, docs per §9.

**Deviations from the plan.** Transfer and unprotect were folded into one
`rustdesk:admin:protect` command with `--show` / `--clear --i-understand`, rather than three
separate commands; the operations are one concept and the shared validation belongs in one place.
No forced password change was added, as scoped. `/api/v1` needed no new guard: it already refuses
to modify any account holding `is_admin`, and it has no delete action.

## 8️⃣ Phase 8: Test Plan
- One test per mutation path in §B asserting the protected account survives, explicitly including
  `bulkUpdate()`'s mass writes and LDAP demotion under `LDAP_SYNC=true` with `LDAP_ADMIN_GROUP`
  unset — the exact live bug.
- Boot idempotency: seed twice, assert the password hash is byte-identical and that nothing is
  printed or written the second time.
- `--generate` output satisfies `AccountPasswordPolicy` and passes `BootstrapAdminCredentials` in
  production mode.
- The credential file is `0400`, is not world-readable, and disappears after first login.
- `--clear-2fa` recovers an account whose TOTP is enrolled and whose recovery codes are exhausted.
- Existing `ADMIN_PASS` behaviour is unchanged when the variable is set.

## 8️⃣.5 Verification
* **Status:** `PASS`
  - Pint across 300 files, PHPStan across 194 files.
  - 641 PHPUnit tests / 3,453 assertions on the isolated MariaDB schema, up from 622 / 3,242.
  - 71 Playwright tests with 21 intentional project skips.
  - ShellCheck on `docker/entrypoint.sh` reports only the pre-existing SC2034/SC2016 findings.

## 9️⃣ Phase 9: Documentation
`.env.example` (`ADMIN_PASS` now optional), `QUICKSTART.md`, `README.md`, `docker/README.md` (the
"no fallback" paragraph becomes wrong), `Wiki/core/15-security.md` (a new break-glass boundary),
`docs/modernization/12-access-control-design.md`, `CHANGELOG.md`.

## 🔟 Phase 10: Wrap Up & Archival
* **System Context Updates:** the console gains a designated recovery account that is outside every
  external trust root. Future work touching `is_admin`, `force_sso`, account status, or identity
  linking must check the protected flag, and the CLI becomes a supported administrative surface
  rather than a development convenience.
