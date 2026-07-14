# Parcel Plan: WebUI Review Remediation and Security Hardening

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `IN PROGRESS` |
| **Version** | `v1.0.0` |
| **Active Persona** | Security and frontend hardening implementer |
| **Last Updated** | 2026-07-14 10:00 PDT |

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
- Focused regression tests, full Docker gates, documentation, and isolated local commits.

**Out of scope:**

- RustDesk client wire changes, unrelated feature work, deployment, or pushing.
- Rewriting the Blade/jQuery/Bootstrap architecture.

## 2. Phase 2: Requirements & Context

**Relevant docs:** `AGENT.md`, `Wiki/core/06-design-system.md`,
`Wiki/core/15-security.md`, `docs/modernization/12-access-control-design.md`, and the archived
WebUI modernization plan.

**Commit boundary:** the approved WebUI is committed independently. Functional corrections
and every security boundary are committed separately on `main` so a specific hardening change
can be reverted without reverting the redesign or another security fix. Nothing is pushed.

## 3. Phase 3: User Clarification

- [x] Commit on `main` rather than a feature branch.
- [x] Keep security fixes separate from the WebUI commit and from one another.
- [x] Preserve the earlier no-push instruction.

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
9. Run targeted tests after each boundary, then the complete PHPUnit, Pint, PHPStan, ESLint,
   vendor-build, Blade-cache, and Playwright matrix in Docker.
10. Synchronize security/access-control/build docs, append changelog entries, archive this
    plan, and leave all commits local on `main`.

## 5. Phase 5: Product Owner Review

**Status:** `APPROVED`

- Scope and isolated commit strategy were explicitly approved on 2026-07-14.
- No external deployment or push is authorized.

## 6. Phase 6: Senior Dev Hygiene Review

**Status:** `IN PROGRESS`

- Prefer central validation/services over view-only defenses.
- Keep authorization server-side and mirror it in UI visibility.
- Keep secrets out of URLs, logs, titles, and read-only views.
- Add regression tests that prove hostile inputs and delegated-role denials.

## 7. Phase 7: Implementation Checklist

- [x] Commit WebUI baseline independently.
- [ ] Fix functional/accessibility regressions.
- [ ] Fix API-key privilege escalation.
- [ ] Fix stored XSS.
- [ ] Fix client-config command/PIN handling.
- [ ] Fix webhook SSRF and URL disclosure.
- [ ] Fix alarm/recording destructive permissions.
- [ ] Harden asset/license/build supply chain.
- [ ] Run targeted and complete verification.
- [ ] Synchronize docs/changelog and archive the plan.

## 8. Phase 8: Verification Dashboard

**Verification Status:** `PENDING`

- [ ] Security denial and hostile-input tests pass.
- [ ] Functional, responsive, and accessibility browser tests pass.
- [ ] Full repository quality gates pass.
- [ ] Commit boundaries contain only their stated concerns.

## 9. Phase 9: User Verification

**Status:** `PENDING`

The final handoff will list each local commit so the user can review or revert it independently.

## 10. Phase 10: Wrap Up & Archival

Update security, access-control, design/build documentation and the agent changelog, then move
this plan to `DevOps/archive-plans/` after every gate is green.

## Completion Note

Pending implementation and verification.
