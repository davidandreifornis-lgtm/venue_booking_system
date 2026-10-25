<?php
/**
 * Optional local override for database connection.
 * Copy this file to config.local.php (do not commit) if you need different settings.
 *
 * Database.php will merge config.local.php over database.php when present.
 * Prefer environment variables: VBS_DB_SERVER, VBS_DB_NAME, VBS_DB_USER, VBS_DB_PASSWORD.
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'localhost',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault',
    'port'     => '',
];
