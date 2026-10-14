<?php
declare(strict_types=1);

/**
 * One group of tests per item on the pre-deployment checklist (numbers match the checklist order).
 * Each test asserts the DESIRED behaviour from the checklist. A failing test means the system does
 * not meet that checklist item yet - it is not a bug in the test.
 */
final class ChecklistTest extends VbsTestCase
{
    private static function hhmm($v): string
    {
        return $v instanceof DateTimeInterface ? $v->format('H:i') : substr((string)$v, 0, 5);
    }

    // =====================================================================================
    // 1. Two users book the same venue at the same time  (Critical)
    // =====================================================================================

    public function test01a_simultaneous_direct_bookings_confirm_at_most_one(): void
    {
        $v = $this->mkVenue('Race Hall');
        $hr = $this->mkUser('HR', 'race.hr');
        $admin = $this->mkUser('Administrator', 'race.admin');

        for ($round = 0; $round < 5; $round++) {
            $date = $this->date(30 + $round);
            $payload = $this->payload($v, $date, '10:00', '11:00');
            $this->race([
                ['action' => 'create', 'user' => $hr, 'payload' => $payload],
                ['action' => 'create', 'user' => $admin, 'payload' => $payload],
            ]);
            $this->assertLessThanOrEqual(1, $this->countApprovedOverlap($v, $date, '10:00', '11:00'),
                "Round $round: two overlapping reservations were confirmed for the same venue/time.");
        }
    }

    public function test01b_simultaneous_approvals_confirm_at_most_one(): void
    {
        // Two employees both have a pending request for the same slot (pending requests do not hold the slot by default).
        $d = $this->mkDept('Race Dept');
        $e1 = $this->mkUser('Employee', 'race.e1', $d);
        $e2 = $this->mkUser('Employee', 'race.e2', $d);
        $h1 = $this->mkUser('HR', 'race.h1');
        $h2 = $this->mkUser('HR', 'race.h2');
        $v = $this->mkVenue('Race Hall 2');

        for ($round = 0; $round < 6; $round++) {
            $date = $this->date(40 + $round);
            $b1 = $this->insertBooking($v, $e1['id'], $date, '13:00', '14:00', 'Pending HR Decision', $d);
            $b2 = $this->insertBooking($v, $e2['id'], $date, '13:00', '14:00', 'Pending HR Decision', $d);
            $res = $this->race([
                ['action' => 'approve', 'user' => $h1, 'bookingId' => $b1],
                ['action' => 'approve', 'user' => $h2, 'bookingId' => $b2],
            ]);
            $this->assertLessThanOrEqual(1, $this->countApprovedOverlap($v, $date, '13:00', '14:00'),
                "Round $round: both overlapping requests were approved. Worker results: " . json_encode($res));
        }
        // NOTE: a passing result here does not PROVE safety (the race window is small).
        // test01c below is the deterministic check.
    }

    public function test01c_database_itself_rejects_two_overlapping_confirmed_rows(): void
    {
        $d = $this->mkDept('Constraint Dept');
        $u = $this->mkUser('Employee', 'constraint.e', $d);
        $v = $this->mkVenue('Constraint Hall');
        $date = $this->date();
        $this->insertBooking($v, $u['id'], $date, '10:00', '11:00', 'Approved', $d);

        $rejected = false;
        try {
            $this->insertBooking($v, $u['id'], $date, '10:30', '11:30', 'Approved', $d);
        } catch (PDOException $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'The database accepted two overlapping Approved bookings for one venue. '
            . 'Application-level checks alone leave a gap (approve() has no transaction); add a database guard.');
    }

    // =====================================================================================
    // 2. Employee attempts an Admin-only action  (Critical)
    // =====================================================================================

    public function test02_employee_cannot_use_admin_endpoints(): void
    {
        $d = $this->mkDept('Perm Dept');
        $emp = $this->mkUser('Employee', 'perm.emp', $d);
        $other = $this->mkUser('Employee', 'perm.other', $d);
        $v = $this->mkVenue('Perm Hall', 20);
        $otherBooking = $this->insertBooking($v, $other['id'], $this->date(), '09:00', '10:00', 'Pending Manager Validation', $d);
        $http = $this->httpAs($emp);

        $calls = [
            ['POST', '/users', ['email' => 'vbt.evil@test.local', 'name' => 'Evil', 'password' => 'secret123', 'role' => 'Administrator']],
            ['PUT', "/users/{$emp['id']}", ['role' => 'Administrator']],            // privilege escalation
            ['PUT', "/users/{$emp['id']}", ['departmentId' => 1]],                  // move self to another department
            ['GET', '/users', null],
            ['POST', '/venues', ['name' => 'VBT Evil', 'capacity' => 5]],
            ['PUT', "/venues/$v", ['capacity' => 1]],
            ['POST', "/venues/$v/deactivate", []],
            ['PUT', '/settings', ['operating_hours_end' => '23:00']],
            ['POST', '/departments', ['name' => 'VBT Evil']],
            ['POST', '/requirement-options', ['name' => 'VBT Evil']],
            ['GET', '/activity-logs', null],
            ['POST', "/bookings/$otherBooking/approve", []],
            ['POST', "/bookings/$otherBooking/validate", []],
            ['POST', "/bookings/$otherBooking/decline", ['reason' => 'nope']],
            ['POST', "/bookings/$otherBooking/cancel", ['reason' => 'nope']],       // someone else's booking
            ['PUT', "/bookings/$otherBooking", ['purpose' => 'hijacked']],
        ];
        foreach ($calls as [$method, $path, $body]) {
            $r = $http->request($method, $path, $body);
            $this->assertSame(403, $r['status'], "$method $path should be 403 for an Employee, got {$r['status']}: {$r['raw']}");
        }

        // ...and nothing changed.
        $role = $this->pdo->query("SELECT r.RoleCode FROM dbo.Users u JOIN dbo.Roles r ON r.RoleId=u.RoleId WHERE u.UserId={$emp['id']}")->fetchColumn();
        $this->assertSame('Employee', $role);
        $this->assertSame(20, (int)$this->pdo->query("SELECT Capacity FROM dbo.Venues WHERE VenueId=$v")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM dbo.Venues WHERE Name='VBT Evil'")->fetchColumn());
        $this->assertSame('Pending Manager Validation', $this->statusOf($otherBooking));
    }

    // =====================================================================================
    // 3. Requester attempts to approve their own booking  (Critical)
    // =====================================================================================

    public function test03a_manager_cannot_approve_or_validate_own_booking(): void
    {
        $d = $this->mkDept('Self Dept');
        $mgr = $this->mkUser('Manager', 'self.mgr', $d);
        $v = $this->mkVenue('Self Hall');
        $b = BookingService::create($mgr, $this->payload($v, $this->date(), '09:00', '10:00'));
        $this->assertSame('Pending HR Decision', $b['status'], 'Manager bookings skip the manager stage and go to HR.');

        $this->assertRejected(fn() => BookingService::approve($mgr, $b['id'], []), [403]);
        $this->assertRejected(fn() => BookingService::validateByManager($mgr, $b['id'], []), [403, 409]);
        $this->assertNotSame('Approved', $this->statusOf($b['id']));
    }

    public function test03b_hr_booking_is_not_self_approved_and_goes_to_an_alternative_approver(): void
    {
        $hr = $this->mkUser('HR', 'self.hr');
        $admin = $this->mkUser('Administrator', 'self.admin');
        $v = $this->mkVenue('Self Hall HR');

        $b = BookingService::create($hr, $this->payload($v, $this->date(), '09:00', '10:00'));
        $this->assertNotSame('Approved', $b['status'],
            'HR booking was confirmed at creation with no second approver. It must wait for an eligible alternative approver.');

        $this->assertRejected(fn() => BookingService::approve($hr, $b['id'], []), [403, 409]);
        $this->assertNotSame('Approved', $this->statusOf($b['id']), 'HR approved their own booking.');

        $done = BookingService::approve($admin, $b['id'], []);
        $this->assertSame('Approved', $done['status']);
        $this->assertSame($admin['id'], (int)$this->row($b['id'])['DecidedByUserId']);
    }

    public function test03c_admin_booking_is_not_self_approved(): void
    {
        $admin = $this->mkUser('Administrator', 'self.admin2');
        $v = $this->mkVenue('Self Hall Admin');
        $b = BookingService::create($admin, $this->payload($v, $this->date(), '09:00', '10:00'));
        $this->assertNotSame('Approved', $b['status'],
            'Admin booking was confirmed at creation. Direct confirmation must only happen if a setting explicitly allows it.');
        $this->assertRejected(fn() => BookingService::approve($admin, $b['id'], []), [403, 409]);
    }

    public function test03d_manager_of_another_department_cannot_validate(): void
    {
        $d1 = $this->mkDept('Dept One');
        $d2 = $this->mkDept('Dept Two');
        $emp = $this->mkUser('Employee', 'x.emp', $d1);
        $otherMgr = $this->mkUser('Manager', 'x.mgr2', $d2);
        $noDeptMgr = $this->mkUser('Manager', 'x.mgr0', null);
        $v = $this->mkVenue('Cross Hall');
        $b = $this->insertBooking($v, $emp['id'], $this->date(), '09:00', '10:00', 'Pending Manager Validation', $d1);

        $this->assertRejected(fn() => BookingService::validateByManager($otherMgr, $b, []), [403]);
        $this->assertRejected(fn() => BookingService::validateByManager($noDeptMgr, $b, []), [403],
            'A Manager with no department can validate requests from any department.');
        $this->assertSame('Pending Manager Validation', $this->statusOf($b));
    }

    // =====================================================================================
    // 4. Assigned approver is deactivated  (High)
    // =====================================================================================

    public function test04a_deactivating_the_assigned_manager_reassigns_to_another_active_manager(): void
    {
        $d = $this->mkDept('Approver Dept');
        $m1 = $this->mkUser('Manager', 'ap.m1', $d);
        $m2 = $this->mkUser('Manager', 'ap.m2', $d);
        $emp = $this->mkUser('Employee', 'ap.emp', $d);
        $admin = $this->mkUser('Administrator', 'ap.admin');
        $v = $this->mkVenue('Approver Hall');

        // Employee creates a request; the system assigns a manager.
        $b = BookingService::create($emp, $this->payload($v, $this->date(), '09:00', '10:00'));
        $assigned = (int)$this->row($b['id'])['DepartmentManagerId'];
        $this->assertContains($assigned, [$m1['id'], $m2['id']]);
        $removed = $assigned;
        $remaining = $assigned === $m1['id'] ? $m2['id'] : $m1['id'];

        $res = $this->httpAs($admin)->request('PUT', "/users/$removed", ['isActive' => false]);
        $this->assertContains($res['status'], [200, 409], $res['raw']);
        if ($res['status'] === 409) {
            $this->addToAssertionCount(1); // deactivation refused: also an acceptable outcome
            return;
        }
        $now = (int)$this->row($b['id'])['DepartmentManagerId'];
        $this->assertSame($remaining, $now, 'Request is still assigned to the deactivated manager; the other active manager was not made responsible or notified.');
    }

    public function test04b_deactivating_the_only_manager_does_not_strand_the_request(): void
    {
        $d = $this->mkDept('Only Mgr Dept');
        $m = $this->mkUser('Manager', 'only.m', $d);
        $emp = $this->mkUser('Employee', 'only.emp', $d);
        $admin = $this->mkUser('Administrator', 'only.admin');
        $v = $this->mkVenue('Only Mgr Hall');
        $b = BookingService::create($emp, $this->payload($v, $this->date(), '09:00', '10:00'));
        $this->pdo->exec('DELETE FROM dbo.Notifications');

        $res = $this->httpAs($admin)->request('PUT', "/users/{$m['id']}", ['isActive' => false]);
        $this->assertContains($res['status'], [200, 409], $res['raw']);
        if ($res['status'] === 409) {
            $this->addToAssertionCount(1); // prevented
            return;
        }
        $this->assertTrue($this->someActiveApproverWasAlerted($b['id'], $emp['id']) || $this->statusOf($b['id']) !== 'Pending Manager Validation',
            'The request is still waiting on a manager who no longer exists, and nobody who can act on it was told.');
    }

    // =====================================================================================
    // 5. New schedule conflicts with another booking  (Critical)
    // =====================================================================================

    public function test05_conflicting_reschedule_keeps_the_original_booking_valid(): void
    {
        $d = $this->mkDept('Resched Dept');
        $emp = $this->mkUser('Employee', 'rs.emp', $d);
        $other = $this->mkUser('Employee', 'rs.other', $d);
        $hr = $this->mkUser('HR', 'rs.hr');
        $v = $this->mkVenue('Resched Hall');
        $date = $this->date();

        $a = $this->insertBooking($v, $other['id'], $date, '10:00', '11:00', 'Approved', $d);
        $b = $this->insertBooking($v, $emp['id'], $date, '14:00', '15:00', 'Approved', $d);

        // (i) Proposing a slot that is already taken is refused outright.
        $this->assertRejected(fn() => BookingService::reschedule($hr, $b, ['date' => $date, 'startTime' => '10:00', 'endTime' => '11:00']), [409, 422]);
        $this->assertSame('Approved', $this->statusOf($b));
        $this->assertSame('14:00', self::hhmm($this->row($b)['StartTime']));

        // (ii) A free slot is proposed; the original stays on the books and keeps blocking its slot.
        BookingService::reschedule($hr, $b, ['date' => $date, 'startTime' => '16:00', 'endTime' => '17:00']);
        $this->assertSame('14:00', self::hhmm($this->row($b)['StartTime']), 'Original schedule must not change until the proposal is accepted.');
        $this->assertNotNull(BookingService::findConflict($v, $date, '14:00', '15:00', null), 'Original slot stopped being held while a proposal is pending.');

        // (iii) Someone else takes the proposed slot before the requester accepts.
        $c = $this->insertBooking($v, $other['id'], $date, '16:00', '17:00', 'Approved', $d);
        $this->assertRejected(fn() => BookingService::respondReschedule($emp, $b, ['accept' => true]), [409, 422]);

        $row = $this->row($b);
        $this->assertSame('14:00', self::hhmm($row['StartTime']), 'Booking moved onto a slot that was already taken.');
        $this->assertContains($row['Status'], ['Approved', 'Reschedule Proposed']);
        $this->assertSame(1, $this->countApprovedOverlap($v, $date, '16:00', '17:00'));
        $this->assertSame('Approved', $this->statusOf($c));
    }

    public function test05b_declined_proposal_restores_the_original_confirmed_booking(): void
    {
        $d = $this->mkDept('Resched Dept 2');
        $emp = $this->mkUser('Employee', 'rs2.emp', $d);
        $hr = $this->mkUser('HR', 'rs2.hr');
        $v = $this->mkVenue('Resched Hall 2');
        $date = $this->date();
        $b = $this->insertBooking($v, $emp['id'], $date, '14:00', '15:00', 'Approved', $d);

        BookingService::reschedule($hr, $b, ['date' => $date, 'startTime' => '09:00', 'endTime' => '10:00']);
        BookingService::respondReschedule($emp, $b, ['accept' => false]);

        $row = $this->row($b);
        $this->assertSame('Approved', $row['Status']);
        $this->assertSame('14:00', self::hhmm($row['StartTime']));
        $this->assertNull($row['ProposedDate']);
    }

    // =====================================================================================
    // 6. Email service is temporarily unavailable  (High)
    // =====================================================================================

    public function test06_email_outage_keeps_booking_state_and_allows_retry(): void
    {
        $this->markTestIncomplete(
            'The application has no email delivery at all (NotificationService only inserts in-app rows; there is no SMTP code, '
            . 'delivery-status column, or retry job). Once email exists, test: make the SMTP host unreachable -> create/approve a booking -> '
            . 'booking state is correct AND the notification is recorded as Failed with an error -> restore SMTP -> run the retry job -> '
            . 'it is sent exactly once.'
        );
    }

    // =====================================================================================
    // 7. Restore a database backup  (Critical)  -- operational drill, not a unit test
    // =====================================================================================

    public function test07_restore_backup_drill(): void
    {
        $this->markTestSkipped('Manual drill: run tests/manual/restore_drill.sql (see tests/README.md) and record the timings against your recovery targets.');
    }

    // =====================================================================================
    // 8. Cancelled and pending bookings appear in reports  (High)
    // =====================================================================================

    public function test08a_status_filters_separate_confirmed_pending_and_cancelled(): void
    {
        $d = $this->mkDept('Report Dept');
        $emp = $this->mkUser('Employee', 'rep.emp', $d);
        $admin = $this->mkUser('Administrator', 'rep.admin');
        $v = $this->mkVenue('Report Hall');
        $date = $this->date();
        $ok = $this->insertBooking($v, $emp['id'], $date, '08:00', '09:00', 'Approved', $d);
        $pe = $this->insertBooking($v, $emp['id'], $date, '10:00', '11:00', 'Pending HR Decision', $d);
        $ca = $this->insertBooking($v, $emp['id'], $date, '12:00', '13:00', 'Cancelled', $d);

        $ids = static fn(array $rows) => array_column($rows, 'id');
        $this->assertSame([$ok], $ids(BookingService::listForUser($admin, ['status' => 'Approved', 'venueId' => $v])));
        $this->assertSame([$pe], $ids(BookingService::listForUser($admin, ['status' => 'Pending HR Decision', 'venueId' => $v])));
        $this->assertSame([$ca], $ids(BookingService::listForUser($admin, ['status' => 'Cancelled', 'venueId' => $v])));
    }

    public function test08b_reports_exist_and_count_each_status_separately(): void
    {
        $this->markTestIncomplete(
            'There is no reporting feature or endpoint in the application (no route, query, or page computes totals, utilization, '
            . 'approval rates, or exports). Build it, then assert: with 1 Approved + 1 Pending + 1 Cancelled booking, confirmed=1, pending=1, '
            . 'cancelled=1; utilization counts only confirmed hours; no booking is counted twice.'
        );
    }

    // =====================================================================================
    // 9. Admin removes the only eligible approver  (High)
    // =====================================================================================

    public function test09a_removing_the_only_manager_is_blocked_or_safely_handled(): void
    {
        $d = $this->mkDept('Last Mgr Dept');
        $m = $this->mkUser('Manager', 'last.m', $d);
        $emp = $this->mkUser('Employee', 'last.emp', $d);
        $admin = $this->mkUser('Administrator', 'last.admin');
        $v = $this->mkVenue('Last Mgr Hall');
        $b = BookingService::create($emp, $this->payload($v, $this->date(), '09:00', '10:00'));
        $this->pdo->exec('DELETE FROM dbo.Notifications');

        // Demoting the manager removes the approver role.
        $res = $this->httpAs($admin)->request('PUT', "/users/{$m['id']}", ['role' => 'Employee']);
        $this->assertContains($res['status'], [200, 409], $res['raw']);
        if ($res['status'] === 409) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->assertTrue($this->someActiveApproverWasAlerted($b['id'], $emp['id']),
            'The only manager of the department was demoted with a request waiting on them; no safe reassignment or alert happened.');
    }

    public function test09b_removing_the_only_hr_approver_is_blocked_or_safely_handled(): void
    {
        $d = $this->mkDept('Last HR Dept');
        $emp = $this->mkUser('Employee', 'lasthr.emp', $d);
        $hr = $this->mkUser('HR', 'lasthr.hr');
        $admin = $this->mkUser('Administrator', 'lasthr.admin');
        $v = $this->mkVenue('Last HR Hall');
        $b = $this->insertBooking($v, $emp['id'], $this->date(), '09:00', '10:00', 'Pending HR Decision', $d);

        // Make our HR user the only active HR (restore the seeded ones afterwards).
        $seeded = $this->pdo->query("SELECT u.UserId FROM dbo.Users u JOIN dbo.Roles r ON r.RoleId=u.RoleId
                                     WHERE r.RoleCode='HR' AND u.IsActive=1 AND u.Email NOT LIKE 'vbt.%'")->fetchAll(PDO::FETCH_COLUMN);
        try {
            foreach ($seeded as $id) { $this->pdo->exec("UPDATE dbo.Users SET IsActive=0 WHERE UserId=" . (int)$id); }
            $this->pdo->exec('DELETE FROM dbo.Notifications');
            $res = $this->httpAs($admin)->request('PUT', "/users/{$hr['id']}", ['isActive' => false]);
            $this->assertContains($res['status'], [200, 409], $res['raw']);
            if ($res['status'] === 409) {
                $this->addToAssertionCount(1);
                return;
            }
            $this->assertTrue($this->someActiveApproverWasAlerted($b, $emp['id']),
                'The only HR approver was deactivated with a request waiting on HR; nobody who can act on it was alerted.');
        } finally {
            foreach ($seeded as $id) { $this->pdo->exec("UPDATE dbo.Users SET IsActive=1 WHERE UserId=" . (int)$id); }
        }
    }

    // =====================================================================================
    // 10. Employee uses the system on a mobile phone  (Medium) -- mostly manual
    // =====================================================================================

    public function test10a_every_page_declares_a_mobile_viewport(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/index.html'], glob($root . '/pages/*.html') ?: []);
        $missing = [];
        foreach ($files as $f) {
            if (!preg_match('/<meta[^>]+name=["\']viewport["\']/i', (string)file_get_contents($f))) {
                $missing[] = basename($f);
            }
        }
        $this->assertSame([], $missing, 'Pages without a viewport meta tag: ' . implode(', ', $missing));
    }

    public function test10b_booking_tracking_and_cancellation_work_on_a_phone(): void
    {
        $this->markTestSkipped('Manual: follow the phone script in tests/README.md (375px wide; book, track, cancel).');
    }

    // ---------- helpers ----------

    /** True if an active Manager/HR/Admin (other than the requester) was notified about this booking. */
    private function someActiveApproverWasAlerted(int $bookingId, int $requesterId): bool
    {
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dbo.Notifications n
             JOIN dbo.Users u ON u.UserId = n.UserId JOIN dbo.Roles r ON r.RoleId = u.RoleId
             WHERE n.LinkUrl LIKE ? AND u.IsActive = 1 AND u.UserId <> ? AND r.RoleCode IN ('Manager','HR','Administrator')"
        );
        $s->execute(['%id=' . $bookingId, $requesterId]);
        return (int)$s->fetchColumn() > 0;
    }
}
