<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Uses Argon2id for password hashing as required by the password policy.
    | Argon2id is memory-hard and resistant to GPU/ASIC attacks.
    |
    | Supported: "bcrypt", "argon2id"
    |
    */

    'driver' => 'argon2id',

    /*
    |--------------------------------------------------------------------------
    | Algorithm-Specific Options
    |--------------------------------------------------------------------------
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
    ],

    'argon2id' => [
        'memory' => 65536,       // 64 MB
        'time' => 3,             // 3 iterations
        'threads' => 4,          // 4 threads
    ],
];
