<?php
/**
 * Optional local override.
 * Copy this file to config.local.php (do not commit) if you need different DB settings.
 *
 * Currently Database.php reads database.php only.
 * To use a local override, either:
 *   1. Edit database.php directly, or
 *   2. Point Database.php at this file (advanced).
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'VMAPPS2',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault',
    'port'     => '',
];
