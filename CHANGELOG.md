# Changelog

Notable changes to RD-API-Server are recorded here. Release tags follow Semantic Versioning;
operational agent records remain in `DevOps/logs/` and are not a substitute for public release
notes.

## [Unreleased]

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

[1.0.0]: docs/releases/v1.0.0.md
