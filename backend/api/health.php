<?php
/**
 * GET /backend/api/health.php — connectivity check.
 * Without a valid token: only ok / php version (no DB details).
 * With X-VBS-Health-Token matching config security.health_token (or VBS_HEALTH_TOKEN):
 * returns limited diagnostics (still never returns the DB password).
 */
header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/../config/config.php';
$tokenExpected = (string)($cfg['security']['health_token'] ?? '');
$provided = (string)($_SERVER['HTTP_X_VBS_HEALTH_TOKEN'] ?? $_GET['token'] ?? '');
$detailed = $tokenExpected !== '' && hash_equals($tokenExpected, $provided);

$result = [
    'ok' => false,
    'php' => PHP_VERSION,
];

if (!$detailed) {
    // Minimal public response
    try {
        require_once __DIR__ . '/../lib/Database.php';
        Database::pdo()->query('SELECT 1')->fetch();
        $result['ok'] = true;
    } catch (Throwable $e) {
        $result['ok'] = false;
        http_response_code(503);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$result['pdo_drivers'] = PDO::getAvailableDrivers();
$result['sqlsrv_loaded'] = in_array('sqlsrv', PDO::getAvailableDrivers(), true);
$result['config'] = null;
$result['db_name'] = null;
$result['error'] = null;

try {
    require_once __DIR__ . '/../lib/Database.php';
    $dbCfg = Database::config();
    $result['config'] = [
        'driver' => $dbCfg['driver'] ?? null,
        'server' => $dbCfg['server'] ?? null,
        'database' => $dbCfg['database'] ?? null,
        // username intentionally omitted even for detailed health
        'port' => $dbCfg['port'] ?? null,
    ];

    $pdo = Database::pdo();
    $row = $pdo->query('SELECT DB_NAME() AS dbname, @@VERSION AS ver')->fetch(PDO::FETCH_ASSOC);
    $row = array_change_key_case($row ?: [], CASE_LOWER);
    $result['db_name'] = $row['dbname'] ?? null;
    $result['sql_version'] = isset($row['ver']) ? substr($row['ver'], 0, 80) : null;
    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = 'Database connection failed.';
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
