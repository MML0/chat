<?php

namespace App;

use PDO;

/** PDO helpers. Always utf8mb4 (Persian text), exceptions on, table names with a prefix. */
final class Db
{
    public static function connect(array $config): PDO
    {
        $d = $config['database'];
        $port = isset($d['port']) ? ";port={$d['port']}" : '';
        $db = new PDO("mysql:host={$d['host']}{$port};dbname={$d['dbname']};charset=utf8mb4", $d['username'], $d['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $db;
    }

    /** Validated table prefix, e.g. 'rc_' → rc_users, rc_payments … */
    public static function prefix(array $config): string
    {
        $p = (string) ($config['database']['table_prefix'] ?? 'rc_');
        if (!preg_match('~^[A-Za-z0-9_]{1,20}$~', $p)) {
            throw new \InvalidArgumentException('table_prefix: letters, digits and _ only');
        }
        return $p;
    }

    /** True when the error is a duplicate key (MySQL 1062 / SQLite UNIQUE). Lets us write portable "insert once" code. */
    public static function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || str_contains($e->getMessage(), 'UNIQUE') || $e->getCode() === '23000';
    }
}
