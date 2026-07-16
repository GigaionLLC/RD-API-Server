# Parcel Plan: GitHub Actions Node 24 Runtime

## State Dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `IN PROGRESS` |
| **Active Persona** | CI maintenance implementer |
| **Last Updated** | 2026-07-15 21:03 PDT |

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
- [ ] Synchronize logs and archive this plan.
- [ ] Commit, push, and inspect the new run.
