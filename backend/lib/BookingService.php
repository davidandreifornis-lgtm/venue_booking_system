<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/RequirementOptions.php';

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
    public const STATUS_REVISION = 'Revision Requested';
    public const STATUS_REJECTED_MANAGER = 'Rejected by Manager';
    public const STATUS_PROPOSED = 'Reschedule Proposed';

    /** Statuses that block venue time (overlap). */
    public static function blockingStatuses(bool $includePending = false): array
    {
        $s = [self::STATUS_APPROVED, self::STATUS_RESCHEDULED, self::STATUS_PROPOSED];
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
            'phase' => self::phaseOf($r),
            'managerNotes' => $r['ManagerNotes'],
            'hrNotes' => $r['HrNotes'],
            'cancelReason' => $r['CancelReason'],
            'declineReason' => $r['DeclineReason'],
            'createdAt' => $r['CreatedAt'] ?? null,
            'updatedAt' => $r['UpdatedAt'] ?? null,
            'proposedVenueId' => isset($r['ProposedVenueId']) ? (int)$r['ProposedVenueId'] : null,
            'proposedDate' => !empty($r['ProposedDate']) ? self::fmtDate($r['ProposedDate']) : null,
            'proposedStartTime' => !empty($r['ProposedStartTime']) ? self::fmtTime($r['ProposedStartTime']) : null,
            'proposedEndTime' => !empty($r['ProposedEndTime']) ? self::fmtTime($r['ProposedEndTime']) : null,
            'proposalNotes' => $r['ProposalNotes'] ?? null,
        ];
    }

    /** 'live' while an approved meeting is happening, 'done' once it has ended, else null. */
    private static function phaseOf(array $r): ?string
    {
        if ($r['Status'] === self::STATUS_COMPLETED) {
            return 'done';
        }
        if ($r['Status'] !== self::STATUS_APPROVED && $r['Status'] !== self::STATUS_RESCHEDULED) {
            return null;
        }
        $d = self::fmtDate($r['BookingDate']);
        $now = time();
        $start = strtotime($d . ' ' . self::fmtTime($r['StartTime']));
        $end = strtotime($d . ' ' . self::fmtTime($r['EndTime']));
        if ($now >= $end) {
            return 'done';
        }
        return $now >= $start ? 'live' : null;
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
        self::completePast();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(self::baseSelect() . ' WHERE b.BookingId = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $m = self::mapRow($row);
        $m['requirements'] = RequirementOptions::forBooking($id);
        return $m;
    }

    /** Mark past approved bookings as Completed. */
    public static function completePast(): void
    {
        $pdo = Database::pdo();
        $d = date('Y-m-d');
        $t = date('H:i:s');
        $st = [self::STATUS_APPROVED, self::STATUS_RESCHEDULED];
        $cond = "Status IN (?, ?) AND (BookingDate < ? OR (BookingDate = ? AND EndTime <= ?))";
        $bind = array_merge($st, [$d, $d, $t]);
        $pdo->prepare("INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason)
                       SELECT BookingId, Status, 'Completed', NULL, 'Auto-completed after event' FROM dbo.Bookings WHERE $cond")->execute($bind);
        $pdo->prepare("UPDATE dbo.Bookings SET Status = 'Completed', UpdatedAt = SYSUTCDATETIME() WHERE $cond")->execute($bind);
    }

    public static function listForUser(array $user, array $params = []): array
    {
        self::completePast();
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

        if (!empty($params['venueId'])) {
            $where[] = 'b.VenueId = ?';
            $bind[] = (int)$params['venueId'];
        }
        if (!empty($params['department'])) {
            $where[] = 'd.Name = ?';
            $bind[] = (string)$params['department'];
        }
        if (!empty($params['from'])) {
            $where[] = 'b.BookingDate >= ?';
            $bind[] = substr((string)$params['from'], 0, 10);
        }
        if (!empty($params['to'])) {
            $where[] = 'b.BookingDate <= ?';
            $bind[] = substr((string)$params['to'], 0, 10);
        }

        if (!$status && ($params['view'] ?? '') === 'calendar') {
            // Calendar default: everything still relevant (no declined / cancelled / revision).
            $status = implode(',', [
                self::STATUS_APPROVED, self::STATUS_RESCHEDULED, self::STATUS_PROPOSED, self::STATUS_COMPLETED,
                self::STATUS_PENDING_MANAGER, self::STATUS_MANAGER_VALIDATED, self::STATUS_PENDING_HR,
            ]);
        }
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

    public static function validatePayload(array $payload, ?int $excludeBookingId = null, ?array $user = null): array
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
        $today = date('Y-m-d');
        if ($date < $today) {
            $errors['date'] = 'Cannot book a past date.';
        }

        $lead = self::leadDaysFor($user);
        if ($lead > 0 && !isset($errors['date'])) {
            $earliest = date('Y-m-d', strtotime("today +{$lead} days"));
            if ($date < $earliest) {
                $errors['date'] = "Bookings must be made at least {$lead} day(s) in advance. Earliest available date: {$earliest}.";
            }
        }

        $window = (int)(Settings::get('booking_window_days', '90') ?: 90);
        $maxDate = date('Y-m-d', time() + $window * 86400);
        if ($date > $maxDate) {
            $errors['date'] = "Bookings cannot be more than {$window} days ahead.";
        }

        $ohStart = Settings::get('operating_hours_start', '08:00');
        $ohEnd = Settings::get('operating_hours_end', '17:00');
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
            $step = self::stepMinutes();
            if ($start % $step !== 0 || $end % $step !== 0) {
                $errors['startTime'] = $step === 60
                    ? 'Times must start and end on the hour.'
                    : 'Times must start and end on the hour or half hour.';
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
                $errors['conflict'] = 'This venue is already booked, or too close to another booking, for the selected time (ref: ' . $conflict['ReferenceCode'] . ').';
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
                FROM dbo.Bookings WITH (UPDLOCK, HOLDLOCK)
                WHERE VenueId = ?
                  AND BookingDate = ?
                  AND Status IN ($ph)
                  AND StartTime < ?
                  AND EndTime > ?";
        // Keep a buffer free before and after every booking.
        $buffer = self::bufferMinutes();
        $endPlusBuffer = self::shiftTime($end, $buffer);
        $startMinusBuffer = self::shiftTime($start, -$buffer);
        $bind = array_merge([$venueId, $date], $statuses, [$endPlusBuffer, $startMinusBuffer]);
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
        $errors = self::validatePayload($payload, null, $user);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }

        $reqItems = RequirementOptions::normalize($payload['requirements'] ?? []);
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
                Response::error('This venue is already booked, or too close to another booking, for the selected time (ref: ' . $conflict['ReferenceCode'] . ').', 409);
            }

            $stockErr = RequirementOptions::checkStock($reqItems, (int)$payload['venueId'], substr($payload['date'], 0, 10), self::normalizeTime($payload['startTime']), self::normalizeTime($payload['endTime']), null);
            if ($stockErr) {
                $pdo->rollBack();
                Response::error($stockErr, 409);
            }
            $ref = self::nextReference($pdo);
            $deptId = $user['departmentId'];
            $mgrId = isset($payload['departmentManagerId']) ? (int)$payload['departmentManagerId'] : null;
            if (!$mgrId && $deptId && $user['role'] === 'Employee') {
                $q = $pdo->prepare(
                    "SELECT TOP 1 u.UserId FROM dbo.Users u INNER JOIN dbo.Roles r ON r.RoleId = u.RoleId
                     WHERE r.RoleCode = 'Manager' AND u.IsActive = 1 AND u.DepartmentId = ? ORDER BY u.UserId"
                );
                $q->execute([$deptId]);
                $mgrId = (int)$q->fetchColumn() ?: null;
            }
            // Manager requests skip manager stage; HR/Admin create official (direct) bookings.
            $initial = self::STATUS_PENDING_MANAGER;
            if ($user['role'] === 'Manager') {
                $initial = self::STATUS_PENDING_HR;
                $mgrId = null;
            } elseif (in_array($user['role'], ['HR', 'Administrator'], true)) {
                $initial = self::STATUS_APPROVED;
                $mgrId = null;
            }

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
                $initial,
            ]);
            $id = (int)$stmt->fetchColumn();

            RequirementOptions::attach($pdo, $id, $reqItems);
            self::history($pdo, $id, null, $initial, $user['id'], $initial === self::STATUS_APPROVED ? 'Official booking (direct)' : 'Submitted');
            $pdo->commit();

            ActivityLog::write($user['id'], $user['email'], 'booking.create', 'booking', (string)$id, ['ref' => $ref]);

            if ($initial === self::STATUS_PENDING_HR) {
                foreach (self::usersByRole('HR') as $hrId) {
                    NotificationService::notify($hrId, 'Booking ready for HR', 'Request ' . $ref . ' awaits decision.', 'booking-details.html?id=' . $id);
                }
            }
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
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot validate your own request.', 403);
        }
        if ($user['role'] === 'Manager') {
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
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot approve your own request.', 403);
        }

        $conflict = self::findConflict($b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id);
        if ($conflict) {
            Response::error('Cannot approve: venue conflict with ' . $conflict['ReferenceCode'] . '.', 409);
        }

        $reqs = array_map(static fn($r) => [$r['optionId'], $r['name'], $r['quantity']], RequirementOptions::forBooking($id));
        $stockErr = RequirementOptions::checkStock($reqs, (int)$b['venueId'], $b['date'], self::normalizeTime($b['startTime']), self::normalizeTime($b['endTime']), $id);
        if ($stockErr) {
            Response::error('Cannot approve: ' . $stockErr, 409);
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

        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot decline your own request.', 403);
        }
        $newStatus = ($user['role'] === 'Manager') ? self::STATUS_REJECTED_MANAGER : self::STATUS_DECLINED;
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, HrNotes = COALESCE(?, HrNotes),
                ManagerNotes = CASE WHEN ? = 'Manager' THEN COALESCE(?, ManagerNotes) ELSE ManagerNotes END,
                DecidedAt = SYSUTCDATETIME(), DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
             WHERE BookingId = ?"
        )->execute([
            $newStatus,
            $reason,
            $payload['notes'] ?? $reason,
            $user['role'],
            $reason,
            $user['id'],
            $id,
        ]);
        self::history($pdo, $id, $b['status'], $newStatus, $user['id'], $reason);

        ActivityLog::write($user['id'], $user['email'], 'booking.decline', 'booking', (string)$id, ['reason' => $reason]);
        NotificationService::notify($b['requesterId'], 'Booking declined', 'Your booking ' . $b['ref'] . ' was declined.', 'booking-details.html?id=' . $id);

        return self::getById($id);
    }

    /** HR/Admin proposes a new schedule. The original schedule is kept until the requester accepts. */
    public static function reschedule(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if (!in_array($b['status'], [self::STATUS_PENDING_HR, self::STATUS_APPROVED], true)) {
            Response::error('Booking cannot be rescheduled in its current status.', 409);
        }
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot propose a reschedule for your own request.', 403);
        }
        $merged = array_merge($b, [
            'venueId' => $payload['venueId'] ?? $b['venueId'],
            'date' => $payload['date'] ?? $b['date'],
            'startTime' => $payload['startTime'] ?? $b['startTime'],
            'endTime' => $payload['endTime'] ?? $b['endTime'],
        ]);
        $errors = self::validatePayload($merged, $id);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET ProposedVenueId = ?, ProposedDate = ?, ProposedStartTime = ?, ProposedEndTime = ?,
                ProposalNotes = ?, StatusBeforeProposal = ?, Status = ?, DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
             WHERE BookingId = ?"
        )->execute([
            (int)$merged['venueId'], substr($merged['date'], 0, 10),
            self::normalizeTime($merged['startTime']), self::normalizeTime($merged['endTime']),
            $payload['notes'] ?? null, $b['status'], self::STATUS_PROPOSED, $user['id'], $id,
        ]);
        $desc = 'Original: ' . $b['date'] . ' ' . $b['startTime'] . '-' . $b['endTime'] . ' (venue ' . $b['venueId'] . '); proposed: '
            . substr($merged['date'], 0, 10) . ' ' . $merged['startTime'] . '-' . $merged['endTime'] . ' (venue ' . $merged['venueId'] . '). ' . ($payload['notes'] ?? '');
        self::history($pdo, $id, $b['status'], self::STATUS_PROPOSED, $user['id'], $desc);
        ActivityLog::write($user['id'], $user['email'], 'booking.reschedule_proposed', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Reschedule proposed', 'A new schedule was proposed for ' . $b['ref'] . '. Please respond.', 'booking-details.html?id=' . $id);
        return self::getById($id);
    }

    /** Requester accepts or rejects a proposal. */
    public static function respondReschedule(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if ($b['requesterId'] !== $user['id']) {
            Response::error('Forbidden.', 403);
        }
        if ($b['status'] !== self::STATUS_PROPOSED) {
            Response::error('No reschedule proposal is pending.', 409);
        }
        $pdo = Database::pdo();
        $prev = $pdo->prepare('SELECT StatusBeforeProposal FROM dbo.Bookings WHERE BookingId = ?');
        $prev->execute([$id]);
        $prevStatus = $prev->fetchColumn() ?: self::STATUS_PENDING_HR;
        $accept = !empty($payload['accept']);

        if ($accept) {
            $pv = (int)$b['proposedVenueId'];
            $errors = self::validatePayload([
                'venueId' => $pv, 'date' => $b['proposedDate'], 'startTime' => $b['proposedStartTime'],
                'endTime' => $b['proposedEndTime'], 'purpose' => $b['purpose'], 'attendees' => $b['attendees'],
            ], $id);
            if ($errors) {
                Response::error('Proposal no longer available: ' . reset($errors), 409);
            }
            $pdo->beginTransaction();
            try {
                if (self::findConflict($pv, $b['proposedDate'], $b['proposedStartTime'], $b['proposedEndTime'], $id)) {
                    $pdo->rollBack();
                    Response::error('Proposal no longer available (conflict).', 409);
                }
                $pdo->prepare(
                    "UPDATE dbo.Bookings SET VenueId = ?, BookingDate = ?, StartTime = ?, EndTime = ?, Status = ?,
                        ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL, ProposedEndTime = NULL,
                        StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
                )->execute([$pv, $b['proposedDate'], self::normalizeTime($b['proposedStartTime']), self::normalizeTime($b['proposedEndTime']), self::STATUS_APPROVED, $id]);
                self::history($pdo, $id, self::STATUS_PROPOSED, self::STATUS_APPROVED, $user['id'], 'Requester accepted proposed schedule');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } elseif ($prevStatus === self::STATUS_PENDING_HR) {
            // Not accepted -> declined (original schedule stays recorded in history)
            $reason = trim((string)($payload['reason'] ?? '')) ?: 'Reschedule proposal not accepted by requester';
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, DecidedAt = SYSUTCDATETIME(),
                    ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL, ProposedEndTime = NULL,
                    StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
            )->execute([self::STATUS_DECLINED, $reason, $id]);
            self::history($pdo, $id, self::STATUS_PROPOSED, self::STATUS_DECLINED, $user['id'], $reason);
        } else {
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL,
                    ProposedEndTime = NULL, StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
            )->execute([$prevStatus, $id]);
            self::history($pdo, $id, self::STATUS_PROPOSED, $prevStatus, $user['id'], 'Requester rejected proposal; original schedule retained');
        }
        ActivityLog::write($user['id'], $user['email'], 'booking.reschedule_response', 'booking', (string)$id, ['accept' => $accept]);
        foreach (self::usersByRole('HR') as $hrId) {
            NotificationService::notify($hrId, 'Reschedule ' . ($accept ? 'accepted' : 'rejected'), 'Requester responded for ' . $b['ref'] . '.', 'booking-details.html?id=' . $id);
        }
        return self::getById($id);
    }

    /** Manager asks the requester to revise. */
    public static function requestRevision(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if ($b['status'] !== self::STATUS_PENDING_MANAGER) {
            Response::error('Booking is not awaiting manager validation.', 409);
        }
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot act on your own request.', 403);
        }
        if ($user['role'] === 'Manager' && $user['departmentId'] && $b['departmentId'] && $user['departmentId'] !== $b['departmentId']) {
            Response::error('You can only review requests from your department.', 403);
        }
        $notes = trim((string)($payload['notes'] ?? $payload['reason'] ?? ''));
        if ($notes === '') {
            Response::error('Revision comments are required.', 422);
        }
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?")
            ->execute([self::STATUS_REVISION, $notes, $id]);
        self::history($pdo, $id, $b['status'], self::STATUS_REVISION, $user['id'], $notes);
        ActivityLog::write($user['id'], $user['email'], 'booking.request_revision', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Revision requested', 'Please revise ' . $b['ref'] . ': ' . $notes, 'booking-details.html?id=' . $id);
        return self::getById($id);
    }

    /** Requester edits and resubmits after a revision request. */
    public static function resubmit(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if ($b['requesterId'] !== $user['id']) {
            Response::error('Forbidden.', 403);
        }
        if ($b['status'] !== self::STATUS_REVISION) {
            Response::error('Only bookings with requested revisions can be edited.', 409);
        }
        $m = array_merge($b, array_intersect_key($payload, array_flip(['venueId', 'date', 'startTime', 'endTime', 'purpose', 'eventType', 'attendees', 'additionalRequirements'])));
        $errors = self::validatePayload($m, $id, $user);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }
        $next = $user['role'] === 'Manager' ? self::STATUS_PENDING_HR : self::STATUS_PENDING_MANAGER;
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET VenueId = ?, BookingDate = ?, StartTime = ?, EndTime = ?, Purpose = ?, EventType = ?,
                Attendees = ?, AdditionalRequirements = ?, Status = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([
            (int)$m['venueId'], substr($m['date'], 0, 10), self::normalizeTime($m['startTime']), self::normalizeTime($m['endTime']),
            trim($m['purpose']), $m['eventType'] ?? null, max(1, (int)$m['attendees']), $m['additionalRequirements'] ?? null, $next, $id,
        ]);
        self::history($pdo, $id, self::STATUS_REVISION, $next, $user['id'], 'Revised and resubmitted');
        ActivityLog::write($user['id'], $user['email'], 'booking.resubmit', 'booking', (string)$id, null);
        if ($b['departmentManagerId'] && $next === self::STATUS_PENDING_MANAGER) {
            NotificationService::notify($b['departmentManagerId'], 'Request resubmitted', $b['ref'] . ' was revised.', 'booking-details.html?id=' . $id);
        }
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

        $terminal = [self::STATUS_CANCELLED, self::STATUS_COMPLETED, self::STATUS_DECLINED, self::STATUS_REJECTED_MANAGER];
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
        $notify = [];
        if (in_array($b['status'], [self::STATUS_APPROVED, self::STATUS_RESCHEDULED, self::STATUS_PENDING_HR, self::STATUS_PROPOSED], true)) {
            $notify = self::usersByRole('HR');
        }
        if ($b['departmentManagerId']) {
            $notify[] = $b['departmentManagerId'];
        }
        if (!$isOwner) {
            $notify[] = $b['requesterId'];
        }
        foreach (array_unique(array_diff($notify, [$user['id']])) as $uid) {
            NotificationService::notify((int)$uid, 'Booking cancelled', $b['ref'] . ' was cancelled.', 'booking-details.html?id=' . $id);
        }
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
        $year = date('Y');
        $stmt = $pdo->query("SELECT ISNULL(MAX(CAST(RIGHT(ReferenceCode, 4) AS INT)), 0) FROM dbo.Bookings WITH (UPDLOCK, HOLDLOCK) WHERE ReferenceCode LIKE 'VB-{$year}-%'");
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

    /** Employees and Managers must book N days ahead; HR/Admin create official bookings and are exempt. */
    public static function leadDaysFor(?array $user): int
    {
        if (!$user || in_array($user['role'] ?? '', ['HR', 'Administrator'], true)) {
            return 0;
        }
        return max(0, Settings::int('booking_lead_days', 2));
    }

    public static function bufferMinutes(): int
    {
        return max(0, Settings::int('booking_buffer_minutes', 30));
    }

    public static function stepMinutes(): int
    {
        $s = Settings::int('slot_step_minutes', 30);
        return $s === 60 ? 60 : 30;
    }

    /** "HH:MM[:SS]" shifted by $minutes, clamped to the same day, returned as HH:MM:SS. */
    private static function shiftTime(string $t, int $minutes): string
    {
        $m = self::toMinutes($t) ?? 0;
        $m = max(0, min(24 * 60 - 1, $m + $minutes));
        return sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    }

    /**
     * Calendar data for one venue: booking rules + the blocked time ranges.
     * Deliberately exposes no purpose / requester names (any employee can call it).
     */
    public static function availability(int $venueId, string $from, string $to, array $user): array
    {
        self::completePast();
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($re, $from) || !preg_match($re, $to) || $to < $from) {
            Response::error('Invalid date range.', 422);
        }
        if ((strtotime($to) - strtotime($from)) > 62 * 86400) {
            Response::error('Date range too large (max 62 days).', 422);
        }

        $lead = self::leadDaysFor($user);
        $window = (int)(Settings::get('booking_window_days', '90') ?: 90);
        $config = [
            'minDate'       => date('Y-m-d', strtotime("today +{$lead} days")),
            'maxDate'       => date('Y-m-d', strtotime("today +{$window} days")),
            'today'         => date('Y-m-d'),
            'leadDays'      => $lead,
            'bufferMinutes' => self::bufferMinutes(),
            'stepMinutes'   => self::stepMinutes(),
            'minDuration'   => (int)(Settings::get('min_duration_minutes', '30') ?: 30),
            'maxDuration'   => (int)(Settings::get('max_duration_minutes', '480') ?: 480),
            'opens'         => Settings::get('operating_hours_start', '08:00'),
            'closes'        => Settings::get('operating_hours_end', '17:00'),
        ];

        $statuses = self::blockingStatuses(Settings::get('overlap_include_pending', '0') === '1');
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT BookingDate, StartTime, EndTime, RequesterUserId
             FROM dbo.Bookings
             WHERE VenueId = ? AND BookingDate BETWEEN ? AND ? AND Status IN ($ph)
             ORDER BY BookingDate, StartTime"
        );
        $stmt->execute(array_merge([$venueId, $from, $to], $statuses));
        $blocks = [];
        foreach ($stmt->fetchAll() as $r) {
            $blocks[] = [
                'date'  => self::fmtDate($r['BookingDate']),
                'start' => self::fmtTime($r['StartTime']),
                'end'   => self::fmtTime($r['EndTime']),
                'mine'  => (int)$r['RequesterUserId'] === (int)$user['id'],
            ];
        }
        return ['config' => $config, 'blocks' => $blocks];
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
