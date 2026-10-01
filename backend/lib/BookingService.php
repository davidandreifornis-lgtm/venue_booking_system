<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/NotificationService.php';

class BookingService
{
    public const STATUS_PENDING_MANAGER = 'Pending Manager Validation';
    public const STATUS_MANAGER_VALIDATED = 'Manager Validated';
    public const STATUS_PENDING_HR = 'Pending HR Decision';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_DECLINED = 'Declined';
    public const STATUS_RESCHEDULED = 'Rescheduled';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_COMPLETED = 'Completed';

    /** Statuses that block venue time (overlap). */
    public static function blockingStatuses(bool $includePending = false): array
    {
        $s = [self::STATUS_APPROVED, self::STATUS_RESCHEDULED];
        if ($includePending) {
            $s[] = self::STATUS_PENDING_MANAGER;
            $s[] = self::STATUS_MANAGER_VALIDATED;
            $s[] = self::STATUS_PENDING_HR;
        }
        return $s;
    }

    public static function mapRow(array $r): array
    {
        return [
            'id' => (int)$r['BookingId'],
            'ref' => $r['ReferenceCode'],
            'venueId' => (int)$r['VenueId'],
            'venueName' => $r['VenueName'] ?? null,
            'venue' => $r['VenueName'] ?? null,
            'requesterId' => (int)$r['RequesterUserId'],
            'requesterName' => $r['RequesterName'] ?? null,
            'requester' => $r['RequesterName'] ?? null,
            'departmentId' => $r['DepartmentId'] !== null ? (int)$r['DepartmentId'] : null,
            'department' => $r['DepartmentName'] ?? null,
            'departmentManagerId' => $r['DepartmentManagerId'] !== null ? (int)$r['DepartmentManagerId'] : null,
            'departmentManager' => $r['ManagerName'] ?? null,
            'managerName' => $r['ManagerName'] ?? null,
            'date' => self::fmtDate($r['BookingDate']),
            'startTime' => self::fmtTime($r['StartTime']),
            'endTime' => self::fmtTime($r['EndTime']),
            'purpose' => $r['Purpose'],
            'eventType' => $r['EventType'],
            'attendees' => (int)$r['Attendees'],
            'additionalRequirements' => $r['AdditionalRequirements'],
            'status' => $r['Status'],
            'managerNotes' => $r['ManagerNotes'],
            'hrNotes' => $r['HrNotes'],
            'cancelReason' => $r['CancelReason'],
            'declineReason' => $r['DeclineReason'],
            'createdAt' => $r['CreatedAt'] ?? null,
            'updatedAt' => $r['UpdatedAt'] ?? null,
        ];
    }

    private static function fmtDate($v): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        return substr((string)$v, 0, 10);
    }

    private static function fmtTime($v): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i');
        }
        $s = (string)$v;
        // SQL Server time may be HH:MM:SS.nnnnnnn
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return $s;
    }

    private static function baseSelect(): string
    {
        return "SELECT b.*,
                       v.Name AS VenueName,
                       u.FullName AS RequesterName,
                       d.Name AS DepartmentName,
                       m.FullName AS ManagerName
                FROM dbo.Bookings b
                INNER JOIN dbo.Venues v ON v.VenueId = b.VenueId
                INNER JOIN dbo.Users u ON u.UserId = b.RequesterUserId
                LEFT JOIN dbo.Departments d ON d.DepartmentId = b.DepartmentId
                LEFT JOIN dbo.Users m ON m.UserId = b.DepartmentManagerId";
    }

    public static function getById(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE b.BookingId = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::mapRow($row) : null;
    }

    public static function listForUser(array $user, array $params = []): array
    {
        $pdo = Database::pdo();
        $where = ['1=1'];
        $bind = [];

        $scope = $params['scope'] ?? null;
        $status = $params['status'] ?? null;

        if ($user['role'] === 'Employee' || $scope === 'mine') {
            $where[] = 'b.RequesterUserId = ?';
            $bind[] = $user['id'];
        } elseif ($user['role'] === 'Manager' && $scope !== 'all') {
            // Own department
            if ($user['departmentId']) {
                $where[] = 'b.DepartmentId = ?';
                $bind[] = $user['departmentId'];
            } else {
                $where[] = 'b.RequesterUserId = ?';
                $bind[] = $user['id'];
            }
        }
        // HR / Admin: all unless scope=mine

        if ($status) {
            $parts = array_map('trim', explode(',', $status));
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $where[] = "b.Status IN ($ph)";
            foreach ($parts as $p) {
                $bind[] = $p;
            }
        }

        $sql = self::baseSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY b.BookingDate DESC, b.StartTime DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return array_map([self::class, 'mapRow'], $stmt->fetchAll());
    }

    public static function validatePayload(array $payload, ?int $excludeBookingId = null): array
    {
        $errors = [];
        foreach (['venueId' => 'Venue', 'date' => 'Date', 'startTime' => 'Start time', 'endTime' => 'End time', 'purpose' => 'Purpose'] as $k => $label) {
            if (!isset($payload[$k]) || trim((string)$payload[$k]) === '') {
                $errors[$k] = "$label is required.";
            }
        }
        if ($errors) {
            return $errors;
        }

        $start = self::toMinutes($payload['startTime']);
        $end = self::toMinutes($payload['endTime']);
        if ($start === null || $end === null || $end <= $start) {
            $errors['endTime'] = 'End time must be after start time.';
        }

        $date = substr((string)$payload['date'], 0, 10);
        $today = gmdate('Y-m-d');
        if ($date < $today) {
            $errors['date'] = 'Cannot book a past date.';
        }

        $window = (int)(Settings::get('booking_window_days', '90') ?: 90);
        $maxDate = gmdate('Y-m-d', time() + $window * 86400);
        if ($date > $maxDate) {
            $errors['date'] = "Bookings cannot be more than {$window} days ahead.";
        }

        $ohStart = Settings::get('operating_hours_start', '08:00');
        $ohEnd = Settings::get('operating_hours_end', '18:00');
        if ($start !== null && $end !== null) {
            $os = self::toMinutes($ohStart);
            $oe = self::toMinutes($ohEnd);
            if ($os !== null && $start < $os) {
                $errors['startTime'] = "Start time must be on or after {$ohStart}.";
            }
            if ($oe !== null && $end > $oe) {
                $errors['endTime'] = "End time must be on or before {$ohEnd}.";
            }
            $dur = $end - $start;
            $minD = (int)(Settings::get('min_duration_minutes', '30') ?: 30);
            $maxD = (int)(Settings::get('max_duration_minutes', '480') ?: 480);
            if ($dur < $minD) {
                $errors['endTime'] = "Minimum duration is {$minD} minutes.";
            }
            if ($dur > $maxD) {
                $errors['endTime'] = "Maximum duration is {$maxD} minutes.";
            }
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT VenueId, Capacity, Status, IsActive FROM dbo.Venues WHERE VenueId = ?');
        $stmt->execute([(int)$payload['venueId']]);
        $venue = $stmt->fetch();
        if (!$venue || !(int)$venue['IsActive']) {
            $errors['venueId'] = 'Venue is not available.';
        } elseif ($venue['Status'] !== 'Available') {
            $errors['venueId'] = 'Venue is not available for booking.';
        } elseif (isset($payload['attendees']) && (int)$payload['attendees'] > (int)$venue['Capacity']) {
            $errors['attendees'] = 'Attendees exceed venue capacity (' . $venue['Capacity'] . ').';
        }

        if (!$errors) {
            $conflict = self::findConflict(
                (int)$payload['venueId'],
                $date,
                $payload['startTime'],
                $payload['endTime'],
                $excludeBookingId
            );
            if ($conflict) {
                $errors['conflict'] = 'This venue is already booked for the selected time (ref: ' . $conflict['ReferenceCode'] . ').';
            }
        }

        return $errors;
    }

    public static function findConflict(int $venueId, string $date, string $start, string $end, ?int $excludeId = null): ?array
    {
        $includePending = Settings::get('overlap_include_pending', '0') === '1';
        $statuses = self::blockingStatuses($includePending);
        $pdo = Database::pdo();

        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT TOP 1 BookingId, ReferenceCode, StartTime, EndTime, Status
                FROM dbo.Bookings
                WHERE VenueId = ?
                  AND BookingDate = ?
                  AND Status IN ($ph)
                  AND StartTime < ?
                  AND EndTime > ?";
        $bind = array_merge([$venueId, $date], $statuses, [self::normalizeTime($end), self::normalizeTime($start)]);
        if ($excludeId) {
            $sql .= ' AND BookingId <> ?';
            $bind[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $user, array $payload): array
    {
        $errors = self::validatePayload($payload);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Re-check conflict inside transaction
            $conflict = self::findConflict(
                (int)$payload['venueId'],
                substr($payload['date'], 0, 10),
                $payload['startTime'],
                $payload['endTime'],
                null
            );
            if ($conflict) {
                $pdo->rollBack();
                Response::error('This venue is already booked for the selected time (ref: ' . $conflict['ReferenceCode'] . ').', 409);
            }

            $ref = self::nextReference($pdo);
            $deptId = $user['departmentId'];
            $mgrId = isset($payload['departmentManagerId']) ? (int)$payload['departmentManagerId'] : null;

            $stmt = $pdo->prepare(
                "INSERT INTO dbo.Bookings
                  (ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
                   BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
                   AdditionalRequirements, Status)
                 OUTPUT INSERTED.BookingId
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $ref,
                (int)$payload['venueId'],
                $user['id'],
                $deptId,
                $mgrId ?: null,
                substr($payload['date'], 0, 10),
                self::normalizeTime($payload['startTime']),
                self::normalizeTime($payload['endTime']),
                trim($payload['purpose']),
                $payload['eventType'] ?? null,
                max(1, (int)($payload['attendees'] ?? 1)),
                $payload['additionalRequirements'] ?? null,
                self::STATUS_PENDING_MANAGER,
            ]);
            $id = (int)$stmt->fetchColumn();

            self::history($pdo, $id, null, self::STATUS_PENDING_MANAGER, $user['id'], 'Submitted');
            $pdo->commit();

            ActivityLog::write($user['id'], $user['email'], 'booking.create', 'booking', (string)$id, ['ref' => $ref]);

            // Notify manager
            if ($mgrId) {
                NotificationService::notify(
                    $mgrId,
                    'New booking request',
                    $user['name'] . ' submitted request ' . $ref,
                    'booking-details.html?id=' . $id
                );
            }

            return self::getById($id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function validateByManager(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if ($b['status'] !== self::STATUS_PENDING_MANAGER) {
            Response::error('Booking is not awaiting manager validation.', 409);
        }
        if ($user['role'] === 'Manager') {
            if ($b['requesterId'] === $user['id']) {
                Response::error('You cannot validate your own request.', 403);
            }
            if ($user['departmentId'] && $b['departmentId'] && $user['departmentId'] !== $b['departmentId']) {
                Response::error('You can only validate requests from your department.', 403);
            }
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, ValidatedAt = SYSUTCDATETIME(),
                ValidatedByUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([
            self::STATUS_PENDING_HR,
            $payload['notes'] ?? null,
            $user['id'],
            $id,
        ]);
        // Also set Manager Validated intermediate is merged into Pending HR for simplicity
        self::history($pdo, $id, self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, $user['id'], $payload['notes'] ?? null);

        ActivityLog::write($user['id'], $user['email'], 'booking.validate', 'booking', (string)$id, null);
        NotificationService::notify(
            $b['requesterId'],
            'Request validated',
            'Your booking ' . $b['ref'] . ' was validated and sent to HR.',
            'booking-details.html?id=' . $id
        );
        // Notify HR users
        foreach (self::usersByRole('HR') as $hrId) {
            NotificationService::notify($hrId, 'Booking ready for HR', 'Request ' . $b['ref'] . ' awaits decision.', 'booking-details.html?id=' . $id);
        }

        return self::getById($id);
    }

    public static function approve(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if (!in_array($b['status'], [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED], true)) {
            Response::error('Booking is not awaiting HR decision.', 409);
        }
        if ($b['requesterId'] === $user['id'] && $user['role'] !== 'Administrator') {
            Response::error('You cannot approve your own request.', 403);
        }

        $conflict = self::findConflict($b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id);
        if ($conflict) {
            Response::error('Cannot approve: venue conflict with ' . $conflict['ReferenceCode'] . '.', 409);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, HrNotes = ?, DecidedAt = SYSUTCDATETIME(),
                DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([self::STATUS_APPROVED, $payload['notes'] ?? null, $user['id'], $id]);
        self::history($pdo, $id, $b['status'], self::STATUS_APPROVED, $user['id'], $payload['notes'] ?? null);

        ActivityLog::write($user['id'], $user['email'], 'booking.approve', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Booking approved', 'Your booking ' . $b['ref'] . ' was approved.', 'booking-details.html?id=' . $id);

        return self::getById($id);
    }

    public static function decline(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        $reason = trim((string)($payload['reason'] ?? $payload['notes'] ?? ''));
        if ($reason === '') {
            Response::error('A decline reason is required.', 422);
        }

        // Manager may reject at validation stage; HR at decision stage
        $allowed = [self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED];
        if (!in_array($b['status'], $allowed, true)) {
            Response::error('Booking cannot be declined in its current status.', 409);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, HrNotes = COALESCE(?, HrNotes),
                ManagerNotes = CASE WHEN ? = 'Manager' THEN COALESCE(?, ManagerNotes) ELSE ManagerNotes END,
                DecidedAt = SYSUTCDATETIME(), DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
             WHERE BookingId = ?"
        )->execute([
            self::STATUS_DECLINED,
            $reason,
            $payload['notes'] ?? $reason,
            $user['role'],
            $reason,
            $user['id'],
            $id,
        ]);
        self::history($pdo, $id, $b['status'], self::STATUS_DECLINED, $user['id'], $reason);

        ActivityLog::write($user['id'], $user['email'], 'booking.decline', 'booking', (string)$id, ['reason' => $reason]);
        NotificationService::notify($b['requesterId'], 'Booking declined', 'Your booking ' . $b['ref'] . ' was declined.', 'booking-details.html?id=' . $id);

        return self::getById($id);
    }

    public static function reschedule(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if (!in_array($b['status'], [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED, self::STATUS_APPROVED], true)) {
            Response::error('Booking cannot be rescheduled in its current status.', 409);
        }

        $merged = array_merge($b, [
            'venueId' => $payload['venueId'] ?? $b['venueId'],
            'date' => $payload['date'] ?? $b['date'],
            'startTime' => $payload['startTime'] ?? $b['startTime'],
            'endTime' => $payload['endTime'] ?? $b['endTime'],
            'attendees' => $payload['attendees'] ?? $b['attendees'],
            'purpose' => $b['purpose'],
        ]);
        $errors = self::validatePayload($merged, $id);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET VenueId = ?, BookingDate = ?, StartTime = ?, EndTime = ?,
                Status = ?, HrNotes = COALESCE(?, HrNotes), DecidedAt = SYSUTCDATETIME(),
                DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([
            (int)$merged['venueId'],
            substr($merged['date'], 0, 10),
            self::normalizeTime($merged['startTime']),
            self::normalizeTime($merged['endTime']),
            self::STATUS_RESCHEDULED,
            $payload['notes'] ?? null,
            $user['id'],
            $id,
        ]);
        self::history($pdo, $id, $b['status'], self::STATUS_RESCHEDULED, $user['id'], $payload['notes'] ?? null);

        ActivityLog::write($user['id'], $user['email'], 'booking.reschedule', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Booking rescheduled', 'Your booking ' . $b['ref'] . ' was rescheduled.', 'booking-details.html?id=' . $id);

        return self::getById($id);
    }

    public static function cancel(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        $reason = trim((string)($payload['reason'] ?? $payload['notes'] ?? ''));
        $isOwner = $b['requesterId'] === $user['id'];
        $isAdmin = $user['role'] === 'Administrator';

        if (!$isOwner && !$isAdmin) {
            Response::error('Forbidden.', 403);
        }

        $terminal = [self::STATUS_CANCELLED, self::STATUS_COMPLETED, self::STATUS_DECLINED];
        if (in_array($b['status'], $terminal, true)) {
            Response::error('Booking is already closed.', 409);
        }

        if (in_array($b['status'], [self::STATUS_APPROVED, self::STATUS_RESCHEDULED], true) && $reason === '') {
            Response::error('A cancellation reason is required for approved bookings.', 422);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, CancelReason = ?, CancelledAt = SYSUTCDATETIME(),
                CancelledByUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([self::STATUS_CANCELLED, $reason ?: null, $user['id'], $id]);
        self::history($pdo, $id, $b['status'], self::STATUS_CANCELLED, $user['id'], $reason ?: null);

        ActivityLog::write($user['id'], $user['email'], 'booking.cancel', 'booking', (string)$id, null);
        return self::getById($id);
    }

    private static function history(PDO $pdo, int $bookingId, ?string $from, string $to, ?int $by, ?string $reason): void
    {
        $pdo->prepare(
            "INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$bookingId, $from, $to, $by, $reason]);
    }

    private static function nextReference(PDO $pdo): string
    {
        $year = gmdate('Y');
        $stmt = $pdo->query("SELECT COUNT(*) FROM dbo.Bookings WHERE ReferenceCode LIKE 'VB-{$year}-%'");
        $n = (int)$stmt->fetchColumn() + 1;
        return sprintf('VB-%s-%04d', $year, $n);
    }

    private static function toMinutes($t): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$t, $m)) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function normalizeTime(string $t): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        }
        return $t;
    }

    private static function usersByRole(string $roleCode): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT u.UserId FROM dbo.Users u INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
             WHERE r.RoleCode = ? AND u.IsActive = 1"
        );
        $stmt->execute([$roleCode]);
        return array_map('intval', array_column($stmt->fetchAll(), 'UserId'));
    }
}
