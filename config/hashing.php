<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Argon2id is used for every newly stored password. Bcrypt remains readable
    | only so legacy hashes can be upgraded after a successful local login.
    |
    */

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        // Cross-algorithm verification is required while legacy bcrypt hashes exist.
        'verify' => env('HASH_VERIFY', false),
        // Prevent silent 72-byte truncation if code explicitly selects this legacy driver.
        'limit' => 72,
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        // password_verify safely identifies the encoded algorithm. Requiring Argon here
        // would reject existing bcrypt credentials before they can be upgraded.
        'verify' => env('HASH_VERIFY', false),
    ],

    // The application performs a compare-and-swap upgrade after successful local logins.
    // Laravel's automatic save can overwrite a concurrent real password replacement.
    'rehash_on_login' => false,
];
