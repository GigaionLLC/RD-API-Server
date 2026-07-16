# 08 - Build Log (PHP rewrite)

Chronological record of what was built and its verification state. Newest at top.

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
