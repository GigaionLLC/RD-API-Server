---
type: "core"
name: "Security & Agent Governance"
status: "stable"
dependencies: []
description: "Establishes the project's Core Security Perimeter and Agentic Governance standard."
---

# 🔒 Security & Agent Governance

## 🛡️ Core Security Perimeter
- **Zero-Trust Access:** *Zero-trust design patterns.*
- **API Protection:** *Rate limits and sanitization guidelines.*

## 🗄️ Database Row-Level Security (RLS)
*Details of Row-Level Security rules and policies governing tables.*

## 🗝️ Secrets Management
- **Environment variables:** *Naming conventions and setup instructions.*
- **Exclusion Rules:** *Making sure no credentials bypass `.gitignore`.*

## Outbound Webhook Boundary

- Webhook delivery accepts only HTTP and HTTPS destinations on explicitly allowed ports
  (`80,443` by default; configure `RUSTDESK_WEBHOOK_ALLOWED_PORTS` when a public custom port
  is intentional).
- Every attempt resolves the destination again, rejects the host if any answer is private,
  loopback, link-local, reserved, or otherwise non-public, and pins the request to a validated
  address. Redirects, inherited proxies, and connection reuse are disabled so the HTTP
  transport cannot perform a second, unvalidated route selection.
- Destination validation is authoritative at send time, including retries. Admin form
  validation is only an earlier usability check and must not replace the egress guard.
- Treat the complete webhook URL and shared secret as write-only credentials. Admin list and
  delivery-history pages use centralized redacted labels; model serialization hides the raw
  URL, secret, and stored delivery error.
- Transport exceptions are sanitized before logging or persistence, and historical errors are
  sanitized again when rendered so records created by older releases cannot disclose URL path,
  query, fragment, userinfo, or shared-secret values.
- `webhooks.view` grants redacted configuration and delivery history only. Create, update,
  toggle, test, resend, and delete operations require `webhooks.edit` on the server and are not
  rendered for view-only delegates.

## Generic OIDC Egress Boundary

- Generic OIDC issuers and every discovered authorization, token, and userinfo endpoint must
  use HTTPS, resolve entirely to globally routable addresses, and use an allowed port (`443` by
  default; configure `RUSTDESK_OIDC_ALLOWED_PORTS` for an intentional public custom port).
- Discovery must assert the configured issuer. A missing or mismatched `issuer`, unsafe URL, or
  unsafe endpoint aborts the login before an authorization URL is returned.
- Discovery, token, and userinfo requests resolve immediately before use and pin the validated
  address into the connection. Redirects, inherited proxies, and connection reuse are disabled;
  token and userinfo destinations are resolved again after discovery to prevent DNS rebinding.
- Cross-host endpoints remain supported when each endpoint independently passes the public
  network checks. The guard does not assume that every standards-compliant provider uses one
  hostname.

## Identity Provider Trust Boundary

- OAuth/OIDC issuer, client, and provider-type configuration is an authentication trust root.
  Delegated administrators may inspect redacted provider state but only a full administrator
  may create, update, or delete a provider.
- Existing external identities are keyed inside that trust domain. A provider with linked
  identities cannot change its issuer, client, type, or key in place; it must be explicitly
  deleted and recreated. Deletion removes its links transactionally before the provider key
  can be reused, so a replacement issuer cannot inherit the previous users.
- Console SSO treats the identity provider as the sign-in trust anchor and does not layer the
  application's local TOTP challenge onto that path. Operators who require MFA for SSO must
  enforce it at the identity provider; the application must not claim that every OIDC provider
  or policy guarantees MFA.

## LDAP Identity Boundary

- A successful directory bind does not authorize whichever local account happens to share its
  username. LDAP users resolve only through a unique persisted directory-provider + immutable
  subject hash; both client and console login use that exact linked user.
- New directory identities receive a collision-safe local username and an unusable random local
  password. Existing local or legacy accounts are never adopted automatically, so ambiguous
  upgrades fail secure instead of inheriting full-admin flags or delegated roles.
- `LDAP_SUBJECT_ATTR` must name an immutable identifier (`entryUUID` for OpenLDAP or
  `objectGUID` for Active Directory); blank and reusable DN subjects fail closed. Set a stable
  `LDAP_PROVIDER_ID` before intentionally moving the same directory to a different endpoint.
- Provider/subject and one-link-per-user database uniqueness, transactions, and retries serialize
  concurrent first logins. Later username/profile sync cannot move a link to another account.

## Inbound Proxy Boundary

- Forwarded headers are ignored by default. A deployment behind a reverse proxy must set
  `TRUSTED_PROXIES` to that proxy's explicit IP address or CIDR; multiple entries are separated
  with commas.
- Never trust a wildcard or a network that untrusted clients can reach directly. The proxy must
  construct a trustworthy client chain instead of passing client-supplied forwarded headers
  through unchanged, and the application port must not be exposed through a path that bypasses
  the proxy.
- Client IPs feed login and 2FA throttles, API-key IP allowlists, audit records, and last-seen
  metadata. Any new IP-based security control must use the framework request IP and retain this
  trusted-proxy boundary.

## Login Challenge Boundary

- Email and stock-client TOTP login both require a password-proven, opaque challenge before a
  passwordless second request can issue an access token. The challenge is bound to the exact
  user, RustDesk id, and UUID; only its SHA-256 digest is persisted.
- Challenges expire after five minutes, are superseded by a new attempt for the same device,
  and are consumed once under a database row lock. Replay, cross-user, cross-device, and
  cross-UUID submissions fail.
- Each row has its own five-guess budget, so rotating source IPs cannot reset it. A live TOTP
  code without the password-proven challenge is insufficient; the supported one-request path
  still requires the local/LDAP password and TOTP together.

## Device Enrollment Boundary

- Unknown devices fail closed by default: `RUSTDESK_REQUIRE_DEPLOYMENT=true` and
  `RUSTDESK_AUTO_REGISTER=false`. They receive the stock empty/`ID_NOT_FOUND` responses without
  creating rows, firing webhooks, consuming disconnects, or receiving strategy secrets.
- Existing approved devices continue to require their exact stored non-empty UUID. Deployment
  tokens and explicit operator approval are the authenticated enrollment paths.
- Legacy first-seen enrollment is available only when both switches are deliberately reversed.
  That compatibility mode inherently trusts the first caller for a RustDesk ID; it is bounded by
  per-source and global one-minute registration ceilings plus a total-device quota. Defaults are
  30 per source, 100 globally, and 5,000 total devices.
- The framework request IP is authoritative for enrollment limits, so reverse proxies must obey
  the explicit trusted-proxy boundary above.

## Audit Ingestion Boundary

- The RustDesk audit feeds are fire-and-forget and do not carry an account bearer. Current
  connection, file-transfer, and alarm writers do carry the controlled device's `id` and `uuid`.
  A write is accepted only when both exactly match an existing approved device.
- Unknown devices, UUID mismatches, unapproved devices, malformed fields, oversized bodies, and
  rate-limited requests receive the normal empty JSON acknowledgement (`{}`) but produce no
  database, email, or webhook side effects. This preserves the client wire contract without
  exposing validation details to an unauthenticated caller.
- Body ceilings are 16 KiB for connection events, 64 KiB for file events, and 16 KiB for alarms.
  Stored strings, enums, IPs, arrays, and counters also have destination-appropriate limits;
  JSON nesting is capped and random `u64` session identifiers are decoded without precision loss.
- One-minute rate limits default to 300 invalid requests per source IP, 12,000 valid requests per
  source IP, and per-device ceilings of 240 connection, 1,200 file, and 60 alarm events. Operators
  may tune these with the `RUSTDESK_AUDIT_*` environment variables in `.env.example`.
- The older `POST /api/audit/conn` note body contains no UUID and cannot be safely attributed, so
  that exact legacy shape now acknowledges without changing a row. The application also provides
  the bearer-authenticated `GET /api/audit/conn/active` plus `PUT /api/audit` guid flow. Both
  operations independently require owner, delegated group, or administrator access to the target
  device, so knowing a guid cannot grant cross-account write access. Clients still using the exact
  legacy POST will not persist a note unless upstream adds an attributable credential; a
  compatibility caller that adds the matching device UUID is additionally scoped by both peer id
  and session id.

## Generated Output Boundaries

- Database-backed HTML mail templates are trusted layout, but every runtime placeholder is
  centrally HTML-escaped by `MailService`. Usernames, device labels, links, and audit messages are
  text values and must never be inserted as raw markup.
- Every admin CSV export passes through the shared export concern. Cells whose first meaningful
  character is `=`, `+`, `-`, or `@` are prefixed with an apostrophe, including attempts hidden
  behind leading controls, spaces, or a UTF-8 BOM, so spreadsheet software treats them as text.

## Email Login Challenge Boundary

- After the password succeeds, email verification issues a fresh 64-character random challenge
  and a six-digit code for one exact user, RustDesk id, and device UUID. The challenge expires
  after five minutes; issuing another challenge for that same user/device invalidates the prior one.
- The API returns the challenge secret to the client but persists only its SHA-256 digest. The
  six-digit email code is stored with the configured password hasher, never as plaintext. Both
  values are hidden from model serialization.
- The stock client's `type:"email_code"` request must echo the challenge `secret` together with
  the same username, `id`, and `uuid`. Missing or rebound fields receive the same generic
  wrong/expired-code response and cannot issue a token.
- Each challenge has its own five-failure budget in the database. Verification locks the row,
  so rotating source IPs or racing requests cannot bypass this budget. The challenge is disabled
  at the fifth wrong code and atomically consumed on success, preventing replay.
- Rows created before the challenge-hash migration contain no challenge digest and are therefore
  intentionally unusable after deployment; the user can begin a fresh five-minute login attempt.
- The exact `login_verify = 'email'` policy requires a non-null address containing at least one
  non-whitespace character. Admin create/edit requests and API v1 partial updates validate the
  effective policy, while the user-management command rejects an explicit clear before changing
  the password, role, address, or credential version.
- With directory attribute synchronization enabled, LDAP refuses the complete linked-account
  refresh before assigning any fields when the directory removes or supplies a malformed address
  required by email verification. It does not keep a potentially stale destination, persist an
  undeliverable value, or silently weaken the policy.
- A named MariaDB CHECK makes the address invariant durable with byte-exact policy comparison.
  The deployment migration performs a read-only preflight and aborts with a bounded list of user
  IDs when historical invalid state exists. Operators must add a valid address or intentionally
  change policy; the migration never converts a configured second factor to password-only.
- Run the preflight and constraint installation with old writers quiesced. `ALTER TABLE` cannot be
  made atomic with the preceding read, so a legacy write between them can make installation fail.

## Two-Factor Recovery Boundary

- Authenticator recovery codes are returned only in the successful enrollment response. That
  response is private/no-store and the plaintext list is never written to the database-backed
  session, user row, model serialization, or logs.
- The user row stores only versioned HMAC-SHA-256 digests separated from other uses of `APP_KEY`.
  Verification accepts the current key and configured `APP_PREVIOUS_KEYS`, consumes one digest
  under a row lock, and rejects replay from concurrent or stale requests.
- Legacy plaintext lists are converted by the deployment migration and are also upgraded under
  lock if encountered during a rolling transition. Rolling back the one-way digest migration
  invalidates affected recovery lists; the authenticator remains usable and new recovery codes
  require re-enrollment.

## Canonical Authenticator State Boundary

- An active account TOTP factor has all three structural signals together:
  `login_verify = 'totp'`, `two_factor_enabled = 1`, and a non-null encrypted seed. Inactive
  `off`/`email` state has a false enabled flag and null seed, confirmation time, and recovery
  list. Policy values are compared byte-for-byte so the database's case-insensitive text
  collation cannot turn variants such as `TOTP` into a state PHP would interpret differently. A
  named MariaDB CHECK rejects partial or non-canonical states after deployment.
- Before the repair migration changes any account, it decrypts and format-validates every stored
  seed using the current `APP_KEY` or a configured `APP_PREVIOUS_KEYS` key. Any missing key,
  malformed ciphertext, or invalid seed aborts before the first normalization write. Operators
  must restore the matching key instead of silently discarding an unreadable factor.
- A valid seed plus either historical active signal is normalized to active TOTP (strongest
  security intent wins). An active signal without a seed becomes `off`, except an existing
  `email` policy remains email; orphan seed/metadata and unknown policies become canonical
  inactive state. Rolling back drops only the CHECK and does not recreate unsafe split state.
- Only an account with console access may enroll or remove its own TOTP through the protected
  personal settings. Generic user administration can set `off` or `email` only when TOTP is
  inactive. An active factor is read-only there and preserved under a row lock; factor-state
  fields must be missing from generic requests and are stripped defensively before persistence.
  Full and delegated administrators cannot inject, replace, clear, or corrupt another account's
  seed or recovery codes through the generic editor.
- Run the normalization/constraint migration with old writers quiesced. Its preflight and repair
  transaction prevent partial application failures, but MariaDB cannot make the repair and
  subsequent `ALTER TABLE` one atomic unit; a legacy writer between them can make constraint
  installation fail. Back up the database and matching application keys first.

## Two-Factor Management Reauthentication Boundary

- A console session may change its own authenticator only shortly after its configured sign-in
  flow completes. The marker is issued centrally after local, LDAP, or SSO authentication has
  established the final browser session, and only after the application TOTP challenge when that
  challenge applies. Password acceptance while a TOTP challenge is still pending never qualifies.
- The marker is encrypted independently of the database session and bound to the exact user,
  credential version, current password hash, regenerated browser-session ID, issue time, expiry,
  and a random nonce. It expires after five minutes by default;
  `AUTH_TWO_FACTOR_MANAGEMENT_TIMEOUT` is clamped to 60-900 seconds. Remembered or pre-upgrade
  sessions without the marker remain signed in but cannot mutate two-factor state. Every route
  that reads or consumes this state uses session blocking so overlapping requests cannot restore
  a consumed marker or pending enrollment snapshot.
- The settings page remains readable after expiry, but it clears and never renders a pending
  enrollment secret. Starting and confirming enrollment and removing the factor each recheck
  freshness. Candidate setup state is encrypted and bound to the same account, credential
  version, marker nonce, and short expiry, so it cannot cross accounts, browser sessions, or a
  later completed sign-in.
- Successful enrollment is serialized against the user row and cannot overwrite a factor enabled
  concurrently. Removal requires the existing authenticator or one unused recovery code unless
  that exact factor was already proved while completing the immediately preceding application
  sign-in. Carried factor assurance is keyed to the enrollment's encrypted seed and confirmation
  time, so proof for a replaced factor cannot remove its successor. This permits a final recovery
  code to complete sign-in and removal without demanding a nonexistent second code. Failures are
  rate-limited per account and source IP; success clears the seed, recovery list, confirmation
  time, login mode, pending setup, and recent marker together.
- LDAP and SSO accounts use a new completed directory/provider sign-in instead of their unusable
  random local password. The explicit “Sign out and sign in again” action invalidates the current
  application session and returns the newly authenticated account to its two-factor settings.
  An OIDC provider can satisfy that authorization from its own existing session; operators must
  configure provider-side reauthentication or step-up policy when fresh IdP credential entry is
  required. SSO does not carry application-factor assurance, so removal still requires the
  current authenticator or a recovery code.

## Authenticator Secret and Application-Key Boundary

- Authenticator seeds are encrypted before they are stored in `users.two_factor_secret`.
  Enrollment candidates are also encrypted and account/session/time-bound inside the
  database-backed session; setup and recovery responses are private/no-store and suppress
  referrer disclosure. A database or session disclosure without the application key therefore
  does not reveal a usable seed.
- The runtime uses an explicit `APP_KEY` when configured; otherwise it generates and persists one
  at `storage/app/.appkey`. The database and that key are one backup unit. Restoring the database
  without the matching key makes existing authenticator secrets unreadable and keyed recovery
  codes unverifiable. Every API replica must use the same current and previous keys.
- Key rotation sets a new `APP_KEY` and retains the old key in the comma-separated
  `APP_PREVIOUS_KEYS` setting. Previous keys must remain until authenticator enrollments and
  recovery-code sets created under them have been replaced and old encrypted sessions have
  expired; one-way recovery-code digests cannot be bulk-rekeyed.
- Deploy the plaintext-to-encrypted authenticator migration in a maintenance window. Quiesce all
  old replicas, back up the database and key, run the migration on one upgraded instance, and
  start only upgraded replicas. A mixed-version rolling deployment is unsafe because an old
  replica can write plaintext after the upgraded code begins requiring ciphertext.
