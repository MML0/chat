<?php
// ============================================================================
// migrate.php — creates the tables from database.sql with your table_prefix.
//   browser: https://HOST/PATH/migrate.php?pass=ADMIN_PASSWORD     CLI: php migrate.php
// Safe to run again: CREATE TABLE IF NOT EXISTS only, never drops or changes data.
// ============================================================================
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$config = hamsobat_config();
if (PHP_SAPI !== 'cli' && !hash_equals((string) $config['admin']['password'], (string) ($_GET['pass'] ?? ''))) {
    http_response_code(403);
    exit("❌ Wrong admin password.\n");
}

try {
    $db = Hamsobat\Database::connect($config);
    foreach (Hamsobat\Database::schema(__DIR__ . '/database.sql', $db->prefix) as $sql) {
        $db->pdo->exec($sql);
        if (preg_match('~^CREATE TABLE IF NOT EXISTS (\w+)~', $sql, $m)) {
            echo "✔ {$m[1]}\n";
        }
    }
    echo "\nMigration completed successfully! Next: check.php?pass=…\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration error: ' . $e->getMessage() . "\n";
}

