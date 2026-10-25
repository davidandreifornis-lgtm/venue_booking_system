<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/BookingService.php';
require_once __DIR__ . '/Response.php';

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
            return ['created' => 0, 'ids' => [], 'skipped' => [], 'message' => 'Only FREQ=WEEKLY is supported'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM dbo.Bookings WHERE BookingId = ?');
        $stmt->execute([$parentBookingId]);
        $parent = $stmt->fetch();
        if (!$parent) {
            return ['created' => 0, 'ids' => [], 'skipped' => [], 'message' => 'Parent not found'];
        }
        // Only the person who made the booking may repeat it (the copies are created in their name).
        if ((int)$parent['RequesterUserId'] !== (int)$user['id']) {
            Response::error('Only the requester can repeat this booking.', 403);
        }
        if (!empty($parent['IsRecurrenceInstance'])) {
            return ['created' => 0, 'ids' => [], 'skipped' => [], 'message' => 'This booking is already part of a series.'];
        }

        // Mark parent
        try {
            $pdo->prepare(
                'UPDATE dbo.Bookings SET RecurrenceRule = ?, IsRecurrenceInstance = 0 WHERE BookingId = ?'
            )->execute([$rule, $parentBookingId]);
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        $parentDate = substr((string)($parent['BookingDate'] instanceof DateTimeInterface ? $parent['BookingDate']->format('Y-m-d') : $parent['BookingDate']), 0, 10);
        $byday = $parsed['byday'] ?: [self::phpDowToCode((int)date('w', strtotime($parentDate)))];
        $count = min($parsed['count'] ?: 8, $max);
        $created = [];
        $skipped = [];
        $date = new DateTimeImmutable($parentDate);
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
                'startTime' => substr((string)$parent['StartTime'], 0, 5),
                'endTime' => substr((string)$parent['EndTime'], 0, 5),
                'purpose' => $parent['Purpose'],
                'attendees' => (int)$parent['Attendees'],
                'departmentId' => $parent['DepartmentId'] !== null ? (int)$parent['DepartmentId'] : null,
                'requirements' => [], // keep simple; equipment not auto-copied
            ];

            // create() reports problems with Response::error(), which ENDS the request in production, so a try/catch
            // cannot skip a bad week. Check the rules first and only create weeks that will be accepted.
            $problems = BookingService::validatePayload($payload, null, $user);
            if ($problems) {
                $skipped[] = ['date' => $payload['date'], 'reason' => (string)reset($problems)];
                continue;
            }
            try {
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
            } catch (ApiResponse $e) {
                $skipped[] = ['date' => $payload['date'], 'reason' => $e->getMessage()];
            }
        }

        return ['created' => count($created), 'ids' => $created, 'skipped' => $skipped, 'message' => null];
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
