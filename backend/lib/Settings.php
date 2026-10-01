<?php

require_once __DIR__ . '/Database.php';

class Settings
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT SettingValue FROM dbo.Settings WHERE SettingKey = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $val = $row ? $row['SettingValue'] : $default;
        self::$cache[$key] = $val;
        return $val;
    }

    public static function all(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT SettingKey, SettingValue, Description FROM dbo.Settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['SettingKey']] = [
                'value' => $r['SettingValue'],
                'description' => $r['Description'],
            ];
            self::$cache[$r['SettingKey']] = $r['SettingValue'];
        }
        return $out;
    }
}
