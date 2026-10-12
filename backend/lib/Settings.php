<?php

require_once __DIR__ . '/Database.php';

class Settings
{
    private static array $cache = [];

    /** Fallbacks used when a key has no row in dbo.Settings. [value, description] */
    public const DEFAULTS = [
        'min_duration_minutes'    => ['30',    'Minimum booking duration (minutes)'],
        'max_duration_minutes'    => ['480',   'Maximum booking duration (minutes)'],
        'booking_window_days'     => ['90',    'Max days ahead for a booking'],
        'booking_lead_days'       => ['2',     'Minimum days in advance a Employee/Manager must book'],
        'booking_buffer_minutes'  => ['30',    'Gap kept free before and after every booking (minutes)'],
        'slot_step_minutes'       => ['30',    'Booking time step: 30 = hours and half-hours, 60 = whole hours only'],
        'equipment_buffer_minutes'  => ['30', 'Turnaround time (minutes) needed before shared equipment can be used again'],
        'equipment_reserve_pending' => ['0',  '1 = pending requests also reserve equipment stock (default 0: only Approved bookings hold equipment)'],
        'operating_hours_start'   => ['08:00', 'Earliest start time'],
        'operating_hours_end'     => ['17:00', 'Latest end time'],
        'overlap_include_pending' => ['0',     '1 = pending requests also block the venue'],
        'reminder_hours_before'   => ['24',    'Reminder hours before event'],
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default ?? (self::DEFAULTS[$key][0] ?? null);
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT SettingValue FROM dbo.Settings WHERE SettingKey = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        self::$cache[$key] = $row ? $row['SettingValue'] : null;
        return self::$cache[$key] ?? $default ?? (self::DEFAULTS[$key][0] ?? null);
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key, (string)$default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function all(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT SettingKey, SettingValue, Description FROM dbo.Settings')->fetchAll();
        $out = [];
        foreach (self::DEFAULTS as $k => [$v, $d]) {
            $out[$k] = ['value' => $v, 'description' => $d];
        }
        foreach ($rows as $r) {
            $out[$r['SettingKey']] = [
                'value' => $r['SettingValue'],
                'description' => $r['Description'],
            ];
            self::$cache[$r['SettingKey']] = $r['SettingValue'];
        }
        return $out;
    }

    public static function set(string $key, string $value): void
    {
        $desc = self::DEFAULTS[$key][1] ?? null;
        $pdo = Database::pdo();
        $pdo->prepare(
            'MERGE dbo.Settings AS t
             USING (SELECT ? AS k, ? AS v, ? AS d) AS s ON t.SettingKey = s.k
             WHEN MATCHED THEN UPDATE SET SettingValue = s.v, UpdatedAt = SYSUTCDATETIME()
             WHEN NOT MATCHED THEN INSERT (SettingKey, SettingValue, Description, UpdatedAt)
               VALUES (s.k, s.v, s.d, SYSUTCDATETIME());'
        )->execute([$key, $value, $desc]);
        self::$cache[$key] = $value;
    }
}
