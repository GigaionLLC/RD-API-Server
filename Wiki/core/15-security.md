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
