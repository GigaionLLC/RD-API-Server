# 12 · Access Control & Roles — Design Note

Design for phase 4 batch 3. Pulls the three distinct Pro concepts (catalog [03](03-pro-feature-catalog.md)
§3–§6) into a concrete plan against our schema. Implement in the order below; each layer is
independently shippable.

## The three concepts (don't conflate them)

| Concept | Question it answers | Pro ref | Enforced where |
|---------|--------------------|---------|----------------|
| **Access Control** | *Who may connect to which device?* | §3 | Server: which devices a user sees as "accessible" |
| **Control Role** | *Once connected, what may the controller do?* (12 in‑session perms) | §5 | Pushed to the controlled client via the Strategy/option channel |
| **Admin Role** | *What may this account manage in the console?* | §4 | Server: admin authz checks |

We already have the substrate: `users`, `groups` (user groups), `device_groups`, `devices`
(with `user_id`, `device_group_id`, `strategy_id`), and the **Strategy push** pipeline
([StrategyService](../../app/Services/StrategyService.php)) that Control Roles ride on.

---

## Layer 1 — Access Control (highest value)

**Model (Pro semantics):** a device is assignable to one user and/or one device group.
Access is **cumulative** — granted if *either* the user‑group rules OR the device‑group rules
allow it. Disabled user or disabled device ⇒ denied.

**New tables:**
```
user_group_access   { id, group_id, can_access_group_id, created_at }   // directional: group_id may access can_access_group_id
device_group_access { id, group_id, device_group_id, created_at }       // user group -> device group grant
```
Plus existing assignments: `devices.user_id` (owner), `devices.device_group_id`.

**Resolution — `AccessService::accessibleDeviceIds(User $u): array`:**
1. Devices owned by `$u` (`devices.user_id = $u->id`).
2. Devices owned by users in groups that `$u`'s group `can_access` (via `user_group_access`).
3. Devices in any `device_group` that `$u`'s group is granted (via `device_group_access`).
4. Union, minus disabled devices; empty/disabled user ⇒ `[]`.

**Wire‑in:** the client `/api/peers`, `/api/device-group/accessible`, and the address‑book
"accessible devices" queries filter by this set. Admin UI: on the Groups page add
"Can access" (user→user group) and "Can access device groups" editors.

**Status:** today only `is_admin` + flat groups exist — this is greenfield. Requires client
≥ 1.3.8 for device‑group access to take effect (server returns the right set regardless).

---

## Layer 2 — Control Role (in‑session permissions)

**Model:** one Control Role per user; 12 tri‑state perms (Use‑Client / Enable / Disable),
Disable wins. Maps **directly** onto the client option keys catalogued in
[10-client-config-keys.md](10-client-config-keys.md): `enable-keyboard`, `enable-clipboard`,
`enable-file-transfer`, `enable-audio`, `enable-remote-restart`, `enable-recording`,
`enable-camera`, `enable-terminal`, `enable-tunnel`, `enable-remote-printer`,
`block-input`/`allow-remote-config-modification`, etc.

**Implementation:** this is **a thin layer over Strategy push** — no new transport. Options:
- Simplest: a Control Role *is* a Strategy whose `options` are restricted to the 12 perm keys,
  assigned per user. Reuse `strategies` + `strategy_assignments` + `StrategyService` as‑is.
- Cleaner UX: a dedicated `control_roles` table (`name`, `perms json`) + `users.control_role_id`,
  and have `StrategyService::resolveForDevice` **merge** the controlling user's control‑role
  perms into the pushed `config_options` (control‑role perms take priority).

Recommend the dedicated table for clarity, merged at resolve time. Needs controlled client
≥ 1.4.5. Built‑ins: *Default* (logged‑in) and *Not Logged* (anonymous connections).

**Caveat (from [11](11-client-feature-opportunities.md)):** heartbeat‑pushed options land in
the user‑overridable layer — they're *policy*, not hard‑locked. Truly unchangeable perms need
a custom‑client build. The UI should label this distinction.

---

## Layer 3 — Admin Role (scoped console management)

**Model:** replace the single `users.is_admin` boolean with a role/permission layer. A user
may hold several admin roles (union of perms). Role types: **Global**, **Individual**
(own devices/logs only), **Group‑Scoped** (selected user/device groups).

**New tables:**
```
admin_roles            { id, name, type, scope json, perms json }
admin_role_user        { admin_role_id, user_id }
```
`perms` is a set of capability flags (Users.View/Edit, Devices.View/Edit/Assign,
Strategies.View/Edit, AuditLogs.View/Edit, OAuth.Edit, …) per catalog §4.

**Wire‑in:** introduce `Gate`/policy checks; replace the `admin` middleware
([EnsureAdmin](../../app/Http/Middleware/EnsureAdmin.php)) with a permission‑aware check.
Keep `is_admin = true` ⇒ Global role for backward compatibility (no migration pain).

**Effort:** largest of the three; do last. Most installs are fine with `is_admin` until they
need delegation.

---

## Recommended order & sizing
1. **Access Control (Layer 1)** — ★★★ value, M effort. Greenfield tables + one service + query wire‑in + Groups UI.
2. **Control Role (Layer 2)** — ★★ value, M effort. Reuses Strategy push; mostly UI + a resolve‑time merge.
3. **Admin Role (Layer 3)** — ★★ value, L effort. Authz refactor; backward‑compatible via `is_admin`⇒Global.

All three are additive and default‑open (current single‑admin behavior preserved until an
admin opts in), so they won't regress the existing tests or the simple `docker compose up`
experience.

## Implementation status & a finding on Layer 2

- **Layer 1 (Access Control) — DONE** (2026‑06‑21). `user_group_access` + `device_group_access`
  + `AccessService` + the `/api/users`, `/api/peers`, `/api/device-group/accessible` endpoints,
  with admin "can access" editors. Verified.
- **Layer 2 (Control Role) — finding: largely redundant in the OSS context.** The 12 in‑session
  permission keys (`enable-keyboard`, `enable-file-transfer`, `enable-recording`, …) are already
  **strategy‑pushable** via the existing Strategy editor + `StrategyService` (they're ordinary
  `config_options`). So *device‑level* in‑session policy is already achievable today. The Pro
  distinction is **per‑controller** enforcement (the controlled device applies the *controlling*
  user's role), which is delivered through Pro `hbbs`'s connection‑permission protocol — not
  available in the open‑source rendezvous server and not reachable from the API heartbeat (which
  is keyed by the controlled device, with no knowledge of who is connecting). **Recommendation:**
  don't build a separate Control‑Role transport; instead ship a thin "Control Role" *preset* UX
  on top of Strategy (a strategy whose editor is scoped to the 12 perm keys) if/when desired.
- **Layer 3 (Admin Role) — DONE and hardened.** Full administrators retain unconditional
  access through `is_admin`; delegated administrators receive explicit view/edit permissions.
  API-key management has its own `api_keys.view` / `api_keys.edit` permissions, delegated key
  managers can only manage their own keys, and a key cannot exceed or outlive its owner's
  current resource permissions. Because no safe role hierarchy exists yet, only a full
  administrator may create or mutate admin roles, assign roles, promote accounts, or modify
  other privileged accounts. Delegated `roles.view` remains read-only. The `/api/v1` user
  write surface manages ordinary accounts only and cannot set `is_admin` or take over a full
  or delegated administrator.
