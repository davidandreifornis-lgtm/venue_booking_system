<?php
/**
 * SQL Server connection — single source of truth.
 * Used by Database::config() / Database::pdo().
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'VMAPPS2',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault',
    'port'     => '',
];
