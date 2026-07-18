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
| 2026-07-18 | Unchanged (`v1.1.0` candidate) | OpenAI Codex / GPT-5 | User-authorized reviewed main-branch delivery of release-channel guard `ac293f9`, Nginx/PHP-FPM candidate `2627aa6`, source-parity capacity harness `5eb304e`, native runtime CI `1850b9a`, and independently revertible runtime package cleanup `d51c0da`. Full local quality gates and native AMD64 runtime smoke passed. Main publishes only a full-commit SHA candidate tag; stable `latest` remains v1.0.1 pending the complete 1,800-RPS matrix, native ARM64 result, and public 1Panel canary. No application version, schema, API, storage, port, or proxy-contract change. |
| 2026-07-17 | v1.0.1 | OpenAI Codex / GPT-5 | Published verified commit `8a3f708` as annotated tag `v1.0.1` and the [GitHub Release](https://github.com/GigaionLLC/RD-API-Server/releases/tag/v1.0.1). Main CI run `29627965974` and tag CI run `29628204395` passed the complete quality matrix, native AMD64/ARM64 digest smoke tests, and final-manifest verification. GHCR SemVer tags resolve to manifest digest `sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2`. The new pipeline completed in 7 minutes 11 seconds on main and 5 minutes 49 seconds warm on the tag. No database schema or RustDesk wire-contract change. |
| 2026-07-17 | v1.0.0 | OpenAI Codex / GPT-5 | Published verified commit `026b841` as annotated tag `v1.0.0` and the first stable [GitHub Release](https://github.com/GigaionLLC/RD-API-Server/releases/tag/v1.0.0). CI run `29626539704`, main-image run `29626539712`, and tag-image run `29626681147` passed. GHCR tags `1.0.0`, `1.0`, and `1` contain AMD64 and ARM64 at digest `sha256:512c1fb8b40ff72cb71fe1a66c872198741f1ac6d08a4c0c0f00ee5877949705`. Production also passes the public HTTPS proxy checker after trusting its exact application-observed 1Panel peer and enabling secure cookies. |
| 2026-07-17 | Unchanged | OpenAI Codex / GPT-5 | User-authorized source delivery `52410f9` and `4c28f08` restricted the trusted forwarded-header surface and added HTTPS proxy diagnostics, safe deployment guidance, secure-cookie configuration, and a fail-closed public smoke check. The full Docker matrix passed 538 PHPUnit tests / 3,051 assertions plus static and packaging gates; GitHub CI run `29623089296` and Docker Publish run `29623089305` also completed successfully. No formal release or production deployment was performed; the public origin still requires its application-observed proxy IP/CIDR before the mixed-content incident is resolved. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | CI-maintenance push `22dbe25` replaced deprecated Node 20 checkout/setup actions with immutable Node 24-native v7.0.0 pins. GitHub CI run `29470504915` and multi-architecture Docker Publish run `29470504901` passed without the prior deprecation annotation. No formal release or application deployment was performed. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | User-authorized completion push to `origin/main` for the independently revertible WebUI modernization, MariaDB-only boundary, review remediation, and security hardening series. Final Docker gates passed 532 PHPUnit tests / 3,018 assertions and 68 Playwright tests with 12 intentional skips; static, packaging, and dependency-audit gates were green. No deployment or formal release was performed. |

## 📈 Release Log

| Version | Date | Level | Deployer | Key Release Highlights / Milestones |
|---|---|---|---|---|
| **[v1.0.1](https://github.com/GigaionLLC/RD-API-Server/releases/tag/v1.0.1)** | 2026-07-17 | Patch | GigaionLLC / OpenAI Codex | Dark mode defaults on first visit while saved light mode persists; runtime images reuse one PHP extension layer and publish from native AMD64/ARM64 runners only after exact-commit quality and digest smoke gates pass. AMD64/ARM64 images are published at manifest digest `sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2`. |
| **[v1.0.0](https://github.com/GigaionLLC/RD-API-Server/releases/tag/v1.0.0)** | 2026-07-17 | Major | GigaionLLC / OpenAI Codex | First stable PHP 8.5/Laravel release with the modern WebUI, RustDesk-compatible client and administration APIs, MariaDB/InnoDB-only runtime, explicit reverse-proxy trust, and AMD64/ARM64 Docker images at manifest digest `sha256:512c1fb8b40ff72cb71fe1a66c872198741f1ac6d08a4c0c0f00ee5877949705`. |
