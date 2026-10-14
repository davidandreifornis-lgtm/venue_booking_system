<?php
declare(strict_types=1);
// Usage (from tests): php race_worker.php '<json job>'
$job = json_decode($argv[1] ?? '', true);
$root = dirname(__DIR__, 2);
$cfg = require $root . '/backend/config/config.php';
date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
require_once $root . '/backend/lib/Response.php';
Response::$throw = true;
require_once $root . '/backend/lib/BookingService.php';

// Warm up the connection and settings cache BEFORE the barrier so the race is on the booking logic only.
Database::pdo();
Settings::get('overlap_include_pending');
Settings::get('booking_buffer_minutes');

while (microtime(true) < (float)$job['startAt']) {
    usleep(200);
}
try {
    if ($job['action'] === 'create') {
        BookingService::create($job['user'], $job['payload']);
    } elseif ($job['action'] === 'approve') {
        BookingService::approve($job['user'], (int)$job['bookingId'], []);
    }
    echo json_encode(['ok' => true]);
} catch (ApiResponse $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'status' => 500, 'message' => get_class($e) . ': ' . $e->getMessage()]);
}
