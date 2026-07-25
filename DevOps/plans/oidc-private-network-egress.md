# 📦 Parcel Plan: Opt-in trusted private networks for OIDC egress

## 📊 State Dashboard
| Metric | Value |
| :--- | :--- |
| **Status** | `IMPLEMENTED` |
| **Version** | `v1.0.0` |
| **Active Persona** | `Architect` |
| **Last Updated** | 2026-07-24 |

---

## 1️⃣ Phase 1: Expansion & Scoping
* **Intent:** Make a self-hosted identity provider on a private network usable
  ([issue #1](https://github.com/GigaionLLC/RD-API-Server/issues/1)), and make an OIDC failure
  say why instead of reporting a misconfigured provider for every cause.
* **In Scope:**
  - An opt-in, deny-by-default trusted-network allowlist for generic OIDC destinations.
  - Diagnostic logging for every OIDC discovery and exchange rejection.
  - The two DNS-resolution defects that make internal hosts unresolvable even once trusted.
  - Config, env, Compose, entrypoint, and documentation surfaces for the new settings.
* **Out of Scope:**
  - Any change to webhook egress. Webhooks permit plain HTTP and have no TLS name binding to
    fall back on, so the same opt-in would be a materially larger blast radius; it stays closed.
  - Relaxing the HTTPS requirement, the allowed-port list, redirect refusal, or DNS pinning.
  - Any TLS verification escape hatch. Certificate validation is what stops a hostile DNS
    answer from redirecting a login to another machine inside an allowed range.
  - An admin "test connection" button for OAuth providers (see the follow-up note below).

## 2️⃣ Phase 2: Requirements & Context
* **Relevant Docs Found:**
  - `Wiki/core/15-security.md` -> owns the Generic OIDC Egress Boundary description.
  - `docs/modernization/02-client-api-contract.md` -> confirms no client-visible string changes.
* **Relevant Code Found:**
  - `app/Services/OidcDestinationGuard.php` -> `isPublicIp()` rejects every RFC 1918 answer.
  - `app/Services/OauthService.php` -> `discoverOidc()` discarded every exception silently.
  - `app/Services/OidcDnsResolver.php`, `app/Services/WebhookDnsResolver.php` -> a combined
    `dns_get_record()` bitmask returns `false` when any single record type hard-fails, and never
    consults the name service switch.

## 3️⃣ Phase 3: User Clarification
* **Open Questions:**
  - `[x]` Allowlist, blanket switch, or both? -> **Answer:** both, with the blanket switch
    defined tightly as RFC 1918 plus `fd00::/8` and subject to the same permanent blocks.
  - `[x]` Extend the same opt-in to webhooks? -> **Answer:** no, deliberately OIDC-only.

## 4️⃣ Phase 4: Detailed Execution Plan
* **Architecture & Files to Touch:**
  - `app/Support/TrustedPrivateNetworks.php` -> new parser/matcher, shared-ready but wired to
    OIDC only.
  - `app/Services/OidcDestinationGuard.php` -> consult the allowlist after the routability check
    and scope private addresses to the issuer host; expose diagnostics.
  - `app/Services/OauthService.php` -> thread the issuer host through every hop; report failures.
  - `app/Services/{Oidc,Webhook}DnsResolver.php` -> per-type queries plus an NSS union.
  - `config/rustdesk.php`, `.env.example`, `phpunit.xml`, `docker/entrypoint.sh`, the three
    deployment Compose files, and the documentation set.
* **Test Verification Plan:**
  - `docker compose -f docker/compose.toolchain.yml run --rm app bash -lc './vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G'`
  - `docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test`
  - `[x]` The 18 pre-existing deny-by-default data sets pass with the file's assertions unchanged.
  - `[x]` An allowlisted private issuer completes discovery; one outside the list does not.
  - `[x]` Hard-blocked addresses survive every opt-in, including ranges written one bit wider
        than the block so only the match-time refusal can stop them.
  - `[x]` Unusable entries are discarded and named; one bad entry does not disable the others.
  - `[x]` The OIDC opt-ins do not widen webhook egress.

## 5️⃣ Phase 5: Product Owner Review
* **Status:** `APPROVED`
* **Findings:**
  - ✅ **Vision & Scope** — restores the deployment shape most of this product's users have.
  - ✅ **Business Logic & Edge Cases** — split DNS answers, IP literals, and mixed families all
    covered by tests.
  - ⚠️ **Dependency & Functional Risk** — the NSS union can newly reject a host whose
    `/etc/hosts` entry points somewhere non-routable. That is fail-closed and more correct than
    validating an address libcurl would not have used, and it is documented.
  - ✅ **Completeness & User Intent** — both halves of the issue are addressed.

## 6️⃣ Phase 6: Senior Dev Hygiene Review
* **Status:** `APPROVED`
* **Findings:**
  - ✅ **DRY Scan** — range matching lives in one class rather than a third copy of `isInRange()`.
  - ✅ **Abstraction & Architecture** — parsing is separated from matching, so a security
    predicate never depends on ambient error-handler behaviour for an operator-supplied string.
  - ✅ **Secret Management** — no log context carries a client secret, code, or bearer token;
    transport messages are stripped of URL userinfo and query strings.
  - ✅ **Error Handling** — every silent `return null` now reports a reason.

## 7️⃣ Phase 7: Implementation Checklist (Execution)
- `[x]` `TrustedPrivateNetworks` with permanent blocks, prefix floors, and entry rejection.
- `[x]` Guard consults the allowlist and scopes private addresses to the issuer host.
- `[x]` Issuer host threaded through discovery, token, and userinfo hops.
- `[x]` Discovery/exchange failures reported with a sanitized reason.
- `[x]` Both DNS resolvers query per type and union the name service switch.
- `[x]` Config, env, phpunit baseline, Compose files, entrypoint warning.
- `[x]` Tests across the guard, diagnostics, resolvers, and webhook non-widening.
- `[x]` Documentation sync: Wiki security, QUICKSTART, README, docker/README, CHANGELOG,
  build log.

## 8️⃣ Phase 8: Verification Dashboard
* **Verification Status:** `PASS`
* **Report:**
  - `[x]` Test suite runs clean in the isolated MariaDB profile
  - `[x]` Pint and PHPStan clean
  - `[x]` No functional gaps identified

## 9️⃣ Phase 9: User Verification
* **Status:** `PENDING`
* **User Feedback:** awaiting confirmation from the issue reporter against a live Authentik
  instance on a private network.

## 🔟 Phase 10: Wrap Up & Archival
* **System Context Updates:** the Generic OIDC Egress Boundary in `Wiki/core/15-security.md` now
  documents a conditional, deployment-scoped exception rather than an absolute rule. Two
  invariants carry that exception and should be preserved by any future change: permanently
  blocked ranges are evaluated before the allowlist, and a private address is only ever accepted
  for the issuer's own host.

## ✅ Completion Note
Delivered as planned. Two defects were found during implementation that the issue did not
report and that would have kept an internal provider unreachable even with a correct allowlist:
`dns_get_record()` discarding collected answers when one record type hard-fails, and its
complete blindness to `/etc/hosts`. Both were fixed in the OIDC and webhook resolvers.

An adversarial review over the finished diff (six independent lenses, every finding
independently refuted before being accepted) raised no bypass of the allowlist and no
default-behaviour regression. Four low-severity defects survived verification and were fixed:

1. `reportDiscoveryFailure()` logged the issuer verbatim, and the one path where an issuer is
   rejected for carrying userinfo or a query string is exactly the path where it still holds
   the raw configured value. It is now reduced to scheme, host, port, and path.
2. A rejected discovered endpoint was reported against an issuer that was itself valid, with no
   indication of which endpoint or host failed. The loop now names both.
3. The console SSO callback attributed every failed exchange to an unlinked account.
4. The name-service half of resolution is IPv4-only. Documented rather than pulling
   `ext-sockets` into the runtime image for it.

Deliberately left alone after review: a mixed public/private answer set for one host is
accepted when the private half is allowlisted and the host is the issuer. That is the intended
semantics -- every answer is still individually validated, and the pinned address is drawn from
the validated set.

**Follow-up worth considering, deliberately not built here:** the admin console has a "Test
connection" affordance for LDAP (`app/Http/Controllers/Admin/LdapController.php`) and for
webhooks, but none for OAuth providers. One would let an operator read the real rejection reason
without leaving the console, instead of reading the server log.
