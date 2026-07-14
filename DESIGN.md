# Application Design & Frontend Guidelines

The master frontend contract is
[`Wiki/core/06-design-system.md`](Wiki/core/06-design-system.md). Read it before creating or
changing an admin or authentication screen. The global implementation is
`public/assets/css/theme-dark.css` plus `public/assets/js/app.js`.

## Creative north star

The console should feel like a focused self-hosted operations tool: warm mineral surfaces,
restrained copper accents, compact but calm information density, and obvious hierarchy. Dark
and light modes are first-class, and the interface must remain fully usable from a 390px
mobile viewport through a wide desktop.

The frontend remains **server-rendered Blade + jQuery + Bootstrap 5**. Do not introduce Vue,
another SPA framework, remote template assets, runtime CDN dependencies, or a second design
system.

## Quick reference

| Need | Use |
|---|---|
| Page background | `--rd-canvas` |
| Navigation background | `--rd-sidebar-bg` |
| Cards and panels | `--rd-surface` |
| Inputs and raised controls | `--rd-surface-raised` |
| Hover/selection | `--rd-surface-hover` |
| Main/secondary/muted text | `--rd-text`, `--rd-text-secondary`, `--rd-text-muted` |
| Headings/emphasis | `--rd-text-bright` |
| Primary action | `--rd-primary`, `--rd-on-primary` |
| Semantic state | `--rd-success`, `--rd-warning`, `--rd-danger`, `--rd-info` |
| Keyboard focus | `--rd-focus` plus the shared `:focus-visible` rule |
| Standard card radius | `--rd-radius` |

Prefer established `rd-*` primitives: `.rd-page-header`, `.rd-card`, `.rd-toolbar`,
`.rd-table-wrap` / `.rd-table`, `.rd-field`, `.rd-input`, `.rd-select`, `.rd-btn`,
`.rd-badge`, `.rd-callout`, `.rd-empty`, and `.rd-pagination`.

## Non-negotiable checklist

- Use semantic CSS variables; no literal theme colors in Blade or page scripts.
- Put reusable styling in `theme-dark.css`; no page-level `<style>` blocks.
- Use local assets under `public/assets/vendor/`; no CDN or remote font dependency.
- Keep wide tables inside `.rd-table-wrap` and prevent page-level horizontal overflow.
- Use the responsive sidebar shell and existing mobile breakpoints.
- Use `RD.confirm()` / `data-confirm` for destructive actions, never `window.confirm()`.
- Put `data-confirm` on the submit control (or form) so mouse, keyboard, and implicit form
  submissions share the same confirmation and focus-return behavior.
- Use `RD.toast()` and the shared searchable combobox behavior rather than page-local copies.
- Treat `*.view` and `*.edit` as different interface states: retain useful navigation and
  data for viewers, hide mutation controls, disable non-editable form fields, and explain the
  view-only state in a shared callout.
- On validation failure, reopen the originating dialog, restore only non-sensitive input,
  and focus an error summary; never repopulate passwords or file inputs.
- Off-canvas navigation must make background content inert, keep focus inside the drawer,
  provide an explicit close control, and return focus to its opener.
- Preserve labels, focus states, keyboard operation, ARIA state, and reduced-motion support.
- Verify both desktop themes plus tablet and mobile projects in Playwright, with axe coverage
  for representative pages.

For complete tokens, markup contracts, interaction APIs, responsive behavior, local asset
workflow, and verification expectations, use the
[full design-system guide](Wiki/core/06-design-system.md).
