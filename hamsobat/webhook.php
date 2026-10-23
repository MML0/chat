<?php
// ============================================================================
// webhook.php — Bale posts every update here (set it with check.php?setwebhook=1).
//   1. secret in the URL (?k=…)   2. decode   3. DB + de-duplicate re-delivered updates
//   4. periodic work (search timeouts, idle chats, cleanup)   5. route to the handlers
// ============================================================================
require __DIR__ . '/bootstrap.php';

use Hamsobat\App;
use Hamsobat\Router;

$config = hamsobat_config();

// 1) Only Bale knows the secret in the webhook URL.
$secret = (string) ($config['bale']['webhook_secret'] ?? '');
if ($secret === '' || !hash_equals($secret, (string) ($_GET['k'] ?? ''))) {
    http_response_code(403);
    exit;
}

// 2) Decode
$raw = (string) file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    exit;
}
date_default_timezone_set('UTC');

// 3) DB + de-duplicate
try {
    $app = App::fromConfig($config);
} catch (Throwable $e) {
    error_log('[hamsobat] DB connection failed: ' . $e->getMessage());
    http_response_code(500); // Bale will retry later
    exit;
}
if (isset($update['update_id'])) {
    try {
        $app->db->run('INSERT INTO {updates_seen} (update_key, seen_at) VALUES (?, ?)', [$update['update_id'] . ':' . md5($raw), $app->ts()]);
    } catch (PDOException $e) {
        if (Hamsobat\Database::isDuplicate($e)) {
            exit; // already processed
        }
        $app->log->error('dedupe failed', ['error' => $e->getMessage()]);
    }
}

// 4 + 5) Work. Errors are logged (readable with /errors) and Bale still gets 200, so a
// broken update is not re-sent forever.
try {
    $app->maintenance()->tick();
} catch (Throwable $e) {
    $app->log->error('tick: ' . $e->getMessage(), ['at' => basename($e->getFile()) . ':' . $e->getLine()]);
}
try {
    (new Router($app))->handle($update);
} catch (Throwable $e) {
    $app->log->error($e->getMessage(), ['at' => basename($e->getFile()) . ':' . $e->getLine(), 'update' => array_keys($update)]);
    $from = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
    if ($from) {
        $app->bale->send((int) $from, '⚠️ یه مشکلی پیش اومد. لطفاً دوباره امتحان کن.');
    }
    $app->alertAdminsOnce('webhook_error', "⚠️ خطا در ربات:\n" . mb_substr($e->getMessage(), 0, 500) . "\n\nجزئیات: /errors", 600);
}
