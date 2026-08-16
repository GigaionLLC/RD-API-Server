<?php

/*
 * RustDesk server settings consumed by the client API layer.
 * All values are overridable via environment variables so deployments need no code change.
 * These map to what the RustDesk client expects from its API server (see
 * docs/modernization/02-client-api-contract.md).
 */

return [

    // Public-facing server endpoints handed to clients (web client config, deploy helper).
    'id_server' => env('RUSTDESK_ID_SERVER', '127.0.0.1:21116'),
    'relay_server' => env('RUSTDESK_RELAY_SERVER', '127.0.0.1:21117'),
    'api_server' => env('RUSTDESK_API_SERVER', 'http://127.0.0.1:8080'),

    // The RustDesk public key (contents of id_ed25519.pub). Either inline or a file path.
    'key' => env('RUSTDESK_KEY', ''),
    'key_file' => env('RUSTDESK_KEY_FILE', ''),

    /*
     * WebSocket endpoints for the browser remote-desktop viewer.
     *
     * hbbs binds rendezvous+2 (21118) and hbbr binds relay+2 (21119) unconditionally, but
     * both speak plain ws only. An https console therefore CANNOT reach them directly:
     * the browser blocks ws:// from a secure page. Put a TLS terminator in front and set
     * these to its wss URLs — see docs/web-client-deployment.md for nginx and Caddy.
     *
     * Leave empty to derive ws://<id_server host>:21118 and :21119, which only works when
     * the console itself is served over http (localhost during development).
     */
    'web_client' => [
        'ws_id_url' => env('RUSTDESK_WS_ID_URL', ''),
        'ws_relay_url' => env('RUSTDESK_WS_RELAY_URL', ''),
    ],

    // Server-command targets (ports the API talks to on hbbs/hbbr).
    'id_server_port' => (int) env('RUSTDESK_ID_SERVER_PORT', 21116),
    'relay_server_port' => (int) env('RUSTDESK_RELAY_SERVER_PORT', 21117),

    // Heartbeat / Strategy push.
    'heartbeat' => [
        // Seconds before a device with no heartbeat is considered offline (device-check job).
        'offline_after' => (int) env('RUSTDESK_OFFLINE_AFTER', 120),
    ],

    // Device onboarding.
    'devices' => [
        // When true, unknown devices get ID_NOT_FOUND from /api/sysinfo until deployed/approved.
        'require_deployment' => (bool) env('RUSTDESK_REQUIRE_DEPLOYMENT', true),
        // Legacy unauthenticated registration is an explicit opt-in and remains bounded below.
        'auto_register' => (bool) env('RUSTDESK_AUTO_REGISTER', false),
        'auto_registration' => [
            'per_ip_per_minute' => (int) env('RUSTDESK_AUTO_REGISTER_PER_IP_PER_MINUTE', 30),
            'global_per_minute' => (int) env('RUSTDESK_AUTO_REGISTER_GLOBAL_PER_MINUTE', 100),
            'max_devices' => (int) env('RUSTDESK_AUTO_REGISTER_MAX_DEVICES', 5000),
        ],
        // When true, new/ungrouped devices auto-join a default device group (promoting the
        // oldest group, or creating a "Default" one) so they never sit in "None".
        'auto_default_group' => (bool) env('RUSTDESK_AUTO_DEFAULT_GROUP', true),
    ],

    // Whether the personal (non-shared) address book API is enabled.
    'personal_address_book' => (bool) env('RUSTDESK_PERSONAL_AB', true),

    // Max peers allowed per address book (0 = unlimited). Enforced on peer-add across the
    // client API, the admin manager and /api/v1, and surfaced to the client as max_peer_one_ab.
    'ab_max_peers' => (int) env('RUSTDESK_AB_MAX_PEERS', 0),

    // Bearer token lifetime for the client API (account login tokens).
    'token_ttl_days' => (int) env('RUSTDESK_TOKEN_TTL_DAYS', 90),

    // Prometheus /metrics endpoint. Empty = disabled (404). When set, scrapers must send
    // `Authorization: Bearer <token>`.
    'metrics_token' => env('RUSTDESK_METRICS_TOKEN', ''),

    // Delete audit rows (connection / file / login logs + alarms) older than this many days.
    // 0 = keep forever. Pruned by the scheduled `audit:prune` command.
    'audit_retention_days' => (int) env('RUSTDESK_AUDIT_RETENTION_DAYS', 0),

    // Outbound webhooks are restricted to standard web ports by default. Add a public custom
    // HTTP(S) port here only when a deployment intentionally needs it.
    'webhooks' => [
        'allowed_ports' => array_map(
            'intval',
            explode(',', (string) env('RUSTDESK_WEBHOOK_ALLOWED_PORTS', '80,443'))
        ),
    ],

    // Generic OIDC discovery/token/userinfo calls require HTTPS and, by default, a globally
    // routable address. Standard TLS port 443 is allowed by default; list an additional public
    // port only when an IdP needs it.
    //
    // A self-hosted identity provider on a LAN, VPN, or container network is supported through
    // the two opt-ins below, both off by default so the boundary stays closed unless an
    // operator deliberately relaxes it. Every trusted address is an address a compromised or
    // spoofed provider could aim this server at, and the token endpoint receives the client
    // secret, so list the narrowest range that covers the provider. Loopback, link-local,
    // multicast, NAT64/6to4/Teredo, and cloud instance-metadata addresses are refused in every
    // mode and cannot be overridden.
    'oidc' => [
        'allowed_ports' => array_map(
            'intval',
            explode(',', (string) env('RUSTDESK_OIDC_ALLOWED_PORTS', '443'))
        ),

        // Comma-separated CIDR ranges, or bare addresses for a single host. Empty means only
        // globally routable addresses are accepted.
        'allowed_networks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RUSTDESK_OIDC_ALLOWED_NETWORKS', ''))
        ))),

        // Shorthand for the RFC 1918 ranges plus IPv6 unique-local space. An explicit
        // allowed_networks entry is narrower and preferred.
        'allow_private_networks' => (bool) env('RUSTDESK_OIDC_ALLOW_PRIVATE_NETWORKS', false),
    ],

    // Identity-provider group membership can grant console admin roles (see the SSO role
    // mappings screen). A mapping never touches the legacy `is_admin` column, so a full
    // administrator always remains the way back into a console whose provider is broken.
    'sso_role_mapping' => [
        // A `global` role grants every permission, including ones absent from the permission
        // catalogue, so letting an identity-provider group confer one is equivalent to handing
        // out full console authority. It therefore requires host-level access to enable, not
        // merely a console session.
        'allow_global_roles' => (bool) env('SSO_ROLE_MAPPING_ALLOW_GLOBAL', false),

        // Ceiling on how many asserted group values are considered per sign-in. A compromised or
        // misconfigured provider can return an unbounded list.
        'max_groups' => (int) env('SSO_ROLE_MAPPING_MAX_GROUPS', 200),
    ],

    // Audit feeds are unauthenticated at the HTTP layer because the upstream RustDesk client
    // does not send an account bearer. The controller instead binds each write to an existing
    // approved device's id + UUID and applies both aggregate-IP and per-device burst limits.
    'audit' => [
        'rate_limits' => [
            'invalid_per_ip' => (int) env('RUSTDESK_AUDIT_INVALID_PER_IP', 300),
            'valid_per_ip' => (int) env('RUSTDESK_AUDIT_VALID_PER_IP', 12000),
            'per_device' => [
                'conn' => (int) env('RUSTDESK_AUDIT_CONN_PER_DEVICE', 240),
                'file' => (int) env('RUSTDESK_AUDIT_FILE_PER_DEVICE', 1200),
                'alarm' => (int) env('RUSTDESK_AUDIT_ALARM_PER_DEVICE', 60),
            ],
        ],
    ],
];
