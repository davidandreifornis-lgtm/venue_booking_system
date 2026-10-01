<?php

require_once __DIR__ . '/Database.php';

class ActivityLog
{
    public static function write(
        ?int $userId,
        ?string $email,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        $detail = null
    ): void {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                "INSERT INTO dbo.ActivityLogs (ActorUserId, ActorEmail, Action, EntityType, EntityId, Detail, IpAddress)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $email,
                $action,
                $entityType,
                $entityId,
                $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Never break the request because of logging failure
            error_log('ActivityLog failed: ' . $e->getMessage());
        }
    }
}
