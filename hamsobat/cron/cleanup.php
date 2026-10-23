<?php
// ============================================================================
// cron/cleanup.php — search timeouts, idle chats, old data. Safe to run any time.
//   CLI:  php cron/cleanup.php                   (cPanel cron, every minute)
//   URL:  https://HOST/PATH/cron/cleanup.php?k=CRON_SECRET  (external cron service)
// Not required: the webhook does the same work while people use the bot. A cron only
// makes "nobody found" messages arrive on time when nobody else is sending anything.
// ============================================================================
require dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$config = hamsobat_config();
$secret = (string) ($config['cron_secret'] ?? '');
if (PHP_SAPI !== 'cli' && ($secret === '' || !hash_equals($secret, (string) ($_GET['k'] ?? '')))) {
    http_response_code(403);
    exit("forbidden\n");
}
date_default_timezone_set('UTC');

try {
    $app = Hamsobat\App::fromConfig($config);
    $r = $app->maintenance()->run();
    $app->settings->internal('_tick', (string) $app->now(), $app->now());
    echo json_encode($r) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage() . "\n";
}
