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
        // Set true behind HTTPS always; auto-detected from HTTPS / port 443 / X-Forwarded-Proto.
        'force_secure_cookie' => false,
    ],
    'security' => [
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
        // Set a shared secret and send header X-VBS-Health-Token to unlock detailed health.
        'health_token' => getenv('VBS_HEALTH_TOKEN') ?: '',
    ],
    'cors' => [
        // Leave empty for same-origin only (recommended).
        // Set e.g. 'http://localhost:8080' only if frontend is on a different origin.
        'allowed_origin' => '',
    ],
    'app' => [
        'timezone' => 'Asia/Manila',
        // Prefer env VBS_DEBUG=1 for local debugging; default false for safer deploys.
        'debug' => filter_var(getenv('VBS_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    ],
];
