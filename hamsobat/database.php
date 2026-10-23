<?php
// PDO wrapper: utf8mb4, exceptions, table prefix, transactions with deadlock retry.
// SQL is written with {table} placeholders: "SELECT * FROM {users}" → hs_users.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    /** Number of queries this request (shown on the panel's performance charts). */
    public int $queries = 0;
    private array $tables = [];

    public function __construct(public readonly PDO $pdo, public readonly string $prefix)
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,20}$~', $prefix)) {
            throw new \InvalidArgumentException('table_prefix: letters, digits and _ only');
        }
    }

    public static function connect(array $config): self
    {
        $d = $config['database'];
        $dsn = 'mysql:' . (!empty($d['socket']) ? "unix_socket={$d['socket']}" : "host={$d['host']}" . (!empty($d['port']) ? ";port={$d['port']}" : ''))
            . ";dbname={$d['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $d['username'], $d['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return new self($pdo, (string) ($d['table_prefix'] ?? 'hs_'));
    }

    public function t(string $name): string
    {
        return $this->prefix . $name;
    }

    public function sql(string $sql): string
    {
        return preg_replace('~\{([a-z_]+)\}~', $this->prefix . '$1', $sql);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $this->queries++;
        $stmt = $this->pdo->prepare($this->sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public function row(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT one row, returns the new id. */
    public function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $this->run("INSERT INTO {{$table}} ({$cols}) VALUES ({$marks})", array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Runs $fn inside one transaction and returns its result. A deadlock or lock timeout
     * (1213 / 1205) rolls back and retries up to 3 times. Never call Bale inside $fn:
     * a retry would send the message again, and slow calls would hold locks.
     */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $fn($this);
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (($code === 1213 || $code === 1205) && $attempt < 3) {
                    usleep(random_int(20000, 80000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * True when a table exists. Code for tables added in a newer version asks this first,
     * so new files work even before migrate.php has run (live updates).
     */
    public function hasTable(string $name): bool
    {
        $full = $this->t($name);
        return $this->tables[$full] ??= (bool) $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote(addcslashes($full, '_%')))->fetchColumn();
    }

    /** database.sql split into statements, with hs_ replaced by the configured prefix. */
    public static function schema(string $file, string $prefix): array
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('~^\s*--.*$~m', '', $sql);
        $sql = preg_replace('~\bhs_~', $prefix, $sql);
        return array_values(array_filter(array_map('trim', preg_split('~;[ \t]*\r?$~m', $sql))));
    }

    public static function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
