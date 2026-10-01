<?php
/**
 * GET /backend/api/health.php — diagnose DB connectivity
 */
header('Content-Type: application/json; charset=utf-8');

$result = [
    'ok' => false,
    'php' => PHP_VERSION,
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'sqlsrv_loaded' => in_array('sqlsrv', PDO::getAvailableDrivers(), true),
    'config' => null,
    'db_name' => null,
    'error' => null,
];

try {
    require_once __DIR__ . '/../lib/Database.php';
    $cfg = Database::config();
    $result['config'] = [
        'driver' => $cfg['driver'] ?? null,
        'server' => $cfg['server'] ?? null,
        'database' => $cfg['database'] ?? null,
        'username' => $cfg['username'] ?? null,
        'port' => $cfg['port'] ?? null,
        // password intentionally omitted
    ];

    $pdo = Database::pdo();
    $row = $pdo->query('SELECT DB_NAME() AS dbname, @@VERSION AS ver')->fetch(PDO::FETCH_ASSOC);
    $row = array_change_key_case($row ?: [], CASE_LOWER);
    $result['db_name'] = $row['dbname'] ?? null;
    $result['sql_version'] = isset($row['ver']) ? substr($row['ver'], 0, 80) : null;
    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
