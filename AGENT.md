# rustdesk-api — Agent Entry Point 🚀

**This is the single source of truth for working in this repository.** Before searching the
codebase, read the localized intelligence hub below. `CLAUDE.md` points here on purpose.

## What you're working on

A self-hosted **API server for the RustDesk remote-desktop client**, built in **PHP 8.5 /
Laravel 13** with a from-scratch **HTML/jQuery/Bootstrap 5** dark admin, **all in English** —
and adding features the RustDesk client supports that no open-source API server implements
(Strategy push, preset auto-registration). It began as a port of an earlier Go server, now
**retired** (the repo is single-stack PHP). The historical Go code is recoverable via git
history; the research that drove the port lives in `docs/modernization/`.

> **Affiliation:** This is an **independent, third-party, open-source** project — **not**
> affiliated with, endorsed by, or sponsored by RustDesk / Purslane Ltd. "RustDesk" is
> referenced only to describe compatibility with the client's public API. Never present this
> project or its UI as an official RustDesk product.

## 📌 Mandatory reading (the docs hub)

### 1. 🗺️ Start here: [Wiki/core/00-system-index.md](Wiki/core/00-system-index.md)
The master router for the architecture knowledge base (`/Wiki`) and operational tooling
(`/DevOps`).

### 2. 🧭 The rebuild itself: [docs/modernization/](docs/modernization/)
The deep research + plan that drives this rebuild. The load-bearing ones:
- [02-client-api-contract.md](docs/modernization/02-client-api-contract.md) — **the spec**:
  exact endpoints/JSON the RustDesk client expects. Build client-facing code to this.
- [04-gap-analysis.md](docs/modernization/04-gap-analysis.md) — have/partial/missing + value.
- [06-reference-implementations.md](docs/modernization/06-reference-implementations.md) —
  what to borrow from the other open-source servers.
- [07-rewrite-plan-php.md](docs/modernization/07-rewrite-plan-php.md) — the master rebuild plan.
- [09-port-status.md](docs/modernization/09-port-status.md) — live Go→PHP parity checklist.

### 3. 🎨 Building UI? Read [Wiki/core/06-design-system.md](Wiki/core/06-design-system.md) FIRST.
Use the design tokens and components defined there + `public/assets/css/theme-dark.css`. Do
not invent CSS classes or hardcode colors. No Vue — Blade + jQuery only.

### 4. 🗄️ Touching the API/DB? Verify the contract & schema first.
Client-facing JSON keys and routes are **fixed by the RustDesk client** — never rename them
(see the contract doc). Schema lives in `database/migrations/` (PHP) with the Go `model/`
package as the reference.

## 🔎 Task lookup

| Task | Read first | Then drill into |
|------|------------|-----------------|
| Implement a client `/api/*` endpoint | [Client API contract](docs/modernization/02-client-api-contract.md) | `app/Http/Controllers/Api/`, `routes/api.php` |
| Build/edit an admin screen | [Design System](Wiki/core/06-design-system.md) | `resources/views/admin/`, `public/assets/` |
| Add/alter a DB table | [Port status](docs/modernization/09-port-status.md) | `database/migrations/`, `app/Models/` |
| Add a feature (mail/2FA/strategy/…) | [Gap analysis](docs/modernization/04-gap-analysis.md) + [Roadmap](docs/modernization/05-roadmap-and-implementation.md) | `app/Services/` |
| Borrow from other OSS servers | [Reference impls](docs/modernization/06-reference-implementations.md) | the cited files |
| Check roadmap / parked items | [Backlog Index](DevOps/backlog/backlog-index.md) | specific backlog plan |
| Review project state before coding | [Agent Changelog](DevOps/logs/agent-changelog.md) | last 3 entries |

## ⚡ Core development rules
1. **No Vue / SPA frameworks.** Admin UI = Blade + jQuery + Bootstrap 5 + original CSS
   (`theme-dark.css`). Reuse design-system components; never hardcode styles.
2. **English everywhere** — identifiers, comments, UI strings, docs.
3. **Never break the wire protocol.** JSON keys and `/api/*` paths the client speaks are
   fixed (contract doc). English renames apply to PHP identifiers, not the protocol.
4. **Destructive actions need confirmation.** Both in the product (confirm modals for
   delete) and in the repo (sign-off before deleting tracked code or data — it's recoverable via git, but confirm intent).
5. **Verify in Docker.** The host lacks Composer/Node; use the toolchain image / dev stack.
   A change isn't "done" until it runs green there (lint + tests + E2E where relevant).
6. **Plan multi-step work** under `DevOps/plans/` using the [template](DevOps/plans/template-plan.md).

## ✅ Mandatory wrap-up protocol
When a task/feature is complete (or the user says "wrap up", "ship it", "we're done", etc.):

1. **Changelog:** append a row to [DevOps/logs/agent-changelog.md](DevOps/logs/agent-changelog.md):
   ```markdown
   ## [YYYY-MM-DD HH:MM] - [Task Name]
   **Agent:** rustdesk-api ([Model Name])
   **Files Modified:**
   - `path/...`
   **Database/API Changes:** None | [describe]
   **Summary:** One sentence.
   ```
2. **Docs sync:** update any `Wiki/` or `docs/modernization/` file whose described behavior
   changed (incl. [09-port-status.md](docs/modernization/09-port-status.md) and the
   [build log](docs/modernization/08-build-log.md)).
3. **Version history:** if a deploy/push happened, log it in
   [DevOps/logs/version-history.md](DevOps/logs/version-history.md).
4. **Archive plans:** move finished plans from `DevOps/plans/` to `DevOps/archive-plans/`.

## 🛠️ Build & test (quick reference)
```bash
docker build -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .
docker compose -f docker/compose.toolchain.yml up -d
docker compose -f docker/compose.toolchain.yml run --rm app composer install
docker compose -f docker/compose.toolchain.yml run --rm app php artisan migrate
```
Stack: Laravel 13 (PHP 8.5) · Blade + jQuery + Bootstrap 5 · MariaDB/SQLite · Mailpit (SMTP
testing) · Playwright (E2E) · Pint/PHPStan/ESLint (gates).
