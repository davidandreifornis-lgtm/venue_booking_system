<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';

class RequirementOptions
{
    private static function map(array $r): array
    {
        return [
            'id' => (int)$r['OptionId'],
            'name' => $r['Name'],
            'description' => $r['Description'],
            'maxQuantity' => (int)$r['MaxQuantity'],
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
        $pdo = Database::pdo();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM dbo.RequirementOptions WHERE Name = ? AND OptionId <> ?');
        $dup->execute([$name, (int)$id]);
        if ((int)$dup->fetchColumn() > 0) {
            Response::error('An option with this name already exists.', 409);
        }
        if ($id) {
            $pdo->prepare('UPDATE dbo.RequirementOptions SET Name=?, Description=?, MaxQuantity=?, SortOrder=?, IsActive=?, TotalStock=? WHERE OptionId=?')
                ->execute([$name, $desc, $max, $sort, $active, $stock, $id]);
        } else {
            $pdo->prepare('INSERT INTO dbo.RequirementOptions (Name, Description, MaxQuantity, SortOrder, IsActive, TotalStock) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $desc, $max, $sort, $active, $stock]);
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

    /** Returns an error message if requested quantities exceed stock for overlapping approved bookings, else null. */
    public static function checkStock(array $items, string $date, string $start, string $end, ?int $excludeId): ?string
    {
        $pdo = Database::pdo();
        $statuses = ['Approved', 'Rescheduled', 'Reschedule Proposed'];
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        foreach ($items as [$oid, $name, $qty]) {
            $o = $pdo->prepare('SELECT TotalStock FROM dbo.RequirementOptions WHERE OptionId = ?');
            $o->execute([$oid]);
            $total = $o->fetchColumn();
            if ($total === false || $total === null) {
                continue;
            }
            $sql = "SELECT ISNULL(SUM(br.Quantity), 0) FROM dbo.BookingRequirements br
                    INNER JOIN dbo.Bookings b WITH (UPDLOCK, HOLDLOCK) ON b.BookingId = br.BookingId
                    WHERE br.OptionId = ? AND b.BookingDate = ? AND b.Status IN ($ph)
                      AND b.StartTime < ? AND b.EndTime > ?";
            $bind = array_merge([$oid, $date], $statuses, [$end, $start]);
            if ($excludeId) {
                $sql .= ' AND b.BookingId <> ?';
                $bind[] = $excludeId;
            }
            $q = $pdo->prepare($sql);
            $q->execute($bind);
            $free = (int)$total - (int)$q->fetchColumn();
            if ($qty > $free) {
                return $name . ': only ' . max(0, $free) . ' available for that time.';
            }
        }
        return null;
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
