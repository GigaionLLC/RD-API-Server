# 08 - Build Log (PHP rewrite)

Chronological record of what was built and its verification state. Newest at top.

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
