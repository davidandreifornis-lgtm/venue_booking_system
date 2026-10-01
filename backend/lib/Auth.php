<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ActivityLog.php';

class Auth
{
    public static function startSession(): void
    {
        $cfg = require __DIR__ . '/../config/config.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_name($cfg['session']['name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::startSession();
        $cfg = require __DIR__ . '/../config/config.php';

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $now = time();
        $idle = (int)$cfg['session']['idle_timeout_seconds'];
        if (!empty($_SESSION['last_seen']) && ($now - (int)$_SESSION['last_seen']) > $idle) {
            self::clearSession();
            return null;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT u.UserId, u.Email, u.FullName, u.IsActive, u.DepartmentId,
                    r.RoleCode AS Role, d.Name AS DepartmentName
             FROM dbo.Users u
             INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
             LEFT JOIN dbo.Departments d ON d.DepartmentId = u.DepartmentId
             WHERE u.UserId = ?"
        );
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (!$row || !(int)$row['IsActive']) {
            self::clearSession();
            return null;
        }

        $_SESSION['last_seen'] = $now;
        $_SESSION['role'] = $row['Role'];

        return [
            'id' => (int)$row['UserId'],
            'email' => $row['Email'],
            'name' => $row['FullName'],
            'role' => $row['Role'],
            'departmentId' => $row['DepartmentId'] !== null ? (int)$row['DepartmentId'] : null,
            'department' => $row['DepartmentName'],
        ];
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            Response::error('Unauthenticated.', 401);
        }
        return $u;
    }

    public static function requireRole(array $roles): array
    {
        $u = self::requireUser();
        if (!in_array($u['role'], $roles, true)) {
            ActivityLog::write($u['id'], $u['email'], 'permission_denied', 'auth', null, [
                'required' => $roles,
                'had' => $u['role'],
            ]);
            Response::error('Forbidden.', 403);
        }
        return $u;
    }

    public static function login(string $email, string $password): array
    {
        $cfg = require __DIR__ . '/../config/config.php';
        $pdo = Database::pdo();
        $email = trim($email);

        $stmt = $pdo->prepare(
            "SELECT u.UserId, u.Email, u.PasswordHash, u.FullName, u.IsActive,
                    u.FailedLogins, u.LockedUntil, u.DepartmentId,
                    r.RoleCode AS Role, d.Name AS DepartmentName
             FROM dbo.Users u
             INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
             LEFT JOIN dbo.Departments d ON d.DepartmentId = u.DepartmentId
             WHERE LOWER(u.Email) = LOWER(?)"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row) {
            ActivityLog::write(null, $email, 'login_failed', 'auth', null, ['reason' => 'unknown_user']);
            Response::error('Invalid email or password.', 401);
        }

        if (!(int)$row['IsActive']) {
            ActivityLog::write((int)$row['UserId'], $email, 'login_failed', 'auth', null, ['reason' => 'inactive']);
            Response::error('Account is deactivated.', 403);
        }

        if ($row['LockedUntil'] && strtotime($row['LockedUntil']) > time()) {
            Response::error('Account temporarily locked. Try again later.', 423);
        }

        if (!password_verify($password, $row['PasswordHash'])) {
            $fails = (int)$row['FailedLogins'] + 1;
            $lockSql = '';
            $params = [$fails, (int)$row['UserId']];
            if ($fails >= (int)$cfg['security']['max_failed_logins']) {
                $minutes = (int)$cfg['security']['lockout_minutes'];
                $pdo->prepare(
                    "UPDATE dbo.Users SET FailedLogins = ?, LockedUntil = DATEADD(MINUTE, ?, SYSUTCDATETIME()), UpdatedAt = SYSUTCDATETIME() WHERE UserId = ?"
                )->execute([$fails, $minutes, (int)$row['UserId']]);
            } else {
                $pdo->prepare(
                    "UPDATE dbo.Users SET FailedLogins = ?, UpdatedAt = SYSUTCDATETIME() WHERE UserId = ?"
                )->execute([$fails, (int)$row['UserId']]);
            }
            ActivityLog::write((int)$row['UserId'], $email, 'login_failed', 'auth', null, ['reason' => 'bad_password']);
            Response::error('Invalid email or password.', 401);
        }

        $pdo->prepare(
            "UPDATE dbo.Users SET FailedLogins = 0, LockedUntil = NULL, LastLoginAt = SYSUTCDATETIME(), UpdatedAt = SYSUTCDATETIME() WHERE UserId = ?"
        )->execute([(int)$row['UserId']]);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['UserId'];
        $_SESSION['role'] = $row['Role'];
        $_SESSION['last_seen'] = time();

        ActivityLog::write((int)$row['UserId'], $email, 'login', 'auth', (string)$row['UserId'], null);

        return [
            'id' => (int)$row['UserId'],
            'email' => $row['Email'],
            'name' => $row['FullName'],
            'role' => $row['Role'],
            'departmentId' => $row['DepartmentId'] !== null ? (int)$row['DepartmentId'] : null,
            'department' => $row['DepartmentName'],
        ];
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            ActivityLog::write($u['id'], $u['email'], 'logout', 'auth', (string)$u['id'], null);
        }
        self::clearSession();
    }

    private static function clearSession(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
