<?php
declare(strict_types=1);

/**
 * Test bootstrap. SAFETY: these tests DELETE bookings, notifications and activity logs,
 * so they refuse to run unless the target database name ends in "_Test".
 *
 * Point them at a test database with environment variables (see tests/README.md):
 *   VBS_DB_SERVER, VBS_DB_NAME (must end in _Test), VBS_DB_USER, VBS_DB_PASSWORD
 */

$root = dirname(__DIR__);
$cfg = require $root . '/backend/config/config.php';
date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');

require_once $root . '/backend/lib/Response.php';
Response::$throw = true;

require_once $root . '/backend/lib/Database.php';
require_once $root . '/backend/lib/Settings.php';
require_once $root . '/backend/lib/Permissions.php';
require_once $root . '/backend/lib/BookingService.php';
require_once $root . '/backend/lib/NotificationService.php';

$dbName = (string)(Database::config()['database'] ?? '');
if (!preg_match('/_test$/i', $dbName)) {
    fwrite(STDERR, "REFUSING TO RUN: database '{$dbName}' does not end in _Test.\n"
        . "Set VBS_DB_NAME to a dedicated test database (e.g. VenueBooking_Test).\n");
    exit(2);
}

require_once __DIR__ . '/Support/Http.php';
require_once __DIR__ . '/Support/VbsTestCase.php';
