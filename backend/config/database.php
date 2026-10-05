<?php
/**
 * SQL Server connection — single source of truth.
 * Same server/credentials that work for toner_inventory.
 * Database name MUST be VenueBooking.
 */
return [
    'driver'   => 'sqlsrv',
    'server'   => 'VMAPPS2',
    'database' => 'VenueBooking',
    'username' => 'sa_dev',
    'password' => 'D3fault',
    'port'     => '',
];
