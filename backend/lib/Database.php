<?php
/**
 * PDO connection to SQL Server (VenueBooking).
 * Credentials: backend/config/database.php
 */

class Database
{
    private static ?PDO $pdo = null;

    public static function config(): array
    {
        $path = __DIR__ . '/../config/database.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing backend/config/database.php');
        }
        return require $path;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = self::config();

        $server   = trim((string)($config['server'] ?? 'VMAPPS2'));
        $database = trim((string)($config['database'] ?? 'VenueBooking'));
        $user     = (string)($config['username'] ?? '');
        $pass     = (string)($config['password'] ?? '');
        $driver   = $config['driver'] ?? 'sqlsrv';
        $port     = trim((string)($config['port'] ?? ''));

        if ($database === '') {
            $database = 'VenueBooking';
        }

        if ($driver === 'sqlsrv' && !in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                'PDO sqlsrv driver missing. Enable php_pdo_sqlsrv in php.ini and install ODBC Driver for SQL Server.'
            );
        }

        if ($driver === 'dblib') {
            $host = $port !== '' ? "{$server}:{$port}" : $server;
            $dsn = "dblib:host={$host};dbname={$database};charset=UTF-8";
        } else {
            $serverPart = $port !== '' ? "{$server},{$port}" : $server;
            $dsn = "sqlsrv:Server={$serverPart};Database={$database};TrustServerCertificate=yes;LoginTimeout=15";
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException(
                'Database connection failed: ' . $e->getMessage()
                . " (Server={$server}" . ($port !== '' ? ",{$port}" : '') . "; Database={$database})",
                (int) $e->getCode(),
                $e
            );
        }

        // Confirm we landed on the expected database
        try {
            $row = self::$pdo->query('SELECT DB_NAME() AS dbname')->fetch(PDO::FETCH_ASSOC);
            $row = array_change_key_case($row ?: [], CASE_LOWER);
            $actual = (string)($row['dbname'] ?? '');
            if ($actual !== '' && strcasecmp($actual, $database) !== 0) {
                throw new RuntimeException(
                    "Connected to database '{$actual}' but config requires '{$database}'. Fix backend/config/database.php."
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // ignore if DB_NAME() not permitted
        }

        return self::$pdo;
    }
}
