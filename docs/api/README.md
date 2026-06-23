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

## Scopes

| Scope | Grants |
|-------|--------|
| `devices.read` | list devices |
| `users.read` | list users |
| `strategies.read` | list strategies |
| `address_book.read` | list the key owner's address books + peers |
| `address_book.write` | add / delete peers in the key owner's books |
| `audit.read` | read the connection audit log |

A key may hold any combination. (A `*` scope, if ever issued, grants all.)

## Endpoints

All list endpoints are paginated (`?per_page=` up to 100, `?page=`) and return Laravel's
paginator shape: `{ "data": [...], "total", "per_page", "current_page", ... }`.

| Method | Path | Scope | Notes |
|--------|------|-------|-------|
| GET | `/api/v1/devices` | `devices.read` | `?q=` filters id/host/alias |
| GET | `/api/v1/users` | `users.read` | `?q=` filters username/email |
| GET | `/api/v1/strategies` | `strategies.read` | includes `options`, `assignments_count` |
| GET | `/api/v1/audit/connections` | `audit.read` | `?peer_id=` filter |
| GET | `/api/v1/address-books` | `address_book.read` | the key owner's books |
| GET | `/api/v1/address-books/{id}/peers` | `address_book.read` | peers in a book you own |
| POST | `/api/v1/address-books/{id}/peers` | `address_book.write` | body `{ id, alias?, note?, tags?[] }` → `201` |
| DELETE | `/api/v1/address-books/{id}/peers/{peer}` | `address_book.write` | |

Address‑book endpoints are scoped to the **key owner's** books (`403` otherwise).

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

> An OpenAPI spec + Postman/Bruno collection are planned (roadmap); until then this page is the
> reference. Endpoints are additive — new resources/scopes will be appended here.
