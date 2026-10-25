<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';

/**
 * Per-venue operating hours and blackout windows.
 * Falls back to global operating_hours_* settings when no per-venue rows exist.
 */
class VenueScheduleService
{
    public static function getHours(int $venueId): array
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'VenueOperatingHours')) {
            return self::globalHoursAsWeek();
        }
        $stmt = $pdo->prepare(
            'SELECT DayOfWeek, OpenTime, CloseTime, IsClosed FROM dbo.VenueOperatingHours WHERE VenueId = ? ORDER BY DayOfWeek'
        );
        $stmt->execute([$venueId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return self::globalHoursAsWeek();
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'dayOfWeek' => (int)$r['DayOfWeek'],
                'openTime' => substr((string)$r['OpenTime'], 0, 5),
                'closeTime' => substr((string)$r['CloseTime'], 0, 5),
                'isClosed' => (bool)$r['IsClosed'],
            ];
        }
        return $out;
    }

    public static function setHours(int $venueId, array $days, ?array $actor = null): void
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'VenueOperatingHours')) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM dbo.VenueOperatingHours WHERE VenueId = ?')->execute([$venueId]);
            $ins = $pdo->prepare(
                'INSERT INTO dbo.VenueOperatingHours (VenueId, DayOfWeek, OpenTime, CloseTime, IsClosed) VALUES (?,?,?,?,?)'
            );
            foreach ($days as $d) {
                $ins->execute([
                    $venueId,
                    (int)$d['dayOfWeek'],
                    $d['openTime'] ?? '08:00',
                    $d['closeTime'] ?? '17:00',
                    !empty($d['isClosed']) ? 1 : 0,
                ]);
            }
            $pdo->commit();
            require_once __DIR__ . '/ActivityLog.php';
            ActivityLog::write(
                $actor['id'] ?? null,
                $actor['email'] ?? null,
                'venue.hours_set',
                'venue',
                (string)$venueId,
                ['days' => count($days)]
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function listBlackouts(int $venueId, ?string $from = null, ?string $to = null): array
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'VenueBlackouts')) {
            return [];
        }
        $sql = 'SELECT BlackoutId, VenueId, StartAt, EndAt, Reason, CreatedAt FROM dbo.VenueBlackouts WHERE VenueId = ?';
        $bind = [$venueId];
        if ($from) {
            $sql .= ' AND EndAt >= ?';
            $bind[] = $from;
        }
        if ($to) {
            $sql .= ' AND StartAt <= ?';
            $bind[] = $to;
        }
        $sql .= ' ORDER BY StartAt';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return array_map(static function ($r) {
            return [
                'id' => (int)$r['BlackoutId'],
                'venueId' => (int)$r['VenueId'],
                'startAt' => $r['StartAt'],
                'endAt' => $r['EndAt'],
                'reason' => $r['Reason'],
                'createdAt' => $r['CreatedAt'],
            ];
        }, $stmt->fetchAll());
    }

    public static function addBlackout(int $venueId, string $startAt, string $endAt, ?string $reason, ?array $actor = null): int
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'VenueBlackouts')) {
            return 0;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO dbo.VenueBlackouts (VenueId, StartAt, EndAt, Reason, CreatedByUserId)
             OUTPUT INSERTED.BlackoutId
             VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$venueId, $startAt, $endAt, $reason, $actor['id'] ?? null]);
        $row = $stmt->fetch();
        return $row ? (int)$row['BlackoutId'] : 0;
    }

    public static function deleteBlackout(int $blackoutId): void
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'VenueBlackouts')) {
            return;
        }
        $pdo->prepare('DELETE FROM dbo.VenueBlackouts WHERE BlackoutId = ?')->execute([$blackoutId]);
    }

    /**
     * Returns null if OK, or an error message if the slot violates hours or a blackout.
     */
    public static function validateSlot(int $venueId, string $date, string $startTime, string $endTime): ?string
    {
        if (Settings::get('venue_hours_enabled') !== '1') {
            return null;
        }
        $pdo = Database::pdo();

        // Blackouts
        if (self::tableExists($pdo, 'VenueBlackouts')) {
            $startAt = substr($date, 0, 10) . ' ' . substr($startTime, 0, 5);
            $endAt   = substr($date, 0, 10) . ' ' . substr($endTime, 0, 5);
            $stmt = $pdo->prepare(
                'SELECT TOP 1 Reason FROM dbo.VenueBlackouts
                 WHERE VenueId = ? AND StartAt < ? AND EndAt > ?'
            );
            $stmt->execute([$venueId, $endAt, $startAt]);
            $b = $stmt->fetch();
            if ($b) {
                $reason = $b['Reason'] ?: 'maintenance';
                return "Venue is unavailable during this time ({$reason}).";
            }
        }

        // Operating hours for that weekday. The weekday is worked out here (0 = Sunday ... 6 = Saturday) instead of
        // with SQL Server's DATEPART(WEEKDAY), which changes meaning with the server's DATEFIRST / language setting.
        if (self::tableExists($pdo, 'VenueOperatingHours')) {
            $dow = (int)date('w', strtotime($date));
            $stmt = $pdo->prepare(
                'SELECT OpenTime, CloseTime, IsClosed FROM dbo.VenueOperatingHours
                 WHERE VenueId = ? AND DayOfWeek = ?'
            );
            $stmt->execute([$venueId, $dow]);
            $h = $stmt->fetch();
            if ($h) {
                if ((int)$h['IsClosed']) {
                    return 'Venue is closed on this day.';
                }
                $open = substr((string)$h['OpenTime'], 0, 5);
                $close = substr((string)$h['CloseTime'], 0, 5);
                if (substr($startTime, 0, 5) < $open || substr($endTime, 0, 5) > $close) {
                    return "Booking must be within venue hours ({$open}–{$close}).";
                }
            }
        }

        return null;
    }

    private static function globalHoursAsWeek(): array
    {
        $open = Settings::get('operating_hours_start') ?: '08:00';
        $close = Settings::get('operating_hours_end') ?: '17:00';
        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            $closed = ($d === 0 || $d === 6); // default weekend closed
            $days[] = [
                'dayOfWeek' => $d,
                'openTime' => $open,
                'closeTime' => $close,
                'isClosed' => $closed,
            ];
        }
        return $days;
    }

    private static function tableExists(PDO $pdo, string $name): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT OBJECT_ID(N'dbo.{$name}', N'U') AS id");
            $stmt->execute();
            $r = $stmt->fetch();
            return $r && $r['id'] !== null;
        } catch (Throwable $e) {
            return false;
        }
    }
}
