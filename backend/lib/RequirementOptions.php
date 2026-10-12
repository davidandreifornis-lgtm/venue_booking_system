<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Settings.php';

class RequirementOptions
{
    private static function map(array $r): array
    {
        return [
            'id' => (int)$r['OptionId'],
            'name' => $r['Name'],
            'description' => $r['Description'],
            'maxQuantity' => (int)$r['MaxQuantity'],
            'stockScope' => ($r['StockScope'] ?? 'Shared') === 'PerVenue' ? 'PerVenue' : 'Shared',
            'totalStock' => isset($r['TotalStock']) && $r['TotalStock'] !== null ? (int)$r['TotalStock'] : null,
            'sortOrder' => (int)$r['SortOrder'],
            'isActive' => (bool)$r['IsActive'],
        ];
    }

    public static function list(bool $all): array
    {
        $sql = 'SELECT * FROM dbo.RequirementOptions' . ($all ? '' : ' WHERE IsActive = 1') . ' ORDER BY SortOrder, Name';
        return array_map([self::class, 'map'], Database::pdo()->query($sql)->fetchAll());
    }

    public static function save(?int $id, array $p): array
    {
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            Response::error('Name is required.', 422);
        }
        $max = max(1, (int)($p['maxQuantity'] ?? 1));
        $desc = trim((string)($p['description'] ?? '')) ?: null;
        $sort = (int)($p['sortOrder'] ?? 0);
        $stock = (isset($p['totalStock']) && $p['totalStock'] !== '' && $p['totalStock'] !== null) ? max(0, (int)$p['totalStock']) : null;
        $active = array_key_exists('isActive', $p) ? (int)(bool)$p['isActive'] : 1;
        $scope = ($p['stockScope'] ?? 'Shared') === 'PerVenue' ? 'PerVenue' : 'Shared';
        $pdo = Database::pdo();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM dbo.RequirementOptions WHERE Name = ? AND OptionId <> ?');
        $dup->execute([$name, (int)$id]);
        if ((int)$dup->fetchColumn() > 0) {
            Response::error('An option with this name already exists.', 409);
        }
        if ($id) {
            $pdo->prepare('UPDATE dbo.RequirementOptions SET Name=?, Description=?, MaxQuantity=?, SortOrder=?, IsActive=?, TotalStock=?, StockScope=? WHERE OptionId=?')
                ->execute([$name, $desc, $max, $sort, $active, $stock, $scope, $id]);
        } else {
            $pdo->prepare('INSERT INTO dbo.RequirementOptions (Name, Description, MaxQuantity, SortOrder, IsActive, TotalStock, StockScope) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $desc, $max, $sort, $active, $stock, $scope]);
            $id = (int)$pdo->lastInsertId();
        }
        $s = $pdo->prepare('SELECT * FROM dbo.RequirementOptions WHERE OptionId = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) {
            Response::error('Option not found.', 404);
        }
        return self::map($row);
    }

    /** Validate a requested list against active options. Returns [[optionId, name, qty], ...]. */
    public static function normalize($reqs): array
    {
        if (!is_array($reqs) || !$reqs) {
            return [];
        }
        $pdo = Database::pdo();
        $out = [];
        foreach ($reqs as $r) {
            $oid = (int)($r['optionId'] ?? 0);
            $qty = max(1, (int)($r['quantity'] ?? 1));
            $s = $pdo->prepare('SELECT Name, MaxQuantity, IsActive FROM dbo.RequirementOptions WHERE OptionId = ?');
            $s->execute([$oid]);
            $o = $s->fetch();
            if (!$o || !(int)$o['IsActive']) {
                Response::error('A selected requirement is no longer available.', 422);
            }
            if ($qty > (int)$o['MaxQuantity']) {
                Response::error($o['Name'] . ': maximum quantity is ' . $o['MaxQuantity'] . '.', 422);
            }
            $out[$oid] = [$oid, $o['Name'], $qty];
        }
        return array_values($out);
    }

    /** Statuses whose equipment is held. Pending requests reserve stock too (admin setting). */
    private static function holdingStatuses(): array
    {
        $s = ['Approved', 'Rescheduled', 'Reschedule Proposed'];
        if (Settings::get('equipment_reserve_pending', '0') === '1') {
            array_push($s, 'Pending Manager Validation', 'Manager Validated', 'Pending HR Decision');
        }
        return $s;
    }

    /** "HH:MM[:SS]" shifted by $minutes, clamped to the same day -> HH:MM:SS. */
    private static function shift(string $t, int $minutes): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return $t;
        }
        $v = max(0, min(24 * 60 - 1, (int)$m[1] * 60 + (int)$m[2] + $minutes));
        return sprintf('%02d:%02d:00', intdiv($v, 60), $v % 60);
    }

    /**
     * Quantity of one option already held by other bookings around [start, end).
     * Shared stock keeps a turnaround buffer (gear must be moved between rooms);
     * PerVenue stock only counts bookings in the same venue and needs no buffer.
     */
    private static function reserved(PDO $pdo, int $oid, string $scope, int $venueId, string $date, string $start, string $end, ?int $excludeId, bool $lock): int
    {
        $statuses = self::holdingStatuses();
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $buf = $scope === 'PerVenue' ? 0 : max(0, Settings::int('equipment_buffer_minutes', 30));
        $hint = $lock ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
        $sql = "SELECT ISNULL(SUM(br.Quantity), 0) FROM dbo.BookingRequirements br
                INNER JOIN dbo.Bookings b{$hint} ON b.BookingId = br.BookingId
                WHERE br.OptionId = ? AND b.BookingDate = ? AND b.Status IN ($ph)
                  AND b.StartTime < ? AND b.EndTime > ?";
        $bind = array_merge([$oid, $date], $statuses, [self::shift($end, $buf), self::shift($start, -$buf)]);
        if ($scope === 'PerVenue') {
            $sql .= ' AND b.VenueId = ?';
            $bind[] = $venueId;
        }
        if ($excludeId) {
            $sql .= ' AND b.BookingId <> ?';
            $bind[] = $excludeId;
        }
        $q = $pdo->prepare($sql);
        $q->execute($bind);
        return (int)$q->fetchColumn();
    }

    /** Returns an error message if requested quantities exceed what is free for that time, else null. */
    public static function checkStock(array $items, int $venueId, string $date, string $start, string $end, ?int $excludeId): ?string
    {
        $pdo = Database::pdo();
        $buf = max(0, Settings::int('equipment_buffer_minutes', 30));
        foreach ($items as [$oid, $name, $qty]) {
            $o = $pdo->prepare('SELECT TotalStock, StockScope FROM dbo.RequirementOptions WHERE OptionId = ?');
            $o->execute([$oid]);
            $row = $o->fetch();
            if (!$row || $row['TotalStock'] === null) {
                continue; // unlimited
            }
            $scope = ($row['StockScope'] ?? 'Shared') === 'PerVenue' ? 'PerVenue' : 'Shared';
            $free = (int)$row['TotalStock'] - self::reserved($pdo, (int)$oid, $scope, $venueId, $date, $start, $end, $excludeId, true);
            if ($qty > $free) {
                $msg = $name . ': only ' . max(0, $free) . ' available for that time';
                if ($scope === 'Shared' && $buf > 0) {
                    $msg .= " (allowing {$buf} min to move equipment between bookings)";
                }
                return $msg . '.';
            }
        }
        return null;
    }

    /** Live stock for the booking form: what is left of every active option for a venue/date/time. */
    public static function availability(int $venueId, string $date, string $start, string $end, ?int $excludeId = null): array
    {
        $pdo = Database::pdo();
        $out = [];
        foreach (self::list(false) as $o) {
            if ($o['totalStock'] === null) {
                $out[] = ['optionId' => $o['id'], 'scope' => $o['stockScope'], 'total' => null, 'reserved' => 0, 'available' => null];
                continue;
            }
            $held = self::reserved($pdo, $o['id'], $o['stockScope'], $venueId, $date, $start, $end, $excludeId, false);
            $out[] = [
                'optionId' => $o['id'],
                'scope' => $o['stockScope'],
                'total' => $o['totalStock'],
                'reserved' => $held,
                'available' => max(0, $o['totalStock'] - $held),
            ];
        }
        return $out;
    }

    public static function attach(PDO $pdo, int $bookingId, array $items): void
    {
        $ins = $pdo->prepare('INSERT INTO dbo.BookingRequirements (BookingId, OptionId, OptionName, Quantity) VALUES (?,?,?,?)');
        foreach ($items as [$oid, $name, $qty]) {
            $ins->execute([$bookingId, $oid, $name, $qty]);
        }
    }

    public static function forBooking(int $bookingId): array
    {
        $s = Database::pdo()->prepare('SELECT OptionId, OptionName, Quantity FROM dbo.BookingRequirements WHERE BookingId = ? ORDER BY OptionName');
        $s->execute([$bookingId]);
        return array_map(static fn($r) => ['optionId' => (int)$r['OptionId'], 'name' => $r['OptionName'], 'quantity' => (int)$r['Quantity']], $s->fetchAll());
    }
}
