<?php
/**
 * Optional override: copy to config.local.php (not committed).
 * Example: use existing test database instead of VenueBooking.
 */
return [
    'db' => [
        'driver'   => 'sqlsrv',
        'server'   => 'VMAPPS2',
        'database' => 'toner_inventory_test', // or VenueBooking
        'username' => 'sa_dev',
        'password' => 'D3fault',
        'port'     => '',
        'trust_server_certificate' => true,
    ],
];
