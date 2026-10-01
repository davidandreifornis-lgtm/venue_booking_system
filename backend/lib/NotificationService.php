<?php

require_once __DIR__ . '/Database.php';

class NotificationService
{
    public static function notify(int $userId, string $title, string $body, ?string $link = null): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            "INSERT INTO dbo.Notifications (UserId, Title, Body, LinkUrl) VALUES (?, ?, ?, ?)"
        )->execute([$userId, $title, $body, $link]);
    }

    public static function listForUser(int $userId, array $params = []): array
    {
        $pdo = Database::pdo();
        $sql = "SELECT NotificationId, Title, Body, LinkUrl, IsRead, CreatedAt
                FROM dbo.Notifications WHERE UserId = ?";
        $bind = [$userId];
        if (isset($params['unread']) && $params['unread'] === '1') {
            $sql .= ' AND IsRead = 0';
        }
        $sql .= ' ORDER BY CreatedAt DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return array_map(static function ($r) {
            return [
                'id' => (int)$r['NotificationId'],
                'title' => $r['Title'],
                'body' => $r['Body'],
                'link' => $r['LinkUrl'],
                'isRead' => (bool)$r['IsRead'],
                'createdAt' => $r['CreatedAt'],
            ];
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
