<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/RequirementOptions.php';
require_once __DIR__ . '/VenueScheduleService.php';
require_once __DIR__ . '/BookingWorkflow.php';

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
    public const STATUS_NO_SHOW = 'No-Show';
    public const STATUS_EXPIRED = 'Expired';

    /** How many days (from the original date) the auto-reschedule looks ahead for a free slot. */
    private const AUTO_SEARCH_DAYS = 7;

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
            'revisionCount' => isset($r['RevisionCount']) ? (int)$r['RevisionCount'] : null,
            'reachedHrAt' => self::fmtStamp($r['ReachedHrAt'] ?? null),
            'bypassReason' => $r['BypassReason'] ?? null,
            'hrNotes' => $r['HrNotes'],
            'cancelReason' => $r['CancelReason'],
            'declineReason' => $r['DeclineReason'],
            'createdAt' => self::fmtStamp($r['CreatedAt'] ?? null),   // UTC, ISO-8601 with Z
            'updatedAt' => self::fmtStamp($r['UpdatedAt'] ?? null),
            'proposedVenueId' => isset($r['ProposedVenueId']) ? (int)$r['ProposedVenueId'] : null,
            'proposedDate' => !empty($r['ProposedDate']) ? self::fmtDate($r['ProposedDate']) : null,
            'proposedStartTime' => !empty($r['ProposedStartTime']) ? self::fmtTime($r['ProposedStartTime']) : null,
            'proposedEndTime' => !empty($r['ProposedEndTime']) ? self::fmtTime($r['ProposedEndTime']) : null,
            'proposalNotes' => $r['ProposalNotes'] ?? null,
            'validatedAt' => self::fmtStamp($r['ValidatedAt'] ?? null),
            'managerValidatedAt' => self::fmtStamp($r['ValidatedAt'] ?? null),
            // When it entered HR's queue: manager validation time, or creation time for requests that skip the manager step.
            'hrQueuedAt' => self::fmtStamp(($r['ReachedHrAt'] ?? null) ?: (($r['ValidatedAt'] ?? null) ?: ($r['CreatedAt'] ?? null))),
            'stageEnteredAt' => self::fmtStamp($r['StageEnteredAt'] ?? null),
            'managerViewedAt' => self::fmtStamp($r['ManagerViewedAt'] ?? null),
            'hrViewedAt' => self::fmtStamp($r['HrViewedAt'] ?? null),
            'lastReminderAt' => self::fmtStamp($r['LastReminderAt'] ?? null),
            'reminderCount' => isset($r['ReminderCount']) ? (int)$r['ReminderCount'] : 0,
            'isOverdue' => !empty($r['IsOverdue']),
            'waitingDays' => self::waitingDays($r),
        ];
    }

    /** Whole days the request has been in its current waiting stage (UTC). */
    private static function waitingDays(array $r): ?int
    {
        $status = $r['Status'] ?? '';
        if (!in_array($status, BookingWorkflow::waitingStatuses(), true)) {
            return null;
        }
        $entered = $r['StageEnteredAt'] ?? $r['ReachedHrAt'] ?? $r['ValidatedAt'] ?? $r['CreatedAt'] ?? null;
        if ($entered === null || $entered === '') {
            return null;
        }
        try {
            $t = $entered instanceof DateTimeInterface
                ? $entered->getTimestamp()
                : strtotime((string)$entered);
            if ($t === false) {
                return null;
            }
            return (int) max(0, floor((time() - $t) / 86400));
        } catch (Throwable $e) {
            return null;
        }
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

    /** DB timestamps are UTC; return ISO-8601 with a Z so the browser converts to local time. Null stays null. */
    private static function fmtStamp($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s') . 'Z';
        }
        $s = (string)$v;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $s, $m)) {
            return $m[1] . 'T' . $m[2] . 'Z';
        }
        return $s;
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
        $m['statusHistory'] = self::getStatusHistory($id);
        return $m;
    }

    /**
     * Full audit trail for a booking: every status change with actor and reason.
     * Used on the details page and for investigations.
     */
    public static function getStatusHistory(int $bookingId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT h.HistoryId, h.BookingId, h.FromStatus, h.ToStatus, h.Reason, h.ChangedAt,
                    h.ChangedByUserId, u.FullName AS ChangedByName, u.Email AS ChangedByEmail
             FROM dbo.BookingStatusHistory h
             LEFT JOIN dbo.Users u ON u.UserId = h.ChangedByUserId
             WHERE h.BookingId = ?
             ORDER BY h.ChangedAt ASC, h.HistoryId ASC"
        );
        $stmt->execute([$bookingId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id' => (int)$r['HistoryId'],
                'bookingId' => (int)$r['BookingId'],
                'fromStatus' => $r['FromStatus'],
                'toStatus' => $r['ToStatus'],
                'reason' => $r['Reason'],
                'changedAt' => self::fmtStamp($r['ChangedAt'] ?? null),
                'changedByUserId' => $r['ChangedByUserId'] !== null ? (int)$r['ChangedByUserId'] : null,
                'changedByName' => $r['ChangedByName'] ?: ($r['ChangedByUserId'] ? null : 'System'),
                'changedByEmail' => $r['ChangedByEmail'] ?? null,
                'isSystem' => $r['ChangedByUserId'] === null,
            ];
        }
        return $out;
    }

    /** Mark past approved bookings as Completed. */
    public static function completePast(): void
    {
        self::maybeRunScheduledJobs();
        if (Settings::get('auto_complete_enabled', '1') !== '1') {
            return;
        }
        self::runAutoComplete();
    }

    /** Approved/Rescheduled bookings whose end has passed (app timezone) become Completed. Returns how many. */
    private static function runAutoComplete(): int
    {
        $pdo = Database::pdo();
        $d = date('Y-m-d');
        $t = date('H:i:s');
        $st = [self::STATUS_APPROVED, self::STATUS_RESCHEDULED];
        $cond = "Status IN (?, ?) AND (BookingDate < ? OR (BookingDate = ? AND EndTime <= ?))";
        $bind = array_merge($st, [$d, $d, $t]);
        $pdo->prepare("INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason)
                       SELECT BookingId, Status, 'Completed', NULL, 'Auto-completed after event' FROM dbo.Bookings WHERE $cond")->execute($bind);
        $upd = $pdo->prepare("UPDATE dbo.Bookings SET Status = 'Completed', UpdatedAt = SYSUTCDATETIME() WHERE $cond");
        $upd->execute($bind);
        return (int)$upd->rowCount();
    }

    /**
     * There is no cron job in a plain XAMPP/IIS install, so SLA jobs would never run on their own.
     * Piggy-back on normal traffic: at most once every 5 minutes (per server) and never twice in one request.
     * Prefer a real scheduler (Windows Task Scheduler / cron) calling POST /api/bookings/run-sla-jobs.
     */
    private static function maybeRunScheduledJobs(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vbs_jobs_' . md5(__DIR__) . '.stamp';
        $last = @filemtime($stamp);
        if ($last !== false && (time() - $last) < 300) {
            return;
        }
        @touch($stamp);
        try {
            self::runSlaJobs();
        } catch (Throwable $e) {
            error_log('Scheduled SLA jobs failed: ' . $e->getMessage());
        }
    }

    /**
     * Full SLA / reminder / expiry pass. Safe to call from a scheduler every 15 minutes.
     * Returns counts of actions taken.
     */
    public static function runSlaJobs(): array
    {
        $out = [
            'manager_reminders' => 0,
            'manager_escalated' => 0,
            'hr_reminders' => 0,
            'hr_escalated' => 0,
            'revision_expired' => 0,
            'proposal_expired' => 0,
            'event_expired' => 0,
        ];
        try {
            $out['manager_reminders'] = self::sendStageReminders(self::STATUS_PENDING_MANAGER, 'Manager');
            $out['manager_escalated'] = self::escalateOverdueManagerValidations()['escalated'] ?? 0;
            $out['hr_reminders'] = self::sendStageReminders(self::STATUS_PENDING_HR, 'HR');
            $out['hr_escalated'] = self::escalateOverdueHrDecisions()['escalated'] ?? 0;
            $out['revision_expired'] = self::expireStaleRevisions();
            $out['proposal_expired'] = self::expireStaleProposals();
            $out['event_expired'] = self::expirePastUndecided();
        } catch (Throwable $e) {
            error_log('runSlaJobs error: ' . $e->getMessage());
            $out['error'] = $e->getMessage();
        }
        return $out;
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
        } elseif ($user['role'] === 'Manager') {
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
        $rows = array_map([self::class, 'mapRow'], $stmt->fetchAll());
        if (in_array($user['role'] ?? '', ['HR', 'Administrator'], true)) {
            $rows = self::annotateCompeting($rows);
        }
        return $rows;
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
            $cap = (int)$venue['Capacity'];
            $want = (int)$payload['attendees'];
            $errors['attendees'] = "Cannot proceed: {$want} attendees exceed this venue's capacity limit of {$cap}. Choose a larger venue or reduce the attendee count.";
        }

        if (!$errors) {
            
        // Per-venue hours / blackouts (additive; skipped if tables missing)
        if (!empty($payload['venueId']) && !empty($payload['date']) && !empty($payload['startTime']) && !empty($payload['endTime'])) {
            $schedErr = VenueScheduleService::validateSlot(
                (int)$payload['venueId'],
                substr((string)$payload['date'], 0, 10),
                substr((string)$payload['startTime'], 0, 5),
                substr((string)$payload['endTime'], 0, 5)
            );
            if ($schedErr) {
                $errors['schedule'] = $schedErr;
            }
        }
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
            // Who needs whose approval:
            //   Employee      -> department Manager -> HR (an Employee with no active manager goes straight to HR)
            //   Manager       -> HR (manager step skipped)
            //   HR / Admin    -> NO approver: their bookings are official and confirmed immediately.
            // Whatever overlaps a confirmed booking is then handled automatically (see resolveCompetitors()).
            // An optional written note (bypassReason / reason) is kept on the booking when one is given.
            $initial = self::STATUS_PENDING_MANAGER;
            $bypassReason = null;
            $reachedHrAt = null;
            if ($user['role'] === 'Manager') {
                $initial = self::STATUS_PENDING_HR;
                $mgrId = null;
                $reachedHrAt = true;
            } elseif (in_array($user['role'], ['HR', 'Administrator'], true)) {
                $mgrId = null;
                $initial = self::STATUS_APPROVED;
                $note = trim((string)($payload['bypassReason'] ?? $payload['reason'] ?? ''));
                $bypassReason = $note !== '' ? $note : null;
            } elseif ($user['role'] === 'Employee' && !$mgrId) {
                // No active manager for department → escalate to HR immediately
                $initial = self::STATUS_PENDING_HR;
                $reachedHrAt = true;
            }

            // Prefer extended columns when migrations 12/13 have been applied
            $hasExt = self::hasColumn($pdo, 'Bookings', 'ReachedHrAt');
            $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
            if ($hasExt && $hasStage) {
                $stmt = $pdo->prepare(
                    "INSERT INTO dbo.Bookings
                      (ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
                       BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
                       AdditionalRequirements, Status, ReachedHrAt, BypassReason, StageEnteredAt)
                     OUTPUT INSERTED.BookingId
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                             (CASE WHEN CAST(? AS INT) = 1 THEN SYSUTCDATETIME() ELSE NULL END),
                             ?, SYSUTCDATETIME())"
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
                    $reachedHrAt ? 1 : 0,
                    $bypassReason !== null ? self::clip($bypassReason, 500) : null,
                ]);
            } elseif ($hasExt) {
                $stmt = $pdo->prepare(
                    "INSERT INTO dbo.Bookings
                      (ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
                       BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
                       AdditionalRequirements, Status, ReachedHrAt, BypassReason)
                     OUTPUT INSERTED.BookingId
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (CASE WHEN CAST(? AS INT) = 1 THEN SYSUTCDATETIME() ELSE NULL END), ?)"
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
                    $reachedHrAt ? 1 : 0,
                    $bypassReason !== null ? self::clip($bypassReason, 500) : null,
                ]);
            } else {
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
            }
            $id = (int)$stmt->fetchColumn();

            RequirementOptions::attach($pdo, $id, $reqItems);
            $histMsg = 'Submitted';
            if ($initial === self::STATUS_APPROVED) {
                $histMsg = 'Official booking by ' . $user['role'] . ' (confirmed immediately, no approver needed)'
                    . ($bypassReason ? ': ' . $bypassReason : '');
            } elseif ($initial === self::STATUS_PENDING_HR && $user['role'] === 'Employee' && !$mgrId) {
                $histMsg = 'Submitted — no active department manager; escalated to HR';
            } elseif ($initial === self::STATUS_PENDING_HR && $user['role'] === 'Manager') {
                $histMsg = 'Submitted by Manager (manager stage skipped)';
            }
            self::history($pdo, $id, null, $initial, $user['id'], $histMsg);
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

            $created = self::getById($id);
            if ($initial === self::STATUS_APPROVED) {
                // A confirmed HR/Admin booking wins over requests still waiting for HR at an overlapping time:
                // they are moved to the nearest free slot (or declined) and their requesters are told.
                $resolved = self::resolveCompetitors($created, $user);
                if ($resolved) {
                    ActivityLog::write($user['id'], $user['email'], 'booking.auto_resolve', 'booking', (string)$id, ['results' => $resolved]);
                    $created['autoResolved'] = $resolved;
                }
            }
            return $created;
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
        BookingWorkflow::assertTransition($b['status'], self::STATUS_PENDING_HR, 'validate');
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot validate your own request.', 403);
        }
        // Managers must share the request's department (no open-ended validation)
        if ($user['role'] === 'Manager') {
            if (!$user['departmentId'] || !$b['departmentId'] || (int)$user['departmentId'] !== (int)$b['departmentId']) {
                Response::error('You can only validate requests from your own department.', 403);
            }
        }

        $pdo = Database::pdo();
        $hasReached = self::hasColumn($pdo, 'Bookings', 'ReachedHrAt');
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        if ($hasReached && $hasStage) {
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, ValidatedAt = SYSUTCDATETIME(),
                    ValidatedByUserId = ?, ReachedHrAt = SYSUTCDATETIME(), StageEnteredAt = SYSUTCDATETIME(),
                    ReminderCount = 0, LastReminderAt = NULL, IsOverdue = 0,
                    UpdatedAt = SYSUTCDATETIME(),
                    RowVersion = ISNULL(RowVersion, 1) + 1 WHERE BookingId = ?"
            )->execute([
                self::STATUS_PENDING_HR,
                $payload['notes'] ?? null,
                $user['id'],
                $id,
            ]);
        } elseif ($hasReached) {
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, ValidatedAt = SYSUTCDATETIME(),
                    ValidatedByUserId = ?, ReachedHrAt = SYSUTCDATETIME(), UpdatedAt = SYSUTCDATETIME(),
                    RowVersion = ISNULL(RowVersion, 1) + 1 WHERE BookingId = ?"
            )->execute([
                self::STATUS_PENDING_HR,
                $payload['notes'] ?? null,
                $user['id'],
                $id,
            ]);
        } else {
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, ValidatedAt = SYSUTCDATETIME(),
                    ValidatedByUserId = ?, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
            )->execute([
                self::STATUS_PENDING_HR,
                $payload['notes'] ?? null,
                $user['id'],
                $id,
            ]);
        }
        self::history($pdo, $id, self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, $user['id'], $payload['notes'] ?? null);

        ActivityLog::write($user['id'], $user['email'], 'booking.validate', 'booking', (string)$id, null);

        // HR gives the final verdict and the request validated first has priority. If a confirmed booking
        // already holds this slot, handle this late request now (move it or decline it) instead of leaving
        // it in HR's queue with a guaranteed conflict error.
        try {
            $held = self::findConfirmedConflict((int)$b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id);
            $holder = $held ? self::getById((int)$held['BookingId']) : null;
            if ($holder) {
                $taken = [];
                self::autoResolve($id, $holder, $user, $taken);
            }
        } catch (Throwable $e) {
            error_log('Auto-resolve on validate failed for booking ' . $id . ': ' . $e->getMessage());
        }

        $now = self::getById($id);
        if ($now && $now['status'] === self::STATUS_PENDING_HR) {
            NotificationService::notify(
                $b['requesterId'],
                'Request validated',
                'Your booking ' . $b['ref'] . ' was validated and sent to HR.',
                'booking-details.html?id=' . $id
            );
            foreach (self::usersByRole('HR') as $hrId) {
                NotificationService::notify($hrId, 'Booking ready for HR', 'Request ' . $b['ref'] . ' awaits decision.', 'booking-details.html?id=' . $id);
            }
        }

        return $now;
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
        BookingWorkflow::assertTransition($b['status'], self::STATUS_APPROVED, 'approve');
        // Employees/Managers can never approve their own request. HR/Admin bookings need no approver, so one that is
        // still pending (created before this rule was restored) may be confirmed by its owner.
        if ($b['requesterId'] === $user['id'] && !in_array($user['role'], ['HR', 'Administrator'], true)) {
            Response::error('You cannot approve your own request.', 403);
        }

        $reqs = array_map(static fn($r) => [$r['optionId'], $r['name'], $r['quantity']], RequirementOptions::forBooking($id));

        // Check-and-confirm must be ONE atomic step: the lock taken by findConflict() only lasts while a
        // transaction is open, so without this two HR users could confirm the same slot at the same moment.
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $conflict = self::findConflict($b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id);
            if ($conflict) {
                $pdo->rollBack();
                Response::error('Cannot approve: venue conflict with ' . $conflict['ReferenceCode'] . '.', 409);
            }
            $stockErr = RequirementOptions::checkStock($reqs, (int)$b['venueId'], $b['date'], self::normalizeTime($b['startTime']), self::normalizeTime($b['endTime']), $id);
            if ($stockErr) {
                $pdo->rollBack();
                Response::error('Cannot approve: ' . $stockErr, 409);
            }
            $upd = $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, HrNotes = ?, DecidedAt = SYSUTCDATETIME(),
                    DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
                 WHERE BookingId = ? AND Status IN (?, ?)"
            );
            $upd->execute([self::STATUS_APPROVED, $payload['notes'] ?? null, $user['id'], $id, self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                $who = self::getById($id);
                $msg = 'This request was already decided';
                if ($who && !empty($who['decidedBy'])) {
                    $msg .= ' by ' . $who['decidedBy'];
                }
                if ($who && !empty($who['status'])) {
                    $msg .= ' (now: ' . $who['status'] . ')';
                }
                Response::error($msg . '.', 409);
            }
            self::history($pdo, $id, $b['status'], self::STATUS_APPROVED, $user['id'], $payload['notes'] ?? null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        ActivityLog::write($user['id'], $user['email'], 'booking.approve', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Booking approved', 'Your booking ' . $b['ref'] . ' was approved.', 'booking-details.html?id=' . $id);

        // The other requests for this slot lose out: reschedule or decline them automatically.
        $approved = self::getById($id);
        $resolved = self::resolveCompetitors($approved, $user);
        if ($resolved) {
            ActivityLog::write($user['id'], $user['email'], 'booking.auto_resolve', 'booking', (string)$id, ['results' => $resolved]);
            $approved['autoResolved'] = $resolved;
        }
        return $approved;
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
        } else {
            // Reject proposal: requester chooses keep original (return to previous status) or withdraw.
            // Default is keep (do not auto-decline) — process improvement #6.
            $action = strtolower(trim((string)($payload['rejectAction'] ?? $payload['action'] ?? 'keep')));
            $reason = trim((string)($payload['reason'] ?? ''));
            if ($action === 'withdraw' || $action === 'decline' || $action === 'cancel') {
                $newStatus = self::STATUS_CANCELLED;
                if ($reason === '') {
                    $reason = 'Requester withdrew after rejecting reschedule proposal';
                }
                BookingWorkflow::assertTransition(self::STATUS_PROPOSED, $newStatus, 'withdraw after proposal');
                $pdo->prepare(
                    "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, DecidedAt = SYSUTCDATETIME(),
                        ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL, ProposedEndTime = NULL,
                        StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
                )->execute([$newStatus, $reason, $id]);
                self::history($pdo, $id, self::STATUS_PROPOSED, $newStatus, $user['id'], $reason);
            } else {
                // keep original schedule and return to previous process stage
                $restore = $prevStatus ?: self::STATUS_PENDING_HR;
                if (!BookingWorkflow::canTransition(self::STATUS_PROPOSED, $restore)) {
                    $restore = self::STATUS_PENDING_HR;
                }
                // A request that was moved automatically because ANOTHER booking took its slot cannot go back to HR's
                // queue (it could never be approved): it is declined with a clear reason instead of getting stuck.
                $held = in_array($restore, [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED], true)
                    ? self::findConfirmedConflict((int)$b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id)
                    : null;
                if ($held) {
                    $declineWhy = self::clip('Proposal rejected and the original time is no longer available (' . $held['ReferenceCode'] . ' holds it).', 900);
                    $pdo->prepare(
                        "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, HrNotes = ?, DecidedAt = SYSUTCDATETIME(),
                            ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL, ProposedEndTime = NULL,
                            StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
                    )->execute([self::STATUS_DECLINED, $declineWhy, $declineWhy, $id]);
                    self::history($pdo, $id, self::STATUS_PROPOSED, self::STATUS_DECLINED, $user['id'], $declineWhy);
                } else {
                    $pdo->prepare(
                        "UPDATE dbo.Bookings SET Status = ?, ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL,
                            ProposedEndTime = NULL, StatusBeforeProposal = NULL, UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
                    )->execute([$restore, $id]);
                    self::history($pdo, $id, self::STATUS_PROPOSED, $restore, $user['id'],
                        $reason ?: 'Requester rejected proposal; original schedule retained and returned to queue');
                }
            }
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
        $allowedFrom = [self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED];
        if (!in_array($b['status'], $allowedFrom, true)) {
            Response::error('Booking cannot be sent for revision in its current status.', 409);
        }
        BookingWorkflow::assertTransition($b['status'], self::STATUS_REVISION, 'request revision');
        if ($b['requesterId'] === $user['id']) {
            Response::error('You cannot act on your own request.', 403);
        }
        if ($user['role'] === 'Manager') {
            if ($b['status'] !== self::STATUS_PENDING_MANAGER) {
                Response::error('Managers can only request revision while awaiting manager validation.', 403);
            }
            if (!$user['departmentId'] || !$b['departmentId'] || (int)$user['departmentId'] !== (int)$b['departmentId']) {
                Response::error('You can only review requests from your own department.', 403);
            }
        } elseif (!in_array($user['role'], ['HR', 'Administrator'], true)) {
            Response::error('Forbidden.', 403);
        }
        $notes = trim((string)($payload['notes'] ?? $payload['reason'] ?? ''));
        if ($notes === '') {
            Response::error('Revision comments are required.', 422);
        }
        $pdo = Database::pdo();
        $maxRev = Settings::int('max_revisions', 2);
        if (self::hasColumn($pdo, 'Bookings', 'RevisionCount')) {
            $q = $pdo->prepare('SELECT RevisionCount FROM dbo.Bookings WHERE BookingId = ?');
            $q->execute([$id]);
            $curRev = (int)$q->fetchColumn();
            if ($curRev >= $maxRev) {
                Response::error("Maximum revisions ({$maxRev}) reached. Please approve or decline this request.", 409);
            }
            // Manager comments go to ManagerNotes, HR/Admin comments to HrNotes (column chosen here, not in SQL).
            $noteCol = in_array($user['role'], ['HR', 'Administrator'], true) ? 'HrNotes' : 'ManagerNotes';
            $stageSql = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt')
                ? ', StageEnteredAt = SYSUTCDATETIME(), ReminderCount = 0, LastReminderAt = NULL, IsOverdue = 0'
                : '';
            $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, RevisionCount = RevisionCount + 1, {$noteCol} = ?,
                    UpdatedAt = SYSUTCDATETIME(){$stageSql} WHERE BookingId = ?"
            )->execute([self::STATUS_REVISION, $notes, $id]);
        } else {
            $stageSql = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt')
                ? ', StageEnteredAt = SYSUTCDATETIME()'
                : '';
            $pdo->prepare("UPDATE dbo.Bookings SET Status = ?, ManagerNotes = ?, UpdatedAt = SYSUTCDATETIME(){$stageSql} WHERE BookingId = ?")
                ->execute([self::STATUS_REVISION, $notes, $id]);
        }
        self::history($pdo, $id, $b['status'], self::STATUS_REVISION, $user['id'], $notes);
        ActivityLog::write($user['id'], $user['email'], 'booking.request_revision', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Revision requested', 'Please revise ' . $b['ref'] . ': ' . $notes, 'booking-details.html?id=' . $id);
        return self::getById($id);
    }

    /** Requester edits and resubmits after a revision request. Same validation rules as create. */
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

        // Merge edited fields onto the existing booking (unchanged fields keep original values).
        $editable = ['venueId', 'date', 'startTime', 'endTime', 'purpose', 'eventType', 'attendees', 'additionalRequirements'];
        $m = array_merge($b, array_intersect_key($payload, array_flip($editable)));
        $m['venueId'] = (int)($m['venueId'] ?? $b['venueId']);
        $m['attendees'] = max(1, (int)($m['attendees'] ?? $b['attendees'] ?? 1));
        $m['purpose'] = trim((string)($m['purpose'] ?? ''));
        $m['date'] = substr((string)($m['date'] ?? ''), 0, 10);

        // Same rules as the original booking form (hours, capacity, lead days, schedule, etc.)
        $errors = self::validatePayload($m, $id, $user);
        if ($errors) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }

        $conflict = self::findConflict(
            (int)$m['venueId'],
            $m['date'],
            self::normalizeTime($m['startTime']),
            self::normalizeTime($m['endTime']),
            $id
        );
        if ($conflict) {
            Response::error(
                'This venue is already booked, or too close to another booking, for the selected time (ref: ' . $conflict['ReferenceCode'] . ').',
                409,
                ['errors' => ['date' => 'Selected time conflicts with ' . $conflict['ReferenceCode'] . '.']]
            );
        }

        $next = $user['role'] === 'Manager' ? self::STATUS_PENDING_HR : self::STATUS_PENDING_MANAGER;
        $pdo = Database::pdo();
        $extra = '';
        $params = [
            (int)$m['venueId'],
            $m['date'],
            self::normalizeTime($m['startTime']),
            self::normalizeTime($m['endTime']),
            $m['purpose'],
            $m['eventType'] ?? null,
            $m['attendees'],
            isset($m['additionalRequirements']) ? (string)$m['additionalRequirements'] : null,
            $next,
        ];
        if (self::hasColumn($pdo, 'Bookings', 'StageEnteredAt')) {
            $extra = ', StageEnteredAt = SYSUTCDATETIME(), ReminderCount = 0, LastReminderAt = NULL, IsOverdue = 0, ManagerViewedAt = NULL, HrViewedAt = NULL';
            if ($next === self::STATUS_PENDING_HR && self::hasColumn($pdo, 'Bookings', 'ReachedHrAt')) {
                $extra .= ', ReachedHrAt = SYSUTCDATETIME()';
            }
        }
        $pdo->prepare(
            "UPDATE dbo.Bookings SET VenueId = ?, BookingDate = ?, StartTime = ?, EndTime = ?, Purpose = ?, EventType = ?,
                Attendees = ?, AdditionalRequirements = ?, Status = ?, UpdatedAt = SYSUTCDATETIME(){$extra} WHERE BookingId = ?"
        )->execute(array_merge($params, [$id]));

        // Audit trail: record what actually changed
        $changes = [];
        if ((int)$b['venueId'] !== (int)$m['venueId']) {
            $changes[] = 'venue updated';
        }
        if (($b['date'] ?? '') !== $m['date']) {
            $changes[] = 'date ' . ($b['date'] ?? '') . ' → ' . $m['date'];
        }
        $oldStart = self::normalizeTime((string)($b['startTime'] ?? ''));
        $oldEnd = self::normalizeTime((string)($b['endTime'] ?? ''));
        $newStart = self::normalizeTime($m['startTime']);
        $newEnd = self::normalizeTime($m['endTime']);
        if ($oldStart !== $newStart || $oldEnd !== $newEnd) {
            $changes[] = "time {$oldStart}-{$oldEnd} → {$newStart}-{$newEnd}";
        }
        if ((int)($b['attendees'] ?? 0) !== (int)$m['attendees']) {
            $changes[] = 'attendees ' . ($b['attendees'] ?? '') . ' → ' . $m['attendees'];
        }
        if (trim((string)($b['purpose'] ?? '')) !== $m['purpose']) {
            $changes[] = 'purpose updated';
        }
        if (($b['eventType'] ?? null) !== ($m['eventType'] ?? null)) {
            $changes[] = 'event type updated';
        }
        $reason = $changes
            ? ('Revised and resubmitted: ' . implode('; ', $changes))
            : 'Revised and resubmitted (details confirmed)';

        self::history($pdo, $id, self::STATUS_REVISION, $next, $user['id'], self::clip($reason, 1000));
        ActivityLog::write($user['id'], $user['email'], 'booking.resubmit', 'booking', (string)$id, [
            'changes' => $changes,
            'venueId' => (int)$m['venueId'],
            'date' => $m['date'],
            'startTime' => $newStart,
            'endTime' => $newEnd,
            'attendees' => $m['attendees'],
        ]);

        if ($b['departmentManagerId'] && $next === self::STATUS_PENDING_MANAGER) {
            NotificationService::notify($b['departmentManagerId'], 'Request resubmitted', $b['ref'] . ' was revised.', 'booking-details.html?id=' . $id);
        }
        if ($next === self::STATUS_PENDING_HR) {
            foreach (self::usersByRole('HR') as $hrId) {
                NotificationService::notify($hrId, 'Request resubmitted', $b['ref'] . ' was revised and is awaiting HR.', 'booking-details.html?id=' . $id);
            }
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

    // =====================================================================================
    // Competing requests + emergency override
    //
    // Business rules implemented here:
    //   * Several requests may be pending for the same slot - HR gives the final verdict.
    //   * When HR approves one, every other request that overlaps it and is waiting for HR is handled
    //     automatically: moved to the nearest free slot (setting conflict_auto_action = reschedule,
    //     declined if nothing is free) or simply declined (conflict_auto_action = decline).
    //   * A request whose manager validates it AFTER the slot is already confirmed is handled the same
    //     way straight away, so it never sits in HR's queue with a guaranteed conflict error.
    //   * HR/Admin can override an already-approved booking in an emergency (move it or cancel it).
    // =====================================================================================

    /** Handles every pending-HR request that overlaps the just-approved booking, oldest manager validation first. */
    private static function resolveCompetitors(array $winner, ?array $actor): array
    {
        $out = [];
        $taken = [];
        try {
            $ids = array_column(self::pendingCompetitors($winner), 'id');
        } catch (Throwable $e) {
            error_log('Competing-request lookup failed for booking ' . ($winner['id'] ?? '?') . ': ' . $e->getMessage());
            return [];
        }
        foreach ($ids as $loserId) {
            try {
                $r = self::autoResolve((int)$loserId, $winner, $actor, $taken);
                if ($r) {
                    $out[] = $r;
                }
            } catch (Throwable $e) {
                // One failure must never undo the approval or stop the remaining requests being handled.
                error_log('Auto-resolve failed for booking ' . $loserId . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Handles ONE losing request. Returns a short result array, or null when nothing was changed
     * (already decided by someone else). $taken lists slots already offered to other losers in this pass,
     * so two requests are never sent to the same alternative.
     */
    private static function autoResolve(int $loserId, array $winner, ?array $actor, array &$taken): ?array
    {
        $b = self::getById($loserId);
        $open = [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED];
        if (!$b || !in_array($b['status'], $open, true)) {
            return null;
        }
        $pdo = Database::pdo();
        $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
        $actorEmail = $actor['email'] ?? null;
        $link = 'booking-details.html?id=' . $loserId;
        $why = $winner['ref'] . ' was approved for the same venue at an overlapping time';
        $rescheduleMode = Settings::get('conflict_auto_action', 'reschedule') !== 'decline';

        if ($rescheduleMode) {
            $slot = self::findAlternativeSlot($b, $taken);
            if ($slot) {
                $note = self::clip('Automatic proposal: ' . $why . '. The nearest free slot is offered instead.', 900);
                $upd = $pdo->prepare(
                    "UPDATE dbo.Bookings SET ProposedVenueId = ?, ProposedDate = ?, ProposedStartTime = ?, ProposedEndTime = ?,
                        ProposalNotes = ?, StatusBeforeProposal = ?, Status = ?, DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
                     WHERE BookingId = ? AND Status IN (?, ?)"
                );
                $upd->execute([
                    (int)$b['venueId'], $slot['date'], self::normalizeTime($slot['start']), self::normalizeTime($slot['end']),
                    $note, $b['status'], self::STATUS_PROPOSED, $actorId, $loserId, $open[0], $open[1],
                ]);
                if ($upd->rowCount() !== 1) {
                    return null;
                }
                $desc = 'Automatic reschedule proposal (' . $why . '). Original: ' . $b['date'] . ' ' . $b['startTime'] . '-' . $b['endTime']
                    . '; proposed: ' . $slot['date'] . ' ' . $slot['start'] . '-' . $slot['end'] . '.';
                self::history($pdo, $loserId, $b['status'], self::STATUS_PROPOSED, $actorId, self::clip($desc, 900));
                $taken[] = ['venueId' => (int)$b['venueId'], 'date' => $slot['date'], 'start' => $slot['start'], 'end' => $slot['end']];
                NotificationService::notify(
                    $b['requesterId'],
                    'New time proposed for your booking',
                    $b['ref'] . ' could not keep its requested time because ' . $why . '. A free slot was proposed instead: '
                        . $slot['date'] . ' ' . $slot['start'] . '-' . $slot['end'] . '. Please open the request to accept or reject it.',
                    $link
                );
                if ($b['departmentManagerId'] && $b['departmentManagerId'] !== $actorId) {
                    NotificationService::notify($b['departmentManagerId'], 'Booking moved automatically', $b['ref'] . ' was offered ' . $slot['date'] . ' ' . $slot['start'] . '-' . $slot['end'] . ' because ' . $why . '.', $link);
                }
                ActivityLog::write($actorId, $actorEmail, 'booking.auto_reschedule', 'booking', (string)$loserId, [
                    'winner' => $winner['ref'], 'proposed' => $slot,
                ]);
                return ['id' => $loserId, 'ref' => $b['ref'], 'outcome' => 'rescheduled',
                        'date' => $slot['date'], 'startTime' => $slot['start'], 'endTime' => $slot['end']];
            }
        }

        $reason = 'Automatically declined: ' . $why . '.'
            . ($rescheduleMode ? ' No free alternative slot was found in the next ' . self::AUTO_SEARCH_DAYS . ' days.' : '');
        $reason = self::clip($reason, 900);
        $upd = $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, DeclineReason = ?, HrNotes = ?, DecidedAt = SYSUTCDATETIME(),
                DecidedByUserId = ?, UpdatedAt = SYSUTCDATETIME()
             WHERE BookingId = ? AND Status IN (?, ?)"
        );
        $upd->execute([self::STATUS_DECLINED, $reason, $reason, $actorId, $loserId, $open[0], $open[1]]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        self::history($pdo, $loserId, $b['status'], self::STATUS_DECLINED, $actorId, $reason);
        NotificationService::notify($b['requesterId'], 'Booking declined', $b['ref'] . ' was declined. ' . $reason, $link);
        if ($b['departmentManagerId'] && $b['departmentManagerId'] !== $actorId) {
            NotificationService::notify($b['departmentManagerId'], 'Booking declined automatically', $b['ref'] . ': ' . $reason, $link);
        }
        ActivityLog::write($actorId, $actorEmail, 'booking.auto_decline', 'booking', (string)$loserId, ['winner' => $winner['ref']]);
        return ['id' => $loserId, 'ref' => $b['ref'], 'outcome' => 'declined'];
    }

    /**
     * Nearest free slot for a booking: same venue and length, closest to the original start time on the same day,
     * then the following days. Every candidate must pass the normal booking rules (hours, capacity, venue status,
     * conflicts, equipment stock). $avoid lists extra ranges that must stay free (slots already offered to others).
     * Returns ['date' => 'Y-m-d', 'start' => 'HH:MM', 'end' => 'HH:MM'] or null.
     */
    private static function findAlternativeSlot(array $b, array $avoid = []): ?array
    {
        $step = self::stepMinutes();
        $buffer = self::bufferMinutes();
        $origStart = self::toMinutes($b['startTime']);
        $origEnd = self::toMinutes($b['endTime']);
        $open = self::toMinutes((string)Settings::get('operating_hours_start', '08:00'));
        $close = self::toMinutes((string)Settings::get('operating_hours_end', '17:00'));
        if ($origStart === null || $origEnd === null || $open === null || $close === null) {
            return null;
        }
        $dur = $origEnd - $origStart;
        if ($dur <= 0 || $dur > ($close - $open)) {
            return null;
        }

        $starts = [];
        $first = (int)(ceil($open / $step) * $step);
        for ($s = $first; $s + $dur <= $close; $s += $step) {
            $starts[] = $s;
        }
        // Closest to the original start first; on a tie prefer the later slot.
        usort($starts, static function (int $x, int $y) use ($origStart): int {
            $dx = abs($x - $origStart);
            $dy = abs($y - $origStart);
            return $dx === $dy ? $y <=> $x : $dx <=> $dy;
        });

        $fmt = static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $reqs = array_map(
            static fn($r) => [$r['optionId'], $r['name'], $r['quantity']],
            $b['requirements'] ?? RequirementOptions::forBooking((int)$b['id'])
        );

        for ($d = 0; $d < self::AUTO_SEARCH_DAYS; $d++) {
            $date = date('Y-m-d', strtotime($b['date'] . " +{$d} days"));
            foreach ($starts as $s) {
                $e = $s + $dur;
                $blocked = false;
                foreach ($avoid as $t) {
                    if ((int)$t['venueId'] === (int)$b['venueId'] && $t['date'] === $date) {
                        $ts = self::toMinutes($t['start']);
                        $te = self::toMinutes($t['end']);
                        if ($ts !== null && $te !== null && $s < $te + $buffer && $e > $ts - $buffer) {
                            $blocked = true;
                            break;
                        }
                    }
                }
                if ($blocked) {
                    continue;
                }
                $candidate = array_merge($b, ['date' => $date, 'startTime' => $fmt($s), 'endTime' => $fmt($e)]);
                if (self::validatePayload($candidate, (int)$b['id'])) {
                    continue; // fails hours / rules / venue status / conflict
                }
                if ($reqs && RequirementOptions::checkStock($reqs, (int)$b['venueId'], $date, self::normalizeTime($fmt($s)), self::normalizeTime($fmt($e)), (int)$b['id'])) {
                    continue; // equipment not available then
                }
                return ['date' => $date, 'start' => $fmt($s), 'end' => $fmt($e)];
            }
        }
        return null;
    }

    /** Other requests waiting for HR that overlap this booking (buffer included), earliest manager validation first. */
    private static function pendingCompetitors(array $b): array
    {
        $buffer = self::bufferMinutes();
        $stmt = Database::pdo()->prepare(
            "SELECT b.BookingId, b.ReferenceCode, COALESCE(b.ReachedHrAt, b.ValidatedAt, b.CreatedAt) AS QueuedAt, b.Status, u.FullName AS RequesterName
             FROM dbo.Bookings b
             INNER JOIN dbo.Users u ON u.UserId = b.RequesterUserId
             WHERE b.VenueId = ? AND b.BookingDate = ? AND b.BookingId <> ?
               AND b.Status IN (?, ?)
               AND b.StartTime < ? AND b.EndTime > ?
             ORDER BY COALESCE(b.ValidatedAt, b.CreatedAt), b.BookingId"
        );
        $stmt->execute([
            (int)$b['venueId'], $b['date'], (int)$b['id'],
            self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED,
            self::shiftTime($b['endTime'], $buffer), self::shiftTime($b['startTime'], -$buffer),
        ]);
        return array_map(static function ($r) {
            return [
                'id' => (int)$r['BookingId'],
                'ref' => $r['ReferenceCode'],
                'requesterName' => $r['RequesterName'],
                'status' => $r['Status'],
                'queuedAt' => self::fmtStamp($r['QueuedAt'] ?? null),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * A booking that really holds its slot: Approved / Rescheduled, or an approved booking with an open reschedule
     * proposal. (A pending request that merely received a proposal does not count.) Buffer applies like findConflict().
     */
    private static function findConfirmedConflict(int $venueId, string $date, string $start, string $end, ?int $excludeId = null): ?array
    {
        $buffer = self::bufferMinutes();
        $sql = "SELECT TOP 1 BookingId, ReferenceCode, StartTime, EndTime, Status
                FROM dbo.Bookings
                WHERE VenueId = ? AND BookingDate = ?
                  AND (Status IN (?, ?) OR (Status = ? AND StatusBeforeProposal IN (?, ?)))
                  AND StartTime < ? AND EndTime > ?";
        $bind = [
            $venueId, $date,
            self::STATUS_APPROVED, self::STATUS_RESCHEDULED,
            self::STATUS_PROPOSED, self::STATUS_APPROVED, self::STATUS_RESCHEDULED,
            self::shiftTime($end, $buffer), self::shiftTime($start, -$buffer),
        ];
        if ($excludeId) {
            $sql .= ' AND BookingId <> ?';
            $bind[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** For HR before they approve: which other requests would be affected, and who was validated first. */
    public static function competingFor(int $id): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        $list = self::pendingCompetitors($b);
        $mine = $b['hrQueuedAt'];
        foreach ($list as &$c) {
            $c['validatedFirst'] = $c['queuedAt'] !== null && ($mine === null || $c['queuedAt'] < $mine);
        }
        unset($c);
        $held = self::findConfirmedConflict((int)$b['venueId'], $b['date'], $b['startTime'], $b['endTime'], $id);
        return [
            'competing' => $list,
            'heldBy' => $held ? ['id' => (int)$held['BookingId'], 'ref' => $held['ReferenceCode']] : null,
            'action' => Settings::get('conflict_auto_action', 'reschedule') === 'decline' ? 'decline' : 'reschedule',
        ];
    }

    /**
     * Emergency: HR/Admin needs the venue now and an approved booking is in the way.
     * mode = 'cancel'     -> the booking is cancelled at once.
     * mode = 'reschedule' -> the booking is moved at once (no need for the requester to accept). Give date/startTime/endTime
     *                        (venueId optional), or leave them out to move it to the nearest free slot.
     * A written reason is mandatory; the requester and the department manager are notified; everything is logged.
     */
    public static function emergencyOverride(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '') {
            Response::error('A reason is required for an emergency override.', 422);
        }
        if (strlen($reason) > 500) {
            Response::error('Please keep the reason under 500 characters.', 422);
        }
        if ($b['requesterId'] === $user['id']) {
            Response::error('This is your own booking - cancel it instead of overriding it.', 403);
        }

        $pdo = Database::pdo();
        $holds = in_array($b['status'], [self::STATUS_APPROVED, self::STATUS_RESCHEDULED], true);
        if ($b['status'] === self::STATUS_PROPOSED) {
            $q = $pdo->prepare('SELECT StatusBeforeProposal FROM dbo.Bookings WHERE BookingId = ?');
            $q->execute([$id]);
            $holds = in_array((string)$q->fetchColumn(), [self::STATUS_APPROVED, self::STATUS_RESCHEDULED], true);
        }
        if (!$holds) {
            Response::error('Only confirmed (approved or rescheduled) bookings can be overridden. Use Decline for requests that are still pending.', 409);
        }
        if (($b['phase'] ?? null) === 'done') {
            Response::error('This booking has already ended.', 409);
        }

        // Optional: HR/Admin also books the freed time for themselves in the same step, so nobody can grab the
        // slot between the override and the emergency booking (HR/Admin bookings are confirmed immediately).
        $takePayload = null;
        if (!empty($payload['takeOver'])) {
            $takePurpose = trim((string)($payload['purpose'] ?? ''));
            if ($takePurpose === '') {
                Response::error('Describe what the venue is needed for (purpose) to book it for yourself.', 422);
            }
            $takePayload = [
                'venueId' => (int)$b['venueId'], 'date' => $b['date'], 'startTime' => $b['startTime'], 'endTime' => $b['endTime'],
                'purpose' => self::clip($takePurpose, 500), 'attendees' => max(1, (int)($payload['attendees'] ?? 1)),
                'requirements' => [],
            ];
            // The booking being overridden is ignored for the conflict check, because it is about to leave this slot.
            $preErrors = self::validatePayload($takePayload, $id, $user);
            if ($preErrors) {
                Response::error(reset($preErrors), 422, ['errors' => $preErrors]);
            }
        }

        $mode = (($payload['mode'] ?? 'cancel') === 'reschedule') ? 'reschedule' : 'cancel';
        $note = self::clip('Emergency override by ' . $user['role'] . ': ' . $reason, 900);
        $live = [self::STATUS_APPROVED, self::STATUS_RESCHEDULED, self::STATUS_PROPOSED];
        $link = 'booking-details.html?id=' . $id;
        $clearProposal = 'ProposedVenueId = NULL, ProposedDate = NULL, ProposedStartTime = NULL, ProposedEndTime = NULL, ProposalNotes = NULL, StatusBeforeProposal = NULL';

        if ($mode === 'reschedule') {
            if (!empty($payload['date']) && !empty($payload['startTime']) && !empty($payload['endTime'])) {
                $slot = [
                    'venueId' => (int)($payload['venueId'] ?? $b['venueId']),
                    'date' => substr((string)$payload['date'], 0, 10),
                    'start' => substr((string)$payload['startTime'], 0, 5),
                    'end' => substr((string)$payload['endTime'], 0, 5),
                ];
            } else {
                // Nearest free slot, keeping well away from the time HR is about to use.
                $found = self::findAlternativeSlot($b, [[
                    'venueId' => (int)$b['venueId'], 'date' => $b['date'], 'start' => $b['startTime'], 'end' => $b['endTime'],
                ]]);
                if (!$found) {
                    Response::error('No free slot was found in the next ' . self::AUTO_SEARCH_DAYS . ' days. Pick a time manually or cancel the booking.', 409);
                }
                $slot = ['venueId' => (int)$b['venueId'], 'date' => $found['date'], 'start' => $found['start'], 'end' => $found['end']];
            }
            if ($takePayload && $slot['venueId'] === (int)$b['venueId'] && $slot['date'] === $b['date']) {
                $ns = self::toMinutes($slot['start']);
                $ne = self::toMinutes($slot['end']);
                $os = self::toMinutes($b['startTime']);
                $oe = self::toMinutes($b['endTime']);
                $buf = self::bufferMinutes();
                if ($ns !== null && $ne !== null && $os !== null && $oe !== null && $ns < $oe + $buf && $ne > $os - $buf) {
                    Response::error('The new time overlaps the time you want to use yourself. Choose a different time.', 422);
                }
            }
            $merged = array_merge($b, ['venueId' => $slot['venueId'], 'date' => $slot['date'], 'startTime' => $slot['start'], 'endTime' => $slot['end']]);
            $errors = self::validatePayload($merged, $id);
            if ($errors) {
                Response::error(reset($errors), 422, ['errors' => $errors]);
            }
            $reqs = array_map(static fn($r) => [$r['optionId'], $r['name'], $r['quantity']], $b['requirements'] ?? []);

            $pdo->beginTransaction();
            try {
                if (self::findConflict($slot['venueId'], $slot['date'], $slot['start'], $slot['end'], $id)) {
                    $pdo->rollBack();
                    Response::error('The new time is no longer free. Choose another time.', 409);
                }
                $stockErr = RequirementOptions::checkStock($reqs, $slot['venueId'], $slot['date'], self::normalizeTime($slot['start']), self::normalizeTime($slot['end']), $id);
                if ($stockErr) {
                    $pdo->rollBack();
                    Response::error('Cannot move there: ' . $stockErr, 409);
                }
                $upd = $pdo->prepare(
                    "UPDATE dbo.Bookings SET VenueId = ?, BookingDate = ?, StartTime = ?, EndTime = ?, Status = ?, HrNotes = ?,
                        DecidedAt = SYSUTCDATETIME(), DecidedByUserId = ?, {$clearProposal}, UpdatedAt = SYSUTCDATETIME()
                     WHERE BookingId = ? AND Status IN (?, ?, ?)"
                );
                $upd->execute([
                    $slot['venueId'], $slot['date'], self::normalizeTime($slot['start']), self::normalizeTime($slot['end']),
                    self::STATUS_RESCHEDULED, $note, $user['id'], $id, $live[0], $live[1], $live[2],
                ]);
                if ($upd->rowCount() !== 1) {
                    $pdo->rollBack();
                    Response::error('This booking was changed by someone else. Reload and try again.', 409);
                }
                self::history($pdo, $id, $b['status'], self::STATUS_RESCHEDULED, $user['id'],
                    self::clip('EMERGENCY OVERRIDE - moved from ' . $b['date'] . ' ' . $b['startTime'] . '-' . $b['endTime']
                        . ' to ' . $slot['date'] . ' ' . $slot['start'] . '-' . $slot['end'] . '. Reason: ' . $reason, 900));
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $msg = $b['ref'] . ' was moved to ' . $slot['date'] . ' ' . $slot['start'] . '-' . $slot['end'] . ' because the venue is needed urgently. Reason: ' . $reason;
            $title = 'Booking moved (emergency)';
        } else {
            $upd = $pdo->prepare(
                "UPDATE dbo.Bookings SET Status = ?, CancelReason = ?, CancelledAt = SYSUTCDATETIME(), CancelledByUserId = ?,
                    HrNotes = ?, {$clearProposal}, UpdatedAt = SYSUTCDATETIME()
                 WHERE BookingId = ? AND Status IN (?, ?, ?)"
            );
            $upd->execute([
                self::STATUS_CANCELLED, self::clip('Emergency override: ' . $reason, 900), $user['id'], $note,
                $id, $live[0], $live[1], $live[2],
            ]);
            if ($upd->rowCount() !== 1) {
                Response::error('This booking was changed by someone else. Reload and try again.', 409);
            }
            self::history($pdo, $id, $b['status'], self::STATUS_CANCELLED, $user['id'], self::clip('EMERGENCY OVERRIDE - cancelled. Reason: ' . $reason, 900));
            $msg = $b['ref'] . ' was cancelled because the venue is needed urgently. Reason: ' . $reason;
            $title = 'Booking cancelled (emergency)';
        }

        ActivityLog::write($user['id'], $user['email'], 'booking.emergency_override', 'booking', (string)$id, ['mode' => $mode, 'reason' => $reason]);
        NotificationService::notify($b['requesterId'], $title, self::clip($msg, 900), $link);
        if ($b['departmentManagerId'] && $b['departmentManagerId'] !== $user['id']) {
            NotificationService::notify($b['departmentManagerId'], $title, self::clip($msg, 900), $link);
        }

        $result = self::getById($id);
        if ($takePayload) {
            $mine = self::create($user, $takePayload + [
                'bypassReason' => self::clip('Emergency override of ' . $b['ref'] . ': ' . $reason, 500),
            ]);
            $result['takeOverBooking'] = ['id' => $mine['id'] ?? null, 'ref' => $mine['ref'] ?? null, 'status' => $mine['status'] ?? null];
        }
        return $result;
    }

    /** Adds 'competing' (other pending-HR requests that overlap) and 'firstInLine' to rows HR is about to decide on. */
    private static function annotateCompeting(array $rows): array
    {
        $open = [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED];
        $hasPending = false;
        foreach ($rows as $r) {
            if (in_array($r['status'], $open, true)) {
                $hasPending = true;
                break;
            }
        }
        if (!$hasPending) {
            return $rows;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT BookingId, ReferenceCode, VenueId, BookingDate, StartTime, EndTime, COALESCE(ValidatedAt, CreatedAt) AS QueuedAt
             FROM dbo.Bookings WHERE Status IN (?, ?) AND BookingDate >= ?"
        );
        $stmt->execute([$open[0], $open[1], date('Y-m-d')]);
        $all = [];
        foreach ($stmt->fetchAll() as $o) {
            $all[] = [
                'id' => (int)$o['BookingId'], 'ref' => $o['ReferenceCode'], 'venueId' => (int)$o['VenueId'],
                'date' => self::fmtDate($o['BookingDate']),
                'start' => (int)self::toMinutes(self::fmtTime($o['StartTime'])),
                'end' => (int)self::toMinutes(self::fmtTime($o['EndTime'])),
                'queuedAt' => self::fmtStamp($o['QueuedAt'] ?? null),
            ];
        }
        $buffer = self::bufferMinutes();
        foreach ($rows as &$r) {
            if (!in_array($r['status'], $open, true)) {
                continue;
            }
            $rs = (int)self::toMinutes($r['startTime']);
            $re = (int)self::toMinutes($r['endTime']);
            $r['competing'] = [];
            $first = true;
            foreach ($all as $o) {
                if ($o['id'] === $r['id'] || $o['venueId'] !== $r['venueId'] || $o['date'] !== $r['date']) {
                    continue;
                }
                if ($o['start'] < $re + $buffer && $o['end'] > $rs - $buffer) {
                    $r['competing'][] = ['id' => $o['id'], 'ref' => $o['ref'], 'queuedAt' => $o['queuedAt']];
                    $earlier = $o['queuedAt'] !== null && ($r['hrQueuedAt'] === null || $o['queuedAt'] < $r['hrQueuedAt']);
                    $tie = $o['queuedAt'] === $r['hrQueuedAt'] && $o['id'] < $r['id'];
                    if ($earlier || $tie) {
                        $first = false;
                    }
                }
            }
            $r['firstInLine'] = $first;
        }
        unset($r);
        return $rows;
    }

    private static function clip(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
        }
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '...' : $s;
    }

    /**
     * Append an audit row. Always store a clear reason so investigators can reconstruct the process.
     * $by = null means system/automated action.
     */
    private static function history(PDO $pdo, int $bookingId, ?string $from, string $to, ?int $by, ?string $reason): void
    {
        $reason = $reason !== null && $reason !== '' ? self::clip((string)$reason, 1000) : null;
        if ($reason === null) {
            if ($from === null) {
                $reason = "Created with status \"{$to}\"";
            } elseif ($by === null) {
                $reason = "System transition from \"{$from}\" to \"{$to}\"";
            } else {
                $reason = "Status changed from \"{$from}\" to \"{$to}\"";
            }
        }
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

    /** True if dbo.$table has column $col (cached per request). */
    private static function hasColumn(PDO $pdo, string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare("SELECT COL_LENGTH(N'dbo.{$table}', ?) AS c");
            $stmt->execute([$col]);
            $r = $stmt->fetch();
            $cache[$key] = $r && $r['c'] !== null;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    /**
     * Option B queue: order by ReachedHrAt (who reached HR first).
     * Pending does not hold the slot; first HR approval wins.
     */
    public static function queuePosition(int $bookingId, ?array $viewer = null): array
    {
        $b = self::getById($bookingId);
        if (!$b) {
            return ['position' => null, 'competitors' => []];
        }
        $isStaff = $viewer && in_array($viewer['role'] ?? '', ['HR', 'Administrator'], true);
        if ($viewer && !$isStaff) {
            $ownDept = ($viewer['role'] ?? '') === 'Manager' && !empty($viewer['departmentId'])
                && (int)$viewer['departmentId'] === (int)$b['departmentId'];
            if ($b['requesterId'] !== $viewer['id'] && !$ownDept) {
                Response::error('Forbidden.', 403);
            }
        }
        $pdo = Database::pdo();
        $hasReached = self::hasColumn($pdo, 'Bookings', 'ReachedHrAt');
        $orderCol = $hasReached
            ? 'COALESCE(b.ReachedHrAt, b.ValidatedAt, b.CreatedAt)'
            : 'COALESCE(b.ValidatedAt, b.CreatedAt)';
        $sql = "SELECT b.BookingId, b.ReferenceCode, {$orderCol} AS QueuedAt, u.FullName AS RequesterName
                FROM dbo.Bookings b
                LEFT JOIN dbo.Users u ON u.UserId = b.RequesterUserId
                WHERE b.VenueId = ? AND b.BookingDate = ?
                  AND b.Status IN (?, ?)
                  AND b.StartTime < ? AND b.EndTime > ?
                ORDER BY {$orderCol} ASC, b.BookingId ASC";
        $stmt = $pdo->prepare($sql);
        $end = self::normalizeTime($b['endTime']);
        $start = self::normalizeTime($b['startTime']);
        $stmt->execute([
            (int)$b['venueId'], $b['date'],
            self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED,
            $end, $start,
        ]);
        $rows = $stmt->fetchAll();
        $competitors = [];
        $position = null;
        $i = 0;
        foreach ($rows as $r) {
            $i++;
            $item = [
                'id' => (int)$r['BookingId'],
                'ref' => $r['ReferenceCode'],
                'requester' => $r['RequesterName'],
                'reachedHrAt' => self::fmtStamp($r['QueuedAt'] ?? null),
                'position' => $i,
            ];
            if ((int)$r['BookingId'] === $bookingId) {
                $position = $i;
            }
            $competitors[] = $item;
        }
        if ($viewer && !$isStaff) {
            // Requesters see where they stand, never who else is asking for the room.
            $competitors = [];
        }
        return [
            'position' => $position,
            'totalInQueue' => count($rows),
            'competitors' => $competitors,
            'policy' => 'queue',
            'message' => $position
                ? "You are #{$position} of " . count($rows) . " for this slot (first to reach HR wins)."
                : 'Not in the HR decision queue for this slot.',
        ];
    }

    /** Move Approved/Rescheduled bookings whose end time has passed to Completed. */
    public static function completePastBookings(): array
    {
        if (Settings::get('auto_complete_enabled', '1') !== '1') {
            return ['completed' => 0, 'skipped' => true];
        }
        $n = self::runAutoComplete();
        if ($n > 0) {
            ActivityLog::write(null, null, 'booking.auto_complete', 'booking', null, ['count' => $n]);
        }
        return ['completed' => $n];
    }

    /** Mark past approved booking as No-Show (HR/Admin). */
    public static function markNoShow(array $user, int $id, array $payload): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        if (!in_array($b['status'], [self::STATUS_APPROVED, self::STATUS_RESCHEDULED, self::STATUS_COMPLETED], true)) {
            Response::error('Only approved or completed bookings can be marked no-show.', 409);
        }
        $startTs = strtotime($b['date'] . ' ' . $b['startTime']);
        if ($startTs === false || $startTs > time()) {
            Response::error('A booking can only be marked no-show after its start time has passed.', 409);
        }
        BookingWorkflow::assertTransition(
            $b['status'] === self::STATUS_COMPLETED ? self::STATUS_COMPLETED : self::STATUS_APPROVED,
            self::STATUS_NO_SHOW,
            'mark no-show'
        );
        // Allow Completed -> No-Show even if map is strict
        $reason = trim((string)($payload['reason'] ?? '')) ?: 'Marked as no-show';
        $pdo = Database::pdo();
        $pdo->prepare(
            "UPDATE dbo.Bookings SET Status = ?, HrNotes = COALESCE(?, HrNotes), UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ?"
        )->execute([self::STATUS_NO_SHOW, $reason, $id]);
        self::history($pdo, $id, $b['status'], self::STATUS_NO_SHOW, $user['id'], $reason);
        ActivityLog::write($user['id'], $user['email'], 'booking.no_show', 'booking', (string)$id, null);
        NotificationService::notify($b['requesterId'], 'Marked no-show', $b['ref'] . ' was marked as no-show.', 'booking-details.html?id=' . $id);
        return self::getById($id);
    }

    /**
     * Escalate Pending Manager requests older than manager_sla_days to Pending HR.
     * Uses StageEnteredAt (or CreatedAt fallback) so resubmitted requests get a fresh clock.
     * Urgent events (within urgent_event_days) use a 1-day SLA.
     */
    public static function escalateOverdueManagerValidations(): array
    {
        $days = Settings::int('manager_sla_days', 3);
        if ($days <= 0) {
            return ['escalated' => 0, 'disabled' => true];
        }
        $urgentDays = Settings::int('urgent_event_days', 3);
        $pdo = Database::pdo();
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        $hasReached = self::hasColumn($pdo, 'Bookings', 'ReachedHrAt');
        $hasOverdue = self::hasColumn($pdo, 'Bookings', 'IsOverdue');

        $clock = $hasStage
            ? 'COALESCE(StageEnteredAt, CreatedAt)'
            : 'CreatedAt';

        // Standard SLA
        $setParts = ['Status = ?', 'UpdatedAt = SYSUTCDATETIME()'];
        if ($hasReached) {
            $setParts[] = 'ReachedHrAt = SYSUTCDATETIME()';
        }
        if ($hasStage) {
            $setParts[] = 'StageEnteredAt = SYSUTCDATETIME()';
        }
        if ($hasOverdue) {
            $setParts[] = 'IsOverdue = 1';
        }
        // Clear manager viewed so HR stage starts clean; reminder counters reset
        if (self::hasColumn($pdo, 'Bookings', 'ReminderCount')) {
            $setParts[] = 'ReminderCount = 0';
            $setParts[] = 'LastReminderAt = NULL';
        }

        $sql = "UPDATE dbo.Bookings
                SET " . implode(', ', $setParts) . "
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId, INSERTED.DepartmentManagerId
                WHERE Status = ?
                  AND {$clock} < DATEADD(DAY, -?, SYSUTCDATETIME())
                  AND (
                    BookingDate > DATEADD(DAY, ?, CAST(SYSUTCDATETIME() AS DATE))
                    OR {$clock} < DATEADD(DAY, -1, SYSUTCDATETIME())
                  )";
        // For urgent events (event within urgentDays), also escalate if stage older than 1 day
        // The OR above: if event is far away use full $days; if event is soon, 1-day floor already covered by the second clause when days>1.
        // Simpler approach: use effective days = 1 when event is soon.
        $sql = "UPDATE dbo.Bookings
                SET " . implode(', ', $setParts) . "
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId, INSERTED.DepartmentManagerId
                WHERE Status = ?
                  AND (
                    ({$clock} < DATEADD(DAY, -?, SYSUTCDATETIME()) AND BookingDate > DATEADD(DAY, ?, CAST(SYSUTCDATETIME() AS DATE)))
                    OR
                    ({$clock} < DATEADD(DAY, -1, SYSUTCDATETIME()) AND BookingDate <= DATEADD(DAY, ?, CAST(SYSUTCDATETIME() AS DATE)))
                  )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            self::STATUS_PENDING_HR,
            self::STATUS_PENDING_MANAGER,
            $days,
            $urgentDays,
            $urgentDays,
        ]);
        $rows = $stmt->fetchAll();
        $notifyManager = Settings::get('sla_notify_manager_on_escalation', '1') === '1';

        foreach ($rows as $r) {
            $bid = (int)$r['BookingId'];
            self::history($pdo, $bid, self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, null,
                "Auto-escalated to HR after manager SLA");
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Request escalated to HR',
                'Your booking ' . $r['ReferenceCode'] . ' was escalated because the manager did not act within the SLA.',
                'booking-details.html?id=' . $bid
            );
            if ($notifyManager && !empty($r['DepartmentManagerId'])) {
                NotificationService::notify(
                    (int)$r['DepartmentManagerId'],
                    'Request auto-escalated',
                    $r['ReferenceCode'] . ' was moved to HR because the manager SLA elapsed.',
                    'booking-details.html?id=' . $bid
                );
            }
            $hrIds = self::usersByRole('HR');
            if (empty($hrIds)) {
                $hrIds = self::usersByRole('Administrator');
            }
            foreach ($hrIds as $hrId) {
                NotificationService::notify($hrId, 'Escalated booking', $r['ReferenceCode'] . ' awaits HR (manager SLA).', 'booking-details.html?id=' . $bid);
            }
        }
        return ['escalated' => count($rows)];
    }

    /**
     * Escalate Pending HR requests older than hr_sla_days; notify Administrators.
     */
    public static function escalateOverdueHrDecisions(): array
    {
        $days = Settings::int('hr_sla_days', 3);
        if ($days <= 0) {
            return ['escalated' => 0, 'disabled' => true];
        }
        $pdo = Database::pdo();
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        $hasOverdue = self::hasColumn($pdo, 'Bookings', 'IsOverdue');
        $clock = $hasStage ? 'COALESCE(StageEnteredAt, ReachedHrAt, CreatedAt)' : 'COALESCE(ReachedHrAt, CreatedAt)';

        $setParts = ['UpdatedAt = SYSUTCDATETIME()'];
        if ($hasOverdue) {
            $setParts[] = 'IsOverdue = 1';
        }
        // We keep status as Pending HR but flag overdue and alert admins (no status change required).
        $sql = "UPDATE dbo.Bookings
                SET " . implode(', ', $setParts) . "
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId
                WHERE Status IN (?, ?)
                  AND {$clock} < DATEADD(DAY, -?, SYSUTCDATETIME())
                  AND (" . ($hasOverdue ? 'IsOverdue = 0' : '1=1') . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED, $days]);
        $rows = $stmt->fetchAll();

        $adminIds = self::usersByRole('Administrator');
        $hrIds = self::usersByRole('HR');
        foreach ($rows as $r) {
            $bid = (int)$r['BookingId'];
            self::history($pdo, $bid, self::STATUS_PENDING_HR, self::STATUS_PENDING_HR, null,
                "HR SLA exceeded ({$days} days) — escalated to Administrator attention");
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Request still awaiting HR',
                'Your booking ' . $r['ReferenceCode'] . ' has exceeded the HR decision SLA. Administrators have been notified.',
                'booking-details.html?id=' . $bid
            );
            foreach (array_unique(array_merge($adminIds, $hrIds)) as $uid) {
                NotificationService::notify(
                    (int)$uid,
                    'Overdue HR decision',
                    $r['ReferenceCode'] . ' has been waiting longer than the HR SLA.',
                    'booking-details.html?id=' . $bid
                );
            }
        }
        return ['escalated' => count($rows)];
    }

    /**
     * Send periodic reminders to the current stage owner.
     * Returns number of reminders sent.
     */
    private static function sendStageReminders(string $status, string $roleCode): int
    {
        $interval = Settings::int('reminder_interval_days', 1);
        if ($interval <= 0) {
            return 0;
        }
        $pdo = Database::pdo();
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        $hasRem = self::hasColumn($pdo, 'Bookings', 'LastReminderAt');
        if (!$hasRem) {
            return 0;
        }
        $clock = $hasStage ? 'COALESCE(StageEnteredAt, CreatedAt)' : 'CreatedAt';

        $sql = "SELECT BookingId, ReferenceCode, RequesterUserId, DepartmentManagerId, Status,
                       ManagerViewedAt, HrViewedAt, ReminderCount
                FROM dbo.Bookings
                WHERE Status = ?
                  AND {$clock} < DATEADD(DAY, -?, SYSUTCDATETIME())
                  AND (LastReminderAt IS NULL OR LastReminderAt < DATEADD(DAY, -?, SYSUTCDATETIME()))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status, $interval, $interval]);
        $rows = $stmt->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            $bid = (int)$r['BookingId'];
            $viewedNote = '';
            if ($roleCode === 'Manager' && empty($r['ManagerViewedAt'])) {
                $viewedNote = ' (not yet opened)';
            } elseif ($roleCode === 'HR' && empty($r['HrViewedAt'])) {
                $viewedNote = ' (not yet opened)';
            } else {
                $viewedNote = ' (opened, awaiting decision)';
            }
            $targets = [];
            if ($roleCode === 'Manager' && !empty($r['DepartmentManagerId'])) {
                $targets[] = (int)$r['DepartmentManagerId'];
            } elseif ($roleCode === 'HR') {
                $targets = self::usersByRole('HR');
                if (empty($targets)) {
                    $targets = self::usersByRole('Administrator');
                }
            }
            foreach ($targets as $uid) {
                NotificationService::notify(
                    $uid,
                    'Reminder: action required',
                    $r['ReferenceCode'] . ' is still waiting for ' . $roleCode . $viewedNote . '.',
                    'booking-details.html?id=' . $bid
                );
            }
            // Also tell requester who it is waiting on
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Still waiting on ' . $roleCode,
                'Your booking ' . $r['ReferenceCode'] . ' is still awaiting a decision from ' . $roleCode . '.',
                'booking-details.html?id=' . $bid
            );
            $pdo->prepare(
                "UPDATE dbo.Bookings SET LastReminderAt = SYSUTCDATETIME(),
                    ReminderCount = ISNULL(ReminderCount, 0) + 1, UpdatedAt = SYSUTCDATETIME()
                 WHERE BookingId = ?"
            )->execute([$bid]);
            $count++;
        }
        return $count;
    }

    /**
     * Auto-cancel Revision Requested after revision_deadline_days and free the slot.
     */
    private static function expireStaleRevisions(): int
    {
        $days = Settings::int('revision_deadline_days', 3);
        if ($days <= 0) {
            return 0;
        }
        $pdo = Database::pdo();
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        $clock = $hasStage ? 'COALESCE(StageEnteredAt, UpdatedAt, CreatedAt)' : 'COALESCE(UpdatedAt, CreatedAt)';
        $sql = "UPDATE dbo.Bookings
                SET Status = ?, CancelReason = ?, CancelledAt = SYSUTCDATETIME(), UpdatedAt = SYSUTCDATETIME()
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId, DELETED.Status AS FromStatus
                WHERE Status = ?
                  AND {$clock} < DATEADD(DAY, -?, SYSUTCDATETIME())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            self::STATUS_CANCELLED,
            "Automatically cancelled: revision not submitted within {$days} days",
            self::STATUS_REVISION,
            $days,
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            self::history($pdo, (int)$r['BookingId'], $r['FromStatus'] ?? self::STATUS_REVISION, self::STATUS_CANCELLED, null,
                "Revision deadline ({$days} days) elapsed");
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Request cancelled',
                $r['ReferenceCode'] . ' was cancelled because the revision was not submitted in time.',
                'booking-details.html?id=' . $r['BookingId']
            );
        }
        return count($rows);
    }

    /**
     * Auto-cancel unanswered Reschedule Proposed after proposal_deadline_days.
     */
    private static function expireStaleProposals(): int
    {
        $days = Settings::int('proposal_deadline_days', 3);
        if ($days <= 0) {
            return 0;
        }
        $pdo = Database::pdo();
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        $clock = $hasStage ? 'COALESCE(StageEnteredAt, UpdatedAt, CreatedAt)' : 'COALESCE(UpdatedAt, CreatedAt)';
        $sql = "UPDATE dbo.Bookings
                SET Status = ?, CancelReason = ?, CancelledAt = SYSUTCDATETIME(), UpdatedAt = SYSUTCDATETIME()
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId, DELETED.Status AS FromStatus
                WHERE Status = ?
                  AND {$clock} < DATEADD(DAY, -?, SYSUTCDATETIME())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            self::STATUS_CANCELLED,
            "Automatically cancelled: reschedule proposal not answered within {$days} days",
            self::STATUS_PROPOSED,
            $days,
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            self::history($pdo, (int)$r['BookingId'], $r['FromStatus'] ?? self::STATUS_PROPOSED, self::STATUS_CANCELLED, null,
                "Proposal deadline ({$days} days) elapsed");
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Proposal expired',
                $r['ReferenceCode'] . ' was cancelled because the reschedule proposal was not answered in time. The slot has been freed.',
                'booking-details.html?id=' . $r['BookingId']
            );
            foreach (self::usersByRole('HR') as $hrId) {
                NotificationService::notify($hrId, 'Proposal expired', $r['ReferenceCode'] . ' cancelled (unanswered proposal).', 'booking-details.html?id=' . $r['BookingId']);
            }
        }
        return count($rows);
    }

    /**
     * Mark undecided bookings whose event date has already passed as Expired (never auto-approve).
     */
    private static function expirePastUndecided(): int
    {
        if (Settings::get('expire_undecided_enabled', '1') !== '1') {
            return 0;
        }
        $pdo = Database::pdo();
        $waiting = [
            self::STATUS_PENDING_MANAGER,
            self::STATUS_PENDING_HR,
            self::STATUS_MANAGER_VALIDATED,
            self::STATUS_REVISION,
            self::STATUS_PROPOSED,
        ];
        $placeholders = implode(',', array_fill(0, count($waiting), '?'));
        $sql = "UPDATE dbo.Bookings
                SET Status = ?, UpdatedAt = SYSUTCDATETIME()
                OUTPUT INSERTED.BookingId, INSERTED.ReferenceCode, INSERTED.RequesterUserId, DELETED.Status AS FromStatus
                WHERE Status IN ($placeholders)
                  AND BookingDate < CAST(SYSUTCDATETIME() AS DATE)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([self::STATUS_EXPIRED], $waiting));
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            self::history($pdo, (int)$r['BookingId'], $r['FromStatus'] ?? 'Unknown', self::STATUS_EXPIRED, null,
                'Event date passed while still undecided — marked Expired');
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Booking expired',
                $r['ReferenceCode'] . ' could not be decided before the event date. Please make other arrangements.',
                'booking-details.html?id=' . $r['BookingId']
            );
        }
        return count($rows);
    }

    /**
     * Record that the current stage owner opened the booking details.
     * Viewing never pauses the SLA clock.
     */
    public static function markViewed(array $user, int $id): array
    {
        $b = self::getById($id);
        if (!$b) {
            Response::error('Booking not found.', 404);
        }
        $pdo = Database::pdo();
        $role = $user['role'] ?? '';
        $status = $b['status'];

        if ($role === 'Manager'
            && in_array($status, [self::STATUS_PENDING_MANAGER, self::STATUS_REVISION], true)
            && self::hasColumn($pdo, 'Bookings', 'ManagerViewedAt')
        ) {
            // Only stamp if not already viewed (first open)
            $pdo->prepare(
                "UPDATE dbo.Bookings SET ManagerViewedAt = COALESCE(ManagerViewedAt, SYSUTCDATETIME()),
                    UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ? AND ManagerViewedAt IS NULL"
            )->execute([$id]);
        } elseif (in_array($role, ['HR', 'Administrator'], true)
            && in_array($status, [self::STATUS_PENDING_HR, self::STATUS_MANAGER_VALIDATED], true)
            && self::hasColumn($pdo, 'Bookings', 'HrViewedAt')
        ) {
            $pdo->prepare(
                "UPDATE dbo.Bookings SET HrViewedAt = COALESCE(HrViewedAt, SYSUTCDATETIME()),
                    UpdatedAt = SYSUTCDATETIME() WHERE BookingId = ? AND HrViewedAt IS NULL"
            )->execute([$id]);
        }
        return self::getById($id);
    }

    /**
     * When a user is deactivated, reassign their open manager-queue items to HR
     * and clear DepartmentManagerId pointers that would block the process.
     */
    public static function onApproverDeactivated(int $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT BookingId, ReferenceCode, RequesterUserId FROM dbo.Bookings
             WHERE Status = ? AND (DepartmentManagerId = ? OR ValidatedByUserId = ?)"
        );
        $stmt->execute([self::STATUS_PENDING_MANAGER, $userId, $userId]);
        $rows = $stmt->fetchAll();
        $moved = 0;
        $hasStage = self::hasColumn($pdo, 'Bookings', 'StageEnteredAt');
        foreach ($rows as $r) {
            $id = (int)$r['BookingId'];
            $sets = 'Status = ?, DepartmentManagerId = NULL, UpdatedAt = SYSUTCDATETIME()';
            if (self::hasColumn($pdo, 'Bookings', 'ReachedHrAt')) {
                $sets .= ', ReachedHrAt = SYSUTCDATETIME()';
            }
            if ($hasStage) {
                $sets .= ', StageEnteredAt = SYSUTCDATETIME()';
            }
            $pdo->prepare("UPDATE dbo.Bookings SET {$sets} WHERE BookingId = ?")
                ->execute([self::STATUS_PENDING_HR, $id]);
            self::history($pdo, $id, self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR, null,
                'Approver deactivated; escalated to HR');
            NotificationService::notify(
                (int)$r['RequesterUserId'],
                'Request reassigned',
                $r['ReferenceCode'] . ' was moved to HR because the assigned manager is no longer active.',
                'booking-details.html?id=' . $id
            );
            $moved++;
        }
        // Clear manager link on departments
        $pdo->prepare('UPDATE dbo.Departments SET ManagerUserId = NULL WHERE ManagerUserId = ?')->execute([$userId]);
        return ['reassigned' => $moved];
    }

}
