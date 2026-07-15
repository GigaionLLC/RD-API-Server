# Parcel Plan: MariaDB-Only Database Support

**Archived:** 2026-07-14 after complete implementation and verification.

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `COMPLETE` |
| **Version** | `v1.0.0` |
| **Active Persona** | Runtime, test-infrastructure, and documentation maintainer |
| **Last Updated** | 2026-07-14 23:10 PDT |

---

## 1. Phase 1: Expansion & Scoping

**Intent:** Make MariaDB the sole supported application and test database, eliminating the
SQLite fallback and the extra compatibility/testing burden it creates.

**In scope:**

- MariaDB-only runtime configuration, container entrypoint, PHP extensions, and dependencies.
- A dedicated ephemeral MariaDB test service/schema that cannot wipe the persistent dev schema.
- MariaDB services for PHPUnit, browser tests, CI, and screenshot fixtures.
- Active operator/developer documentation and a clear SQLite upgrade boundary.
- Full MariaDB verification and one isolated local commit.

**Out of scope:**

- An automated SQLite-to-MariaDB data converter.
- Rewriting immutable changelog history or retired reference-project facts.
- Supporting Oracle MySQL, PostgreSQL, SQL Server, or another database engine.
- Pushing commits or editing the untracked user-owned `AGENTS.md`.

## 2. Phase 2: Requirements & Context

**Relevant code/docs:** `config/database.php`, `docker/entrypoint.sh`, both Dockerfiles,
`docker/compose.toolchain.yml`, `phpunit.xml`, `tests/TestCase.php`, `.github/workflows/ci.yml`,
`docker/demo-shots.sh`, root/runtime Compose files, `AGENT.md`, README/Quick Start/development
guides, and the database/security architecture Wiki.

**Safety finding:** The old Compose-backed PHPUnit command can inherit `rustdesk_api` and run
destructive refresh migrations against the persistent development schema. The replacement must
force the exact `rustdesk_api_testing` database and validate the live server/database before any
test migration hook runs.

## 3. Phase 3: User Clarification

- [x] MariaDB is the only supported database going forward.
- [x] Remove SQLite from code, tooling, tests, and active documentation.
- [x] Keep this work in its own commit on `main`; do not push.

## 4. Phase 4: Detailed Execution Plan

1. Set `mariadb` as the only configured/default connection; remove runtime SQLite fallback and
   reject any other driver before migrations.
2. Remove SQLite packages/extensions/scripts, require `ext-pdo_mysql`, and update the lock file.
3. Add a health-checked, tmpfs-backed MariaDB test service and a dedicated test runner service;
   keep the persistent developer database separate.
4. Force safe PHPUnit environment values and add a pre-migration guard that proves the selected
   database is exactly `rustdesk_api_testing` on a MariaDB server.
5. Move DDL migration coverage out of transaction-backed tests where MariaDB implicit commits
   would corrupt the harness.
6. Convert PHP/E2E CI and screenshot fixtures to isolated MariaDB databases.
7. Update Compose examples, active docs, architecture/build/port status, and add an explicit
   breaking-change warning for SQLite operators.
8. Validate deliberate unsafe overrides fail closed, build both images, run the complete PHPUnit,
   static/style/dependency/Blade/JavaScript/Playwright gates, and verify all Compose surfaces.
9. Append the required changelog entry, archive this plan, and commit as `build: require MariaDB`.

## 5. Phase 5: Product Owner Review

**Status:** `APPROVED`

- The user explicitly selected MariaDB-only support on 2026-07-14.
- SQLite data conversion is not implied; existing SQLite operators must migrate before upgrading
  or stay on the last compatible release.

## 6. Phase 6: Senior Dev Hygiene Review

**Status:** `COMPLETE`

- Preserve MariaDB-required names such as `pdo_mysql`, the PDO `mysql:` DSN, and
  `/var/lib/mysql`; remove support claims/driver settings, not valid implementation details.
- Retain ignore rules for legacy SQLite files as data-leak safeguards.
- Never let tests infer a database from `.env` or a persistent Compose service.
- Validate both loaded configuration and the live server so native Artisan paths cannot reach
  Oracle MySQL, the wrong schema, a non-InnoDB default, or legacy table engines.

## 7. Phase 7: Implementation Checklist

- [x] Require MariaDB in runtime/config/dependencies.
- [x] Isolate and guard the test database.
- [x] Convert CI and screenshot tooling.
- [x] Update active docs and migration warning.
- [x] Run complete MariaDB-only verification.
- [x] Archive plan, log work, and create the isolated local commit.

## 8. Phase 8: Verification Dashboard

**Verification Status:** `PASSED`

- [x] Unsafe database overrides cannot reach a non-test schema; stale cached settings and a live
  MyISAM table target also fail closed before migrations.
- [x] Full PHPUnit suite passes on MariaDB: 466 tests / 2,464 assertions.
- [x] Runtime/toolchain images contain `pdo_mysql`, install no SQLite packages/extensions, and
  reject every non-MariaDB connection (inert modules bundled by the upstream PHP image do not
  constitute support).
- [x] Browser/E2E and screenshot paths run on isolated MariaDB/InnoDB schemas: 68 Playwright
  passes / 12 intentional skips and 1 dedicated screenshot capture pass.
- [x] Pint, PHPStan, Composer/npm audits, JavaScript/vendor, Blade, shell, Compose, runtime image,
  and negative boundary checks pass.
- [x] Active docs contain no SQLite support guidance beyond the explicit retired-path migration
  boundary and historical reference-project facts.

## 9. Phase 9: User Verification

**Status:** `READY FOR REVIEW`

The final handoff will list the independent commit for review/revert and confirm no push occurred.

## 10. Phase 10: Wrap Up & Archival

Updated the database architecture Wiki, modernization build/port status, developer/operator docs,
and newest-first agent changelog. Archived this plan after every gate passed.

## Completion Note

Completed and verified on 2026-07-14. The MariaDB-only boundary is packaged in the independent
local `build: require MariaDB` commit on `main`; nothing was pushed.
