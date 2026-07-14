<?php

/*
 * Session-recording upload controls.
 *
 * The stock RustDesk uploader does not send an account token. Uploads therefore stay
 * disabled until an operator explicitly trusts source addresses, or a trusted proxy/custom
 * client supplies the dedicated secret in Authorization: Bearer or X-Recording-Token.
 */

return [
    'upload' => [
        'enabled' => (bool) env('RUSTDESK_RECORDING_UPLOAD_ENABLED', false),

        // Comma-separated literal addresses or CIDR ranges. Empty means no IP is trusted.
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RUSTDESK_RECORDING_UPLOAD_ALLOWED_IPS', ''))
        ))),

        // Optional secret for a trusted proxy/custom client. Use at least 32 random characters.
        'token' => (string) env('RUSTDESK_RECORDING_UPLOAD_TOKEN', ''),

        // One-minute per-source ceiling. The client normally sends at most about once/second.
        'rate_limit_per_minute' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_RATE_LIMIT', 600)
        ),

        // Hard limits are intentionally finite; zero never means unlimited here.
        'max_chunk_bytes' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_MAX_CHUNK_BYTES', 8 * 1024 * 1024)
        ),
        'max_file_bytes' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_MAX_FILE_BYTES', 2 * 1024 * 1024 * 1024)
        ),
        'max_total_bytes' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_MAX_TOTAL_BYTES', 10 * 1024 * 1024 * 1024)
        ),
        'max_total_files' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_MAX_TOTAL_FILES', 5000)
        ),
        'max_active_per_source' => max(
            1,
            (int) env('RUSTDESK_RECORDING_UPLOAD_MAX_ACTIVE_PER_SOURCE', 4)
        ),
    ],
];
