# 08 - Build Log (PHP rewrite)

Chronological record of what was built and its verification state. Newest at top.

## 2026-07-17 - v1.0.0 first stable release (published)

- Centralized the source-controlled application version as `config('app.version')`, removed
  duplicated controller fallbacks, and asserted the exact public `/api/version` response. The
  version is deliberately not operator-configurable, so retained deployment environments cannot
  report a stale release after an image upgrade.
- Added the public changelog and complete v1.0.0 release notes, changed the README status from
  beta to stable, documented versioned image use with digest pinning, and replaced the stale padded-number
  release policy with standard Semantic Versioning and annotated `vMAJOR.MINOR.PATCH` tags.
- Expanded the 1Panel/OpenResty setup guide with local and routed-LAN proxy topologies, source-IP
  discovery, header verification, container recreation, and the distinction between interface
  binding and source-address firewalling.
- **Verified in Docker:** PHPUnit passed 538 tests / 3,051 assertions; Playwright passed 68 tests
  with 12 intentional screenshot-mode skips; Pint passed 275 files; PHPStan passed 177 files;
  ESLint, 20-file vendor integrity, strict Composer validation, Compose rendering, Bash syntax,
  local documentation links, and diff checks passed. A fresh runtime image built successfully
  and reported application version `1.0.0`.
- **Published from verified commit `026b841`:** GitHub CI run `29626539704` and main-image run
  `29626539712` passed before the annotated `v1.0.0` tag was pushed. Tag-image run `29626681147`
  then published `1.0.0`, `1.0`, and `1` for AMD64 and ARM64 at manifest digest
  `sha256:512c1fb8b40ff72cb71fe1a66c872198741f1ac6d08a4c0c0f00ee5877949705`.
- **Release:** <https://github.com/GigaionLLC/RD-API-Server/releases/tag/v1.0.0>

## 2026-07-17 - HTTPS reverse-proxy hardening and production recovery (resolved)

- Confirmed the public mixed-content outage is an inbound proxy-trust failure: the HTTPS admin
  request redirects to an HTTP login URL, and the login HTML emits HTTP stylesheets/scripts even
  though the same assets are reachable over HTTPS. This also prevents request-derived `Secure`
  cookies and leaves the application with the proxy address instead of the client address.
- Preserved explicit IP/CIDR proxy trust while reducing accepted forwarded input to
  `X-Forwarded-For` and `X-Forwarded-Proto`. Forwarded host, port, and path-prefix values are now
  ignored; hostile URL-poisoning and sanitized nonstandard-port cases have regression coverage.
- Added HTTPS asset, guest-redirect, secure-session/CSRF-cookie, untrusted-scheme, and hostile
  forwarded-header tests. The runtime image now warns when an HTTPS `APP_URL` has no valid parsed
  proxy allowlist, and production examples expose the secure-cookie setting without changing the
  local HTTP default.
- Added safe isolated-network, loopback, and firewall topology guidance plus a fail-closed
  `scripts/check-https-proxy.sh` edge probe. The probe checks the exact HTTPS redirect, insecure
  asset URLs, session/CSRF cookie attributes, and a 2xx CSS asset without printing cookies.
- **Verified in Docker:** the focused suite passed 10 tests / 48 assertions; the full suite passed
  538 tests / 3,051 assertions; Pint passed 275 files; PHPStan reported no errors; ESLint and the
  20-file vendor-integrity check passed; both Compose examples rendered; Bash syntax passed; and
  the runtime image built successfully. Empty and wildcard proxy trust produced the intended
  warning, while an exact IP did not.
- **Delivered 2026-07-17:** commits `52410f9` and `4c28f08` were pushed to `origin/main`.
  GitHub CI run `29623089296` passed JavaScript/vendor, PHP/Pint/PHPStan/PHPUnit/InnoDB, and
  Playwright jobs. Docker Publish run `29623089305` completed successfully for the published
  multi-architecture image.
- **Production resolved 2026-07-17:** the API container log identified the immediate 1Panel peer,
  the deployment added that exact address to `TRUSTED_PROXIES` plus
  `SESSION_SECURE_COOKIE=true`, and the container was recreated. The public edge checker now
  passes the HTTPS admin redirect, login asset URLs, secure session/CSRF cookies, and stylesheet
  reachability. The private deployment repository was updated to bind the backend listener to its
  intended LAN interface and documents the still-required source firewall rule; live firewall
  enforcement remains an operator verification item.

## 2026-07-15 - GitHub Actions Node 24 runtime migration (verified)

- Replaced every immutable `actions/checkout` and `actions/setup-node` reference in CI and image
  publishing with the official v7.0.0 commits. Both exact action manifests declare Node 24; the
  application's explicit Node 24.18.0 selection and npm cache configuration are unchanged.
- Kept all workflow triggers, permissions, jobs, and release behavior intact. Also replaced the
  readiness loop's unused counter with the shell-lint-safe placeholder without changing retries.
- Local actionlint 1.7.12, exact-pin audit, ESLint, 20-file vendor integrity, and diff checks
  passed. GitHub CI run `29470504915` passed PHP, JavaScript/vendor, and Playwright jobs, and
  Docker Publish run `29470504901` published both architectures successfully in 39m36s. Complete
  logs for both runs contain no Node 20 deprecation text.
- Cancelled obsolete older publish run `29470043683` so it could not finish after the newer build
  and overwrite the `latest` tag with stale content.

## 2026-07-15 - WebUI review and security hardening complete (verified)

- Completed the post-redesign functional, responsive, accessibility, authorization, hostile-input,
  secret-handling, outbound-request, dependency, and release-integrity review. The remediation
  preserves the server-rendered Blade + jQuery + Bootstrap architecture and the warm-mineral
  dark/light design system.
- Closed the reviewed privilege, XSS, command/PIN, webhook, destructive-permission, TOTP,
  recovery-code, email-verification, and supply-chain boundaries in independently revertible
  commits. No RustDesk client route, JSON key, or response shape changed.
- Browser QA also exposed identity races in address-book setup. A nullable personal marker plus a
  one-per-owner unique index now supplies durable personal-book identity, and a separate unique
  `(address_book_id, rustdesk_id)` index supplies durable per-book peer identity. Late-collision
  tests cover each established response mapping; they do not claim multi-transaction quota
  coverage, and the separate `max_peers` check remains outside this invariant.
- **Verified in Docker:** PHPUnit passed 532 tests / 3,018 assertions; Pint passed 275 files;
  PHPStan reported no errors; and the 80-case Playwright matrix passed 68 tests with 12 intentional
  screenshot-mode skips across desktop dark/light, tablet dark, and mobile dark. ESLint, the
  20-file checked-in vendor integrity check, Blade compilation, all four Compose renders, strict
  Composer validation, Composer audit, and npm audit also passed with no advisories.

## 2026-07-15 - Per-book peer identity invariant (verified)

- Added a named MariaDB unique index on `(address_book_id, rustdesk_id)`. Its read-only preflight
  rejects legacy duplicate pairs before DDL and reports a bounded list instead of guessing which
  peer credentials, tags, or metadata to delete. Exact index-definition checks make interrupted
  migrations and schema-name collisions fail closed; rollback drops only the index.
- Kept the existing friendly duplicate checks, then mapped a database-lost insert race back to
  each surface's established response: HTTP-200 RustDesk error JSON, API-v1 422, the admin peer
  modal/error bag with non-secret input, or a skipped CSV row. Winner confirmation reads from the
  writer so replication lag cannot misclassify the uniqueness exception.
- Wrapped legacy full-book replacement in a transaction and collapse duplicate payload IDs with
  update-or-create, while deployment and system preset writers already use the same race-safe
  primitive. No client route, key, success acknowledgement, or error shape changed. Focused Docker
  suites passed 58 tests / 278 assertions; targeted Pint and PHPStan checks were clean.

## 2026-07-15 - Personal address-book singleton invariant (verified)

- Replaced the default book's name-based identity with an explicit nullable `is_personal` marker.
  A named MariaDB CHECK permits only `NULL` for ordinary books or `1` for an owned personal book;
  a named unique index permits unlimited ordinary books while enforcing one personal book per
  owner. Eloquent's create-or-first retry now makes simultaneous first-use requests converge.
- The migration preserves every legacy collection and its peers/tags. For each owner without a
  marker, only the lowest-ID collation-equivalent `My address book` row is marked; other duplicate
  names remain ordinary books. Existence guards and marker preflights make interrupted MariaDB DDL
  safely retryable. Rollback removes only enforcement when the former name resolver would preserve
  identity; ambiguous names or a custom marked name make its read-only preflight fail before DDL.
- Personal client endpoints no longer mistake an ordinary same-named collection for the default,
  and the showcase seeder uses the same durable identity. No RustDesk path, JSON key, or response
  shape changed. Focused Docker suites passed 33 tests / 134 assertions; targeted Pint and PHPStan
  checks were clean.

## 2026-07-15 - Email-verification address invariant (verified)

- Required a valid address when admin user creation/editing selects email verification and
  prevented partial API v1 updates from clearing the address of an existing email-policy account.
  The CLI rejects an explicit empty address before any account mutation. Console forms now explain
  the conditional requirement.
- Made LDAP attribute synchronization validate the required address, fail closed, and roll back the
  complete refresh when a linked email-policy account receives a blank or malformed directory
  value; no stale destination, undeliverable replacement, or silent policy downgrade is accepted.
- Added a read-only, fail-before-DDL MariaDB preflight that reports up to 20 affected user IDs.
  Operators must explicitly repair the address or policy. A named CHECK uses byte-exact policy
  comparison and POSIX whitespace detection, and rollback removes only enforcement.
- No RustDesk client path, JSON key, or wire shape changed. Focused Docker suites passed 37 tests /
  285 assertions; targeted Pint/PHPStan, Blade compilation, and diff checks were clean. Complete
  quality-gate results are recorded in the final hardening wrap-up.

## 2026-07-15 - Canonical TOTP state and console self-service ownership (verified)

- Removed TOTP selection and factor-state writes from generic user creation/editing. Console
  account owners enroll and remove authenticator apps only through the protected personal flow;
  generic editors can still choose `off` or `email` for an inactive account, while an active
  factor is rendered read-only and preserved under a user-row lock.
- Required factor fields to be absent from generic requests and defensively strip them before
  persistence. Crafted empty strings, nulls, and empty arrays therefore cannot replace an
  encrypted seed, erase recovery codes, or turn an active factor into a partial state.
- Added a fail-before-write MariaDB migration that validates every encrypted seed using the
  current or configured previous application keys, then normalizes split historical rows by the
  strongest usable TOTP intent. Unusable and orphaned state is cleared; unknown policy becomes
  `off`; a named CHECK uses byte-exact policy comparisons so case-insensitive database collation
  cannot admit values the application interprets differently. It keeps active and inactive state
  structurally consistent; rollback removes only enforcement and keeps the repaired data.
- No table/column, client API, or RustDesk wire-contract change was introduced. Focused Docker
  suites passed 23 tests / 157 assertions; targeted Pint/PHPStan, Blade compilation, and diff
  checks were clean. Complete quality-gate results are recorded in the final hardening wrap-up.

## 2026-07-15 - Recent completed-sign-in boundary for 2FA management (verified)

- Added an encrypted five-minute management marker after the configured console sign-in flow
  completes. It is bound to the account, credential version, current password hash, regenerated
  browser-session ID, issue/expiry times, and a random nonce; malformed, replayed, future-dated,
  cross-account, changed-credential, expired, and missing markers fail closed. Session blocking
  serializes every competing management/challenge request so consumed state cannot be restored by
  a concurrent request snapshot.
- Applied the boundary to the entire enrollment lifecycle and removal. Pending setup state is now
  encrypted with its account, credential version, recent-auth nonce, and expiry, disappears from
  stale read-only sessions, and cannot overwrite a factor enabled by another request.
- Replaced the local-password-only removal path (which federated accounts could not satisfy) with
  a current authenticator or unused recovery code after a recent local, LDAP, or SSO sign-in. A
  factor just proved during local/LDAP application sign-in carries narrowly scoped assurance for
  that exact enrollment, allowing even the final recovery code to authorize removal; replacement
  invalidates that assurance and SSO still requires a factor code. Removal attempts are
  rate-limited, recovery consumption remains serialized, and successful setup/removal consumes
  the recent marker.
- Added a real cancel action and a sign-out/re-entry path that preserves the intended settings
  destination. Updated the UI to distinguish password-based sign-ins from SSO, whose MFA and
  reauthentication interaction policy remain the responsibility of the identity provider.
- Exposed `AUTH_TWO_FACTOR_MANAGEMENT_TIMEOUT` with a 300-second default and a server-enforced
  60-900 second range in the example and bundled Compose environments. No schema, `/api/*`, JSON
  key, or RustDesk client response change was introduced.
- **Verified in Docker:** focused two-factor, SSO, LDAP, and password-policy suites passed 50 tests
  / 534 assertions. Targeted Pint and PHPStan, Blade compilation, route discovery, Playwright test
  discovery, and diff checks were clean. The complete-suite rerun is recorded by the final
  hardening wrap-up after the separately revertible follow-on fixes.

## 2026-07-14 - MariaDB-only database boundary (verified)

- Standardized runtime, development, PHPUnit, CI, browser, and screenshot paths on
  MariaDB/InnoDB. `DB_CONNECTION=mariadb` is now the application contract; existing MariaDB
  installations using the former `mysql` connection name change only that setting after a backup
  and a clean read-only InnoDB table-engine audit.
- Removed the SQLite runtime/test compatibility target. Unsupported drivers are rejected before
  migrations instead of receiving a best-effort execution path.
- Added a cached-configuration and live-connection boundary inside Laravel as well as the
  container preflight. Stale unsupported connection definitions and database-backed cache,
  queue, or session selectors fail closed. The first query and every reconnect verify the exact
  schema, MariaDB server identity, InnoDB default, and InnoDB-only existing table set; recovery
  commands remain usable so operators can clear an obsolete cache.
- Isolated destructive verification from developer data: PHPUnit uses guarded service `test`
  with tmpfs-backed `test-db` and exact database `rustdesk_api_testing`; the full local Playwright
  matrix uses `e2e`, tmpfs-backed `e2e-db`, and `rustdesk_api_e2e`; screenshot capture uses
  `screenshots`, tmpfs-backed `screenshot-db`, and `rustdesk_api_screenshots`. Browser runners
  reject any other target before installing missing locked dependencies.
- Left `DB_HOST` unset in the example environment so bundled Compose selects its internal `db`
  service while preserving conventional `DB_HOST` / `DB_PORT` overrides for external MariaDB.
  Runtime TCP, mounted Unix-socket, connection-timeout, and CA-certificate settings share the
  same readiness and migration checks. URL-only database configuration is rejected in favor of
  explicit fields; external-only Compose topologies must replace the bundled `db` service and app
  dependency as well as selecting the external host.
- Required pre-upgrade audits to prove the live server is MariaDB, its default engine is InnoDB,
  and every existing base table uses InnoDB before the connection-name boundary is crossed.
- Documented the breaking boundary for existing SQLite installations. Conversion must be
  completed manually on the last compatible release before upgrading; the project does not ship
  an automated converter.
- **Verified in Docker:** runtime and toolchain images built; a fresh production-style runtime
  booted on MariaDB 11.8.8 with InnoDB and served `/api/version`; stale-cache recovery before key
  generation passed; and unsupported connection names, `DB_URL`, invalid timeout values, and a
  native Artisan target containing a MyISAM table all failed closed. PHPUnit passed 466 tests /
  2,464 assertions; Pint passed 262 files; PHPStan passed 172 files with no errors; Composer and
  production npm audits reported no advisories; JavaScript lint, 20-file vendor integrity, Blade
  compilation, shell syntax, and all Compose renders passed. The Playwright matrix passed 68
  tests with 12 intentional screenshot-mode skips, and the isolated screenshot workflow passed
  its desktop-dark capture test against 14 devices, 5 users, and 63 connection records.

## 2026-07-14 - Generic OIDC outbound boundary (verified)

- Added an OIDC-specific destination guard for issuer discovery and discovered authorization,
  token, and userinfo endpoints. Generic OIDC now requires HTTPS and public DNS, rejects mixed
  public/private answers, validates the discovery document's issuer, and supports intentional
  public custom ports through `RUSTDESK_OIDC_ALLOWED_PORTS`.
- Discovery, token, and userinfo requests disable redirects and inherited proxies, open fresh
  connections, and pin the address validated immediately before each request. Token and
  userinfo endpoints are re-resolved after discovery so DNS rebinding cannot redirect an
  authorization code, client secret, or bearer token to an internal service.
- Public cross-host provider topologies remain supported. Existing device-login PKCE and admin
  SSO behavior are unchanged, and authorization endpoints with an existing query string now
  receive OAuth parameters correctly.
- **Verified in Docker:** focused OIDC, PKCE, and admin-SSO coverage passed 47 tests / 117
  assertions; the full Pint and PHPStan gates passed with no findings.

## 2026-07-13 - Full admin WebUI modernization (verified)

- Reworked the full admin and authentication surface into one responsive design system using
  server-rendered Blade, jQuery, Bootstrap 5, Remix Icon, and original `rd-*` CSS. No SPA
  framework or API/wire-contract change was introduced.
- Replaced the generic blue-dark dashboard palette with a dual-theme warm-mineral system:
  charcoal/olive dark surfaces, warm paper light surfaces, copper primary actions, and
  sea-glass/moss/ochre/fired-clay semantic accents. Theme choice follows a saved preference or
  the operating-system preference and is applied before first paint.
- Rebuilt the shared application shell with permission-aware grouped navigation, a responsive
  off-canvas sidebar, backdrop and Escape handling, a skip link, consistent page headers,
  cards, toolbars, table scrollers, forms, pagination, empty states, and themed Bootstrap
  overlays.
- Migrated list, form, authentication, dashboard, device, strategy-editor, address-book, and
  client-configuration views away from local style blocks and one-off visual patterns. Wide
  data remains reachable inside local table scrollers without introducing page-level
  horizontal scrolling.
- Expanded the shared jQuery behavior with accessible dismissible/pausable toasts, a
  Bootstrap confirmation dialog (`RD.confirm()` / `data-confirm`), a keyboard-operable
  searchable combobox, theme-aware charts, responsive shell state, and clipboard feedback.
- Pinned Bootstrap, jQuery, Remix Icon, ApexCharts, and axe Playwright integration through npm.
  `npm run build:vendor` copies runtime files into `public/assets/vendor/`; the UI has no
  runtime CDN dependency, and ApexCharts is loaded only by the dashboard.
- Expanded Playwright to four projects: desktop dark, desktop light, tablet dark with touch,
  and mobile dark with touch/mobile behavior. GUI coverage now includes theme persistence,
  responsive overflow, strategy keyboard tabs, bulk device actions, and modal dismissal;
  axe scans login plus representative authenticated pages in both desktop themes.
- Screenshot capture is opt-in through `CAPTURE_SCREENSHOTS=1`, keeping normal quality-gate
  runs from overwriting the checked-in gallery.
- **Verified in Docker:** the Laravel suite completed 557 assertions without a failure (the
  disposable container reports warnings where tests intentionally inspect a missing local
  `.env`); Pint passed 196 files; PHPStan reported zero errors; ESLint, JavaScript syntax, and
  `npm run build:vendor` passed; Blade view caching passed. The final Playwright matrix passed
  46 tests with 6 intentional screenshot-capture skips across desktop dark/light, tablet dark,
  and mobile dark, and an additional 320px flagship reflow check passed. The guarded 13-page
  desktop-dark screenshot gallery was regenerated and visually reviewed.
- The local runtime vendor set is six pinned files totaling 1,258,545 bytes. ApexCharts remains
  dashboard-only and no admin page depends on a public CDN. A meaningful LCP/CLS baseline was
  deliberately deferred because the Windows bind-mounted development server is not a stable
  performance environment.

## 2026-06-18 - Admin shell renders (first visible milestone)

- Built the initial dark dashboard frontend: `public/assets/css/theme-dark.css`,
  `public/assets/js/app.js` (jQuery live-save state machine, toasts, AJAX+bearer, ApexCharts
  wrapper), Blade `layouts/admin` + `admin/partials/{sidebar,navbar}` + `admin/dashboard` +
  `admin/login`; routes in `routes/web.php`.
- **Verified in Docker:** `php artisan serve` -> `/admin/login` and `/admin` both HTTP 200;
  all compiled Blade views lint-clean (`php -l`); Playwright screenshots captured to
  `docs/modernization/_screens/`.
- Caught and fixed a Blade gotcha: inline array literals inside `@json()` break the directive
  argument parser - defaults must be computed in an `@php` block.
- Client research agents delivered: `10-client-config-keys.md` (161 option keys) and
  `11-client-feature-opportunities.md` (12 ranked opportunities).

## 2026-06-18 - Foundation

- Decisions locked: PHP backend rewrite; HTML/jQuery/CSS (Blade, no Vue) dark dashboard; full
  English (including identifiers); Docker build/test with Playwright and linters.
- Added `docker/Dockerfile.toolchain` (PHP 8.5 + Composer + Node 20 + Playwright + linters'
  system dependencies) and `docker/compose.toolchain.yml` (app + MariaDB + Mailpit).
- Built the toolchain image (`rustdesk-api-php-toolchain`).
- Master plan: [07-rewrite-plan-php.md](07-rewrite-plan-php.md).

> Convention: each entry notes the command(s) used to verify build, lint, tests, and E2E so a
> reader can reproduce them. "Verified" means the checks ran green in the toolchain image.
