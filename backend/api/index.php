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

// Prefer explicit route from ?r= (works without URL rewrite on XAMPP)
if (!empty($_GET['r'])) {
    $path = '/' . trim((string)$_GET['r'], '/');
} else {
    // Strip base prefixes: /api, /backend/api, /venue_booking_system/api, etc.
    $path = preg_replace('#^.*?/api(?:/index\.php)?#', '', $path) ?: '/';
    $path = '/' . trim($path, '/');
}
if ($path !== '/') {
    $path = rtrim($path, '/');
}
if ($path === '' || $path === '/index.php') {
    $path = '/';
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
            Response::json(BookingService::resubmit($user, $id, $body));
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
    if (preg_match('#^/bookings/(\d+)/request-revision$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'booking.validate');
        Response::json(BookingService::requestRevision($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/respond-reschedule$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Response::json(BookingService::respondReschedule($user, (int)$m[1], $body));
    }
    if (preg_match('#^/bookings/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Response::json(BookingService::cancel($user, (int)$m[1], $body));
    }

    // Requirement options (admin-defined)
    if ($path === '/requirement-options' && $method === 'GET') {
        $user = Auth::requireUser();
        $all = !empty($_GET['all']) && Permissions::can($user, 'settings.manage');
        Response::json(RequirementOptions::list($all));
    }
    if ($path === '/requirement-options/availability' && $method === 'GET') {
        Auth::requireUser();
        $vid = (int)($_GET['venueId'] ?? 0);
        $date = (string)($_GET['date'] ?? '');
        $st = (string)($_GET['startTime'] ?? '');
        $en = (string)($_GET['endTime'] ?? '');
        $okTime = static fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $t) === 1;
        if ($vid < 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !$okTime($st) || !$okTime($en) || substr($en, 0, 5) <= substr($st, 0, 5)) {
            Response::error('venueId, date, startTime and endTime are required.', 422);
        }
        Response::json(RequirementOptions::availability($vid, $date, substr($st, 0, 5), substr($en, 0, 5)));
    }
    if ($path === '/requirement-options' && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'settings.manage');
        ActivityLog::write($user['id'], $user['email'], 'requirement_option.create', 'settings', null, ['name' => $body['name'] ?? '']);
        Response::json(RequirementOptions::save(null, $body), 201);
    }
    if (preg_match('#^/requirement-options/(\d+)$#', $path, $m) && $method === 'PUT') {
        $user = Auth::requireUser();
        Permissions::require($user, 'settings.manage');
        ActivityLog::write($user['id'], $user['email'], 'requirement_option.update', 'settings', $m[1], null);
        Response::json(RequirementOptions::save((int)$m[1], $body));
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
                "INSERT INTO dbo.Venues (Name, Location, Capacity, Status)
                 OUTPUT INSERTED.VenueId VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $name,
                $body['location'] ?? null,
                $cap,
                $body['status'] ?? 'Available',
            ]);
            $id = (int)$stmt->fetchColumn();
            ActivityLog::write($user['id'], $user['email'], 'venue.create', 'venue', (string)$id, null);
            Response::json(getVenue($id), 201);
        } catch (PDOException $e) {
            Response::error('Venue name must be unique.', 409);
        }
    }
    if (preg_match('#^/venues/(\d+)/availability$#', $path, $m) && $method === 'GET') {
        $user = Auth::requireUser();
        Permissions::require($user, 'venue.view');
        $from = (string)($_GET['from'] ?? date('Y-m-d'));
        $to = (string)($_GET['to'] ?? date('Y-m-d', strtotime($from . ' +30 days')));
        Response::json(BookingService::availability((int)$m[1], $from, $to, $user));
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
                Capacity = COALESCE(?, Capacity),
                Status = COALESCE(?, Status), UpdatedAt = SYSUTCDATETIME() WHERE VenueId = ?"
        )->execute([
            isset($body['name']) ? trim($body['name']) : null,
            $body['location'] ?? null,
            isset($body['capacity']) ? (int)$body['capacity'] : null,
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
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters.', 422);
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
        if (isset($body['role']) && !$roleId) {
            Response::error('Invalid role.', 422);
        }
        if (!empty($body['password']) && strlen((string)$body['password']) < 6) {
            Response::error('Password must be at least 6 characters.', 422);
        }
        $target = getUser($id);
        if (!$target) {
            Response::error('Not found.', 404);
        }
        $demote = $target['role'] === 'Administrator' && isset($body['role']) && $body['role'] !== 'Administrator';
        $deact = $target['role'] === 'Administrator' && isset($body['isActive']) && !$body['isActive'];
        if ($demote || $deact) {
            $cnt = (int)$pdo->query(
                "SELECT COUNT(*) FROM dbo.Users u INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
                 WHERE r.RoleCode = 'Administrator' AND u.IsActive = 1"
            )->fetchColumn();
            if ($cnt <= 1) {
                Response::error('Cannot remove the last active Administrator.', 409);
            }
        }
        if (isset($body['isActive'])) {
            $pdo->prepare('UPDATE dbo.Users SET IsActive = ? WHERE UserId = ?')->execute([$body['isActive'] ? 1 : 0, $id]);
        }
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
        ActivityLog::write($user['id'], $user['email'], 'user.update', 'user', (string)$id, ['passwordChanged' => !empty($body['password']), 'role' => $body['role'] ?? null]);
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
             WHERE d.IsActive = 1
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
    if (preg_match('#^/departments/(\d+)/remove$#', $path, $m) && $method === 'POST') {
        $user = Auth::requireUser();
        Permissions::require($user, 'department.manage');
        $id = (int)$m[1];
        $pdo = Database::pdo();
        $c = $pdo->prepare('SELECT COUNT(*) FROM dbo.Users WHERE DepartmentId = ? AND IsActive = 1');
        $c->execute([$id]);
        $n = (int)$c->fetchColumn();
        if ($n > 0) {
            Response::error("Cannot remove: $n active user(s) still belong to this department. Move them first.", 409);
        }
        $b = $pdo->prepare('SELECT COUNT(*) FROM dbo.Bookings WHERE DepartmentId = ?');
        $b->execute([$id]);
        if ((int)$b->fetchColumn() > 0) {
            // Has booking history: hide instead of deleting so records stay intact.
            $pdo->prepare('UPDATE dbo.Departments SET IsActive = 0, UpdatedAt = SYSUTCDATETIME() WHERE DepartmentId = ?')->execute([$id]);
        } else {
            try {
                $pdo->prepare('UPDATE dbo.Users SET DepartmentId = NULL WHERE DepartmentId = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM dbo.Departments WHERE DepartmentId = ?')->execute([$id]);
            } catch (PDOException $e) {
                $pdo->prepare('UPDATE dbo.Departments SET IsActive = 0, UpdatedAt = SYSUTCDATETIME() WHERE DepartmentId = ?')->execute([$id]);
            }
        }
        ActivityLog::write($user['id'], $user['email'], 'department.remove', 'department', (string)$id, null);
        Response::json(['id' => $id, 'removed' => true]);
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
                'booking_lead_days' => (string)BookingService::leadDaysFor($user),
                'booking_buffer_minutes' => (string)BookingService::bufferMinutes(),
                'slot_step_minutes' => (string)BookingService::stepMinutes(),
                'operating_hours_start' => Settings::get('operating_hours_start'),
                'operating_hours_end' => Settings::get('operating_hours_end'),
            ]);
        }
        Response::json(Settings::all());
    }
    if ($path === '/settings' && $method === 'PUT') {
        $user = Auth::requireUser();
        Permissions::require($user, 'settings.manage');
        $in = is_array($body['settings'] ?? null) ? $body['settings'] : $body;

        $isTime = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
        $intIn = static fn($v, int $lo, int $hi) => (is_string($v) || is_int($v)) && preg_match('/^\d{1,5}$/', (string)$v) === 1 && (int)$v >= $lo && (int)$v <= $hi;
        $rules = [
            'booking_lead_days'       => [fn($v) => $intIn($v, 0, 60),   'Lead days must be a whole number from 0 to 60.'],
            'booking_buffer_minutes'  => [fn($v) => $intIn($v, 0, 240) && (int)$v % 5 === 0, 'Buffer must be 0-240 minutes in steps of 5.'],
            'slot_step_minutes'       => [fn($v) => in_array((string)$v, ['30', '60'], true), 'Time step must be 30 or 60 minutes.'],
            'min_duration_minutes'    => [fn($v) => $intIn($v, 15, 1440), 'Minimum duration must be 15-1440 minutes.'],
            'max_duration_minutes'    => [fn($v) => $intIn($v, 15, 1440), 'Maximum duration must be 15-1440 minutes.'],
            'booking_window_days'     => [fn($v) => $intIn($v, 1, 730),  'Booking window must be 1-730 days.'],
            'operating_hours_start'   => [$isTime, 'Opening time must be HH:MM.'],
            'operating_hours_end'     => [$isTime, 'Closing time must be HH:MM.'],
            'equipment_buffer_minutes'  => [fn($v) => $intIn($v, 0, 240) && (int)$v % 5 === 0, 'Equipment turnaround must be 0-240 minutes in steps of 5.'],
            'equipment_reserve_pending' => [fn($v) => in_array((string)$v, ['0', '1'], true), 'Value must be 0 or 1.'],
            'overlap_include_pending' => [fn($v) => in_array((string)$v, ['0', '1'], true), 'Value must be 0 or 1.'],
            'reminder_hours_before'   => [fn($v) => $intIn($v, 0, 720),  'Reminder hours must be 0-720.'],
        ];
        $clean = [];
        foreach ($in as $k => $v) {
            if (!isset($rules[$k])) {
                continue; // ignore unknown keys
            }
            if (!$rules[$k][0]($v)) {
                Response::error($rules[$k][1], 422, ['errors' => [$k => $rules[$k][1]]]);
            }
            $clean[$k] = (string)$v;
        }
        $cur = Settings::all();
        $get = static fn(string $k) => $clean[$k] ?? $cur[$k]['value'];
        if ((int)$get('min_duration_minutes') > (int)$get('max_duration_minutes')) {
            Response::error('Minimum duration cannot exceed maximum duration.', 422);
        }
        if ($get('operating_hours_start') >= $get('operating_hours_end')) {
            Response::error('Opening time must be before closing time.', 422);
        }
        foreach ($clean as $k => $v) {
            Settings::set($k, $v);
        }
        ActivityLog::write($user['id'], $user['email'], 'settings.update', 'settings', null, $clean);
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
