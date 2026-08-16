# Changelog

Notable changes to RD-API-Server are recorded here. Release tags follow Semantic Versioning;
operational agent records remain in `DevOps/logs/` and are not a substitute for public release
notes.

## [1.4.2] - 2026-08-16

### Added

- **A Remote control screen.** Connect to any machine by its RustDesk ID, with the server details
  filled in from this deployment's own configuration — an operator never types an ID server, a key
  or an endpoint. An ID that is not in the device list is accepted from an operator whose device
  permission is unrestricted, which is what a support desk needs when an ID arrives over the phone;
  anyone scoped to a group keeps that boundary, because otherwise typing an ID by hand would be a
  way around it.

### Changed

- **Nothing connects on its own, and there is no longer any way to make it.** A remote desktop
  session is visible on the other machine and interrupts whoever is sitting at it, so starting one
  must be a decision rather than a side effect of opening a page. **Connect** on a device now opens
  the Remote control screen with that ID filled in and waits. The viewer's auto-connect handling was
  removed outright rather than defaulted off, so no configuration value and no URL can bring it
  back; a browser test asserts that no socket is opened without a click.

### Fixed

- **The viewer rendered as a 300×150 thumbnail** with its toolbar wrapped into a column. Its
  stylesheet was pushed to a Blade stack the layout does not render, so every rule was silently
  discarded and the iframe fell back to its intrinsic size. This affected the diagnostics page too.
- Configuration injection into the viewer document is now verified. When it silently found nothing
  to replace, the viewer fell back to its manual connection form and asked the operator for server
  details they should never have to know; that now raises an error naming the cause.
- Compiled Blade views are cleared before they are rebuilt at start-up. `storage/` is a persistent
  volume, so views compiled by a previous image survive an upgrade, and `view:cache` does not remove
  what it finds there.

## [1.4.1] - 2026-08-16

### Added

- **The browser remote desktop can now be stood up without touching a reverse proxy.** Setting
  `RUSTDESK_WS_ID_UPSTREAM` and `RUSTDESK_WS_RELAY_UPSTREAM` — `host:port` as reachable from the
  container, so on a Compose network the service names — makes this runtime serve `/ws/id` and
  `/ws/relay` on the console's own hostname and certificate and forward them to `hbbs` and `hbbr`.
  The viewer derives its endpoints from `APP_URL`, so nothing else needs configuring: no second
  certificate, no extra public ports, and no hand-written WebSocket vhost.

  The trade-off is stated wherever the option is offered: this container joins the media path,
  which the direct arrangement exists to avoid. Explicit `RUSTDESK_WS_*_URL` values still win, so
  moving between the two is a configuration change rather than a redeployment.

  This also removes the easiest way to break the feature by hand. `hbbs` overwrites a connection's
  address with `X-Real-IP` — falling back to `X-Forwarded-For`, unvalidated — and keys its
  pending-response map on the result, so a proxy that forwards those headers makes concurrent
  operators behind one address take each other's connections. The runtime blanks them.

- **A Remote desktop page** under System reports every condition that would otherwise present as
  "cannot connect" against a server that is running perfectly well: viewer assets, ID server,
  server key, secure context, whether a forwarded HTTPS header is actually trusted, which transport
  is configured, and whether the upstreams answer from inside the container. It also probes the
  endpoint from the operator's own browser — the check that matters, and the only one the server
  cannot make on their behalf.

### Fixed

- An HTTPS console with no WebSocket endpoints configured rendered a viewer that looked ready and
  then failed to connect, which reads as an unreachable server rather than a missing setting. The
  device page now says which values are missing.

- Server-side tests for the viewer routes, which shipped in 1.4.0 without any: both routes re-run
  the operator's device scope, the iframe document is gated exactly like the page that embeds it,
  and no peer password appears in the injected configuration.

## [1.4.0] - 2026-08-16

### Added

- **Browser remote desktop.** A **Connect** action on each device opens a full remote desktop in
  the browser — screen, sound, mouse, keyboard, clipboard and chat — with no plugin, no download
  and no client install. The page speaks the RustDesk protocol directly to `hbbs` and `hbbr` over
  WebSocket, so this server is never in the media path and the feature adds no long-running process
  to operate.

  Working today: video over VP8, VP9, AV1, H.264 and H.265; switching between the peer's monitors;
  the remote cursor; Opus audio; mouse, keyboard, wheel and drag, with Ctrl+W and Escape reaching
  the remote machine in fullscreen; clipboard text and HTML in both directions; and chat with
  whoever is at the far end.

  Not yet implemented: **file transfer** and **terminal**. Direct peer-to-peer is not possible from
  a browser, so **every session is relayed** — budget relay bandwidth before offering this widely.

  Requires **Chrome or Chromium on desktop**, and a **secure context**: an HTTPS console needs a
  TLS terminator in front of ports 21118 and 21119, configured through `RUSTDESK_WS_ID_URL` and
  `RUSTDESK_WS_RELAY_URL`. The peer's own connection password is entered in the viewer; this server
  neither holds nor transmits it. See **[docs/web-client-deployment.md](docs/web-client-deployment.md)**
  for nginx and Caddy configuration and two traps worth reading before deploying.

  The session survives a dropped connection: a relayed link is not a reliable one, so the viewer
  reconnects with exponential backoff and full jitter. What it will **not** retry is the point —
  a wrong password would spend the peer's remaining attempts toward a lockout, and a refused
  encryption downgrade would invite whatever stripped it to keep asking.

  What the peer says is shown. Its message boxes — "waiting for the user to accept", a locked-out
  account, an elevation refusal — appear as notices instead of being dropped, and so do permission
  denials, which are signalled only as negatives and previously made a peer with keyboard control
  switched off indistinguishable from a broken client.

  **Windows elevation** is handled. A UAC prompt runs on the secure desktop, where the operating
  system discards injected input by design, and an elevated foreground window ignores input from a
  lower-integrity session — both of which look like a frozen screen. The viewer names the state and
  offers either a consent prompt at the remote machine or administrator credentials.

  The viewer is a standalone package under `web-client/` with no build step. The Docker image
  publishes it during the build; a source deployment runs
  `node web-client/scripts/install-assets.mjs` after upgrading.

- **A contributor assignment agreement** ([CLA.md](CLA.md)) and
  [CONTRIBUTING.md](CONTRIBUTING.md). Both are drafts pending legal review, and no contribution so
  far is affected.

- **An encrypted notes vault** (`scripts/vault.mjs`) keeps commercially sensitive working notes in
  the repository and its history without exposing them publicly. `DevOps/vault/vault.enc` is
  committed; the decrypted working copy and the passphrase are not.

## [1.3.0] - 2026-07-25

### Added

- A break-glass administrator. The first account is designated as protected and can no longer be
  demoted, disabled, deleted, forced to SSO-only sign-in, or demoted by a directory, so a wrong or
  unreachable identity provider can never remove the last way into the console. Manage the
  designation with `php artisan rustdesk:admin:protect`.
- `php artisan rustdesk:admin:reset <username>` recovers an account from a shell, with opt-in
  `--unlink-federated-identities`, `--clear-force-sso` and `--clear-2fa`. The last of these is the
  only path out of a lost second factor with no recovery codes remaining.
- `ADMIN_PASS` is now optional. When it is unset the initial administrator receives a generated
  password, printed once at first boot and written to `storage/app/.initial-admin-password` until
  that account signs in, after which the file is deleted. Setting `ADMIN_PASS` keeps today's
  behaviour and validation exactly.

### Changed

- Release candidates can now be published. A tag suffixed `-alpha.N`, `-beta.N` or `-rc.N`
  publishes its exact version and moves a new `:next` alias, while `:latest`, `:1` and `:1.3` stay
  on the last stable release, so an unpinned deployment never receives a candidate. Promotion is a
  stable tag on the same commit.

### Fixed

- The production runtime smoke read `docker logs` once immediately after container readiness, which
  could miss a line that had already been written. It now polls, so a slow log flush no longer fails
  the release gate.
- An LDAP directory could silently demote a local administrator on every sign-in. With
  `LDAP_SYNC=true`, attribute synchronization wrote `is_admin` from group membership, and that
  membership test returns false whenever `LDAP_ADMIN_GROUP` is unset, which is its default. The
  break-glass account is now exempt from directory-driven demotion.

- Identity-provider groups can grant console admin roles. A new **SSO role mappings** screen
  declares "membership of this LDAP/FreeIPA or OIDC group grants this role", and membership is
  reconciled at every sign-in across all four login paths. Role assignments now carry provenance,
  so federated grants are revoked when a user leaves a group while hand-assigned roles are left
  alone. A mapping never writes the legacy full-administrator flag, and mapping a group to a
  `global` role requires `SSO_ROLE_MAPPING_ALLOW_GLOBAL=true` on the server.
- OIDC providers gained a configurable group claim, read from the userinfo response, with dot
  notation for nested claims. Providers that emit groups only in the ID token (Entra ID) or not
  at all (Google) are not supported, because no ID token is parsed anywhere in this application.

### Fixed

- Active Directory truncating an oversized `memberOf` into `memberOf;range=` chunks is now
  detected and reported as an unreadable group list rather than as an empty one, so a truncated
  answer can never look like a removal.

## [1.2.0] - 2026-07-24

### Added

- Generic OIDC egress can now reach a self-hosted identity provider on a private network.
  `RUSTDESK_OIDC_ALLOWED_NETWORKS` trusts specific CIDR ranges (or a bare address for one host)
  and `RUSTDESK_OIDC_ALLOW_PRIVATE_NETWORKS` is a shorthand for the RFC 1918 ranges plus
  `fd00::/8`. Both are empty/false by default, so existing deployments are unchanged.

### Fixed

- OIDC discovery failures are now logged with the reason, the offending address, which discovered
  endpoint was rejected, the issuer, and the state of the trusted-network allowlist. Previously
  every exception was discarded and the sign-in screen reported a misconfigured provider
  regardless of the real cause, which made a correctly configured identity provider look
  broken ([#1]).
- The console SSO callback no longer reports a failed code exchange as an unlinked account. A
  wrong client secret or a rejected token endpoint now says the exchange failed and points at
  the log, instead of sending operators to look at linked identities and auto-registration.
- Host resolution no longer discards answers it had already collected when one DNS record type
  returns a server failure, which internal and split-horizon resolvers routinely do for `CNAME`
  or `AAAA`. Hosts published only through `/etc/hosts` (Compose `extra_hosts`, Kubernetes
  `hostAliases`) are now visible to destination validation instead of failing to resolve.

### Security

- Loopback, link-local, multicast, IPv4-mapped IPv6, NAT64/6to4/Teredo translation prefixes, and
  cloud instance-metadata addresses are refused for OIDC egress even when an operator lists
  them, and a trusted private address is accepted only for the issuer's own host. Unusable
  allowlist entries are discarded and named rather than silently widening or closing the
  boundary. Outbound webhooks are deliberately unaffected by the OIDC opt-ins.

## [1.1.0] - 2026-07-18

### Changed

- Replaced the production Apache runtime with Nginx and PHP-FPM in one supervised
  container. It preserves container port `80`, `/var/www/html/storage`, all existing application
  environment settings, the MariaDB startup/migration path, and reverse-proxy behavior as a
  drop-in replacement for the Apache runtime.
- Added validated, optional controls for Nginx connections and access logging, PHP-FPM pool size,
  spare workers, request recycling and slow logging, and graceful-drain duration. Request-body
  limits are derived from the configured recording chunk with upload headroom, and the one Nginx
  access log can be disabled without creating a duplicate PHP-FPM access log.
- Nginx now derives its default worker-process count from the tighter visible CPU count or Docker
  cgroup quota instead of over-provisioning from host CPUs. All runtime tuning and generated server
  configuration are rejected before migrations or first-run seeding can change persistent state.
- The image keeps an eight-second shutdown default for compatibility with unchanged Compose files.
  Bundled Compose examples pair a 30-second runtime drain with a 35-second
  `stop_grace_period` so in-flight work has more time to complete.
- Explicit `TRUSTED_PROXIES=*` is supported, and bundled Compose files use it when the variable is
  unset for convenient LAN/reverse-proxy deployment. Runtime logs warn that wildcard mode trusts
  forwarded client IP and scheme values from every immediate caller. Exact proxy IPs/CIDRs remain
  the recommended setting whenever direct application-port access is possible.

### Security

- FastCGI is restricted to a permission-controlled Unix socket; PHP-FPM does not listen on or
  publish TCP port `9000`. Nginx executes only Laravel's exact front controller, denies dotfiles
  and other PHP paths, hides runtime versions, preserves the restricted trusted-proxy header
  surface, and keeps streamed response buffering disabled.
- Native AMD64 and ARM64 image gates start each exact release digest with disposable
  MariaDB and verify runtime syntax, socket isolation, HTTPS proxy recovery, secure cookies,
  static assets and API behavior, request-size and protected-path boundaries, secret/build-tool
  removal, managed-process failure, and graceful `SIGQUIT`/`SIGTERM` handling.
- Removed the C/C++ compiler drivers, `make`, and Linux header package after extension compilation.
  A same-database Trivy scan on 2026-07-18 found no fixable high/critical vulnerability in the
  final release candidate; vulnerability databases and unfixed vendor findings remain time-sensitive.

### Performance

- The digest-pinned PHP-FPM extension layer is shared by dependency assembly and the final image,
  so extensions compile once, after which the C/C++ compiler drivers, `make`, and Linux kernel
  headers are removed. Nginx/tini installation and Composer assembly use independent BuildKit
  branches, while source-only changes continue to reuse locked dependencies. One local Docker
  Desktop implementation run rebuilt the invalidated extension layer in 74.3 seconds; a warm
  source rebuild measured 5.9 seconds and a fully cached verification took 0.84 seconds. These are
  cache observations, not CI or capacity guarantees.
- Added a reproducible Apache-versus-Nginx heartbeat harness with isolated MariaDB datasets,
  keep-alive and no-reuse profiles, fixed resource limits, payload-fingerprint parity checks, and
  machine-readable output. A short post-fix 300-RPS local run completed without failures, drops, or
  wire mismatches at about 7 ms p95 for both runtimes while the candidate used less sampled CPU and
  app memory. This is useful tuning evidence, not certification for a 10,000-device fleet; large
  operators should canary their own workload and retain the documented v1.0.1 rollback pin.

See the [complete v1.1.0 release notes](docs/releases/v1.1.0.md) for upgrade, proxy-security,
capacity, rollback, and verification details.

## [1.0.1] - 2026-07-17

### Changed

- Dark mode is now the first-visit default on authentication and administration pages, regardless
  of the operating-system color preference. An explicit saved light or dark choice remains
  persistent and is never overridden by later operating-system theme changes.

### Performance

- The production image now compiles its PHP extensions once per architecture and shares that
  pinned PHP-Apache layer between dependency assembly and the final runtime. Composer downloads
  are cached independently of application source changes, while the final image still excludes
  Composer and the extension installer.
- Release images now build concurrently on native AMD64 and ARM64 GitHub runners instead of
  compiling ARM dependencies through QEMU. Each architecture is published by digest and
  smoke-tested before a CI-gated final manifest moves public image tags; architecture-specific
  GitHub and registry caches preserve fast repeat builds.

See the [complete v1.0.1 release notes](docs/releases/v1.0.1.md) for upgrade and verification
details.

## [1.0.0] - 2026-07-17

First stable release of the independent RD-API-Server application.

### Highlights

- RustDesk-compatible client API and a server-rendered administration console built with PHP
  8.5, Laravel 13, Blade, jQuery, and Bootstrap 5.
- Device, user, group, strategy, address-book, session, recording, alarm, and audit management.
- Strategy/settings push, deployment tokens, device approval, preset auto-registration, API keys,
  webhooks, OIDC/OAuth, LDAP/AD, email, and TOTP two-factor authentication.
- MariaDB with InnoDB as the sole supported database and multi-architecture Docker images for
  AMD64 and ARM64.
- Explicit reverse-proxy trust, secure HTTPS session-cookie support, login throttling, scoped
  administration permissions, and hardened credential and request boundaries.
- Modern dark and light WebUI with responsive layouts and accessibility coverage.

### Deployment requirements

- Existing SQLite installations must complete the documented manual migration to MariaDB before
  upgrading. No automated SQLite converter is included.
- HTTPS deployments must configure the public origin, explicitly trust only the proxy address or
  isolated proxy-network CIDR seen by the application, enable secure session cookies, and prevent
  direct access around the proxy. Wildcard proxy trust is intentionally unsupported.
- Production installation requires a unique first-run administrator password and stable
  application-key storage.

See the [complete v1.0.0 release notes](docs/releases/v1.0.0.md) for installation, upgrade,
security, and verification details.

[Unreleased]: https://github.com/GigaionLLC/RD-API-Server/compare/v1.4.2...HEAD
[1.4.2]: docs/releases/v1.4.2.md
[1.4.1]: docs/releases/v1.4.1.md
[1.4.0]: docs/releases/v1.4.0.md
[1.3.0]: docs/releases/v1.3.0.md
[1.2.0]: docs/releases/v1.2.0.md
[#1]: https://github.com/GigaionLLC/RD-API-Server/issues/1
[1.1.0]: docs/releases/v1.1.0.md
[1.0.1]: docs/releases/v1.0.1.md
[1.0.0]: docs/releases/v1.0.0.md
