# Changelog

Notable changes to RD-API-Server are recorded here. Release tags follow Semantic Versioning;
operational agent records remain in `DevOps/logs/` and are not a substitute for public release
notes.

## [Unreleased]

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

[Unreleased]: https://github.com/GigaionLLC/RD-API-Server/compare/v1.1.0...HEAD
[1.1.0]: docs/releases/v1.1.0.md
[1.0.1]: docs/releases/v1.0.1.md
[1.0.0]: docs/releases/v1.0.0.md
