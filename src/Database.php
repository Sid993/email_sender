<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * MySQL is used only for durable data (user accounts). Campaign data is temporary
 * and lives in per-user workspaces (see App\Storage\Workspace).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            // DB_HOST=localhost connects through the local Unix socket (fastest, and
            // treated as secure by MySQL's caching_sha2_password authentication).
            $dsn = Config::get('DB_DSN') ?? sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', 'localhost'),
                Config::int('DB_PORT', 3306),
                Config::get('DB_NAME', 'bulk_mailer')
            );
            // PHP 8.4+: PDO::connect() returns the driver-specific class (Pdo\Mysql).
            self::$pdo = PDO::connect($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }
}
