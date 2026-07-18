# Version History & Policy

This log records formal releases, deployments, and reviewed main-branch pushes for
RD-API-Server.

## 📌 Semantic Versioning

Public releases use `vMAJOR.MINOR.PATCH` Git tags and standard Semantic Versioning:

1. **Major** (`v1.0.0` → `v2.0.0`): incompatible application, deployment, database, or client-contract boundaries.
2. **Minor** (`v1.0.0` → `v1.1.0`): backward-compatible features and substantial new workflows.
3. **Patch** (`v1.0.0` → `v1.0.1`): backward-compatible fixes, hardening, and maintenance.

Main-branch pushes do not automatically create releases or increment the application version.
A release requires an explicitly reviewed version change, a verified release commit, an annotated
tag, published versioned container images, and a GitHub Release.

---

## Main Branch Delivery Log

| Date | Version Impact | Actor | Delivery |
|---|---|---|---|
| 2026-07-17 | v1.0.0 | OpenAI Codex / GPT-5 | Prepared the first stable release with centralized application-version metadata, public release notes, completed HTTPS proxy recovery records, and an updated operator guide. Production now trusts its exact application-observed 1Panel peer, emits HTTPS redirects/assets, sets secure login cookies, and passes the public edge checker. |
| 2026-07-17 | Unchanged | OpenAI Codex / GPT-5 | User-authorized source delivery `52410f9` and `4c28f08` restricted the trusted forwarded-header surface and added HTTPS proxy diagnostics, safe deployment guidance, secure-cookie configuration, and a fail-closed public smoke check. The full Docker matrix passed 538 PHPUnit tests / 3,051 assertions plus static and packaging gates; GitHub CI run `29623089296` and Docker Publish run `29623089305` also completed successfully. No formal release or production deployment was performed; the public origin still requires its application-observed proxy IP/CIDR before the mixed-content incident is resolved. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | CI-maintenance push `22dbe25` replaced deprecated Node 20 checkout/setup actions with immutable Node 24-native v7.0.0 pins. GitHub CI run `29470504915` and multi-architecture Docker Publish run `29470504901` passed without the prior deprecation annotation. No formal release or application deployment was performed. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | User-authorized completion push to `origin/main` for the independently revertible WebUI modernization, MariaDB-only boundary, review remediation, and security hardening series. Final Docker gates passed 532 PHPUnit tests / 3,018 assertions and 68 Playwright tests with 12 intentional skips; static, packaging, and dependency-audit gates were green. No deployment or formal release was performed. |

## 📈 Release Log

| Version | Date | Level | Deployer | Key Release Highlights / Milestones |
|---|---|---|---|---|
| **v1.0.0** | 2026-07-17 | Major | GigaionLLC / OpenAI Codex | First stable PHP 8.5/Laravel release with the modern WebUI, RustDesk-compatible client and administration APIs, MariaDB/InnoDB-only runtime, explicit reverse-proxy trust, and multi-architecture Docker images. |
