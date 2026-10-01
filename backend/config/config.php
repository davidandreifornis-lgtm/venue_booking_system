<?php
/**
 * App config (session, security, CORS, debug).
 * Database credentials live only in database.php.
 */
return [
    'session' => [
        'name' => 'VBSSESSID',
        'lifetime_seconds' => 28800,       // 8 hours absolute
        'idle_timeout_seconds' => 3600,    // 1 hour idle
    ],
    'security' => [
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
    ],
    'cors' => [
        // Leave empty for same-origin only (recommended).
        // Set e.g. 'http://localhost:8080' only if frontend is on a different origin.
        'allowed_origin' => '',
    ],
    'app' => [
        'timezone' => 'UTC',
        'debug' => true,
    ],
];
