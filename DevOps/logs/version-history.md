# Version History & Policy

This log records formal releases, deployments, reviewed main-branch pushes, and the 3-level
versioning strategy enforced across the [APP_NAME] environment.

## 📌 Versioning Strategy (3-Level System)

All versioning follows the semantic hierarchy configured within the `/Test-and-Deploy` pipeline:

1. **Level 1 (Major)**: User-directed primary versions (e.g., `1.02.003` -> `2.00.000`). Triggered for fundamental architectural updates, paradigm shifts, or major product milestones. Resets both minor and patch levels to double/triple zero padding.
2. **Level 2 (Minor)**: New feature versions (e.g., `1.02.003` -> `1.03.003`). Adds `.01` to Level 2 versioning (allowing up to 99 level 2 versions), while preserving the patch level as-is. Prompted and confirmed when introducing discrete new features, capabilities, or major screen flows.
3. **Level 3 (Patch)**: Automated deployment versions (e.g., `1.02.003` -> `1.02.004`). Adds `.001` to Level 3 versioning (allowing up to 999 level 3 versions), while preserving the minor level as-is. Automatically bumped on every routine code deployment or patch push if no major/minor bump is specified.

---

## Main Branch Delivery Log

| Date | Version Impact | Actor | Delivery |
|---|---|---|---|
| 2026-07-17 | Unchanged | OpenAI Codex / GPT-5 | User-authorized source delivery `52410f9` and `4c28f08` restricted the trusted forwarded-header surface and added HTTPS proxy diagnostics, safe deployment guidance, secure-cookie configuration, and a fail-closed public smoke check. The full Docker matrix passed 538 PHPUnit tests / 3,051 assertions plus static and packaging gates. No formal release or production deployment was performed; the public origin still requires its application-observed proxy IP/CIDR before the mixed-content incident is resolved. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | CI-maintenance push `22dbe25` replaced deprecated Node 20 checkout/setup actions with immutable Node 24-native v7.0.0 pins. GitHub CI run `29470504915` and multi-architecture Docker Publish run `29470504901` passed without the prior deprecation annotation. No formal release or application deployment was performed. |
| 2026-07-15 | Unchanged | OpenAI Codex / GPT-5 | User-authorized completion push to `origin/main` for the independently revertible WebUI modernization, MariaDB-only boundary, review remediation, and security hardening series. Final Docker gates passed 532 PHPUnit tests / 3,018 assertions and 68 Playwright tests with 12 intentional skips; static, packaging, and dependency-audit gates were green. No deployment or formal release was performed. |

## 📈 Release Log

| Version | Date | Level | Deployer | Key Release Highlights / Milestones |
|---|---|---|---|---|
| **v1.0.0** | YYYY-MM-DD | Major | [Deployer Name] | Initial stable release of the unified ecosystem. |
