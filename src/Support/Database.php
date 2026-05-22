<?php

namespace Support;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;

    public static function connection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = $config['driver'] ?? 'sqlite';
        if ($driver !== 'sqlite') {
            throw new PDOException('Only the sqlite driver is supported in this starter.');
        }

        $dsn = 'sqlite:' . $config['database'];
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ];

        $databaseFile = $config['database'];
        $directory = dirname($databaseFile);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        if (! file_exists($databaseFile)) {
            touch($databaseFile);
        }

        self::$connection = new PDO($dsn, null, null, $options);
        self::applyPragmas();

        return self::$connection;
    }

    private static function applyPragmas(): void
    {
        if (! self::$connection instanceof PDO) {
            return;
        }

        // Improve SQLite concurrency for async jobs + polling traffic.
        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::$connection->exec('PRAGMA busy_timeout = 10000');

        try {
            self::$connection->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException) {
            // Keep compatibility with environments where WAL is unavailable.
        }

        try {
            self::$connection->exec('PRAGMA synchronous = NORMAL');
        } catch (PDOException) {
            // Non-critical optimization.
        }
    }
}
