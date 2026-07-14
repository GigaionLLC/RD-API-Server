---
type: "core"
name: "Design System & UI Standards"
status: "stable"
description: "The single source of truth for the admin console's visual design and interaction patterns."
---

# Design System

The admin console is a server-rendered **Blade + jQuery + Bootstrap 5** application with an
original `rd-*` component layer. It is not a Vue application, SPA, or purchased dashboard
template. The visual direction is quiet, practical, and warm: mineral neutrals, restrained
copper accents, compact information density, and clear hierarchy in both dark and light
themes.

The implementation lives in
[`public/assets/css/theme-dark.css`](../../public/assets/css/theme-dark.css) (tokens,
components, responsive behavior) and
[`public/assets/js/app.js`](../../public/assets/js/app.js) (shared interactions). This guide
is the usage contract; the stylesheet and script are the source of truth.

## Principles

1. **Operational clarity first.** Dense administrative data should remain easy to scan,
   compare, filter, and act on.
2. **One shared visual language.** Use theme tokens and existing `rd-*` primitives. Extend
   the global stylesheet when a reusable pattern is genuinely missing; do not add page-level
   `<style>` blocks.
3. **Dark and light are equal products.** Every component must work in both themes without
   hardcoded foreground or background colors.
4. **Responsive, not merely compressed.** Reflow grids and forms, move navigation off-canvas,
   and contain wide tables inside their own horizontal scroller. The page itself must not
   scroll horizontally.
5. **Accessibility is part of the component contract.** Preserve landmarks, labels, keyboard
   behavior, focus visibility, reduced-motion preferences, and meaningful live feedback.
6. **No runtime CDN dependency.** Framework assets are pinned through npm and copied into
   `public/assets/vendor/` for local delivery.

## Warm-mineral color system

Use the semantic variables below; never copy their literal values into Blade or JavaScript.
The dark palette uses charcoal and olive mineral surfaces, copper for primary actions,
sea-glass for information, moss for success, ochre for warning, and fired clay for danger.
The light palette keeps the same relationships with stronger foreground values for contrast.

### Surfaces and text

| Token | Dark | Light | Purpose |
|---|---:|---:|---|
| `--rd-canvas` | `#11120f` | `#f2f0e9` | Application canvas |
| `--rd-sidebar-bg` | `#151713` | `#e8e5dc` | Navigation and inset output surfaces |
| `--rd-surface` | `#191c18` | `#fbfaf6` | Cards, panels, dialogs |
| `--rd-surface-raised` | `#20241f` | `#efede5` | Inputs and raised controls |
| `--rd-surface-hover` | `#272c25` | `#e4e1d7` | Hover and selected-row surfaces |
| `--rd-border` | `#343b31` | `#d3cec2` | Quiet dividers and card boundaries |
| `--rd-border-strong` | `#667362` | `#7d8177` | Input and emphasized boundaries |
| `--rd-text` | `#e9e5da` | `#24271f` | Main body text |
| `--rd-text-secondary` | `#b0b1a7` | `#51574e` | Supporting text and control labels |
| `--rd-text-muted` | `#92988e` | `#555c53` | Metadata and tertiary labels |
| `--rd-text-bright` | `#f7f2e7` | `#12150f` | Headings and emphasized values |

### Accent and status colors

| Token | Dark | Light | Purpose |
|---|---:|---:|---|
| `--rd-primary` | `#d18755` | `#85401f` | Primary actions, links, current state |
| `--rd-primary-hover` | `#e09a67` | `#71351b` | Primary hover state |
| `--rd-on-primary` | `#11120f` | `#fffaf3` | Text/icons on primary fills |
| `--rd-success` | `#9cc78a` | `#3f6934` | Online, saved, successful |
| `--rd-warning` | `#d5a24f` | `#93650b` | Dirty, pending, caution |
| `--rd-danger` | `#d47b69` | `#a84f42` | Errors, offline, destructive actions |
| `--rd-info` | `#6fa9a0` | `#39786f` | Informational and technical content |

Each accent has a `--rd-*-soft` companion for subtle backgrounds. Compatibility aliases
`--rd-bg`, `--rd-surface-2`, `--rd-surface-3`, and `--rd-accent` map to the canonical tokens;
new work should prefer the canonical names.

### Geometry and typography

| Token | Value | Purpose |
|---|---:|---|
| `--rd-radius-xs` | `6px` | Tiny controls and marks |
| `--rd-radius-sm` | `9px` | Inputs and buttons |
| `--rd-radius` | `13px` | Cards and panels |
| `--rd-radius-lg` | `18px` | Large shells such as authentication |
| `--rd-sidebar-w` | `264px` | Desktop sidebar width |
| `--rd-navbar-h` | `68px` | Top navigation height |
| `--rd-content-max` | `1600px` | Main-content maximum width |

The UI font stack begins with **Segoe UI Variable** and falls back to system UI fonts.
Technical values use the `--rd-mono-font` stack. Use `--rd-shadow-sm`, `--rd-shadow`, and
`--rd-focus` instead of creating ad-hoc shadows or focus rings.

## Theme contract

- The selected theme is represented by both `data-theme` and `data-bs-theme` on `<html>` so
  the custom system and Bootstrap overlays stay synchronized.
- `resources/views/layouts/admin.blade.php` and the standalone sign-in/two-factor challenge
  views run a small head bootstrap before styles load. It reads `localStorage.rd_theme`,
  otherwise follows
  `prefers-color-scheme`, and falls back to dark if browser storage is unavailable. This
  avoids a wrong-theme flash on first paint.
- Theme buttons use `[data-theme-toggle]`. `RD.setTheme(theme, persist)` updates the document,
  accessible button labels, stored preference, and dispatches `rd:themechange`.
- `RD.themeTokens()` exposes computed semantic colors to JavaScript. `RD.areaChart()` consumes
  these tokens and updates an existing chart after `rd:themechange`; charts must not embed
  their own palette.
- If the user has not stored a preference, a live operating-system color-scheme change is
  followed automatically.

## Application shell

The shared hierarchy is:

```text
.rd-app
|- .rd-sidebar
|- .rd-sidebar__backdrop
`- .rd-main
   |- .rd-navbar
   `- main.rd-content#main-content
```

The shell includes a keyboard-visible `.rd-skip-link`. Navigation is permission-aware and
grouped by operational area: Overview, Fleet, People & access, Control, Audit & safety,
Integrations, and System. The current destination uses `aria-current="page"` as well as the
visual `.active` state.

Below `992px`, the sidebar becomes an off-canvas panel with a backdrop. Its toggle maintains
`aria-expanded`; the panel maintains `aria-hidden`; backdrop click and `Escape` close it and
return focus where appropriate. At desktop width it is always available without reducing the
content canvas to a mobile layout.

## Shared component vocabulary

Prefer these primitives before creating anything new.

| Area | Primary classes | Contract |
|---|---|---|
| Page heading | `.rd-page-header`, `__copy`, `__eyebrow`, `__title`, `__description`, `__actions` | One clear page title with related actions |
| Card/panel | `.rd-card`, `--quiet`, `--flush`, `__header`, `__title`, `__body` | Standard content boundary and spacing |
| Summary/KPI | `.rd-summary`, `__item--*`, `__icon--*`, `__value`, `__label` | Compact, semantic operational metrics |
| Toolbar | `.rd-toolbar`, `__group`, `__search`, `__control` | Filters and list actions that wrap cleanly |
| Table | `.rd-table-wrap` > `.rd-table` | Keep wide data reachable inside a local scroller |
| Actions | `.rd-actions`, `.rd-actions--wrap` | Consistent spacing for related controls |
| Pagination | `.rd-pagination`, `__meta`, `__controls` | Results context and navigation |
| Empty state | `.rd-empty`, `__icon`, `__title`, `__body`, `__actions` | Explain what is missing and the next action |
| Form | `.rd-field`, `.rd-label`, `.rd-input`, `.rd-select`, `.rd-textarea`, `.rd-help` | Explicit label/help/error relationships |
| Form layout | `.rd-form-grid--2`, `.rd-form-grid--3`, `.rd-field--inline` | Responsive field grouping |
| Button | `.rd-btn--primary`, `--ghost`, `--danger`, `--save` | Use semantic intent, not color names |
| Status | `.rd-badge--online|offline|success|warning|danger|info|muted` | Text and shape must carry meaning with color |
| Notice | `.rd-callout--info|success|warning|danger` | Contextual guidance within page flow |
| Code/value | `.rd-code`, `.rd-code-block`, `.rd-mono` | IDs, commands, keys, and generated config |
| Overlay | Bootstrap `.modal` / `.dropdown-menu` with theme overrides | Keep Bootstrap behavior and RD visual tokens |

Complex screens have shared, responsive primitives too: `.rd-strategy-*` for the strategy
editor, `.rd-address-book*`, `.rd-tag-*`, and `.rd-peer-*` for address-book management, and
`.rd-config-*` for client configuration. Treat these as established component families, not
invitations to add local CSS to those pages.

## Interaction contracts (`window.RD`)

### Live-save forms

Use `form.rd-liveform[data-url][data-method]` with a `.rd-btn--save`. `app.js` owns the button
state machine: `idle -> dirty -> saving -> saved|error`. It attaches CSRF and any stored bearer
token through `RD.api()`, submits JSON, exposes `aria-busy`, and reports the outcome through a
toast. Do not duplicate this logic inside a page.

### Destructive confirmation

Every destructive list or form action requires confirmation.

- Declarative actions use `data-confirm="Message"`.
- Optional attributes are `data-confirm-title`, `data-confirm-action`, and
  `data-confirm-tone="primary"` for a non-dangerous confirmation.
- Programmatic flows use `RD.confirm(message, options)`, which returns a jQuery promise that
  resolves to `true` or `false`.
- The shared Bootstrap modal supplies a title, description, keyboard handling, cancel action,
  and focus management. Do not call `window.confirm()`.

### Toasts

Call `RD.toast(message, 'success|error|warning|info')`. Toasts use `status` or `alert`
semantics, escape message text, can be dismissed explicitly, pause their timeout while
hovered or focused, and remain inside the shared `aria-live` region. Do not build page-local
notification stacks.

### Searchable combobox

Use `.rd-combo` with a hidden value input, `.rd-combo__input`, `.rd-combo__menu`, and a
`data-url` endpoint returning `{ id, text }` results. `RD.bindCombobox()` assigns listbox and
option semantics, debounces search, exposes busy/expanded state, and supports Arrow Up/Down,
Enter, Escape, Tab, mouse, and touch selection. Preserve this structure when using the
pattern.

### Copy controls and charts

- `[data-copy="#source"]` and `[data-copy-text="value"]` use the shared clipboard behavior and
  toast feedback.
- `RD.areaChart(element, series, categories, colors)` accepts semantic color names such as
  `primary` and `info`. ApexCharts is loaded only by pages that render charts.

## Responsive and accessibility requirements

- The supported layout floor is `320px`. At `1199px`, `991px`, `767px`, and `520px`, shared
  grids, forms, headers, toolbars, authentication, flagship workspaces, and bulk actions
  progressively reflow.
- Wide tables belong in `.rd-table-wrap`; horizontal scrolling is allowed there, never at the
  page level. Important actions must remain reachable without a desktop viewport.
- Every form control needs an accessible label. Icon-only buttons need an `aria-label`; icons
  that repeat visible text are `aria-hidden="true"`.
- Use native elements first. When custom tabs, switches, dialogs, or comboboxes are necessary,
  keep their roles, state attributes, and complete keyboard behavior.
- Focus must remain visible. Do not suppress the shared `:focus-visible` treatment.
- Motion is disabled or shortened under `prefers-reduced-motion: reduce`; new animation must
  honor the same preference.
- Empty, loading, success, warning, and error states must be understandable without relying on
  color alone.

## Local asset policy

`npm run build:vendor` runs `scripts/copy-admin-vendor.mjs`, cleans the generated vendor
directory, and copies pinned package assets:

- Bootstrap CSS and bundled JavaScript to `public/assets/vendor/bootstrap/`
- jQuery to `public/assets/vendor/jquery/`
- Remix Icon CSS and WOFF2 font to `public/assets/vendor/remixicon/`
- ApexCharts to `public/assets/vendor/apexcharts/`

The generator removes Bootstrap source-map trailers because maps are not shipped, limits the
Remix Icon font declaration to the shipped WOFF2 file, and writes the runtime packages' full
license texts plus `THIRD_PARTY_NOTICES.txt`. The Remix stylesheet carries a local-modification
notice as required by its Apache-2.0 license.

After `npm ci --ignore-scripts`, run `npm run check:vendor` to compare both the complete file
inventory and every byte against a fresh in-memory build. CI performs this check so a package,
generator, notice, or checked-in asset cannot drift independently. Run `npm run build:vendor`
and commit all generated changes whenever a pinned runtime package changes.

The admin and authentication layouts must reference these local assets with Laravel's
`asset()` helper. Do not add CDN URLs, remote fonts, or another icon system. Keep ApexCharts
page-local so non-chart pages do not pay its download cost.

## Verification expectations

UI work is verified in the Docker toolchain, not assumed from a single desktop screenshot.
The Playwright configuration covers:

- `desktop-dark` at `1440x900`
- `desktop-light` at `1440x900`
- `tablet-dark` at `1024x768` with touch enabled
- `mobile-dark` at `390x844` with touch/mobile behavior enabled

`e2e/gui.spec.ts` exercises theme persistence, responsive flagship pages, page-level overflow,
strategy tabs, bulk device actions, and keyboard-dismissible dialogs.
`e2e/accessibility.spec.ts` runs axe against login and scans representative authenticated
pages in both desktop themes. `e2e/screenshots.spec.ts` is opt-in and must never overwrite the
gallery during a normal quality-gate run.

Before finishing a UI change, also confirm that Blade views compile, JavaScript lint passes,
there are no runtime CDN references or page-level `<style>` blocks, and the relevant Laravel
feature tests remain green.
