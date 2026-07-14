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
  the bearer-authenticated `GET /api/audit/conn/active` plus `PUT /api/audit` guid flow. Clients
  still using the exact legacy POST will not persist a note unless upstream adds an attributable
  credential; a compatibility caller that adds the matching device UUID is additionally scoped by
  both peer id and session id.

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
