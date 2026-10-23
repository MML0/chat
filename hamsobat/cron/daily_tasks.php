<?php
// ============================================================================
// cron/daily_tasks.php — once a day: sends the admins the current numbers and deletes
// old daily usage rows. Quotas need no reset: they are stored per date.
//   CLI:  php cron/daily_tasks.php     URL:  …/cron/daily_tasks.php?k=CRON_SECRET
// Running it twice the same day sends nothing the second time.
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
    if (!$app->settings->claim('_daily', 20 * 3600, $app->now())) {
        exit("already done today\n");
    }
    $deleted = $app->db->run('DELETE FROM {daily_usage} WHERE usage_date < ?', [date('Y-m-d', strtotime($app->today() . ' -60 days'))])->rowCount();
    $app->maintenance()->purge();
    $app->notifyAdmins("🗓 گزارش روزانه\n\n" . Hamsobat\Maintenance::statsText($app->maintenance()->stats()));
    echo "ok, old usage rows deleted: {$deleted}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage() . "\n";
}
