<?php
/**
 * Front controller for /api/*
 * Map URL path after /api to handlers.
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

if (!empty($config['cors']['allowed_origin'])) {
    header('Access-Control-Allow-Origin: ' . $config['cors']['allowed_origin']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Permissions.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
require_once __DIR__ . '/../lib/Settings.php';
require_once __DIR__ . '/../lib/BookingService.php';
require_once __DIR__ . '/../lib/NotificationService.php';

Auth::startSession();

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

// Strip base prefixes: /api, /backend/api, /venue-booking-system/backend/api
$path = preg_replace('#^.*?/api#', '', $path) ?: '/';
$path = '/' . trim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}

$body = [];
$raw = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

// Health check (no auth) — /api/health or path ends with health
if ($method === 'GET' && ($path === '/health' || $path === '/api/health' || str_ends_with($path, '/health'))) {
    $out = [
        'ok' => false,
        'php' => PHP_VERSION,
        'pdo_drivers' => PDO::getAvailableDrivers(),
        'sqlsrv_loaded' => in_array('sqlsrv', PDO::getAvailableDrivers(), true),
        'path_seen' => $path,
        'error' => null,
        'db_name' => null,
    ];
    try {
        $cfg = Database::config();
        $out['config'] = [
            'driver' => $cfg['driver'] ?? null,
            'server' => $cfg['server'] ?? null,
            'database' => $cfg['database'] ?? null,
            'username' => $cfg['username'] ?? null,
        ];
        $pdo = Database::pdo();
        $row = $pdo->query('SELECT DB_NAME() AS dbname')->fetch(PDO::FETCH_ASSOC);
        $row = array_change_key_case($row ?: [], CASE_LOWER);
        $out['db_name'] = $row['dbname'] ?? null;
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        Response::json($out, 500);
    }
    Response::json($out);
}

try {
    route($method, $path, $body);
} catch (Throwable $e) {
    $msg = $config['app']['debug'] ? $e->getMessage() : 'Internal server error.';
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error($msg, 500);
}

function route(string $method, string $path, array $body): void
{
    // Auth
    if ($path === '/auth/session' && $method === 'GET') {
        $u = Auth::user();
        Response::json($u); // null if not logged in
    }
    if ($path === '/auth/login' && $method === 'POST') {
        $email = (string)($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') {
            Response::error('Email and password are required.', 422);
        }
        $user = Auth::login($email, $password);
        Response::json($user);
    }
    if ($path === '/auth/logout' && $method === 'POST') {
        Auth::logout();
        Response::noContent();
    }

    // Bookings collection
    if ($path === '/bookings' && $method === 'GET') {
        $user = Auth::requireUser();
        Response::json(BookingService::listForUser($user, $_GET));
    }
    if ($path === '/bookings' && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'booking.create');
        Response::json(BookingService::create($user, $body), 201);
    }

    // Booking by id + actions
    if (preg_match('#^/bookings/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            $user = Auth::requireUser();
            $b = BookingService::getById($id);
            if (!$b) {
                Response::error('Not found.', 404);
            }
            // Scope check
            if ($user['role'] === 'Employee' && $b['requesterId'] !== $user['id']) {
                Response::error('Forbidden.', 403);
            }
            if ($user['role'] === 'Manager' && $user['departmentId'] && $b['departmentId'] && $b['departmentId'] !== $user['departmentId'] && $b['requesterId'] !== $user['id']) {
                Response::error('Forbidden.', 403);
            }
            Response::json($b);
        }
        if ($method === 'PUT') {
            $user = Auth::requireUser();
            // Only owner can edit pending — simplified: cancel + recreate for now
            Response::error('Use cancel and create a new request, or contact HR to reschedule.', 501);
        }
    }

    if (preg_match('#^/bookings/(\d+)/validate$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'booking.validate');
        Response::json(BookingService::validateByManager($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/approve$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'booking.decide');
        Response::json(BookingService::approve($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/decline$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        if (!Permissions::can($user, 'booking.decide') && !Permissions::can($user, 'booking.validate')) {
            Response::error('Forbidden.', 403);
        }
        Response::json(BookingService::decline($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/reschedule$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'booking.decide');
        Response::json(BookingService::reschedule($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Response::json(BookingService::cancel($user, (int)$m[1], $body));
    }

    // Venues
    if ($path === '/venues' && $method === 'GET') {
        Auth::requireUser();
        $pdo = Database::pdo();
        $sql = 'SELECT * FROM dbo.Venues WHERE 1=1';
        $bind = [];
        if (!empty($_GET['status'])) {
            $sql .= ' AND Status = ?';
            $bind[] = $_GET['status'];
        }
        if (!isset($_GET['includeInactive'])) {
            $sql .= ' AND IsActive = 1';
        }
        $sql .= ' ORDER BY Name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::json(array_map('mapVenue', $stmt->fetchAll()));
    }
    if ($path === '/venues' && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'venue.manage');
        $name = trim((string)($body['name'] ?? ''));
        $cap = (int)($body['capacity'] ?? 0);
        if ($name === '' || $cap < 1) {
            Response::error('Name and capacity (>0) are required.', 422);
        }
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status)
                 OUTPUT INSERTED.VenueId VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $name,
                $body['location'] ?? null,
                $cap,
                $body['equipment'] ?? null,
                $body['status'] ?? 'Available',
            ]);
            $id = (int)$stmt->fetchColumn();
            ActivityLog::write($user['id'], $user['email'], 'venue.create', 'venue', (string)$id, null);
            Response::json(getVenue($id), 201);
        } catch (PDOException $e) {
            Response::error('Venue name must be unique.', 409);
        }
    }
    if (preg_match('#^/venues/(\d+)$#', $path, $m) && $method === 'GET') {
        Auth::requireUser();
        $v = getVenue((int)$m[1]);
        if (!$v) {
            Response::error('Not found.', 404);
        }
        Response::json($v);
    }
    if (preg_match('#^/venues/(\d+)$#', $path, $m) && $method === 'PUT') {
        $user = Auth::requireUser();
        Permissions::require($user, 'venue.manage');
        $id = (int)$m[1];
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Venues SET Name = COALESCE(?, Name), Location = COALESCE(?, Location),
                Capacity = COALESCE(?, Capacity), Equipment = COALESCE(?, Equipment),
                Status = COALESCE(?, Status), UpdatedAt = SYSUTCDATETIME() WHERE VenueId = ?"
        )->execute([
            isset($body['name']) ? trim($body['name']) : null,
            $body['location'] ?? null,
            isset($body['capacity']) ? (int)$body['capacity'] : null,
            $body['equipment'] ?? null,
            $body['status'] ?? null,
            $id,
        ]);
        ActivityLog::write($user['id'], $user['email'], 'venue.update', 'venue', (string)$id, $body);
        Response::json(getVenue($id));
    }
    if (preg_match('#^/venues/(\d+)/deactivate$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'venue.manage');
        $id = (int)$m[1];
        Database::pdo()->prepare(
            "UPDATE dbo.Venues SET IsActive = 0, Status = 'Unavailable', UpdatedAt = SYSUTCDATETIME() WHERE VenueId = ?"
        )->execute([$id]);
        ActivityLog::write($user['id'], $user['email'], 'venue.deactivate', 'venue', (string)$id, null);
        Response::json(getVenue($id));
    }

    // Users
    if ($path === '/users' && $method === 'GET') {
        $user = Auth::requireUser();
        $pdo = Database::pdo();
        $sql = "SELECT u.UserId, u.Email, u.FullName, u.IsActive, u.DepartmentId, u.LastLoginAt,
                       r.RoleCode AS Role, d.Name AS DepartmentName
                FROM dbo.Users u
                INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
                LEFT JOIN dbo.Departments d ON d.DepartmentId = u.DepartmentId
                WHERE 1=1";
        $bind = [];
        if (!empty($_GET['role'])) {
            $sql .= ' AND r.RoleCode = ?';
            $bind[] = $_GET['role'];
        }
        // Non-admins only get manager list for booking form
        if ($user['role'] !== 'Administrator') {
            if (!empty($_GET['role']) && $_GET['role'] === 'Manager') {
                // allowed
            } else {
                Permissions::require($user, 'user.manage');
            }
        }
        $sql .= ' ORDER BY u.FullName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::json(array_map('mapUser', $stmt->fetchAll()));
    }
    if ($path === '/users' && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'user.manage');
        $email = trim((string)($body['email'] ?? ''));
        $name = trim((string)($body['name'] ?? $body['fullName'] ?? ''));
        $role = (string)($body['role'] ?? 'Employee');
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $name === '' || $password === '') {
            Response::error('Email, name, and password are required.', 422);
        }
        $roleId = roleId($role);
        if (!$roleId) {
            Response::error('Invalid role.', 422);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
                 OUTPUT INSERTED.UserId VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$email, $hash, $name, $roleId, $body['departmentId'] ?? null]);
            $id = (int)$stmt->fetchColumn();
            ActivityLog::write($user['id'], $user['email'], 'user.create', 'user', (string)$id, ['email' => $email, 'role' => $role]);
            Response::json(getUser($id), 201);
        } catch (PDOException $e) {
            Response::error('Email already exists.', 409);
        }
    }
    if (preg_match('#^/users/(\d+)$#', $path, $m) && $method === 'GET') {
        $user = Auth::requireUser();
        Permissions::require($user, 'user.manage');
        $u = getUser((int)$m[1]);
        if (!$u) {
            Response::error('Not found.', 404);
        }
        Response::json($u);
    }
    if (preg_match('#^/users/(\d+)$#', $path, $m) && $method === 'PUT') {
        $user = Auth::requireUser();
        Permissions::require($user, 'user.manage');
        $id = (int)$m[1];
        $pdo = Database::pdo();
        $roleId = isset($body['role']) ? roleId($body['role']) : null;
        $pdo->prepare(
            "UPDATE dbo.Users SET
                FullName = COALESCE(?, FullName),
                DepartmentId = COALESCE(?, DepartmentId),
                RoleId = COALESCE(?, RoleId),
                UpdatedAt = SYSUTCDATETIME()
             WHERE UserId = ?"
        )->execute([
            isset($body['name']) ? trim($body['name']) : null,
            $body['departmentId'] ?? null,
            $roleId,
            $id,
        ]);
        if (!empty($body['password'])) {
            $pdo->prepare('UPDATE dbo.Users SET PasswordHash = ?, UpdatedAt = SYSUTCDATETIME() WHERE UserId = ?')
                ->execute([password_hash($body['password'], PASSWORD_BCRYPT), $id]);
        }
        ActivityLog::write($user['id'], $user['email'], 'user.update', 'user', (string)$id, null);
        Response::json(getUser($id));
    }
    if (preg_match('#^/users/(\d+)/deactivate$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'user.manage');
        $id = (int)$m[1];
        // Protect last admin
        $pdo = Database::pdo();
        $target = getUser($id);
        if ($target && $target['role'] === 'Administrator') {
            $cnt = (int)$pdo->query(
                "SELECT COUNT(*) FROM dbo.Users u INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
                 WHERE r.RoleCode = 'Administrator' AND u.IsActive = 1"
            )->fetchColumn();
            if ($cnt <= 1) {
                Response::error('Cannot deactivate the last active Administrator.', 409);
            }
        }
        $pdo->prepare('UPDATE dbo.Users SET IsActive = 0, UpdatedAt = SYSUTCDATETIME() WHERE UserId = ?')->execute([$id]);
        ActivityLog::write($user['id'], $user['email'], 'user.deactivate', 'user', (string)$id, null);
        Response::json(getUser($id));
    }

    // Departments
    if ($path === '/departments' && $method === 'GET') {
        Auth::requireUser();
        $pdo = Database::pdo();
        $rows = $pdo->query(
            "SELECT d.DepartmentId, d.Name, d.IsActive, d.ManagerUserId, u.FullName AS ManagerName
             FROM dbo.Departments d
             LEFT JOIN dbo.Users u ON u.UserId = d.ManagerUserId
             ORDER BY d.Name"
        )->fetchAll();
        Response::json(array_map(static function ($r) {
            return [
                'id' => (int)$r['DepartmentId'],
                'name' => $r['Name'],
                'isActive' => (bool)$r['IsActive'],
                'managerId' => $r['ManagerUserId'] !== null ? (int)$r['ManagerUserId'] : null,
                'managerName' => $r['ManagerName'],
            ];
        }, $rows));
    }
    if ($path === '/departments' && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'department.manage');
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            Response::error('Name is required.', 422);
        }
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare('INSERT INTO dbo.Departments (Name) OUTPUT INSERTED.DepartmentId VALUES (?)');
            $stmt->execute([$name]);
            $id = (int)$stmt->fetchColumn();
            ActivityLog::write($user['id'], $user['email'], 'department.create', 'department', (string)$id, null);
            Response::json(['id' => $id, 'name' => $name], 201);
        } catch (PDOException $e) {
            Response::error('Department name must be unique.', 409);
        }
    }
    if (preg_match('#^/departments/(\d+)$#', $path, $m) && $method === 'PUT') {
        $user = Auth::requireUser();
        Permissions::require($user, 'department.manage');
        $id = (int)$m[1];
        Database::pdo()->prepare(
            'UPDATE dbo.Departments SET Name = COALESCE(?, Name), UpdatedAt = SYSUTCDATETIME() WHERE DepartmentId = ?'
        )->execute([isset($body['name']) ? trim($body['name']) : null, $id]);
        Response::json(['id' => $id]);
    }
    if (preg_match('#^/departments/(\d+)/assign-manager$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'department.manage');
        $id = (int)$m[1];
        $mgr = (int)($body['managerId'] ?? 0);
        Database::pdo()->prepare(
            'UPDATE dbo.Departments SET ManagerUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE DepartmentId = ?'
        )->execute([$mgr ?: null, $id]);
        ActivityLog::write($user['id'], $user['email'], 'department.assign_manager', 'department', (string)$id, ['managerId' => $mgr]);
        Response::json(['id' => $id, 'managerId' => $mgr]);
    }

    // Notifications
    if ($path === '/notifications' && $method === 'GET') {
        $user = Auth::requireUser();
        Response::json(NotificationService::listForUser($user['id'], $_GET));
    }
    if (preg_match('#^/notifications/(\d+)/read$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        NotificationService::markRead($user['id'], (int)$m[1]);
        Response::noContent();
    }

    // Activity logs
    if ($path === '/activity-logs' && $method === 'GET') {
        $user = Auth::requireUser();
        Permissions::require($user, 'activity.view');
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT TOP ($limit) LogId, ActorUserId, ActorEmail, Action, EntityType, EntityId, Detail, CreatedAt
             FROM dbo.ActivityLogs ORDER BY CreatedAt DESC"
        );
        $stmt->execute();
        Response::json(array_map(static function ($r) {
            return [
                'id' => (int)$r['LogId'],
                'actorId' => $r['ActorUserId'] !== null ? (int)$r['ActorUserId'] : null,
                'actor' => $r['ActorEmail'],
                'action' => $r['Action'],
                'entityType' => $r['EntityType'],
                'entityId' => $r['EntityId'],
                'target' => trim(($r['EntityType'] ?? '') . ' ' . ($r['EntityId'] ?? '')),
                'detail' => $r['Detail'] ? json_decode($r['Detail'], true) : null,
                'timestamp' => $r['CreatedAt'],
            ];
        }, $stmt->fetchAll()));
    }

    // Settings
    if ($path === '/settings' && $method === 'GET') {
        $user = Auth::requireUser();
        if ($user['role'] !== 'Administrator') {
            // Public subset for validation
            Response::json([
                'min_duration_minutes' => Settings::get('min_duration_minutes'),
                'max_duration_minutes' => Settings::get('max_duration_minutes'),
                'booking_window_days' => Settings::get('booking_window_days'),
                'operating_hours_start' => Settings::get('operating_hours_start'),
                'operating_hours_end' => Settings::get('operating_hours_end'),
            ]);
        }
        Response::json(Settings::all());
    }

    Response::error('Not found.', 404);
}

function mapVenue(array $r): array
{
    return [
        'id' => (int)$r['VenueId'],
        'name' => $r['Name'],
        'location' => $r['Location'],
        'capacity' => (int)$r['Capacity'],
        'equipment' => $r['Equipment'],
        'status' => $r['Status'],
        'isActive' => (bool)$r['IsActive'],
    ];
}

function getVenue(int $id): ?array
{
    $stmt = Database::pdo()->prepare('SELECT * FROM dbo.Venues WHERE VenueId = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ? mapVenue($r) : null;
}

function mapUser(array $r): array
{
    return [
        'id' => (int)$r['UserId'],
        'email' => $r['Email'],
        'name' => $r['FullName'],
        'role' => $r['Role'],
        'departmentId' => $r['DepartmentId'] !== null ? (int)$r['DepartmentId'] : null,
        'department' => $r['DepartmentName'] ?? null,
        'isActive' => (bool)$r['IsActive'],
        'lastLoginAt' => $r['LastLoginAt'] ?? null,
    ];
}

function getUser(int $id): ?array
{
    $stmt = Database::pdo()->prepare(
        "SELECT u.UserId, u.Email, u.FullName, u.IsActive, u.DepartmentId, u.LastLoginAt,
                r.RoleCode AS Role, d.Name AS DepartmentName
         FROM dbo.Users u
         INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
         LEFT JOIN dbo.Departments d ON d.DepartmentId = u.DepartmentId
         WHERE u.UserId = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ? mapUser($r) : null;
}

function roleId(string $code): ?int
{
    $stmt = Database::pdo()->prepare('SELECT RoleId FROM dbo.Roles WHERE RoleCode = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}
