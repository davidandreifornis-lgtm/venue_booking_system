<?php
declare(strict_types=1);

/**
 * Business rules for competing requests and the emergency override:
 *
 *  - Several requests may wait for HR at the same time; HR gives the final verdict.
 *  - When HR approves one, every other overlapping request waiting for HR is handled automatically:
 *    moved to the nearest free slot (setting conflict_auto_action = reschedule, declined if nothing is free)
 *    or declined (conflict_auto_action = decline).
 *  - A request validated by its manager AFTER the slot is already confirmed is handled immediately.
 *  - HR/Admin can move or cancel an already-approved booking in an emergency (reason required).
 */
final class ConflictResolutionTest extends VbsTestCase
{
    private static function hhmm($v): string
    {
        return $v instanceof DateTimeInterface ? $v->format('H:i') : substr((string)$v, 0, 5);
    }

    /** A request waiting for HR that entered HR's queue at $queuedAt (manager validation time). */
    private function pendingHr(int $venueId, int $requesterId, string $date, string $start, string $end, string $queuedAt, ?int $deptId = null): int
    {
        $id = $this->insertBooking($venueId, $requesterId, $date, $start, $end, 'Pending HR Decision', $deptId);
        $this->pdo->prepare('UPDATE dbo.Bookings SET ValidatedAt = ? WHERE BookingId = ?')->execute([$queuedAt, $id]);
        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('conflict_auto_action', 'reschedule');
    }

    // ------------------------------------------------------------------ automatic handling of the loser

    public function test_approving_one_request_moves_the_competing_one_to_a_free_slot(): void
    {
        $d = $this->mkDept('CR Dept 1');
        $ana = $this->mkUser('Employee', 'cr1.ana', $d);
        $ben = $this->mkUser('Employee', 'cr1.ben', $d);
        $hr = $this->mkUser('HR', 'cr1.hr');
        $v = $this->mkVenue('CR Hall 1');
        $date = $this->date();

        $a = $this->pendingHr($v, $ana['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $date, '10:30', '11:30', '2030-01-01 09:00:00', $d);

        $result = BookingService::approve($hr, $a, ['notes' => 'ok']);

        $this->assertSame('Approved', $this->statusOf($a));
        $this->assertSame('Reschedule Proposed', $this->statusOf($b), 'The competing request should have been given a new time.');
        $this->assertCount(1, $result['autoResolved'] ?? []);

        $row = $this->row($b);
        $this->assertSame('Pending HR Decision', $row['StatusBeforeProposal']);
        $ps = self::hhmm($row['ProposedStartTime']);
        $pe = self::hhmm($row['ProposedEndTime']);
        $sameDay = substr((string)($row['ProposedDate'] instanceof DateTimeInterface ? $row['ProposedDate']->format('Y-m-d') : $row['ProposedDate']), 0, 10) === $date;
        $this->assertFalse($sameDay && $ps < '11:00' && $pe > '10:00', "Proposed slot $ps-$pe overlaps the approved booking.");

        // The requester accepts -> the booking is confirmed at the new time, and the original slot still has one holder.
        BookingService::respondReschedule($ben, $b, ['accept' => true]);
        $this->assertSame('Approved', $this->statusOf($b));
        $this->assertSame(1, $this->countApprovedOverlap($v, $date, '10:00', '11:00'));
    }

    public function test_decline_mode_declines_the_competing_request(): void
    {
        Settings::set('conflict_auto_action', 'decline');
        $d = $this->mkDept('CR Dept 2');
        $ana = $this->mkUser('Employee', 'cr2.ana', $d);
        $ben = $this->mkUser('Employee', 'cr2.ben', $d);
        $hr = $this->mkUser('HR', 'cr2.hr');
        $v = $this->mkVenue('CR Hall 2');
        $date = $this->date();

        $a = $this->pendingHr($v, $ana['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $date, '10:00', '11:00', '2030-01-01 09:00:00', $d);

        BookingService::approve($hr, $a, []);

        $row = $this->row($b);
        $this->assertSame('Declined', $row['Status']);
        $this->assertStringContainsString($this->row($a)['ReferenceCode'], (string)$row['DeclineReason']);
    }

    public function test_declined_when_no_free_slot_exists_in_the_search_window(): void
    {
        $d = $this->mkDept('CR Dept 3');
        $ana = $this->mkUser('Employee', 'cr3.ana', $d);
        $ben = $this->mkUser('Employee', 'cr3.ben', $d);
        $other = $this->mkUser('Employee', 'cr3.other', $d);
        $hr = $this->mkUser('HR', 'cr3.hr');
        $v = $this->mkVenue('CR Hall 3');

        // Winner takes the whole day; the following days are full as well.
        $a = $this->pendingHr($v, $ana['id'], $this->date(14), '08:00', '17:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $this->date(14), '10:00', '11:00', '2030-01-01 09:00:00', $d);
        for ($i = 1; $i <= 6; $i++) {
            $this->insertBooking($v, $other['id'], $this->date(14 + $i), '08:00', '17:00', 'Approved', $d);
        }

        BookingService::approve($hr, $a, []);

        $row = $this->row($b);
        $this->assertSame('Declined', $row['Status'], 'With nothing free, the request must be declined rather than left stuck.');
        $this->assertStringContainsString('No free alternative', (string)$row['DeclineReason']);
    }

    public function test_two_losers_are_not_sent_to_the_same_alternative_slot(): void
    {
        $d = $this->mkDept('CR Dept 4');
        $u1 = $this->mkUser('Employee', 'cr4.u1', $d);
        $u2 = $this->mkUser('Employee', 'cr4.u2', $d);
        $u3 = $this->mkUser('Employee', 'cr4.u3', $d);
        $hr = $this->mkUser('HR', 'cr4.hr');
        $v = $this->mkVenue('CR Hall 4');
        $date = $this->date();

        $a = $this->pendingHr($v, $u1['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $u2['id'], $date, '10:00', '11:00', '2030-01-01 09:00:00', $d);
        $c = $this->pendingHr($v, $u3['id'], $date, '10:00', '11:00', '2030-01-01 10:00:00', $d);

        BookingService::approve($hr, $a, []);

        $rb = $this->row($b);
        $rc = $this->row($c);
        $this->assertSame('Reschedule Proposed', $rb['Status']);
        $this->assertSame('Reschedule Proposed', $rc['Status']);
        $slotB = (string)($rb['ProposedDate'] instanceof DateTimeInterface ? $rb['ProposedDate']->format('Y-m-d') : $rb['ProposedDate']) . ' ' . self::hhmm($rb['ProposedStartTime']);
        $slotC = (string)($rc['ProposedDate'] instanceof DateTimeInterface ? $rc['ProposedDate']->format('Y-m-d') : $rc['ProposedDate']) . ' ' . self::hhmm($rc['ProposedStartTime']);
        $this->assertNotSame($slotB, $slotC, 'Two requests were offered the same slot.');
    }

    public function test_request_validated_after_the_slot_is_taken_is_handled_immediately(): void
    {
        $d = $this->mkDept('CR Dept 5');
        $ana = $this->mkUser('Employee', 'cr5.ana', $d);
        $cara = $this->mkUser('Employee', 'cr5.cara', $d);
        $mgr = $this->mkUser('Manager', 'cr5.mgr', $d);
        $v = $this->mkVenue('CR Hall 5');
        $date = $this->date();

        $a = $this->insertBooking($v, $ana['id'], $date, '10:00', '11:00', 'Approved', $d);
        $c = $this->insertBooking($v, $cara['id'], $date, '10:00', '11:00', 'Pending Manager Validation', $d, $mgr['id']);

        BookingService::validateByManager($mgr, $c, ['notes' => '']);

        $this->assertNotSame('Pending HR Decision', $this->statusOf($c), 'The late request must not wait in HR\'s queue with a guaranteed conflict.');
        $this->assertContains($this->statusOf($c), ['Reschedule Proposed', 'Declined']);
        $this->assertSame('Approved', $this->statusOf($a));
    }

    public function test_hr_queue_shows_competing_requests_and_who_reached_hr_first(): void
    {
        $d = $this->mkDept('CR Dept 6');
        $ana = $this->mkUser('Employee', 'cr6.ana', $d);
        $ben = $this->mkUser('Employee', 'cr6.ben', $d);
        $hr = $this->mkUser('HR', 'cr6.hr');
        $v = $this->mkVenue('CR Hall 6');
        $date = $this->date();

        $a = $this->pendingHr($v, $ana['id'], $date, '10:00', '11:00', '2030-01-01 08:00:00', $d);
        $b = $this->pendingHr($v, $ben['id'], $date, '10:30', '11:30', '2030-01-01 09:00:00', $d);

        $rows = [];
        foreach (BookingService::listForUser($hr, ['status' => 'Pending HR Decision']) as $r) {
            $rows[$r['id']] = $r;
        }
        $this->assertCount(1, $rows[$a]['competing']);
        $this->assertTrue($rows[$a]['firstInLine']);
        $this->assertFalse($rows[$b]['firstInLine']);

        $info = BookingService::competingFor($b);
        $this->assertCount(1, $info['competing']);
        $this->assertTrue($info['competing'][0]['validatedFirst']);
    }

    // ------------------------------------------------------------------ emergency override

    public function test_emergency_cancel_frees_the_slot_for_hr_immediately(): void
    {
        $d = $this->mkDept('CR Dept 7');
        $ana = $this->mkUser('Employee', 'cr7.ana', $d);
        $hr = $this->mkUser('HR', 'cr7.hr');
        $v = $this->mkVenue('CR Hall 7');
        $date = $this->date();
        $a = $this->insertBooking($v, $ana['id'], $date, '10:00', '11:00', 'Approved', $d);

        // Before the override HR cannot take the slot.
        $this->assertRejected(fn() => BookingService::create($hr, $this->payload($v, $date, '10:00', '11:00')), [409, 422]);

        BookingService::emergencyOverride($hr, $a, ['mode' => 'cancel', 'reason' => 'Fire drill debrief needs the hall']);

        $row = $this->row($a);
        $this->assertSame('Cancelled', $row['Status']);
        $this->assertStringContainsString('Emergency', (string)$row['CancelReason']);

        $new = BookingService::create($hr, $this->payload($v, $date, '10:00', '11:00'));
        $this->assertSame('Approved', $new['status']);
    }

    public function test_emergency_reschedule_moves_the_booking_at_once(): void
    {
        $d = $this->mkDept('CR Dept 8');
        $ana = $this->mkUser('Employee', 'cr8.ana', $d);
        $hr = $this->mkUser('HR', 'cr8.hr');
        $v = $this->mkVenue('CR Hall 8');
        $date = $this->date();
        $a = $this->insertBooking($v, $ana['id'], $date, '10:00', '11:00', 'Approved', $d);

        BookingService::emergencyOverride($hr, $a, [
            'mode' => 'reschedule', 'reason' => 'Board needs the room', 'date' => $date, 'startTime' => '14:00', 'endTime' => '15:00',
        ]);

        $row = $this->row($a);
        $this->assertSame('Rescheduled', $row['Status']);
        $this->assertSame('14:00', self::hhmm($row['StartTime']));
        $this->assertSame(0, $this->countApprovedOverlap($v, $date, '10:00', '11:00'), 'The original slot must be free right away.');
    }

    public function test_emergency_reschedule_without_a_time_picks_the_nearest_free_slot(): void
    {
        $d = $this->mkDept('CR Dept 9');
        $ana = $this->mkUser('Employee', 'cr9.ana', $d);
        $hr = $this->mkUser('HR', 'cr9.hr');
        $v = $this->mkVenue('CR Hall 9');
        $date = $this->date();
        $a = $this->insertBooking($v, $ana['id'], $date, '10:00', '11:00', 'Approved', $d);

        BookingService::emergencyOverride($hr, $a, ['mode' => 'reschedule', 'reason' => 'Urgent use of the hall']);

        $row = $this->row($a);
        $this->assertSame('Rescheduled', $row['Status']);
        $newDate = $row['BookingDate'] instanceof DateTimeInterface ? $row['BookingDate']->format('Y-m-d') : substr((string)$row['BookingDate'], 0, 10);
        $s = self::hhmm($row['StartTime']);
        $e = self::hhmm($row['EndTime']);
        $this->assertFalse($newDate === $date && $s < '11:00' && $e > '10:00', 'The booking must move away from the time HR needs.');
    }

    public function test_override_guards(): void
    {
        $d = $this->mkDept('CR Dept 10');
        $ana = $this->mkUser('Employee', 'cr10.ana', $d);
        $hr = $this->mkUser('HR', 'cr10.hr');
        $v = $this->mkVenue('CR Hall 10');
        $date = $this->date();
        $approved = $this->insertBooking($v, $ana['id'], $date, '10:00', '11:00', 'Approved', $d);
        $pending = $this->insertBooking($v, $ana['id'], $date, '13:00', '14:00', 'Pending HR Decision', $d);
        $hrOwn = $this->insertBooking($v, $hr['id'], $date, '15:00', '16:00', 'Approved');

        $this->assertFalse(Permissions::can($ana, 'booking.override'), 'Employees must not be able to override bookings.');
        $this->assertTrue(Permissions::can($hr, 'booking.override'));

        $this->assertRejected(fn() => BookingService::emergencyOverride($hr, $approved, ['mode' => 'cancel', 'reason' => '  ']), [422]);
        $this->assertRejected(fn() => BookingService::emergencyOverride($hr, $pending, ['mode' => 'cancel', 'reason' => 'x']), [409]);
        $this->assertRejected(fn() => BookingService::emergencyOverride($hr, $hrOwn, ['mode' => 'cancel', 'reason' => 'x']), [403]);
        $this->assertSame('Approved', $this->statusOf($approved));
        $this->assertSame('Pending HR Decision', $this->statusOf($pending));
    }

    public function test_decision_by_two_people_cannot_both_apply(): void
    {
        $d = $this->mkDept('CR Dept 11');
        $ana = $this->mkUser('Employee', 'cr11.ana', $d);
        $hr = $this->mkUser('HR', 'cr11.hr');
        $v = $this->mkVenue('CR Hall 11');
        $a = $this->pendingHr($v, $ana['id'], $this->date(), '10:00', '11:00', '2030-01-01 08:00:00', $d);

        BookingService::approve($hr, $a, []);
        $this->assertRejected(fn() => BookingService::approve($hr, $a, []), [409]);
        $this->assertSame('Approved', $this->statusOf($a));
    }
}
