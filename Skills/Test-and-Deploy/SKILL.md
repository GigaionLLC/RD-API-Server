---
name: Test-and-Deploy
description: Make sure to use this skill whenever the user mentions running tests, checking lint rules, formatting, pushing to GitHub, creating a release, or deploying commits. It defines the repository's Docker-first verification and Semantic Versioning release process.
---

# RD-API-Server Test, Release, and Deployment Pipeline

Follow `AGENT.md` first. The host is not the supported PHP/Node toolchain; verification runs in
the repository's Docker services or `rustdesk-api-php-toolchain` image.

## 1. Establish scope

1. Inspect `git status --short --branch`, the current branch, and the remote before editing.
2. Preserve unrelated user changes and stage only files that belong to the requested delivery.
3. Read the active plan and latest `DevOps/logs/agent-changelog.md` entries for multi-step work.

## 2. Verify changes

Choose gates in proportion to the change, then run the full release matrix before a formal tag:

```bash
docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain ./vendor/bin/pint --test
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain ./vendor/bin/phpstan analyse
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain npm run lint:js
docker run --rm -v "$PWD":/app -w /app rustdesk-api-php-toolchain npm run check:vendor
docker compose -f docker/compose.toolchain.yml --profile e2e run --rm e2e bash docker/e2e.sh
```

Also validate Compose rendering, checked-in vendor integrity, shell syntax, and the runtime image
when their inputs change. Never run database tests against persistent development or production
data.

## 3. Version and release records

RD-API-Server uses standard Semantic Versioning and annotated `vMAJOR.MINOR.PATCH` Git tags.
The application version is source-controlled in `config/app.php`, not in an operator environment
file or `package.json`.

1. Major versions introduce incompatible application, deployment, database, or contract changes.
2. Minor versions add backward-compatible features.
3. Patch versions contain backward-compatible fixes and maintenance.

For a formal release, synchronize `config/app.php`, `CHANGELOG.md`, the version-specific release
note, `DevOps/logs/version-history.md`, and the mandatory agent changelog. Main-branch pushes alone
do not increment the public version.

## 4. Commit and push

1. Review `git diff --check` and the complete intended diff.
2. Stage explicit paths, commit with a concise conventional message, and follow the branch policy
   explicitly requested by the user.
3. Push only after local gates pass, then wait for the matching GitHub `CI` run. Its image jobs
   start only after PHP, JavaScript, vendor-integrity, and browser gates pass for the exact commit.

## 5. Publish a release

1. Create an annotated `vMAJOR.MINOR.PATCH` tag on the verified release commit and push it.
2. Wait for the tag-triggered `CI` graph to pass both native architecture builds and final
   manifest verification. Confirm the version, minor, major, and commit-SHA image tags exist.
3. Create the GitHub Release from the existing verified tag using the checked-in release notes.
4. Record workflow IDs, image results, tag, and release URL in the operational logs.
