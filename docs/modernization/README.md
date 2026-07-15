# RustDesk‑API Modernization & Feature Program

> **Goal:** modernize `rustdesk-api`, make it intuitive, and close the gap with the
> features the RustDesk client already speaks and that **RustDesk Server Pro** offers —
> using only what an open‑source API server can legitimately provide.

> **Historical research set:** the modernization succeeded. The repository is now a single-stack
> PHP 8.5 / Laravel 13 application with a Blade + jQuery admin console and MariaDB/InnoDB as its
> only database. The Go/Gin/GORM application, Vue frontend, multi-database claims, and “missing”
> feature labels below describe the retired pre-rewrite baseline; they are not current support or
> implementation statements. Start with [AGENT.md](../../AGENT.md) and the
> [current architecture index](../../Wiki/core/00-system-index.md) for active work.

This folder is the working knowledge base for that effort. It was produced from a deep
dive across three repositories plus the official Pro documentation:

| Source | What we mined it for |
|--------|----------------------|
| Retired Go `rustdesk-api` baseline | Routes, models, services, auth, and config before the PHP rewrite. |
| `rustdesk` (the client, Rust) | The **HTTP contract** the client actually speaks — the real spec we must satisfy. |
| `rustdesk-server-pro` (install scripts) | How Pro is deployed (`hbbs`/`hbbr`, ports, license). The server itself is closed‑source. |
| `rustdesk-api-server-pro` (lantongxue, MIT) | A second open‑source API server — reference implementation; already solves several of our gaps (see doc 06). |
| [Pro docs](https://rustdesk.com/docs/en/self-host/rustdesk-server-pro/) | The authoritative Pro feature catalog. |

## Documents in this set

1. **[01-architecture-and-current-state.md](01-architecture-and-current-state.md)** —
   Frozen architecture inventory of the retired Go baseline used to plan the rewrite.
2. **[02-client-api-contract.md](02-client-api-contract.md)** —
   The endpoints/JSON the **client** expects. This is the implementation spec; build to it.
3. **[03-pro-feature-catalog.md](03-pro-feature-catalog.md)** —
   Every RustDesk Server Pro feature, with the keys/flows behind each.
4. **[04-gap-analysis.md](04-gap-analysis.md)** —
   Feature‑by‑feature: have / partial / missing, with effort & value ratings.
5. **[05-roadmap-and-implementation.md](05-roadmap-and-implementation.md)** —
   Prioritized roadmap with concrete implementation notes mapped to this repo's files.
6. **[06-reference-implementations.md](06-reference-implementations.md)** —
   Historical comparison with other open‑source RustDesk API servers and the design ideas
   evaluated during the rewrite.

## Historical executive summary (pre-rewrite)

At the time of the original research, `rustdesk-api` was a strong, multi‑DB
(SQLite/MySQL/PostgreSQL), Gin/GORM
re‑implementation of the RustDesk API server. It covers **login (password / OAuth /
OIDC / LDAP), personal & shared address books, tags, groups, device groups, peer/device
listing, connection & file audit logs, login logs, guest web‑client sharing, server
commands to hbbs/hbbr, a web admin, and a web client.**

The biggest opportunities recorded then — features the **client already supported** but the
retired Go baseline did **not** implement — were:

| # | Capability | Status in retired Go baseline | Why it matters |
|---|------------|--------------|----------------|
| 1 | **Strategy / Settings sync** (push client config via heartbeat) | ❌ absent | The single highest‑value Pro feature. Client is ready; server just never replies with `strategy`. |
| 2 | **2FA (TOTP) + email login verification** | ❌ absent | Client sends/expects `tfa_type`, `secret`, `email_check`, device whitelist. |
| 3 | **SMTP / email subsystem** | ❌ absent | Unlocks #2, invitations, password reset, and alarm notifications. |
| 4 | **Device deployment & approval** (`/api/devices/deploy`, `--deploy`, `ID_NOT_FOUND` gating) | ❌ absent | Controlled onboarding of new devices. |
| 5 | **Preset auto‑registration** (`OPTION_PRESET_*` in sysinfo) | ❌ ignored | Auto‑file devices into address book / strategy / device‑group on first contact. |
| 6 | **Session‑recording upload** (`/api/record`) | ❌ absent | Client streams recordings to this endpoint; nothing receives them. |
| 7 | **Granular access control & roles** (user‑group cross access, device‑group access, control roles, admin roles) | ⚠️ only `is_admin` + basic groups | Needed for teams/MSPs. |
| 8 | **Scoped API tokens + CLI** | ⚠️ session tokens only | Automation parity with Pro's `*.py` CLI. |
| 9 | **Force‑disconnect / live sessions** (heartbeat `conns`/`disconnect`) | ❌ absent | Heartbeat already carries the data. |
| 10 | **Multiple relays + geo routing** | ⚠️ single relay + manual cmd | Depends on hbbs; partial via server‑cmd today. |

See **[04-gap-analysis.md](04-gap-analysis.md)** for the full table and
**[05-roadmap-and-implementation.md](05-roadmap-and-implementation.md)** for sequencing.

## How to use this set

- Implementing client-facing behavior? Use **02** for the wire contract, then verify current
  status and PHP locations through [AGENT.md](../../AGENT.md); do not follow old Go paths as an
  implementation guide.
- Planning? Treat **04–06** as historical design research and confirm every gap against the
  [current port status](09-port-status.md) and architecture knowledge base first.
- The line references in these docs (e.g. `http/controller/api/index.go:41`) were accurate
  in the retired Go checkout at the time of writing; those files are no longer in this repository.
