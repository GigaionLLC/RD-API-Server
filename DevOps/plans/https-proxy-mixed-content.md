# Parcel Plan: HTTPS reverse-proxy mixed-content recovery

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `SOURCE PUSHED; WAITING FOR PRODUCTION PROXY INPUT` |
| **Version** | `v1.0.0` |
| **Active Persona** | `Senior engineer / security reviewer` |
| **Last Updated** | 2026-07-17 17:25 |

---

## 1. Phase 1: Expansion & Scoping

* **Intent:** Restore correct HTTPS URL, redirect, and cookie behavior behind the production
  OpenResty reverse proxy without weakening the explicit trusted-proxy security boundary.
* **In Scope:**
  - Reproduce and document the live mixed-content failure.
  - Add regression tests for trusted HTTPS forwarding and untrusted header spoofing.
  - Improve container startup guidance and production Compose examples.
  - Add a focused proxy diagnosis and recovery runbook.
  - Verify, commit separately, push to `main`, and re-probe the public origin when deployable.
* **Out of Scope:**
  - Wildcard proxy trust, automatic trust of `REMOTE_ADDR`, broad private-network trust, or
    hard-coded/forced HTTPS that hides a broken inbound proxy boundary.
  - Unrelated UI, API, database, or schema changes.
  - Mutating the production host without explicit deployment access.

## 2. Phase 2: Requirements & Context

* **Relevant Docs Found:**
  - `README.md`, `QUICKSTART.md`, and `Wiki/core/15-security.md` describe explicit proxy trust.
  - `AGENT.md` requires Docker verification and changelog/build-log synchronization.
* **Relevant Code Found:**
  - `config/trustedproxy.php` parses exact IP/CIDR allowlists and rejects wildcard-equivalent
    entries.
  - Laravel's global trusted-proxy middleware consumes that configuration at request time.
  - Laravel's default forwarded-header mask is broader than required and can honor forwarded
    host/port/prefix values that common proxy defaults do not sanitize.
  - `docker/entrypoint.sh` rebuilds the configuration cache on every container start.
  - `tests/Feature/TrustedProxySecurityTest.php` already covers client-IP spoofing boundaries.
* **Live Evidence:**
  - `GET https://api-rustdesk1.gigaion.com/admin` redirects to an `http://` login URL.
  - The HTTPS login HTML emits absolute `http://` stylesheets and scripts.
  - The browser blocks those resources, leaving `$` undefined and the console unstyled.
  - HTTPS assets themselves are reachable; the generated scheme is the defect.

## 3. Phase 3: User Clarification

* **Open Questions:**
  - `[x]` May the fix be committed and pushed? -> **Answer:** Yes; the user previously authorized
    a completion push and requested separated commits.
  - `[ ]` What exact source IP or isolated shared-network CIDR does the API container observe for
    OpenResty? -> **Answer:** Not discoverable from this local Docker context or unauthenticated
    public responses; production logs/network inspection are required before setting it.

## 4. Phase 4: Detailed Execution Plan

* **Architecture & Files to Touch:**
  - `tests/Feature/TrustedProxySecurityTest.php` -> cover HTTPS assets, auth redirect, cookies,
    rejection of spoofed forwarded scheme, and forwarded URL-poisoning headers.
  - `bootstrap/app.php` -> limit trusted input to the forwarded client chain and scheme.
  - `docker/entrypoint.sh` -> warn when an HTTPS public origin is configured without proxy trust.
  - `scripts/check-https-proxy.sh` -> fail-closed public edge smoke check.
  - `.env.example`, `docker-compose.yml`, `README.md`, `QUICKSTART.md`, and
    `examples/full-stack.docker-compose.yml` -> expose/document secure-cookie and proxy settings.
  - `Wiki/core/15-security.md` -> preserve the inbound proxy security invariants.
  - Operational logs/build log -> record the change and verification.
* **Implementation Notes:**
  - Continue trusting forwarded headers only from an explicit exact address or narrow CIDR.
  - Ignore forwarded host, port, and prefix values; use sanitized `Host` plus forwarded scheme.
  - Treat `SESSION_SECURE_COOKIE=true` as defense in depth for public HTTPS deployments.
  - Require the edge proxy to overwrite public `Host`/scheme and construct the client chain.
  - Provide commands that inspect only non-secret configuration keys and verify the live HTML.
* **Test Verification Plan:**
  - Run the focused trusted-proxy feature suite in the MariaDB Docker test service.
  - Run Pint, PHPStan, shell syntax/lint, Compose rendering, and relevant documentation checks.
  - Run the full PHPUnit suite if focused gates pass.
  - Re-run external redirect, asset-reference, asset-reachability, and cookie-header probes.

## 5. Phase 5: Product Owner Review

* **Status:** `SOURCE REVIEW PASSED; LIVE RECOVERY PENDING`
* **Findings:**
  - [x] **Vision & Scope** - Recovery targets the entire HTTPS request boundary, not only CSS.
  - [x] **Business Logic & Edge Cases** - Direct HTTP remains supported while public HTTPS is
    explicit.
  - [x] **Dependency & Functional Risk** - No dependency or database changes are planned.
  - [x] **Completeness & User Intent** - Code, operator guidance, and live smoke checks are covered.
* **Required Fixes:**
  - `[ ]` Confirm and apply the production proxy's application-observed IP/CIDR.

## 6. Phase 6: Senior Dev Hygiene Review

* **Status:** `PASSED`
* **Findings:**
  - [x] **DRY Scan** - Extend the existing proxy test suite and runbook locations.
  - [x] **Abstraction & Architecture** - Keep URL/client-IP trust in Laravel's native middleware.
  - [x] **State Management & Data Flow** - No application state changes.
  - [x] **Technical Debt & Deletion** - No deletion or compatibility shim.
  - [x] **Secret Management** - Diagnostic commands avoid broad configuration dumps.
  - [x] **Data Security** - Explicit trust prevents forwarded-IP spoofing.
  - [x] **Rate Limiting** - Correct client IP is restored only through a sanitized proxy chain.
  - [x] **Error Handling** - Startup warning and smoke checks make misconfiguration visible.
* **Required Fixes:**
  - `[x]` No fallback trusts wildcards, `REMOTE_ADDR`, or broad `/0` networks.

## 7. Phase 7: Implementation Checklist

- `[x]` Add trusted/untrusted HTTPS proxy regression coverage.
- `[x]` Restrict the trusted header mask and cover forwarded URL-poisoning attempts.
- `[x]` Add runtime warning and secure-cookie configuration passthrough/examples.
- `[x]` Add diagnosis, recovery, and smoke-test documentation.
- `[x]` Run focused and full Docker verification.
- `[x]` Complete review records and create separately revertible source/security commits.
- `[x]` Complete source-delivery records and push the reviewed commits to `origin/main`.
- `[ ]` Archive this plan after production configuration and the live check pass.
- `[ ]` Re-probe the public origin after the production environment is corrected/recreated.

## 8. Phase 8: Verification Dashboard

* **Verification Status:** `SOURCE VERIFIED; PUBLIC ORIGIN STILL FAILING`
* **Report:**
  - `[x]` Focused proxy suite: 10 tests / 48 assertions.
  - `[x]` Pint (275 files), PHPStan, ESLint/vendor integrity, Bash syntax, and Compose checks pass.
  - `[x]` Full PHPUnit suite: 538 tests / 3,051 assertions.
  - `[x]` Runtime image builds; empty/wildcard trust warns and exact-IP trust does not.
  - `[ ]` Public HTML contains no insecure same-origin asset references after deployment.

## 9. Phase 9: User Verification

* **Status:** `PENDING`
* **User Feedback:** The screenshot and console errors establish the reported failure state.

## 10. Phase 10: Wrap Up & Archival

* **System Context Updates:** Record the split between explicit forwarded-header trust and the
  public HTTPS/cookie deployment settings, plus the operational recovery procedure.

## Completion Note

Source implementation, independent reviews, and the full local verification matrix are complete.
The public origin still redirects HTTPS `/admin` to an HTTP login URL. Completion requires the
actual application-observed production proxy IP/CIDR, container recreation, a passing public
smoke check, plan archival, and the final live-resolution record.
