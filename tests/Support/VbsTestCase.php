<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class VbsTestCase extends TestCase
{
    public const PASSWORD = 'Test#12345';

    protected PDO $pdo;
    private static $server = null;
    private static array $pipes = [];

    protected function setUp(): void
    {
        $this->pdo = Database::pdo();
        $this->cleanup();
        // Deterministic rules for every test.
        foreach ([
            'booking_lead_days' => '2', 'booking_buffer_minutes' => '0', 'slot_step_minutes' => '30',
            'min_duration_minutes' => '30', 'max_duration_minutes' => '480', 'booking_window_days' => '90',
            'operating_hours_start' => '08:00', 'operating_hours_end' => '17:00', 'overlap_include_pending' => '0',
            'equipment_reserve_pending' => '0',
        ] as $k => $v) {
            Settings::set($k, $v);
        }
    }

    /** Removes everything the tests create. Only ever runs against *_Test databases (see bootstrap). */
    protected function cleanup(): void
    {
        $p = $this->pdo;
        foreach (['Notifications', 'BookingRequirements', 'BookingStatusHistory', 'Bookings', 'ActivityLogs'] as $t) {
            $p->exec("DELETE FROM dbo.$t");
        }
        $p->exec("UPDATE dbo.Departments SET ManagerUserId = NULL WHERE Name LIKE 'VBT %'");
        $p->exec("DELETE FROM dbo.UserSessions WHERE UserId IN (SELECT UserId FROM dbo.Users WHERE Email LIKE 'vbt.%')");
        $p->exec("DELETE FROM dbo.Users WHERE Email LIKE 'vbt.%'");
        $p->exec("DELETE FROM dbo.Departments WHERE Name LIKE 'VBT %'");
        $p->exec("DELETE FROM dbo.Venues WHERE Name LIKE 'VBT %'");
    }

    // ---------- fixtures ----------

    protected function mkDept(string $name): int
    {
        $s = $this->pdo->prepare('INSERT INTO dbo.Departments (Name) OUTPUT INSERTED.DepartmentId VALUES (?)');
        $s->execute(['VBT ' . $name]);
        return (int)$s->fetchColumn();
    }

    /** Returns the same array shape Auth::user() returns. */
    protected function mkUser(string $role, string $slug, ?int $deptId = null, bool $active = true): array
    {
        $r = $this->pdo->prepare('SELECT RoleId FROM dbo.Roles WHERE RoleCode = ?');
        $r->execute([$role]);
        $roleId = (int)$r->fetchColumn();
        $this->assertGreaterThan(0, $roleId, "Role $role missing - did you run database/02_seed.sql?");
        $email = "vbt.$slug@test.local";
        $s = $this->pdo->prepare(
            'INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId, IsActive)
             OUTPUT INSERTED.UserId VALUES (?, ?, ?, ?, ?, ?)'
        );
        $s->execute([$email, password_hash(self::PASSWORD, PASSWORD_BCRYPT), "VBT $slug", $roleId, $deptId, $active ? 1 : 0]);
        return ['id' => (int)$s->fetchColumn(), 'email' => $email, 'name' => "VBT $slug", 'role' => $role,
                'departmentId' => $deptId, 'department' => null];
    }

    protected function mkVenue(string $name, int $capacity = 20, string $status = 'Available'): int
    {
        $s = $this->pdo->prepare('INSERT INTO dbo.Venues (Name, Capacity, Status) OUTPUT INSERTED.VenueId VALUES (?, ?, ?)');
        $s->execute(['VBT ' . $name, $capacity, $status]);
        return (int)$s->fetchColumn();
    }

    protected function date(int $daysAhead = 14): string
    {
        return date('Y-m-d', strtotime("today +{$daysAhead} days"));
    }

    protected function payload(int $venueId, string $date, string $start, string $end, array $extra = []): array
    {
        return array_merge(['venueId' => $venueId, 'date' => $date, 'startTime' => $start, 'endTime' => $end,
                            'purpose' => 'VBT meeting', 'attendees' => 5], $extra);
    }

    /** Inserts a booking row directly (bypasses the service) so tests control the starting state. */
    protected function insertBooking(int $venueId, int $requesterId, string $date, string $start, string $end,
                                     string $status, ?int $deptId = null, ?int $managerId = null): int
    {
        $s = $this->pdo->prepare(
            'INSERT INTO dbo.Bookings (ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
                BookingDate, StartTime, EndTime, Purpose, Attendees, Status)
             OUTPUT INSERTED.BookingId VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $s->execute(['VBT-' . bin2hex(random_bytes(6)), $venueId, $requesterId, $deptId, $managerId,
                     $date, $start . ':00', $end . ':00', 'VBT fixture', 5, $status]);
        return (int)$s->fetchColumn();
    }

    protected function row(int $bookingId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM dbo.Bookings WHERE BookingId = ?');
        $s->execute([$bookingId]);
        return $s->fetch() ?: [];
    }

    protected function statusOf(int $bookingId): string
    {
        return (string)($this->row($bookingId)['Status'] ?? '');
    }

    protected function countApprovedOverlap(int $venueId, string $date, string $start, string $end): int
    {
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dbo.Bookings WHERE VenueId = ? AND BookingDate = ?
             AND Status IN ('Approved','Rescheduled') AND StartTime < ? AND EndTime > ?"
        );
        $s->execute([$venueId, $date, $end . ':00', $start . ':00']);
        return (int)$s->fetchColumn();
    }

    // ---------- assertions ----------

    /** Runs $fn, which must end in a Response::error/json; returns the ApiResponse. */
    protected function api(callable $fn): ApiResponse
    {
        try {
            $fn();
        } catch (ApiResponse $e) {
            return $e;
        }
        $this->fail('Expected the service to answer with an HTTP-style response, but it returned normally.');
    }

    protected function assertRejected(callable $fn, array $statuses = [403], string $why = ''): void
    {
        $r = $this->api($fn);
        $this->assertContains($r->status, $statuses, "Got HTTP {$r->status} ({$r->getMessage()}). $why");
    }

    // ---------- HTTP (php -S) ----------

    protected function http(): Http
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('PHP curl extension is required for HTTP-level tests.');
        }
        if (self::$server === null) {
            $port = (int)(getenv('VBS_TEST_PORT') ?: 8099);
            $root = dirname(__DIR__, 2);
            $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
            self::$server = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root],
                [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/vbs_server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/vbs_server.log', 'a']],
                self::$pipes, $root, $env
            );
            $up = false;
            for ($i = 0; $i < 50 && !$up; $i++) {
                $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
                if ($c) { fclose($c); $up = true; } else { usleep(100000); }
            }
            if (!$up) {
                $this->fail("Could not start the PHP built-in server on port $port (see vbs_server.log in the temp dir).");
            }
            register_shutdown_function(static function () {
                if (is_resource(self::$server)) { proc_terminate(self::$server); }
            });
        }
        return new Http('http://127.0.0.1:' . (int)(getenv('VBS_TEST_PORT') ?: 8099));
    }

    protected function httpAs(array $user): Http
    {
        $h = $this->http();
        $res = $h->login($user['email'], self::PASSWORD);
        $this->assertSame(200, $res['status'], 'Test login failed: ' . $res['raw']);
        return $h;
    }

    // ---------- concurrency ----------

    /**
     * Runs several service calls at (nearly) the same instant in separate PHP processes.
     * @param array<int,array{action:string,user:array,payload?:array,bookingId?:int}> $jobs
     * @return array<int,array> worker results
     */
    protected function race(array $jobs): array
    {
        $startAt = microtime(true) + 1.5; // all workers connect first, then fire together
        $procs = [];
        foreach ($jobs as $i => $job) {
            $job['startAt'] = $startAt;
            $pipes = [];
            $procs[$i] = [proc_open(
                [PHP_BINARY, __DIR__ . '/../workers/race_worker.php', json_encode($job)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv()
            ), $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $out[$i] = json_decode(trim($stdout), true) ?? ['ok' => false, 'status' => 0, 'message' => "worker crashed: $stderr $stdout"];
        }
        return $out;
    }
}
