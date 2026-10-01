<?php
/**
 * SQL Server — VenueBooking (Navicat / VMAPPS2)
 * Same connection shape as toner_inventory database.php
 */
return [
    'db' => [
        'driver'   => 'sqlsrv',
        'server'   => 'VMAPPS2',
        'database' => 'VenueBooking',
        'username' => 'sa_dev',
        'password' => 'D3fault',
        'port'     => '',
    ],
    'session' => [
        'name' => 'VBSSESSID',
        'lifetime_seconds' => 28800,
        'idle_timeout_seconds' => 3600,
    ],
    'security' => [
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
    ],
    'cors' => [
        'allowed_origin' => '',
    ],
    'app' => [
        'timezone' => 'UTC',
        'debug' => true,
    ],
];
