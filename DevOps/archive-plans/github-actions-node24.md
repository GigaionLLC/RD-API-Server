# Parcel Plan: GitHub Actions Node 24 Runtime

**Archived:** 2026-07-15 after successful GitHub CI and image-publish verification.

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `COMPLETE` |
| **Active Persona** | CI maintenance implementer |
| **Last Updated** | 2026-07-15 21:45 PDT |

## Intent

Remove GitHub Actions' Node 20 deprecation annotations without changing the application's
selected Node version, test behavior, release triggers, or deployment behavior.

## Scope

- Replace every `actions/checkout` pin with the immutable Node 24-compatible v7.0.0 commit.
- Replace every `actions/setup-node` pin with the immutable Node 24-compatible v7.0.0 commit.
- Keep the existing explicit Node 24.18.0 project runtime and npm cache configuration.
- Verify exact upstream tag/SHA mappings and `runs.using: node24` metadata.
- Remove the pre-existing unused readiness-loop counter reported by workflow shell lint.
- Lint both workflow files, rerun the affected local JavaScript/vendor gates, document, commit,
  push `main`, and inspect the resulting GitHub Actions run.

## Out of Scope

- Application, database, API, Docker image content, workflow trigger, or permission changes.
- Re-running or rewriting the already-successful historical workflow run.

## Checklist

- [x] Confirm the linked annotations and current workflow pins.
- [x] Audit all repository action runtimes.
- [x] Update the affected immutable pins.
- [x] Run workflow lint, pin audit, JavaScript lint, and vendor-integrity validation.
- [x] Synchronize logs and archive this plan.
- [x] Commit, push, and inspect the new run.

## Verification

- `actions/checkout` v7.0.0 resolves to
  `9c091bb21b7c1c1d1991bb908d89e4e9dddfe3e0`; `actions/setup-node` v7.0.0 resolves to
  `820762786026740c76f36085b0efc47a31fe5020`. Both exact manifests declare Node 24.
- Local actionlint 1.7.12, ESLint, the 20-file vendor-integrity check, the seven-reference pin
  audit, and diff checks passed.
- GitHub CI run `29470504915` passed all PHP, JavaScript/vendor, and Playwright jobs. Its complete
  logs contain no Node 20 deprecation text.
- GitHub Docker Publish run `29470504901` passed every step and published both architectures in
  39 minutes 36 seconds. Its complete logs contain no Node 20 deprecation text.
- The obsolete older publish run `29470043683` was cancelled so it could not overwrite the newer
  commit's `latest` image tag after this verified publish.

## Completion Note

Commit `22dbe25` updates only the two workflow files and this plan. The project runtime remains
Node 24.18.0, action references remain immutable SHA pins, workflow triggers and permissions are
unchanged, and no application, database, or RustDesk wire behavior changed.
