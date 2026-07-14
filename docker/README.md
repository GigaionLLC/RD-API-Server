# Build dependency pins

The build and Compose definitions pin third-party images to an exact release tag and the
corresponding multi-architecture manifest digest. A tag documents the intended version; the
digest prevents a registry tag from silently resolving to different bytes later.

## Current pins

| Input | Version | Multi-architecture digest |
|---|---:|---|
| PHP CLI | 8.5.8 Bookworm | `sha256:fb740987f3e7aefd7f52d1f961fa91602874f2b6a5b0bf0105725f8987b54bee` |
| PHP Apache | 8.5.8 Bookworm | `sha256:76f447018df51801eb0587bdced331709c2d7ac4e0bb8b9cb00bd4f93dd85d1c` |
| Node.js | 24.18.0 Bookworm slim | `sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d` |
| Composer | 2.10.2 | `sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760` |
| PHP extension installer | 2.11.12 | `sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138` |
| MariaDB | 11.8.8 | `sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4` |
| Mailpit | 1.30.4 | `sha256:5a49a77c5bdbe7c5474450b4f46348d09949df3695257729c93a30369382d4f6` |
| RustDesk server (full-stack example) | 1.1.15 | `sha256:10818ec05b179039c6660f4d8e74b303f0db2858bbad2b18e24992ea22d54cd6` |

The toolchain installs `playwright@1.61.0` explicitly. This must match the resolved
`node_modules/playwright` version in `package-lock.json`, because each Playwright release expects
a specific browser revision. Node 24 is used because the [official Node.js release
schedule](https://github.com/nodejs/Release) marks Node 20 as end-of-life and Node 24 as an
active LTS release.

The Debian packages installed with `apt` intentionally follow the signed Bookworm repositories
instead of freezing individual package revisions that those repositories may retire. The image
digests, application dependency locks, and exact standalone tool versions remain fixed; rebuilds
can still receive Debian security updates. Never replace the pinned official Node image with a
downloaded repository setup script or execute a remote response through a shell.

## GitHub Actions

Every third-party `uses:` entry under `.github/workflows/` is pinned to a full commit SHA, with
its intended major version retained as an inline comment. When updating an action, resolve the
major tag from the action's official upstream Git repository, use the peeled commit for an
annotated tag, review that commit's release notes and diff, and update every matching workflow
reference together. Do not replace a full SHA with a movable tag.

## First-party application image

The published `ghcr.io/gigaionllc/rustdesk-api-server:latest` reference is intentionally the
project's update channel, so it cannot be pinned in this repository without pointing new releases
back at an older build. Operators who require a fully locked deployment can set
`RUSTDESK_API_IMAGE` to a published tag and digest before running Compose, for example:

```bash
RUSTDESK_API_IMAGE='ghcr.io/gigaionllc/rustdesk-api-server:sha-abcdef@sha256:<64-hex-digest>' \
  docker compose up -d
```

## Updating a pin

1. Select a supported, stable release from the upstream project's official release page. Update
   the readable version tag first; never retain a digest copied from a different tag.
2. Resolve the tag's manifest-list digest (not a platform-specific child manifest):

   ```bash
   docker buildx imagetools inspect node:24.18.0-bookworm-slim
   ```

   Confirm that the output includes both `linux/amd64` and `linux/arm64`, then copy the top-level
   `Digest` value into every matching Docker or Compose reference.
3. Search for stale broad or mutable references:

   ```bash
   rg 'FROM .*:(latest|[0-9]+($|-))|image: .*:(latest|[0-9]+$)|playwright@latest|curl .*[|] *bash' \
     --glob 'Dockerfile*' --glob 'docker-compose*.yml' --glob 'docker/**' --glob 'examples/**' .
   ```

   The only expected non-third-party `latest` reference is this project's own GHCR release
   channel described above.
4. If Playwright changes, update `package-lock.json` and the exact toolchain version together.
   Check the lock value with:

   ```bash
   docker run --rm -v "$PWD":/app -w /app \
     node:24.18.0-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d \
     node -p "require('./package-lock.json').packages['node_modules/playwright'].version"
   ```
5. Rebuild and verify from clean inputs:

   ```bash
   docker build --pull --no-cache -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .
   docker run --rm rustdesk-api-php-toolchain bash -lc \
     'php -v && composer --version && node --version && npm --version && playwright --version && playwright install --dry-run chromium'
   docker build --pull --no-cache -f docker/Dockerfile.runtime -t rustdesk-api:pin-test .
   docker compose config --quiet
   docker compose -f docker-compose.dev.yml config --quiet
   docker compose -f docker/compose.toolchain.yml config --quiet
   docker compose -f examples/full-stack.docker-compose.yml config --quiet
   ```

For a release, also validate the runtime image for both published architectures with Buildx before
updating the pin table.
