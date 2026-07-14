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
        'require_deployment' => (bool) env('RUSTDESK_REQUIRE_DEPLOYMENT', false),
        // When true, the first heartbeat auto-creates the device record.
        'auto_register' => (bool) env('RUSTDESK_AUTO_REGISTER', true),
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

    // Generic OIDC discovery/token/userinfo calls require HTTPS and public DNS. Standard TLS
    // port 443 is allowed by default; list an additional public port only when an IdP needs it.
    'oidc' => [
        'allowed_ports' => array_map(
            'intval',
            explode(',', (string) env('RUSTDESK_OIDC_ALLOWED_PORTS', '443'))
        ),
    ],
];
