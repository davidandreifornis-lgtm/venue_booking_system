<?php
/**
 * Optional local override for database connection.
 * Copy this file to config.local.php (do not commit) if you need different settings.
 *
 * Database.php will merge config.local.php over database.php when present.
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'VMAPPS2',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault',
    'port'     => '',
];
