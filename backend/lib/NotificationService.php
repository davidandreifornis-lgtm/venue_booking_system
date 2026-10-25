<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MailService.php';

class NotificationService
{
    /**
     * Create in-app notification and optionally send e-mail.
     * Existing callers stay compatible — extra params are optional.
     */
    public static function notify(int $userId, string $title, string $body, ?string $link = null): void
    {
        $pdo = Database::pdo();
        $emailStatus = MailService::isEnabled() ? 'pending' : 'skipped';

        // Support both old schema (no EmailStatus) and new
        $hasEmail = self::hasEmailColumns($pdo);
        if ($hasEmail) {
            $stmt = $pdo->prepare(
                "INSERT INTO dbo.Notifications (UserId, Title, Body, LinkUrl, EmailStatus)
                 OUTPUT INSERTED.NotificationId
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $body, $link, $emailStatus]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO dbo.Notifications (UserId, Title, Body, LinkUrl)
                 OUTPUT INSERTED.NotificationId
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $body, $link]);
        }
        $row = $stmt->fetch();
        $notifId = $row ? (int)$row['NotificationId'] : 0;

        if ($emailStatus === 'pending' && $notifId > 0 && $hasEmail) {
            self::trySendEmail($notifId, $userId, $title, $body, $link);
        }
    }

    private static ?bool $emailColumns = null;

    private static function hasEmailColumns(PDO $pdo): bool
    {
        if (self::$emailColumns !== null) {
            return self::$emailColumns;
        }
        try {
            $chk = $pdo->query("SELECT COL_LENGTH('dbo.Notifications','EmailStatus') AS c");
            $r = $chk ? $chk->fetch() : null;
            self::$emailColumns = (bool)($r && $r['c'] !== null);
        } catch (Throwable $e) {
            self::$emailColumns = false;
        }
        return self::$emailColumns;
    }

    /** Links are stored relative to /pages/ (booking-details.html?id=1). E-mail needs a full address to be clickable. */
    private static function absoluteLink(?string $link): ?string
    {
        if ($link === null || $link === '' || preg_match('#^https?://#i', $link)) {
            return $link;
        }
        require_once __DIR__ . '/Settings.php';
        $base = trim((string)Settings::get('app_base_url'));
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
            $root = preg_replace('#/backend/.*$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $root . '/pages';
        }
        return $base === '' ? $link : rtrim($base, '/') . '/' . ltrim($link, '/');
    }

    private static function trySendEmail(int $notifId, int $userId, string $title, string $body, ?string $link): void
    {
        $pdo = Database::pdo();
        $u = $pdo->prepare('SELECT Email, FullName FROM dbo.Users WHERE UserId = ?');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user || empty($user['Email']) || !filter_var($user['Email'], FILTER_VALIDATE_EMAIL)) {
            // Demo/short logins such as "admin" are not mailboxes: record it quietly, it is not a delivery failure.
            $pdo->prepare(
                "UPDATE dbo.Notifications SET EmailStatus = N'skipped', EmailError = N'User has no valid e-mail address' WHERE NotificationId = ?"
            )->execute([$notifId]);
            return;
        }
        $link = self::absoluteLink($link);

        $html = '<p>' . htmlspecialchars($body) . '</p>';
        if ($link) {
            $html .= '<p><a href="' . htmlspecialchars($link) . '">Open in Venue Booking</a></p>';
        }
        $html .= '<hr><p style="color:#666;font-size:12px">This is an automated notification from the Venue Booking System.</p>';

        $result = MailService::send($user['Email'], $title, $html, $body);

        if ($result['ok']) {
            $pdo->prepare(
                "UPDATE dbo.Notifications SET EmailStatus = N'sent', EmailSentAt = SYSUTCDATETIME(), EmailError = NULL WHERE NotificationId = ?"
            )->execute([$notifId]);
        } else {
            $pdo->prepare(
                "UPDATE dbo.Notifications SET EmailStatus = N'failed', EmailError = ? WHERE NotificationId = ?"
            )->execute([function_exists('mb_substr') ? mb_substr((string)$result['error'], 0, 500) : substr((string)$result['error'], 0, 500), $notifId]);
            require_once __DIR__ . '/ActivityLog.php';
            ActivityLog::write(
                null,
                $user['Email'],
                'email_failed',
                'notification',
                (string)$notifId,
                ['error' => $result['error'], 'title' => $title]
            );
        }
    }

    public static function listForUser(int $userId, array $params = []): array
    {
        $pdo = Database::pdo();
        $hasEmail = self::hasEmailColumns($pdo);

        $cols = 'NotificationId, Title, Body, LinkUrl, IsRead, CreatedAt';
        if ($hasEmail) {
            $cols .= ', EmailStatus, EmailError, EmailSentAt';
        }

        $sql = "SELECT {$cols} FROM dbo.Notifications WHERE UserId = ?";
        $bind = [$userId];
        if (isset($params['unread']) && $params['unread'] === '1') {
            $sql .= ' AND IsRead = 0';
        }
        $sql .= ' ORDER BY CreatedAt DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return array_map(static function ($r) use ($hasEmail) {
            $item = [
                'id' => (int)$r['NotificationId'],
                'title' => $r['Title'],
                'body' => $r['Body'],
                'link' => $r['LinkUrl'],
                'isRead' => (bool)$r['IsRead'],
                'createdAt' => $r['CreatedAt'],
            ];
            if ($hasEmail) {
                $item['emailStatus'] = $r['EmailStatus'] ?? null;
                $item['emailError'] = $r['EmailError'] ?? null;
                $item['emailSentAt'] = $r['EmailSentAt'] ?? null;
            }
            return $item;
        }, $stmt->fetchAll());
    }

    public static function markRead(int $userId, int $id): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Notifications SET IsRead = 1 WHERE NotificationId = ? AND UserId = ?"
        )->execute([$id, $userId]);
    }
}
