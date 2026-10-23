<?php
// ============================================================================
// check.php — deployment check (admin only, spends nothing).
//   https://HOST/PATH/check.php?pass=ADMIN_PASSWORD
//   https://HOST/PATH/check.php?pass=ADMIN_PASSWORD&setwebhook=1   ← point the bot at THIS folder
// Rollback = run setwebhook from the old folder (or call setWebhook with the old URL).
// ============================================================================
$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

if (PHP_SAPI !== 'cli' && !hash_equals((string) $config['admin']['password'], (string) ($_GET['pass'] ?? ''))) {
    http_response_code(403);
    exit("❌ Wrong admin password.\n");
}
$line = function (bool $ok, string $label, string $extra = '') {
    echo ($ok ? '✔ ' : '❌ ') . $label . ($extra !== '' ? " — {$extra}" : '') . "\n";
};

echo "== Server\n";
$line(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80100 ? '' : 'PHP 8.1+ needed');
foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
    $line(extension_loaded($ext), "extension {$ext}");
}

echo "\n== Database\n";
try {
    $db = App\Db::connect($config);
    $p = App\Db::prefix($config);
    $line(true, 'connected');
    foreach (['users', 'payments', 'premium', 'updates_seen'] as $t) {
        try {
            $line(true, "{$p}{$t}", $db->query("SELECT COUNT(*) FROM {$p}{$t}")->fetchColumn() . ' rows');
        } catch (Throwable $e) {
            $line(false, "{$p}{$t}", 'missing → run migrate.php');
        }
    }
} catch (Throwable $e) {
    $line(false, 'connect', $e->getMessage());
}

echo "\n== Bale\n";
$bale = new App\Bale($config['bale']['bot_token'], $config['bale']['api_base'] ?? 'https://tapi.bale.ai');
$me = $bale->call('getMe');
$line(!empty($me['ok']), 'bot token', '@' . ($me['result']['username'] ?? '?'));
$wh = $bale->call('getWebhookInfo');
echo '  current webhook: ' . preg_replace('~([?&]k=)[^&]+~', '$1***', (string) ($wh['result']['url'] ?? '(unknown)')) . "\n";
$token = (string) ($config['payment']['provider_token'] ?? '');
$line($token !== '' && !str_contains($token, 'XXXX') && !str_contains($token, 'CHANGE'), 'payment.provider_token set', str_starts_with($token, 'WALLET-TEST') ? 'TEST wallet' : '');

if (!empty($_GET['setwebhook']) && PHP_SAPI !== 'cli') {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/webhook.php';
    if (!empty($config['bale']['webhook_secret'])) {
        $url .= '?k=' . rawurlencode($config['bale']['webhook_secret']);
    }
    $r = $bale->call('setWebhook', ['url' => $url]);
    $line(!empty($r['ok']), 'setWebhook → ' . preg_replace('~([?&]k=)[^&]+~', '$1***', $url), $r['description'] ?? '');
}
