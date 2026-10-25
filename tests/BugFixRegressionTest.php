<?php
declare(strict_types=1);

/**
 * Regression tests for defects found in the review of the "nice to have" / process-flow update.
 */
final class BugFixRegressionTest extends VbsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('conflict_auto_action', 'reschedule');
    }

    private function pendingHr(int $venueId, int $requesterId, string $date, string $start, string $end, string $queuedAt, ?int $deptId = null): int
    {
        $id = $this->insertBooking($venueId, $requesterId, $date, $start, $end, 'Pending HR Decision', $deptId);
        $this->pdo->prepare('UPDATE dbo.Bookings SET ValidatedAt = ? WHERE BookingId = ?')->execute([$queuedAt, $id]);
        return $id;
    }

    public function test_cannot_mark_a_future_booking_as_no_show(): void
    {
        $d = $this->mkDept('BF Dept 1');
        $emp = $this->mkUser('Employee', 'bf1.emp', $d);
        $hr = $this->mkUser('HR', 'bf1.hr');
        $v = $this->mkVenue('BF Hall 1');
        $a = $this->insertBooking($v, $emp['id'], $this->date(), '10:00', '11:00', 'Approved', $d);

        $this->assertRejected(fn() => BookingService::markNoShow($hr, $a, ['reason' => 'x']), [409]);
        $this->assertSame('Approved', $this->statusOf($a), 'A booking that has not started must stay Approved (and keep holding its slot).');
    }

    public function test_rejecting_an_automatic_proposal_declines_instead_of_returning_to_a_queue_it_can_never_win(): void
    {
        $d = $this->mkDept('BF Dept 2');
        $ana = $this->mkUser('Employee', 'bf2.ana', $d);
        $ben = $this->mkUser('Employee', 'bf2.ben', $d);
        $hr = $this->mkUser('HR', 'bf2.hr');
        $v = $this->mkVenue('BF Hall 2');
        $date = $this->date();
        $a = $this->pendingHr($v, $ana['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $date, '10:00', '11:00', '2030-01-01 09:00:00', $d);

        BookingService::approve($hr, $a, []);
        $this->assertSame('Reschedule Proposed', $this->statusOf($b));

        BookingService::respondReschedule($ben, $b, ['accept' => false]);
        $this->assertSame('Declined', $this->statusOf($b), 'The original slot is taken, so the request must not go back to HR\'s queue.');
    }

    public function test_queue_endpoint_does_not_leak_other_requesters(): void
    {
        $d = $this->mkDept('BF Dept 3');
        $ana = $this->mkUser('Employee', 'bf3.ana', $d);
        $ben = $this->mkUser('Employee', 'bf3.ben', $d);
        $eve = $this->mkUser('Employee', 'bf3.eve', $d);
        $hr = $this->mkUser('HR', 'bf3.hr');
        $v = $this->mkVenue('BF Hall 3');
        $date = $this->date();
        $a = $this->pendingHr($v, $ana['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $date, '10:00', '11:00', '2030-01-01 09:00:00', $d);

        $this->assertRejected(fn() => BookingService::queuePosition($a, $eve), [403]);

        $own = BookingService::queuePosition($b, $ben);
        $this->assertSame(2, $own['position']);
        $this->assertSame([], $own['competitors'], 'A requester must not see who else asked for the room.');

        $staff = BookingService::queuePosition($b, $hr);
        $this->assertCount(2, $staff['competitors']);
    }

    public function test_manager_cannot_list_other_departments_with_scope_all(): void
    {
        $d1 = $this->mkDept('BF Dept 4a');
        $d2 = $this->mkDept('BF Dept 4b');
        $mgr = $this->mkUser('Manager', 'bf4.mgr', $d1);
        $other = $this->mkUser('Employee', 'bf4.other', $d2);
        $v = $this->mkVenue('BF Hall 4');
        $secret = $this->insertBooking($v, $other['id'], $this->date(), '10:00', '11:00', 'Approved', $d2);

        $ids = array_column(BookingService::listForUser($mgr, ['scope' => 'all']), 'id');
        $this->assertNotContains($secret, $ids, 'scope=all must not widen a Manager beyond their own department.');
    }

    public function test_recurring_series_skips_a_conflicting_week_instead_of_aborting(): void
    {
        $d = $this->mkDept('BF Dept 5');
        $emp = $this->mkUser('Employee', 'bf5.emp', $d);
        $other = $this->mkUser('Employee', 'bf5.other', $d);
        $v = $this->mkVenue('BF Hall 5');
        $date = $this->date(14);

        $parent = BookingService::create($emp, $this->payload($v, $date, '10:00', '11:00'));
        // Week 2 is already taken by someone else's approved booking.
        $this->insertBooking($v, $other['id'], date('Y-m-d', strtotime($date . ' +7 days')), '10:00', '11:00', 'Approved', $d);

        $res = RecurrenceService::expandSeries($parent['id'], 'FREQ=WEEKLY;COUNT=3', $emp);

        $this->assertSame(1, $res['created'], 'The free week must still be created.');
        $this->assertCount(1, $res['skipped'], 'The blocked week must be reported, not abort the whole series.');
    }

    public function test_only_the_requester_can_repeat_a_booking(): void
    {
        $d = $this->mkDept('BF Dept 6');
        $emp = $this->mkUser('Employee', 'bf6.emp', $d);
        $other = $this->mkUser('Employee', 'bf6.other', $d);
        $v = $this->mkVenue('BF Hall 6');
        $parent = BookingService::create($emp, $this->payload($v, $this->date(14), '10:00', '11:00'));

        $this->assertRejected(fn() => RecurrenceService::expandSeries($parent['id'], 'FREQ=WEEKLY;COUNT=2', $other), [403]);
    }

    public function test_hr_and_admin_may_use_the_queue_and_ical_permissions_check(): void
    {
        // These routes used to demand booking.view_own, which HR and Administrator do not have.
        $hr = $this->mkUser('HR', 'bf7.hr');
        $admin = $this->mkUser('Administrator', 'bf7.admin');
        foreach ([$hr, $admin] as $u) {
            $this->assertTrue(Permissions::can($u, 'booking.view_all'));
        }
    }

    public function test_reached_hr_time_uses_the_database_clock(): void
    {
        $cols = $this->pdo->query("SELECT COL_LENGTH('dbo.Bookings','ReachedHrAt')")->fetchColumn();
        if ($cols === null || $cols === false) {
            $this->markTestSkipped('Run database/12_process_flow.sql first.');
        }
        $d = $this->mkDept('BF Dept 8');
        $mgr = $this->mkUser('Manager', 'bf8.mgr', $d);
        $v = $this->mkVenue('BF Hall 8');
        $b = BookingService::create($mgr, $this->payload($v, $this->date(), '09:00', '10:00'));

        $q = $this->pdo->prepare('SELECT DATEDIFF(MINUTE, ReachedHrAt, SYSUTCDATETIME()) FROM dbo.Bookings WHERE BookingId = ?');
        $q->execute([$b['id']]);
        $diff = (int)$q->fetchColumn();
        $this->assertGreaterThanOrEqual(0, $diff);
        $this->assertLessThanOrEqual(2, $diff, 'ReachedHrAt must be UTC like ValidatedAt, otherwise the "first to reach HR" order is wrong.');
    }
}
