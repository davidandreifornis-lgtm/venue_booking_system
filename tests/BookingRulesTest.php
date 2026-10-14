<?php
declare(strict_types=1);

/** Happy path and the edge cases that matter for booking validation. */
final class BookingRulesTest extends VbsTestCase
{
    public function test_happy_path_employee_to_manager_to_hr_confirmed(): void
    {
        $d = $this->mkDept('Happy Dept');
        $mgr = $this->mkUser('Manager', 'happy.mgr', $d);
        $emp = $this->mkUser('Employee', 'happy.emp', $d);
        $hr = $this->mkUser('HR', 'happy.hr');
        $v = $this->mkVenue('Happy Hall');

        $b = BookingService::create($emp, $this->payload($v, $this->date(), '09:00', '10:30'));
        $this->assertSame('Pending Manager Validation', $b['status']);
        $this->assertSame($mgr['id'], $b['departmentManagerId']);

        $b = BookingService::validateByManager($mgr, $b['id'], ['notes' => 'ok']);
        $this->assertSame('Pending HR Decision', $b['status']);

        $b = BookingService::approve($hr, $b['id'], ['notes' => 'confirmed']);
        $this->assertSame('Approved', $b['status']);

        $hist = $this->pdo->query("SELECT ToStatus FROM dbo.BookingStatusHistory WHERE BookingId={$b['id']} ORDER BY HistoryId")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Pending Manager Validation', 'Pending HR Decision', 'Approved'], $hist);
        $n = (int)$this->pdo->query("SELECT COUNT(*) FROM dbo.Notifications WHERE UserId={$emp['id']}")->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $n);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_by_the_backend(string $start, string $end, int $attendees, int $daysAhead): void
    {
        $d = $this->mkDept('Rules Dept');
        $emp = $this->mkUser('Employee', 'rules.emp', $d);
        $v = $this->mkVenue('Rules Hall', 10);
        $r = $this->api(fn() => BookingService::create($emp, $this->payload($v, $this->date($daysAhead), $start, $end, ['attendees' => $attendees])));
        $this->assertSame(422, $r->status, $r->getMessage());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM dbo.Bookings')->fetchColumn());
    }

    public static function invalidInputs(): array
    {
        return [
            'end equals start'        => ['10:00', '10:00', 5, 14],
            'end before start'        => ['11:00', '10:00', 5, 14],
            'before opening'          => ['07:00', '08:30', 5, 14],
            'after closing'           => ['16:30', '17:30', 5, 14],
            'off the half-hour grid'  => ['09:10', '10:00', 5, 14],
            'over capacity'           => ['09:00', '10:00', 11, 14],
            'in the past'             => ['09:00', '10:00', 5, -1],
            'inside minimum notice'   => ['09:00', '10:00', 5, 1],
            'beyond booking window'   => ['09:00', '10:00', 5, 200],
            'longer than max duration' => ['08:00', '17:00', 5, 14], // 540 min > 480
        ];
    }

    public function test_unavailable_venues_cannot_be_booked(): void
    {
        $d = $this->mkDept('Unavail Dept');
        $emp = $this->mkUser('Employee', 'un.emp', $d);
        foreach (['Maintenance', 'Unavailable'] as $i => $status) {
            $v = $this->mkVenue("Closed Hall $i", 10, $status);
            $this->assertRejected(fn() => BookingService::create($emp, $this->payload($v, $this->date(), '09:00', '10:00')), [422]);
        }
    }

    public function test_back_to_back_bookings_are_allowed_when_no_buffer_is_configured(): void
    {
        $d = $this->mkDept('Adj Dept');
        $emp = $this->mkUser('Employee', 'adj.emp', $d);
        $other = $this->mkUser('Employee', 'adj.other', $d);
        $v = $this->mkVenue('Adj Hall');
        $date = $this->date();
        $this->insertBooking($v, $other['id'], $date, '09:00', '10:00', 'Approved', $d);

        $b = BookingService::create($emp, $this->payload($v, $date, '10:00', '11:00')); // ends/starts at 10:00
        $this->assertSame('Pending Manager Validation', $b['status']);

        $this->assertRejected(fn() => BookingService::create($emp, $this->payload($v, $date, '09:30', '10:30')), [409, 422]);
    }

    public function test_buffer_setting_blocks_back_to_back_bookings(): void
    {
        $d = $this->mkDept('Buf Dept');
        $emp = $this->mkUser('Employee', 'buf.emp', $d);
        $other = $this->mkUser('Employee', 'buf.other', $d);
        $v = $this->mkVenue('Buf Hall');
        $date = $this->date();
        $this->insertBooking($v, $other['id'], $date, '09:00', '10:00', 'Approved', $d);
        Settings::set('booking_buffer_minutes', '30');
        $this->assertRejected(fn() => BookingService::create($emp, $this->payload($v, $date, '10:00', '11:00')), [409, 422]);
    }

    public function test_cancelling_releases_the_slot_and_keeps_the_record(): void
    {
        $d = $this->mkDept('Cancel Dept');
        $emp = $this->mkUser('Employee', 'can.emp', $d);
        $other = $this->mkUser('Employee', 'can.other', $d);
        $v = $this->mkVenue('Cancel Hall');
        $date = $this->date();
        $b = $this->insertBooking($v, $emp['id'], $date, '09:00', '10:00', 'Approved', $d);

        $this->assertRejected(fn() => BookingService::create($other, $this->payload($v, $date, '09:00', '10:00')), [409, 422]);
        $this->assertRejected(fn() => BookingService::cancel($emp, $b, []), [422], 'Approved bookings need a cancellation reason.');
        BookingService::cancel($emp, $b, ['reason' => 'Plans changed']);

        $this->assertSame('Cancelled', $this->statusOf($b));
        $again = BookingService::create($other, $this->payload($v, $date, '09:00', '10:00'));
        $this->assertSame('Pending Manager Validation', $again['status']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM dbo.Bookings WHERE BookingId=$b")->fetchColumn());
    }
}
