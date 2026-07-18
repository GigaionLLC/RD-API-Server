# Changelog

Notable changes to RD-API-Server are recorded here. Release tags follow Semantic Versioning;
operational agent records remain in `DevOps/logs/` and are not a substitute for public release
notes.

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
