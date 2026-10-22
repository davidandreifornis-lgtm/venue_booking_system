<?php

require_once __DIR__ . '/Database.php';

/**
 * Read-only reporting helpers. Never mutates data.
 */
class ReportService
{
    public static function utilization(array $filters = []): array
    {
        $pdo = Database::pdo();
        $from = $filters['from'] ?? date('Y-m-01');
        $to   = $filters['to']   ?? date('Y-m-d');
        $venueId = isset($filters['venueId']) ? (int)$filters['venueId'] : null;
        $deptId  = isset($filters['departmentId']) ? (int)$filters['departmentId'] : null;

        $sql = "
          SELECT
            v.VenueId, v.Name AS VenueName,
            d.DepartmentId, d.Name AS DepartmentName,
            COUNT(*) AS BookingCount,
            SUM(DATEDIFF(MINUTE, b.StartTime, b.EndTime)) AS TotalMinutes,
            AVG(CAST(b.Attendees AS FLOAT)) AS AvgAttendees
          FROM dbo.Bookings b
          INNER JOIN dbo.Venues v ON v.VenueId = b.VenueId
          LEFT JOIN dbo.Departments d ON d.DepartmentId = b.DepartmentId
          WHERE b.Status IN (N'Approved', N'Rescheduled', N'Completed')
            AND b.BookingDate >= ? AND b.BookingDate <= ?
        ";
        $bind = [$from, $to];
        if ($venueId) {
            $sql .= ' AND b.VenueId = ?';
            $bind[] = $venueId;
        }
        if ($deptId) {
            $sql .= ' AND b.DepartmentId = ?';
            $bind[] = $deptId;
        }
        $sql .= ' GROUP BY v.VenueId, v.Name, d.DepartmentId, d.Name ORDER BY TotalMinutes DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = array_map(static function ($r) {
            return [
                'venueId' => (int)$r['VenueId'],
                'venueName' => $r['VenueName'],
                'departmentId' => $r['DepartmentId'] !== null ? (int)$r['DepartmentId'] : null,
                'departmentName' => $r['DepartmentName'],
                'bookingCount' => (int)$r['BookingCount'],
                'totalMinutes' => (int)$r['TotalMinutes'],
                'totalHours' => round((int)$r['TotalMinutes'] / 60, 1),
                'avgAttendees' => round((float)$r['AvgAttendees'], 1),
            ];
        }, $stmt->fetchAll());

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    public static function peakHours(array $filters = []): array
    {
        $pdo = Database::pdo();
        $from = $filters['from'] ?? date('Y-m-01');
        $to   = $filters['to']   ?? date('Y-m-d');

        $sql = "
          SELECT DATEPART(HOUR, b.StartTime) AS HourOfDay, COUNT(*) AS BookingCount
          FROM dbo.Bookings b
          WHERE b.Status IN (N'Approved', N'Rescheduled', N'Completed')
            AND b.BookingDate >= ? AND b.BookingDate <= ?
          GROUP BY DATEPART(HOUR, b.StartTime)
          ORDER BY HourOfDay
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to]);
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = 0;
        }
        foreach ($stmt->fetchAll() as $r) {
            $hours[(int)$r['HourOfDay']] = (int)$r['BookingCount'];
        }
        $out = [];
        foreach ($hours as $h => $c) {
            $out[] = ['hour' => $h, 'label' => sprintf('%02d:00', $h), 'count' => $c];
        }
        return ['from' => $from, 'to' => $to, 'hours' => $out];
    }

    public static function approvalTurnaround(array $filters = []): array
    {
        $pdo = Database::pdo();
        $from = $filters['from'] ?? date('Y-m-01');
        $to   = $filters['to']   ?? date('Y-m-d');

        // Average hours from CreatedAt to first Approved/Declined transition
        $sql = "
          SELECT
            AVG(CAST(DATEDIFF(MINUTE, b.CreatedAt, h.ChangedAt) AS FLOAT) / 60.0) AS AvgHours,
            COUNT(*) AS SampleSize
          FROM dbo.Bookings b
          INNER JOIN dbo.BookingStatusHistory h ON h.BookingId = b.BookingId
            AND h.ToStatus IN (N'Approved', N'Declined', N'Rescheduled')
          WHERE b.CreatedAt >= ? AND b.CreatedAt <= ?
            AND h.HistoryId = (
              SELECT MIN(h2.HistoryId) FROM dbo.BookingStatusHistory h2
              WHERE h2.BookingId = b.BookingId
                AND h2.ToStatus IN (N'Approved', N'Declined', N'Rescheduled')
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        $row = $stmt->fetch();
        return [
            'from' => $from,
            'to' => $to,
            'avgHours' => $row && $row['AvgHours'] !== null ? round((float)$row['AvgHours'], 1) : null,
            'sampleSize' => $row ? (int)$row['SampleSize'] : 0,
        ];
    }

    public static function statusSummary(array $filters = []): array
    {
        $pdo = Database::pdo();
        $from = $filters['from'] ?? date('Y-m-01');
        $to   = $filters['to']   ?? date('Y-m-d');

        $sql = "
          SELECT Status, COUNT(*) AS Cnt
          FROM dbo.Bookings
          WHERE BookingDate >= ? AND BookingDate <= ?
          GROUP BY Status
          ORDER BY Cnt DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to]);
        return array_map(static function ($r) {
            return ['status' => $r['Status'], 'count' => (int)$r['Cnt']];
        }, $stmt->fetchAll());
    }
}
