<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/BookingService.php';

/**
 * Generates additional booking instances for a simple weekly recurrence.
 * Does not modify the original create() flow — call expandSeries() after
 * a parent booking has been created when the client sends recurrence.
 *
 * Rule format: FREQ=WEEKLY;BYDAY=MO,TU,WE;COUNT=8
 * Only WEEKLY is supported in this version.
 */
class RecurrenceService
{
    public static function expandSeries(int $parentBookingId, string $rule, array $user): array
    {
        $max = Settings::int('recurring_max_count', 52);
        $parsed = self::parseRule($rule);
        if (!$parsed || $parsed['freq'] !== 'WEEKLY') {
            return ['created' => 0, 'ids' => [], 'message' => 'Only FREQ=WEEKLY is supported'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM dbo.Bookings WHERE BookingId = ?');
        $stmt->execute([$parentBookingId]);
        $parent = $stmt->fetch();
        if (!$parent) {
            return ['created' => 0, 'ids' => [], 'message' => 'Parent not found'];
        }

        // Mark parent
        try {
            $pdo->prepare(
                'UPDATE dbo.Bookings SET RecurrenceRule = ?, IsRecurrenceInstance = 0 WHERE BookingId = ?'
            )->execute([$rule, $parentBookingId]);
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        $byday = $parsed['byday'] ?: [self::phpDowToCode((int)date('w', strtotime($parent['BookingDate'])))];
        $count = min($parsed['count'] ?: 8, $max);
        $created = [];
        $date = new DateTimeImmutable($parent['BookingDate']);
        $instances = 1; // parent counts as 1

        for ($i = 0; $i < 400 && $instances < $count; $i++) {
            $date = $date->modify('+1 day');
            $code = self::phpDowToCode((int)$date->format('w'));
            if (!in_array($code, $byday, true)) {
                continue;
            }
            $instances++;
            $payload = [
                'venueId' => (int)$parent['VenueId'],
                'date' => $date->format('Y-m-d'),
                'startTime' => substr($parent['StartTime'], 0, 5),
                'endTime' => substr($parent['EndTime'], 0, 5),
                'purpose' => $parent['Purpose'],
                'attendees' => (int)$parent['Attendees'],
                'departmentId' => $parent['DepartmentId'] !== null ? (int)$parent['DepartmentId'] : null,
                'requirements' => [], // keep simple; equipment not auto-copied
            ];
            try {
                // Use internal insert path by calling create — may go through approval again
                $row = BookingService::create($user, $payload);
                $newId = $row['id'] ?? null;
                if ($newId) {
                    try {
                        $pdo->prepare(
                            'UPDATE dbo.Bookings SET RecurrenceParentId = ?, IsRecurrenceInstance = 1, RecurrenceRule = ? WHERE BookingId = ?'
                        )->execute([$parentBookingId, $rule, $newId]);
                    } catch (Throwable $e) {
                        // ignore if columns missing
                    }
                    $created[] = $newId;
                }
            } catch (Throwable $e) {
                // skip conflicts / validation failures for that day
                continue;
            }
        }

        return ['created' => count($created), 'ids' => $created, 'message' => null];
    }

    private static function parseRule(string $rule): ?array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rule))) as $seg) {
            if (strpos($seg, '=') === false) continue;
            [$k, $v] = explode('=', $seg, 2);
            $parts[$k] = $v;
        }
        if (empty($parts['FREQ'])) return null;
        $byday = [];
        if (!empty($parts['BYDAY'])) {
            $byday = array_filter(array_map('trim', explode(',', $parts['BYDAY'])));
        }
        return [
            'freq' => $parts['FREQ'],
            'byday' => $byday,
            'count' => isset($parts['COUNT']) ? (int)$parts['COUNT'] : null,
        ];
    }

    private static function phpDowToCode(int $w): string
    {
        // 0=Sun .. 6=Sat
        return ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][$w] ?? 'MO';
    }
}
