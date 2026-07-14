# Admin REST API (`/api/v1`)

Programmatic access to the panel, authenticated by a **scoped API key**. This is separate from
the RustDesk client API (the wire protocol the client speaks, documented in
[../modernization/02-client-api-contract.md](../modernization/02-client-api-contract.md)).

## Authentication

Create a key in the console under **API Keys**. The plaintext secret (`rdk_…`) is shown once;
only its SHA‑256 hash is stored. Send it on every request as either:

```
Authorization: Bearer rdk_xxxxxxxx
# or
X-API-Key: rdk_xxxxxxxx
```

A missing/invalid/expired key → `401`. A key without the route's scope → `403`.

Full administrators may inspect and manage every key. Delegated administrators need the
dedicated `api_keys.view` / `api_keys.edit` console permissions, can only inspect and manage
their own keys, and may only issue scopes backed by permissions they currently hold. Removing
either API-key edit authority or the matching resource permission immediately prevents an
existing delegated key from using that scope.

## Scopes

| Scope | Grants |
|-------|--------|
| `devices.read` | list devices |
| `devices.write` | reassign a device's owner / group / strategy / alias |
| `users.read` | list users |
| `users.write` | create / update ordinary, non-administrative users |
| `strategies.read` | list strategies |
| `strategies.write` | create / update strategies (options pushed via heartbeat) |
| `address_book.read` | list the key owner's address books + peers |
| `address_book.write` | create / delete books and add / delete peers in the key owner's books |
| `audit.read` | read the connection audit log |

A key may hold any combination. (A `*` scope, if ever issued, grants all.)

## Endpoints

All list endpoints are paginated (`?per_page=` up to 100, `?page=`) and return Laravel's
paginator shape: `{ "data": [...], "total", "per_page", "current_page", ... }`.

| Method | Path | Scope | Notes |
|--------|------|-------|-------|
| GET | `/api/v1/devices` | `devices.read` | `?q=` filters id/host/alias |
| PUT | `/api/v1/devices/{id}` | `devices.write` | body `{ user_id?, device_group_id?, strategy_id?, alias? }`; null clears |
| GET | `/api/v1/users` | `users.read` | `?q=` filters username/email |
| POST | `/api/v1/users` | `users.write` | body `{ username, password, email?, display_name?, status? }` → `201`; always creates a non-administrator |
| PUT | `/api/v1/users/{id}` | `users.write` | partial update of an ordinary account; privileged accounts and `is_admin` are rejected |
| GET | `/api/v1/strategies` | `strategies.read` | includes `options`, `assignments_count` |
| POST | `/api/v1/strategies` | `strategies.write` | body `{ name, note?, enabled?, options? }` → `201` |
| PUT | `/api/v1/strategies/{id}` | `strategies.write` | partial update; bumps `modified_at` so clients re-pull |
| GET | `/api/v1/audit/connections` | `audit.read` | `?peer_id=` filter |
| GET | `/api/v1/address-books` | `address_book.read` | the key owner's books |
| POST | `/api/v1/address-books` | `address_book.write` | body `{ name, note?, is_shared? }` → `201` |
| DELETE | `/api/v1/address-books/{id}` | `address_book.write` | deletes the book + its peers/tags |
| GET | `/api/v1/address-books/{id}/peers` | `address_book.read` | peers in a book you own |
| POST | `/api/v1/address-books/{id}/peers` | `address_book.write` | body `{ id, alias?, note?, tags?[] }` → `201` |
| DELETE | `/api/v1/address-books/{id}/peers/{peer}` | `address_book.write` | |

Address‑book endpoints are scoped to the **key owner's** books (`403` otherwise).
Administrator promotion, delegated-role assignment, and mutation of any full or delegated
administrator are intentionally console-only operations available to a full administrator;
`users.write` never grants administrative authority.

## Examples

```bash
KEY=rdk_xxxxxxxx
BASE=https://api.example.com

# List online devices matching "web"
curl -s -H "Authorization: Bearer $KEY" "$BASE/api/v1/devices?q=web"

# Add a peer to address book 1
curl -s -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"id":"123456789","alias":"Front desk","tags":["lobby"]}' \
  "$BASE/api/v1/address-books/1/peers"

# Recent connection audit for a peer
curl -s -H "X-API-Key: $KEY" "$BASE/api/v1/audit/connections?peer_id=123456789"
```

## Machine-readable specs

This folder ships ready-to-import artifacts covering both the admin API and the client API:

| File | Use |
|------|-----|
| [`openapi.yaml`](openapi.yaml) | OpenAPI 3.1 spec — import into Swagger UI, Redoc, Stoplight, or generate clients. |
| [`postman_collection.json`](postman_collection.json) | Postman collection (v2.1). Set `base_url` + `api_key`; the Client API → Login request captures `account_token`. |
| [`bruno/`](bruno/) | [Bruno](https://www.usebruno.com/) collection — open the folder, pick the **Local** environment. |

## Related surfaces

- **Shared / team address books** — the client lists them via `POST /api/ab/shared/profiles`
  (returns each book's `rule`: 1 read · 2 read/write · 3 full control). Owners and collaborators
  are managed in the console under **Address Books → Share**.
- **Webhooks / notifications** — outbound, not part of this inbound API. Configure Slack /
  Telegram / generic JSON targets in the console under **Webhooks**; generic deliveries carry an
  `X-RustDesk-Signature: sha256=…` HMAC when a secret is set.
  Destinations are restricted to HTTP(S) on configured public ports, resolved and validated on
  every attempt, pinned to a public address, and never followed across redirects. The default
  allowed ports are `80,443`; set `RUSTDESK_WEBHOOK_ALLOWED_PORTS` only for intentional public
  custom ports.

> Endpoints are additive — new resources/scopes are appended here and to `openapi.yaml`.
