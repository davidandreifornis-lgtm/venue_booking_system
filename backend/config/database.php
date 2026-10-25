<?php
/**
 * Database credentials.
 *
 * Prefer environment variables (never commit real secrets):
 *   VBS_DB_DRIVER, VBS_DB_SERVER, VBS_DB_NAME, VBS_DB_USER, VBS_DB_PASSWORD, VBS_DB_PORT
 *
 * Optional local override file (gitignored): config.local.php returning the same keys.
 *
 * Do NOT use an sa / high-privilege SQL login for the application in production.
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'VMAPPS2',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault', // set via VBS_DB_PASSWORD or config.local.php
    'port'     => '',
];
