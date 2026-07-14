# Parcel Plan: Admin Console UI/UX Modernization

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `COMPLETE - LOCAL REVIEW READY` |
| **Version** | `v1.0.0` |
| **Active Persona** | Frontend implementer + accessibility reviewer |
| **Last Updated** | 2026-07-13 22:56 PDT |
| **Implementation State** | Full rollout implemented and verified locally; no commit or push |

---

## Executive direction

Evolve the functional first-generation dark dashboard into a distinctive, calm remote-
operations console. The UI should feel precise, trustworthy, and purpose-built for running a
device fleet—not like a generic Bootstrap admin template.

The recommended design north star is **Calm Remote Operations**:

- operational information and next actions lead the hierarchy;
- dense data remains scannable without making every surface look identical;
- technical values such as device IDs, IPs, keys, and event codes get a dedicated monospace
  treatment;
- status is communicated with text and iconography as well as color;
- color character comes from warm mineral neutrals and natural patina accents, not the
  familiar blue-black/purple/cyan “AI dashboard” palette;
- dark mode remains first-class, with either a complete light mode or no theme toggle;
- subtle connection/telemetry motifs add identity without copying RustDesk or implying an
  official affiliation.

This is an evolutionary redesign. Existing routes, permissions, business behavior, and client
wire compatibility remain unchanged.

## 1. Phase 1: Expansion & Scoping

### Intent

Create a reviewable, phased plan for modernizing the complete server-rendered admin console
while staying within the established Laravel + Blade + jQuery + Bootstrap 5 architecture.

### Current-state evidence

The existing UI is cohesive and functional, but its design system is too narrow for the amount
of product surface now present:

- 47 Blade files across the admin and layout folders;
- 23 permission-gated sidebar links in one long navigation column;
- 24 tables and 69 forms, mostly assembled independently;
- 347 inline `style=` attributes and six page-local `<style>` blocks;
- a 285-line global theme with only two responsive breakpoints;
- a visible light/dark control, but no light-theme token overrides;
- public-CDN dependencies for Bootstrap, jQuery, Remix Icon, and ApexCharts;
- desktop-only visual baselines and no automated accessibility checks.

The screenshots under `docs/screenshots/` are the reproducible “before” baseline. They show
that cards, toolbars, nested panels, and tables use nearly identical navy surfaces, borders,
radius, and shadow. This flattens hierarchy and gives specialized workflows the same visual
language as generic CRUD pages.

### In scope

- Admin shell, navigation, dashboard, authentication/2FA, and every page under
  `resources/views/admin/`.
- Product/persona hypotheses, primary jobs, task hierarchy, and information architecture.
- Independent visual identity and display-name treatment.
- Dark and light color systems, typography, spacing, density, elevation, radius, icon, and
  motion tokens.
- Reusable Blade components and `rd-*` CSS/JavaScript behaviors.
- Tables, filters, pagination, forms, validation, status, feedback, confirmations, empty
  states, loading states, and error recovery.
- Responsive layouts from wide desktop through narrow mobile.
- WCAG 2.2 AA-oriented semantics, keyboard use, focus, contrast, target sizing, reflow, and
  reduced-motion behavior.
- Local delivery of critical frontend assets for air-gapped/self-hosted deployments.
- Playwright interaction, responsive, theme, accessibility, and visual-regression coverage.
- Design-system, user-journey, app-structure, validation, performance, and screenshot docs.

### Out of scope

- Vue, React, another SPA framework, or a commercial admin template.
- Client-facing API routes, JSON keys, database schema, or RustDesk wire behavior.
- The separate browser-based remote-control web client mentioned in modernization research.
- New backend capabilities such as global search, first-run setup, or live fleet streaming;
  those may be separate follow-up plans.
- Copying official RustDesk branding or third-party dashboard assets.
- Removing tracked legacy/unused files such as the stock `welcome.blade.php` without separate
  confirmation.

### Behavior that must survive the redesign

- Permission-gated navigation and route access.
- Existing search, filter, export, bulk-action, CRUD, live-save, copy, and generator flows.
- The client-like mental model in Strategy settings.
- The address-book manager’s peer/tag behavior.
- Destructive-action confirmation.
- The independent-project and not-affiliated messaging.

## 2. Phase 2: Requirements & Context

### Relevant documentation

- `AGENT.md` — repository and wrap-up rules.
- `Wiki/core/06-design-system.md` — current UI contract and `rd-*` primitives.
- `DESIGN.md` — stale quick reference that must be reconciled with the real `--rd-*` tokens.
- `docs/modernization/07-rewrite-plan-php.md` — historical first-generation dashboard plan.
- `docs/screenshots/README.md` — current visual gallery and demo-data baseline.
- `DevOps/plans/template-plan.md` — required planning format.
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/) — accessibility acceptance target.
- [WAI-ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/) — keyboard and semantic
  patterns for dialogs, menus, tabs, and comboboxes.
- [Bootstrap 5.3 accessibility guidance](https://getbootstrap.com/docs/5.3/getting-started/accessibility/)
  — framework capabilities and author responsibilities.

### Relevant implementation

- `public/assets/css/theme-dark.css` — tokens, shell, cards, tables, forms, buttons, overlays,
  and limited responsive behavior.
- `public/assets/js/app.js` — API helper, live-save state, toast, native confirmation, shell,
  theme, chart, and combobox behavior.
- `resources/views/layouts/admin.blade.php` — global admin document and CDN loading.
- `resources/views/admin/partials/{sidebar,navbar,flash,pagination}.blade.php` — shared shell.
- `resources/views/admin/dashboard.blade.php` — KPI/chart/recent-device composition.
- `resources/views/admin/devices/index.blade.php` — highest-leverage list-page pilot.
- `resources/views/admin/strategies/edit.blade.php` — highest-complexity form pilot.
- `resources/views/admin/address_books/show.blade.php` — specialized manager/canvas.
- `resources/views/admin/login.blade.php` and `two_factor/*` — standalone auth surfaces.
- `e2e/{login,gui,screenshots}.spec.ts` and `playwright.config.ts` — present E2E baseline.
- `database/seeders/DemoShowcaseSeeder.php` and `docker/demo-shots.sh` — deterministic visual
  data and capture workflow.

### Persona and job hypotheses to validate

| Persona | Primary jobs |
| :--- | :--- |
| Platform owner | Configure the server, identity, permissions, email, API access, and integrations. |
| Fleet operator | Find devices, assess online health, approve enrollment, and manage assignment. |
| Support technician | Inspect a device/user, manage address books, prepare client configuration, and respond to live sessions. |
| Security/audit operator | Triage alarms and investigate connection, login, file-transfer, and console activity. |

These are working hypotheses because `Wiki/core/02-product-context.md` and
`Wiki/core/03-user-journey.md` are still templates. They must be confirmed before the visual
system is locked.

### UX principles

1. **Action before decoration** — show health, consequence, and next action before ornamental
   chrome.
2. **Scan first, inspect second** — summaries and status remain compact; detail is progressively
   disclosed.
3. **One pattern per job** — every list, filter bar, form error, confirmation, and empty state
   behaves consistently.
4. **Dense but calm** — support operational data volume without tiny type or a wall of borders.
5. **State is explicit** — loading, saved, failed, disabled, stale, filtered, selected, and
   destructive states are visible in words, not just color.
6. **Keyboard is first-class** — all interactions work without a pointer and expose visible
   focus.
7. **Independent identity** — the product may describe RustDesk compatibility but must never
   appear official.

### Proposed information architecture

Routes and permissions stay fixed; only labels, grouping, and shell behavior change.

| Proposed group | Pages |
| :--- | :--- |
| **Overview** | Dashboard |
| **Fleet** | Devices, Pending Devices, Device Groups, Live Sessions |
| **People & Access** | Users, User Groups, Address Books, Admin Roles |
| **Policies & Rollout** | Strategies, Deploy Tokens, Client Config |
| **Activity & Security** | Alarms, Connection Logs, File Transfers, Login Logs, Console Operations, Recordings |
| **Integrations** | API Keys, Webhooks, OAuth Providers, LDAP / AD |
| **System** | Settings |

Navigation requirements:

- groups collapse without hiding the active page and remember local preference;
- active links expose `aria-current="page"`;
- mobile uses an accessible off-canvas panel with backdrop, Escape/outside close, focus return,
  and accurate `aria-expanded` state;
- the sidebar may offer a compact desktop mode, but labels must remain discoverable;
- count badges are added only when data already exists without expensive new queries;
- breadcrumbs appear for nested/detail hierarchy, not as a duplicate title on every page.

### Visual direction

- **Palette character:** use warm carbon and mineral graphite instead of blue-tinted navy.
  Warm bone text avoids the cold white-on-blue cast. Oxidized copper identifies selected and
  primary actions; muted sea-glass and moss are reserved for connectivity and success. Ochre
  and clay communicate warning/destructive states without fluorescent saturation.
- **Color distribution:** neutral surfaces should occupy roughly 85–90% of the screen. Accent
  color appears in small, purposeful regions—focus, active navigation, primary actions, and
  state indicators—not as a full-page glow or blue overlay.
- **Layering:** use tonal separation, whitespace, grouping, and occasional elevation instead of
  putting a border and shadow around every block.
- **Typography:** self-host a readable UI sans with a compatible mono face for IDs, IPs, tokens,
  configuration keys, and log timestamps. Final families require a licensing/glyph/rendering
  spike before approval.
- **Density:** define comfortable and compact component metrics from one spacing scale; do not
  shrink below accessible target and text requirements.
- **Iconography:** keep one self-hosted icon family and pair icon-only controls with accessible
  names/tooltips.
- **Motion:** short functional transitions only, with complete
  `prefers-reduced-motion` handling.
- **Identity:** an original connection/signal motif may appear subtly in the auth shell,
  dashboard, and brand mark. Avoid “neon cyber” decoration and official RustDesk assets.

#### Provisional dark palette

These values define the concept preview, not a production lock. Validate them in real CSS
against every component state before approval.

| Role | Token direction | Value | Notes |
| :--- | :--- | :--- | :--- |
| Canvas | Carbon ink | `#11120F` | Warm near-black; no blue cast |
| Sidebar/chrome | Charcoal olive | `#151713` | Separates navigation without a different hue family |
| Base surface | Mineral graphite | `#191C18` | Default table/card surface |
| Raised surface | Warm graphite | `#20241F` | Inputs, filter bar, selected regions |
| Elevated/hover | Weathered graphite | `#272C25` | Hover and transient elevation |
| Subtle border | Mineral line | `#343B31` | Decorative separation only |
| Control border | Strong mineral line | `#667362` | Meets the non-text contrast target on raised surfaces |
| Primary text | Warm bone | `#E9E5DA` | Softer than pure white |
| Secondary text | Limestone | `#B0B1A7` | Supporting content |
| Muted text | Sage gray | `#92988E` | Still readable on all dark surfaces |
| Primary action | Oxidized copper | `#D18755` | Use `#11120F` as the on-primary text color |
| Connectivity/info | Sea glass | `#6FA9A0` | Never a general decorative accent |
| Success | Moss | `#9CC78A` | Healthy/online states; tuned for badge contrast |
| Warning | Ochre | `#D5A24F` | Attention without neon yellow |
| Danger | Fired clay | `#D47B69` | Destructive/critical states |

The initial contrast pass places primary, secondary, and muted text above 4.5:1 on the
provisional dark surfaces; semantic colors also remain legible as text on the base surface.
Production verification must still test focus, disabled, soft-background, chart, and
high-contrast states.

### Measurable acceptance targets

- WCAG 2.2 AA acceptance matrix for representative pages and shared components.
- No serious or critical automated accessibility violations on the tested page matrix.
- Complete keyboard paths with no focus trap for navigation, dropdowns, dialogs, tabs,
  comboboxes, bulk actions, and forms.
- No page-level horizontal overflow at 320 CSS pixels; genuine two-dimensional data regions
  may use a contained, labeled scroll or responsive alternate presentation.
- Every theme and state meets text/non-text contrast requirements; status never relies only on
  color.
- Desktop (1440×900), tablet (1024×768), and mobile (390×844) screenshot coverage.
- Static inline styles eliminated; genuinely dynamic values use documented custom properties
  or an explicit allowlist.
- Literal palette values removed from Blade/JavaScript except documented technical necessities.
- No critical UI behavior depends on a public CDN.
- Seeded pilot pages meet lab targets of LCP ≤ 2.5 s and CLS ≤ 0.1 with no material regression
  in transferred asset size.
- Existing PHPUnit, Pint, PHPStan, ESLint, and Playwright gates remain green.

## 3. Phase 3: User Clarification

The plan can proceed with the recommended defaults, but these choices should be confirmed at
the first review gate.

- [x] **Surface scope:** admin console only, or also the separate remote-control web client?
  - **Decision:** admin console only.
- [x] **Theme:** complete light + dark modes, or remove the nonfunctional toggle and stay
  dark-only?
  - **Decision:** complete both modes, using system preference until explicitly set.
- [x] **Displayed product name:** align the UI with the README’s “RD-API-Server,” or retain
  “rustdesk-api”?
  - **Decision:** “RD-API-Server,” with compatibility/subtitle copy and the existing
    affiliation guardrail.
- [x] **Mobile ambition:** full administration at phone width or monitor plus safe common
  actions?
  - **Decision:** all content remains reachable; optimize monitoring and common
    actions, while complex editors use progressive disclosure.
- [x] **Density target:** mostly small installations, or fleets with hundreds/thousands of
  rows?
  - **Decision:** optimize scanning for large fleets while retaining a comfortable
    default density.
- [x] **Color character:** approve the warm mineral/copper/sea-glass direction that replaces
  the first preview’s blue-black/iris palette?
  - **Decision:** proceed with the provisional palette above, then tune it in real
    CSS during the Devices pilot.

## 4. Phase 4: Detailed Execution Plan

### Release strategy

Use independently reviewable slices. Do not migrate all pages before the foundation and pilot
are approved.

#### Slice 0 — UX contract and baseline (small)

1. Confirm surface scope, personas/jobs, display name, theme, density, and mobile goal.
2. Inventory every route/view by page archetype and permission.
3. Capture the current desktop/tablet/mobile behavior of the shell and three pilots.
4. Record shared-state requirements: default, hover, focus-visible, active, disabled, loading,
   saved, warning, error, selected, empty, and destructive.
5. Approve one visual concept and one information-architecture proposal.

**Exit gate:** product owner approves the design principles, navigation map, and pilot brief.

#### Slice 1 — Design-system and asset foundation (medium)

1. Expand `--rd-*` tokens for:
   - semantic surfaces/text/borders/status;
   - typography and mono data;
   - spacing and component density;
   - radii, elevation, overlay, and z-index;
   - focus rings, control sizing, motion, and breakpoints;
   - complete dark and light themes.
2. Reorganize `theme-dark.css` into explicit token/base/layout/component/utility layers while
   keeping it the documented source of truth.
3. Vendor or bundle Bootstrap, jQuery, Remix Icon, ApexCharts, and fonts; load chart code only
   where needed.
4. Build reusable Blade primitives under `resources/views/components/admin/`:
   - page header and breadcrumbs;
   - card/section, callout, alert, empty state, skeleton;
   - button, icon button, status badge, action menu;
   - field wrapper, help/error text, checkbox/switch, secret/copy field;
   - filter bar, responsive table shell, bulk bar, pagination;
   - tabs/segmented controls, modal, confirmation dialog, toast/live region.
5. Upgrade `window.RD` behaviors without introducing an SPA:
   - accessible confirm dialog;
   - semantic toasts with close/pause behavior;
   - APG-style combobox keyboard behavior;
   - theme/chart token integration;
   - shared copy/reveal/filter/sidebar helpers.

**Exit gate:** shared components render correctly in both themes, all states, and all target
viewports before page migration begins.

#### Slice 2 — Shell and information architecture (medium)

1. Redesign the app frame, sidebar, top utility bar, content width, and responsive behavior.
2. Apply the approved navigation groups while preserving all permission checks and routes.
3. Introduce a reusable page header with title, supporting description/status, and primary/
   secondary actions.
4. Remove duplicate title/breadcrumb/card hierarchy.
5. Add skip navigation, landmarks, focus management, mobile backdrop, and keyboard behavior.
6. Create a shared auth layout rather than duplicating standalone document styles.

**Exit gate:** every existing page remains reachable for each permission profile, and shell
tests pass at desktop/tablet/mobile widths with keyboard-only navigation.

#### Slice 3 — Representative pilots (large)

Implement and review three pages before broad rollout:

1. **Devices index — first and highest-leverage pilot**
   - page header, fleet summary, unified search/filter bar, filter reset;
   - responsive table/compact presentation;
   - clearer device identity and technical metadata;
   - restrained status badges;
   - contextual row-action menu instead of dominant red delete controls;
   - sticky/anchored bulk-action bar with explicit selection and consequences;
   - designed empty, no-results, loading, and error states;
   - richer result count and pagination.
2. **Dashboard — identity and hierarchy pilot**
   - operational health and actionable exceptions before decorative metrics;
   - better KPI hierarchy and comparison context;
   - useful pending/alarm/integration-failure cues when existing data permits;
   - token-driven, accessible charts with readable empty states;
   - a purpose-built composition rather than equal cards on a uniform grid.
3. **Strategy editor — complex workflow pilot**
   - responsive section navigation;
   - progressive disclosure and clearer inheritance/default semantics;
   - sticky save/status affordance;
   - grouped controls without nested-border overload;
   - accessible bulk “all on/off/default” behavior and assignment workflow.

**Exit gate:** user signs off on the pilots before the pattern is propagated to other pages.

#### Slice 4 — Rollout by page archetype (extra large)

1. **Dense lists and activity tables:** Users, User Groups, Device Groups, Address Books,
   Pending Devices, Deploy Tokens, API Keys, sessions, alarms, recordings, audit pages,
   console operations, webhooks, roles, and provider lists.
2. **Standard CRUD editors:** user/group/device-group/role/provider create/edit pages.
3. **System forms:** Settings, LDAP/AD, two-factor setup, and provider configuration.
4. **Setup/generator flows:** Client Config, Deploy Tokens, API Keys, and Webhooks.
5. Reuse the shared table, filter, action, form, validation, feedback, and confirmation
   contracts; keep truly specialized layouts local but tokenized.

**Exit gate:** all admin views use the new shell and component contracts with no functional
regressions or unexplained one-off styles.

#### Slice 5 — Specialized workflows and auth polish (large)

1. Redesign Address Book Manager for responsive tag/peer navigation, keyboard-accessible peer
   cards, and consistent modal forms.
2. Polish Client Config as a guided “configure → generate → distribute” workflow with safe
   copy affordances and OS-specific output hierarchy.
3. Unify login, SSO, 2FA challenge, and 2FA setup under the shared auth layout.
4. Ensure independent-project messaging remains visible but subordinate to the task.
5. Audit secret fields, one-time credentials, copy states, QR presentation, and error recovery.

#### Slice 6 — Hardening, QA, and documentation (medium)

Accessibility, responsive behavior, and tests are acceptance criteria in every prior slice;
this slice closes cross-page gaps:

1. Run automated and manual accessibility matrices.
2. Test keyboard-only and 200%/400% zoom/reflow flows.
3. Compare dark/light and desktop/tablet/mobile visual snapshots.
4. Verify high-contrast and reduced-motion preferences.
5. Measure critical-page loading, layout shift, and asset weight.
6. Regenerate the public screenshot gallery only after final design approval.
7. Update the design system and the relevant core product/structure/validation/performance docs.

### Component contract

Every shared component must document:

- semantic element/ARIA contract;
- supported variants and sizes;
- all interaction and async states;
- keyboard behavior;
- responsive behavior;
- dark/light token mapping;
- safe use and anti-patterns;
- Blade API and minimal `RD` JavaScript hook, if any.

Do not turn unique product workflows into overly generic components. Extract repeated
structure; keep specialized domain behavior readable in its own view.

### Architecture and files expected to change

| Area | Files |
| :--- | :--- |
| Global UI | `public/assets/css/theme-dark.css`, `public/assets/js/app.js`, locally delivered assets |
| Shell | `resources/views/layouts/admin.blade.php`, new auth layout, `admin/partials/{sidebar,navbar,flash,pagination}.blade.php` |
| Components | New `resources/views/components/admin/*.blade.php` primitives |
| Pilots | `admin/devices/index.blade.php`, `admin/dashboard.blade.php`, `admin/strategies/edit.blade.php` |
| Lists | Remaining `admin/**/index.blade.php` and audit/list views |
| Editors | Remaining create/edit/settings/provider/role views |
| Specialized | `admin/address_books/show.blade.php`, `admin/client_config/index.blade.php`, auth/2FA views |
| Tests | `e2e/*.spec.ts`, `playwright.config.ts`, relevant `tests/Feature/*` |
| Tooling | `package.json`/lockfile and Docker toolchain only if accessibility/visual tooling requires it |
| Docs | `Wiki/core/{01,02,03,05,06,11,14}-*.md`, `DESIGN.md`, `docs/screenshots/*`, changelog |

Controllers should change only when a view needs an already-available value in a cleaner view
model. No client API controller, API route, migration, or client wire contract belongs in this
plan.

### Verification plan

#### Automated gates

```bash
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain bash -lc \
  'php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=512M \
   && npx eslint public/assets/js && npx playwright test'
```

Add:

- a Playwright accessibility suite using a maintained axe integration;
- desktop/tablet/mobile projects or an explicit viewport matrix;
- dark/light theme assertions;
- keyboard tests for shell, dialogs, menus, tabs, combobox, pagination, and bulk actions;
- responsive-overflow assertions;
- stable screenshots for shell plus the three pilot pages;
- feature tests that preserve permission-gated navigation and key form behavior.

#### Static design-system checks

- No unapproved `style=` attributes or page-local `<style>` blocks.
- No literal palette colors in Blade/JavaScript outside an explicit technical allowlist.
- No undefined `rd-*` classes in views.
- No icon-only control without an accessible name.
- No destructive action without the shared confirmation pattern.
- No interactive `div` where a native button/link/input is appropriate.

#### Manual test matrix

| Dimension | Required coverage |
| :--- | :--- |
| Viewport | 1440×900, 1024×768, 390×844, 320px reflow |
| Theme | Dark, light, OS-default first visit |
| Input | Mouse, keyboard only, touch-sized targets |
| Content | Empty, one row, typical demo data, long values, validation/error |
| Permissions | Full admin and at least two restricted-role profiles |
| Preferences | Reduced motion, increased text/zoom, high contrast where supported |
| Connectivity | Critical assets available without public CDN access |

## 5. Phase 5: Product Owner Review

### Status

`APPROVED FOR IMPLEMENTATION`

### Findings

- ✅ **Vision & Scope** — the redesign is purpose-built for the admin console and retains the
  existing stack and behavior.
- ✅ **Business Logic & Edge Cases** — API/DB/wire behavior is explicitly excluded; permission,
  destructive, empty, error, and async states are included.
- ⚠️ **Dependency & Functional Risk** — local asset delivery and complete theming need an early
  spike; complex pages must not be mass-migrated before pilots pass.
- ✅ **Completeness & User Intent** — the plan covers foundation, shell, page archetypes,
  specialized workflows, accessibility, responsive behavior, testing, and docs.

### Required decisions

- [x] Confirm the six choices in Phase 3.
- [x] Approve the revised warm-mineral visual concept preview.
- [x] Approve Devices as the first implemented pilot.

## 6. Phase 6: Senior Dev Hygiene Review

### Status

`COMPLETE`

### Findings

- ✅ **DRY Scan** — replacing hundreds of local style declarations with shared primitives is a
  primary goal.
- ✅ **Abstraction & Architecture** — Blade components and `RD` helpers preserve server-rendered
  architecture; specialized domain workflows remain explicit.
- ✅ **State Management & Data Flow** — local DOM state and server responses remain sufficient;
  no SPA store is introduced.
- ✅ **Technical Debt & Deletion** — dead/unused assets are reported separately and are not
  removed without approval.
- ✅ **Secret Management** — no secret is embedded in theme code; credential and one-time key
  presentation receives dedicated review.
- ✅ **Data Security** — role/permission checks remain server-side and are regression tested.
- ✅ **Rate Limiting** — no rate-limit behavior changes.
- ⚠️ **Error Handling** — inconsistent field validation, toast semantics, and native confirms
  are explicitly replaced by shared accessible patterns.

## 7. Phase 7: Implementation Checklist

- [x] Confirm scope, personas, identity, theme, density, and mobile goals.
- [x] Approve information architecture and visual direction.
- [x] Record viewport/accessibility results and document the performance-measurement deviation.
- [x] Expand tokens and document the component/state matrix.
- [x] Vendor/bundle critical frontend assets.
- [x] Build and verify shared Blade/`RD` primitives.
- [x] Redesign and test shell/navigation/auth layout.
- [x] Implement Devices pilot.
- [x] Implement Dashboard pilot.
- [x] Implement Strategy Editor pilot.
- [x] Obtain user sign-off on pilots.
- [x] Migrate dense lists and activity pages.
- [x] Migrate CRUD/system/setup forms.
- [x] Redesign specialized Address Book and Client Config flows.
- [x] Complete auth/2FA polish.
- [x] Run the full accessibility/responsive/theme matrix, including 320px reflow.
- [x] Regenerate screenshots and synchronize docs.
- [x] Run every repository quality gate.

## 8. Phase 8: Verification Dashboard

| Gate | Status |
| :--- | :--- |
| Product direction approved | `COMPLETE` |
| Component/state contract approved | `COMPLETE` |
| Devices pilot approved | `COMPLETE` |
| Dashboard pilot approved | `COMPLETE` |
| Strategy pilot approved | `COMPLETE` |
| Functional regression suite | `COMPLETE` |
| Accessibility matrix | `COMPLETE` |
| Responsive/theme visual regression | `COMPLETE` |
| Performance/asset budgets | `DEFERRED - no stable LCP/CLS baseline on the Windows bind mount` |
| Docs and screenshot sync | `COMPLETE` |

## 9. Phase 9: User Verification

### Status

`READY FOR FINAL LOCAL REVIEW`

User verification should happen at three points:

1. concept + information architecture — approved 2026-07-13;
2. three representative pilots;
3. complete migrated console and refreshed gallery.

Concept, palette, and representative pilot feedback were incorporated before the user approved
the complete rollout. The finished implementation is intentionally left local and uncommitted
for the user's final repository review.

## 10. Phase 10: Wrap Up & Archival

When implementation is accepted:

1. update the design system and all behaviorally affected Wiki/modernization docs;
2. regenerate `docs/screenshots/` from `DemoShowcaseSeeder`;
3. append the final implementation changelog entry and version history if deployed;
4. add a completion note with actual outcomes/deviations;
5. move this file from `DevOps/plans/` to `DevOps/archive-plans/`.

## Completion Note

Completed locally on 2026-07-13. The full Blade admin/auth surface now uses the warm-mineral
dark/light design system, grouped responsive shell, local frontend assets, shared accessible
feedback/confirmation/combobox behaviors, and redesigned flagship workflows. The final Docker
gates completed with 557 PHPUnit assertions, Pint across 196 files, PHPStan with zero errors,
clean JavaScript lint/syntax/vendor build, and Playwright with 46 passes plus 6 intentional
capture skips across desktop dark/light, tablet dark, and mobile dark. A separate 320px reflow
check and the guarded 13-page desktop-dark gallery capture also passed.

The planned lab LCP/CLS budget was not recorded because the Windows bind-mounted development
server is not a stable performance environment. Asset delivery was still bounded and audited:
six pinned local runtime files total 1,258,545 bytes, ApexCharts is dashboard-only, and no admin
page depends on a public CDN. No API, database, permission, or wire-contract change was made.
Nothing was committed, staged, deployed, or pushed.
