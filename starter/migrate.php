<?php
// ============================================================================
// migrate.php — creates the tables (CREATE TABLE IF NOT EXISTS only; never drops).
//   browser: https://HOST/PATH/migrate.php?pass=ADMIN_PASSWORD     CLI: php migrate.php
// Safe to run again. For a new version that changes tables, use a NEW table_prefix
// and copy data with INSERT … SELECT (see docs/03-deploy-config-migrate-check.md),
// so the old version keeps its own tables and rollback stays one webhook switch away.
// ============================================================================
$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

if (PHP_SAPI !== 'cli' && !hash_equals((string) $config['admin']['password'], (string) ($_GET['pass'] ?? ''))) {
    http_response_code(403);
    exit("❌ Wrong admin password.\n");
}

try {
    $db = App\Db::connect($config);
    $p  = App\Db::prefix($config);

    $tables = [
        // One row per Bale user. user_id = Bale's numeric id.
        "{$p}users" => "CREATE TABLE IF NOT EXISTS {$p}users (
            user_id BIGINT PRIMARY KEY,
            username VARCHAR(64) NULL,
            first_name VARCHAR(255) NULL,
            last_name VARCHAR(255) NULL,
            referred_by BIGINT NULL,
            banned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            INDEX idx_{$p}users_ref (referred_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Every Bale payment, one row per transaction id (paid or not).
        "{$p}payments" => "CREATE TABLE IF NOT EXISTS {$p}payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            package VARCHAR(32) NOT NULL,
            amount BIGINT NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT '',
            payload VARCHAR(255) NOT NULL DEFAULT '',
            charge_id VARCHAR(128) NOT NULL UNIQUE,
            provider_charge_id VARCHAR(128) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL,
            days INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_{$p}pay_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Premium time: payment / admin / referral rows.
        "{$p}premium" => "CREATE TABLE IF NOT EXISTS {$p}premium (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            source VARCHAR(16) NOT NULL,
            reference VARCHAR(128) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            granted_by BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_{$p}prem_user (user_id, status, expires_at),
            INDEX idx_{$p}prem_ref (reference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Processed updates (Bale retries slow webhooks).
        "{$p}updates_seen" => "CREATE TABLE IF NOT EXISTS {$p}updates_seen (
            update_key VARCHAR(80) PRIMARY KEY,
            seen_at DATETIME NOT NULL,
            INDEX idx_{$p}seen_at (seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ---- add the app's own tables here (random chat: see docs/06-random-chat-design.md) ----
    ];

    foreach ($tables as $name => $sql) {
        $db->exec($sql);
        echo "✔ {$name}\n";
    }
    echo "\nMigration completed successfully! Next: check.php?pass=…\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration error: " . $e->getMessage() . "\n";
}
