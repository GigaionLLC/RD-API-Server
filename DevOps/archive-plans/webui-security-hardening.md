# Parcel Plan: WebUI Review Remediation and Security Hardening

**Archived:** 2026-07-15 after complete implementation and verification.

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `COMPLETE` |
| **Version** | `v1.0.0` |
| **Active Persona** | Security and frontend hardening implementer |
| **Last Updated** | 2026-07-15 20:45 PDT |

---

## 1. Phase 1: Expansion & Scoping

**Intent:** Resolve the issues found during the post-redesign functional, accessibility,
authorization, security, dependency, and release-integrity review.

**In scope:**

- Critical delegated-admin/API-key privilege escalation.
- Stored XSS in Blade script contexts.
- Client-config command injection and unlock-PIN exposure.
- Webhook SSRF and credential-bearing URL disclosure.
- Destructive permission gaps for alarms and recordings.
- WebUI interaction, responsive, accessibility, and test gaps found by the audit.
- Reproducible local frontend assets, third-party notices, and mutable Docker tooling.
- Recent completed-sign-in enforcement for personal authenticator setup and removal.
- Consistent TOTP state that generic user editing cannot bypass or make impossible.
- Consistent email-verification state with a nonblank challenge destination.
- Durable one-per-owner personal address-book identity and per-book peer identity.
- Focused regression tests, full Docker gates, documentation, and isolated commits.

**Out of scope:**

- RustDesk client wire changes, unrelated feature work, deployment, or a formal release.
- Rewriting the Blade/jQuery/Bootstrap architecture.
- Making the separate `max_peers` quota check concurrency-durable.

## 2. Phase 2: Requirements & Context

**Relevant docs:** `AGENT.md`, `Wiki/core/06-design-system.md`,
`Wiki/core/15-security.md`, `docs/modernization/12-access-control-design.md`, and the archived
WebUI modernization plan.

**Commit boundary:** the approved WebUI is committed independently. Functional corrections
and every security boundary are committed separately on `main` so a specific hardening change
can be reverted without reverting the redesign or another security fix. The user's earlier
no-push instruction was superseded on 2026-07-15: one completion push to `origin/main` is
authorized after all gates and wrap-up records are complete. No deployment or release was
requested.

## 3. Phase 3: User Clarification

- [x] Commit on `main` rather than a feature branch.
- [x] Keep security fixes separate from the WebUI commit and from one another.
- [x] Push `main` only after the complete review, verification, and documentation wrap-up.

## 4. Phase 4: Detailed Execution Plan

1. Commit the already-verified WebUI rollout as the baseline.
2. Correct shared UI state, mobile navigation, modal/combobox/copy/live-form behavior,
   responsive table containment, permission-aware controls, and their browser tests.
3. Close API-key authorization and administrator-minting paths with denial tests.
4. Replace unsafe Blade JSON serialization and add hostile-payload tests.
5. Validate and quote client deployment commands per operating-system shell; move the unlock
   PIN to a protected non-URL flow and add no-store handling.
6. Add webhook egress validation that blocks private/reserved destinations at send time and
   across redirects; mask credential URLs for view-only users.
7. Separate destructive alarm/recording permissions from read-only access.
8. Make vendored assets reproducible, include required third-party notices, and pin mutable
   build inputs.
9. Require a short, account-bound completed-sign-in window for the entire personal TOTP
   management lifecycle and a current authenticator/recovery code for removal.
10. Remove generic user-editor control of TOTP state and repair inconsistent stored state without
    changing the RustDesk client wire contract.
11. Require a nonblank address everywhere email verification can be configured or synchronized,
    and enforce that invariant in MariaDB without silently downgrading existing accounts.
12. Give each owner's personal address book a durable marker and database-enforced singleton.
13. Enforce peer identity per address book in MariaDB and preserve each existing response shape
    when a late duplicate insert loses the race.
14. Run targeted tests after each boundary, then the complete PHPUnit, Pint, PHPStan, ESLint,
    vendor-integrity, Blade-cache, dependency-audit, Compose-render, and Playwright gates in Docker.
15. Synchronize security/access-control/build docs, append changelog entries, archive this plan,
    and make the user-authorized completion push to `origin/main`.

## 5. Phase 5: Product Owner Review

**Status:** `APPROVED`

- Scope and isolated commit strategy were explicitly approved on 2026-07-14.
- The user authorized one completion push to `origin/main` on 2026-07-15 after final review.
- No external deployment or formal release was requested.

## 6. Phase 6: Senior Dev Hygiene Review

**Status:** `COMPLETE`

- Central validation and services enforce the boundaries; views mirror server-side authorization.
- Secrets stay out of URLs, logs, titles, and read-only views.
- Hostile-input, delegated-role denial, migration-state, and browser regression coverage is present.
- Independent final reviews found no actionable P1/P2 issue in the completed commit series.

## 7. Phase 7: Implementation Checklist

- [x] Commit WebUI baseline independently.
- [x] Fix functional/accessibility regressions.
- [x] Fix API-key privilege escalation.
- [x] Fix stored XSS.
- [x] Fix client-config command/PIN handling.
- [x] Fix webhook SSRF and URL disclosure.
- [x] Fix alarm/recording destructive permissions.
- [x] Harden asset/license/build supply chain.
- [x] Require recent completed sign-in for personal TOTP management.
- [x] Prevent generic user editing from bypassing or corrupting TOTP state.
- [x] Prevent email verification from existing without a nonblank challenge address.
- [x] Enforce one durable personal address book per owner.
- [x] Enforce peer identity within each address book.
- [x] Run targeted and complete verification.
- [x] Synchronize docs/changelog and archive the plan.

## 8. Phase 8: Verification Dashboard

**Verification Status:** `PASSED`

- [x] Security denial, hostile-input, migration-state, and response-mapping tests pass.
- [x] Functional, responsive, and accessibility browser tests pass: 68 passed and 12 intentional
  screenshot-mode skips across the 80-case desktop, tablet, mobile, dark, and light matrix.
- [x] Full repository gates pass: PHPUnit 532 tests / 3,018 assertions; Pint 275 files; PHPStan
  no errors; ESLint, 20-file vendor integrity, Blade compilation, four Compose renders, Composer
  validation/audit, and npm audit are green.
- [x] Independent review confirmed commit boundaries match their stated concerns and remain
  individually revertible.

## 9. Phase 9: User Verification

**Status:** `READY FOR REVIEW`

The final handoff lists the independently revertible commit series and the exact verification
results. The user-owned untracked `AGENTS.md` was not modified, staged, or committed.

## 10. Phase 10: Wrap Up & Archival

Security, access-control, database, design/build, and status documentation is synchronized. The
agent changelog and version history record the completed review, and this plan is archived after
all gates passed.

## Completion Note

The WebUI review remediation and security hardening are complete in separated commits on `main`.
The final matrices passed without changing RustDesk client routes, JSON keys, or response shapes.
The late-collision tests cover established response mapping; the MariaDB unique index supplies
the durable peer-identity invariant. The separate `max_peers` quota remains outside this plan's
concurrency guarantee. The user authorized the final push to `origin/main`; no deployment or
formal release was performed.
