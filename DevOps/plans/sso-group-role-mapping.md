# 📦 Parcel Plan: IdP group → console role mapping

## 📊 State Dashboard
| Metric | Value |
| :--- | :--- |
| **Status** | `IMPLEMENTED` |
| **Version** | `v1.0.0` |
| **Active Persona** | `Architect` |
| **Last Updated** | 2026-07-25 |

---

## 1️⃣ Phase 1: Expansion & Scoping
* **Intent:** An operator declares "IdP group X grants console role Y". At sign-in, a user's
  FreeIPA/LDAP or OIDC group membership is translated into RustDesk console roles automatically,
  with no per-user administration.
* **In Scope:**
  - A provider-scoped mapping table with an admin console screen to manage it.
  - Reconciliation at every sign-in, for all four login paths (console LDAP, client LDAP,
    console OIDC, client OIDC), through one shared service.
  - Grant provenance so IdP-managed and manually assigned roles can coexist safely.
  - Revocation when a user leaves a group, plus a structured audit trail of every change.
  - Reading a group claim from OIDC userinfo, per provider, with a configurable claim name.
* **Out of Scope:**
  - Any path from a group claim to `users.is_admin`. Decided: a mapping may target an
    `AdminRole`, including a `global` one behind an env opt-in, but never the legacy flag.
  - ID-token parsing. There is no JWT verifier in this codebase (no JWKS, no signature check,
    and the `nonce` already generated at `OauthService.php:90` is never verified). Groups come
    from the userinfo response only. This means **Entra ID and Google are not supported** —
    Entra emits groups only in the ID/access token, and Google has no groups claim at all.
  - Nested-group expansion for Active Directory, which needs `LDAP_MATCHING_RULE_IN_CHAIN`.
    FreeIPA materialises indirect membership already, so the stated driver works.
  - Changing the existing env-only `LDAP_ADMIN_GROUP` → `is_admin` behaviour, which is retained
    for backward compatibility precisely because env config requires host access.

## 2️⃣ Phase 2: Requirements & Context
* **Relevant Docs Found:**
  - `docs/modernization/12-access-control-design.md` → Layer 3 is the model being extended.
  - `Wiki/core/06-design-system.md` → the admin screen must use existing components only.
* **Relevant Code Found:**
  - `app/Models/AdminRole.php:43-67` → the 31-permission catalogue and the three role types.
  - `app/Services/PermissionService.php:32` → a `global` role grants **every** permission,
    including strings absent from the catalogue. This is why `global` is opt-in.
  - `app/Http/Middleware/EnsureAdmin.php:25` → holding any role opens the console door, so a
    mapping is also an account-provisioning decision.
  - `app/Services/LdapService.php:118` → `memberOf` DNs already extracted; `:138` already
    derives `is_admin`; `:239` is the existing revocation defect this must not inherit.
  - `app/Services/OauthService.php:380` → the full userinfo claim set is in memory and
    discarded at `:397-404`; `:259-280` `fetchOauthUser()` is the single funnel for both paths.
  - `app/Http/Controllers/Admin/AdminRoleController.php:118-128` → the `is_admin`-only mutation
    precedent this screen must copy.
  - `database/migrations/2026_06_18_100026_create_admin_role_user_table.php` → the pivot to
    extend with provenance.

## 3️⃣ Phase 3: User Clarification
* **Open Questions:**
  - `[x]` How far may a mapping escalate? → **Answer:** delegated roles always; a `global` role
    only with `SSO_ROLE_MAPPING_ALLOW_GLOBAL=true` plus a UI warning; `is_admin` never.
  - `[x]` Delivery shape? → **Answer:** one release, everything.

## 4️⃣ Phase 4: Detailed Execution Plan
* **Architecture & Files to Touch:**
  - `database/migrations/*_create_sso_role_mappings_table.php` → the mapping table.
  - `database/migrations/*_add_origin_to_admin_role_user_table.php` → grant provenance.
  - `database/migrations/*_add_groups_claim_to_oauth_providers_table.php` → per-provider claim.
  - `database/migrations/*_create_sso_role_sync_logs_table.php` → the structured audit trail.
  - `app/Models/SsoRoleMapping.php`, `app/Models/SsoRoleSyncLog.php` (new).
  - `app/Services/SsoRoleSyncService.php` (new) → the single reconciliation engine.
  - `app/Support/SsoGroupKey.php` (new) → deterministic normalisation, one implementation.
  - `app/Services/LdapService.php` → distinguish "no groups" from "lookup failed".
  - `app/Services/OauthService.php` → read the configured claim from userinfo, carry it through
    `fetchOauthUser()`, and invoke the sync from both OIDC entry points.
  - `app/Http/Controllers/Admin/{AuthController,SsoRoleMappingController}.php`,
    `app/Http/Controllers/Api/LoginController.php`.
  - `app/Http/Controllers/Admin/UserController.php:299` → must stop wiping IdP-managed grants.
  - `resources/views/admin/sso_role_mappings/*`, sidebar, routes, config, docs.
* **Test Verification Plan:**
  - `docker compose -f docker/compose.toolchain.yml run --rm app bash -lc './vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G'`
  - `docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test`

## 5️⃣ Phase 5: Product Owner Review
* **Status:** `APPROVED`
* **Findings:**
  - ✅ **Vision & Scope** — delivers "log in and automatically gain administrator access" via a
    `global` role, without handing an IdP string the uncontainable legacy flag.
  - ⚠️ **Dependency & Functional Risk** — Entra ID and Google cannot be supported without an
    ID-token verifier. Documented as an explicit limitation rather than silently failing.
  - ✅ **Business Logic & Edge Cases** — provenance resolves the additive/authoritative dilemma.

## 6️⃣ Phase 6: Senior Dev Hygiene Review
* **Status:** `APPROVED`
* **Findings:**
  - ✅ **DRY Scan** — one sync service invoked from four call sites; one normaliser.
  - ✅ **Error Handling** — reconciliation runs outside the identity transaction and can never
    fail a login; an unresolved group list never revokes.
  - ✅ **Secret Management** — no credential enters the sync log; group values are capped.

## 7️⃣ Phase 7: Implementation Checklist (Execution)
- `[x]` Migrations: mappings, pivot provenance, provider claim column, sync log.
- `[x]` `SsoGroupKey` normaliser + `SsoRoleMapping` / `SsoRoleSyncLog` models.
- `[x]` `SsoRoleSyncService` with the reconciliation rules in §8.
- `[x]` LDAP: explicit unknown-groups marker; wire the sync into both LDAP login paths.
- `[x]` OIDC: per-provider claim read from userinfo; wire the sync into both OIDC paths.
- `[x]` `UserController` manual-only sync + read-only display of IdP-managed roles.
- `[x]` Admin screen, routes, sidebar, `is_admin`-only mutation.
- `[x]` Config + env + docs + tests, including a Playwright walkthrough of the new screen.

## 8️⃣ Phase 8: The rules the implementation must follow

1. **`is_admin` is never written by a mapping.** The engine writes `admin_role_user` rows only.
2. **Mapping CRUD is `is_admin`-only**, mirroring `AdminRoleController::authorizeRoleMutation()`.
   A new `sso_mappings.view` permission is added for read-only delegate visibility; no
   corresponding `.edit` permission is added to the catalogue.
3. **Mappings are provider-scoped.** Keyed on `(provider_kind, provider_key, group_key)`. For
   OIDC `provider_key` is `oauth_providers.op`; for LDAP it is the namespace from
   `LdapService::identityProvider()`, which already fails closed when directory config changes.
   No cross-provider fallback matching, ever.
4. **Exact, normalised matching.** Store the raw value for display and a normalised key for
   comparison. LDAP: lowercase, trim, collapse whitespace around `,` and `=`. OIDC: trim,
   lowercase. No wildcards, no regex, no prefix matching.
5. **Sync is authoritative over IdP-owned grants only.** `admin_role_user.origin` is `manual` or
   `idp:<kind>:<key>`. A login reconciles only rows owned by the authenticating provider.
6. **A `global`-type target requires `SSO_ROLE_MAPPING_ALLOW_GLOBAL=true`**, refused at write
   time and re-checked at sync time so flipping the flag off disables existing mappings.
7. **Never revoke on uncertainty.** Groups resolved → reconcile. Provider error or claim absent
   → authenticate, change nothing, log the skip. Only an explicitly empty set revokes.
8. **Never modify a full administrator.** Users with `is_admin` are skipped entirely, so the
   break-glass account can never be altered by an IdP.
9. **Sync can never fail a login.** It runs outside the identity transaction; any failure is
   logged and audited, and authentication proceeds.
10. **One service, four call sites.** Console LDAP, client LDAP, console OIDC, client OIDC.
11. **OIDC groups come from userinfo only**, via a per-provider claim name defaulting to
    `groups`. Nested claim paths (`realm_access.roles`) are supported with dot notation.
12. **Bound the input.** Cap group count and per-value length before comparison or logging.
13. **A change in effective authority invalidates prior sessions and tokens.** A sync that grants
    or revokes bumps `credential_version` and revokes the user's `AuthToken` rows, whose
    `is_admin` is a snapshot taken at issue time.
14. **Every effective change is audited**, along with every skip reason. Auditing is best-effort
    and never breaks a login.

## 8️⃣.5 Phase 8: Verification Dashboard
* **Verification Status:** `PASS`
* **Report:**
  - `[x]` Pint across 292 files and PHPStan across 188 files clean.
  - `[x]` 622 PHPUnit tests / 3,242 assertions on the isolated MariaDB schema, up from 590 / 3,176.
  - `[x]` 71 Playwright tests with 21 intentional project skips.
  - `[x]` Adversarial review across six lenses over the finished change, every finding
        independently refuted before acceptance. 13 distinct defects survived and were fixed
        before the change was committed; see the agent changelog entry for the material ones.

## 9️⃣ Phase 9: User Verification
* **Status:** `PENDING`
* **Notes:** Not yet exercised against a live FreeIPA or Authentik deployment. The reconciliation
  rules are covered by tests with synthetic group lists; the directory-specific caveats in §1
  (AD nested groups, AD primary group, FreeIPA `memberOf` containing roles and HBAC rules as well
  as groups) are documented rather than verified against real directories.

## 🔟 Phase 10: Wrap Up & Archival
* **System Context Updates:** `docs/modernization/12-access-control-design.md` gains a
  federated-grant concept: role assignments now carry provenance, and Layer 3 membership can be
  computed from an external trust root rather than only assigned by hand.
