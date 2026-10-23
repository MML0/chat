<?php
// ============================================================================
// check.php — deployment self-test (admin password, spends nothing).
//   https://HOST/PATH/check.php?pass=ADMIN_PASSWORD
//   https://HOST/PATH/check.php?pass=ADMIN_PASSWORD&setwebhook=1   ← point the bot at THIS folder
// Rollback = open setwebhook=1 from the old folder.
// ============================================================================
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$config = hamsobat_config();
if (PHP_SAPI !== 'cli' && !hash_equals((string) $config['admin']['password'], (string) ($_GET['pass'] ?? ''))) {
    http_response_code(403);
    exit("❌ Wrong admin password.\n");
}
$line = function (bool $ok, string $label, string $extra = '') {
    echo ($ok ? '✔ ' : '❌ ') . $label . ($extra !== '' ? " — {$extra}" : '') . "\n";
};
$placeholder = fn ($v) => $v === '' || preg_match('~CHANGE_ME|YOUR_|XXXX~', (string) $v);

echo "== Hamsobat " . HAMSOBAT_VERSION . "\n\n== Server\n";
$line(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80200 ? '' : 'PHP 8.2+ needed');
foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
    $line(extension_loaded($ext), "extension {$ext}");
}
$logDir = (string) ($config['log_dir'] ?? '');
$line($logDir !== '' && (is_dir($logDir) || @mkdir($logDir, 0750, true)) && is_writable($logDir), 'log folder writable', $logDir !== '' ? basename(dirname($logDir)) . '/' . basename($logDir) : 'log_dir not set');

echo "\n== config.php\n";
$line(!$placeholder($config['bale']['bot_token'] ?? ''), 'bale.bot_token set');
$line(!$placeholder($config['bale']['bot_username'] ?? '') && !str_starts_with((string) $config['bale']['bot_username'], '@'), 'bale.bot_username', (string) ($config['bale']['bot_username'] ?? ''));
$line(!$placeholder($config['bale']['webhook_secret'] ?? '') && strlen((string) $config['bale']['webhook_secret']) >= 16, 'bale.webhook_secret (16+ random characters)');
$line(!$placeholder($config['admin']['password'] ?? '') && strlen((string) $config['admin']['password']) >= 12, 'admin.password (12+ characters)');
$adminIds = array_filter(array_map('intval', (array) ($config['admin']['ids'] ?? [])), fn ($id) => $id > 0 && $id !== 123456789);
$line((bool) $adminIds, 'admin.ids', $adminIds ? implode(', ', $adminIds) : 'put your Bale user id (see "newest users" below)');
$line(!$placeholder($config['cron_secret'] ?? ''), 'cron_secret set', 'needed only for cron/*.php over HTTP');

echo "\n== Database\n";
$app = null;
try {
    $app = Hamsobat\App::fromConfig($config);
    $line(true, 'connected', 'prefix ' . $app->db->prefix);
    foreach (Hamsobat\Database::schema(__DIR__ . '/database.sql', $app->db->prefix) as $sql) {
        if (preg_match('~^CREATE TABLE IF NOT EXISTS (\w+)~', $sql, $m)) {
            try {
                $line(true, $m[1], $app->db->pdo->query("SELECT COUNT(*) FROM {$m[1]}")->fetchColumn() . ' rows');
            } catch (Throwable $e) {
                $line(false, $m[1], 'missing → run migrate.php');
            }
        }
    }
} catch (Throwable $e) {
    $line(false, 'connect', $e->getMessage());
}

echo "\n== Bale\n";
$bale = new Hamsobat\BaleApi((string) $config['bale']['bot_token'], (string) ($config['bale']['api_base'] ?? 'https://tapi.bale.ai'));
$me = $bale->call('getMe');
$line(!empty($me['ok']), 'bot token (getMe)', !empty($me['ok']) ? '@' . ($me['result']['username'] ?? '?') : (string) ($me['description'] ?? ''));
if (!empty($me['ok']) && strcasecmp((string) ($me['result']['username'] ?? ''), (string) $config['bale']['bot_username']) !== 0) {
    $line(false, 'bale.bot_username must be ' . ($me['result']['username'] ?? '?'), 'links would be wrong');
}
$wh = $bale->call('getWebhookInfo');
echo '  current webhook: ' . preg_replace('~([?&]k=)[^&]+~', '$1***', (string) ($wh['result']['url'] ?? '(unknown)')) . "\n";
if (!empty($wh['result']['last_error_message'])) {
    echo '  last webhook error: ' . $wh['result']['last_error_message'] . "\n";
}

if ($app) {
    echo "\n== Settings\n";
    $s = $app->settings;
    $channel = $s->str('required_channel');
    if ($s->bool('require_channel') && $channel !== '') {
        $botId = (int) ($me['result']['id'] ?? 0);
        $res = $botId ? $bale->getChatMember($channel, $botId) : [];
        $status = (string) ($res['result']['status'] ?? '');
        $line(in_array($status, ['administrator', 'creator', 'owner'], true), "bot is admin of {$channel}", $status !== '' ? $status : (string) ($res['description'] ?? 'not checked'));
        $line($s->str('channel_link') !== '', 'channel_link', $s->str('channel_link'));
    } else {
        echo "  required channel: off (set it in Bale with /setchannel)\n";
    }
    foreach (['daily_free_chats', 'required_referrals', 'referral_reward_credits', 'search_timeout_seconds', 'min_age', 'maintenance'] as $k) {
        echo "  {$k} = " . $s->get($k) . "\n";
    }
    $last = (int) ($s->raw('_tick') ?? 0);
    echo '  last periodic run: ' . ($last ? gmdate('Y-m-d H:i:s', $last) . ' UTC' : 'never (runs with the next update)') . "\n";

    echo "\n== Newest users (to find your Bale id for admin.ids)\n";
    foreach ($app->db->all('SELECT id, bale_user_id, nickname, created_at FROM {users} ORDER BY id DESC LIMIT 5') as $u) {
        echo "  #{$u['id']} bale id {$u['bale_user_id']} " . ($u['nickname'] ?? '(no profile yet)') . " {$u['created_at']} UTC\n";
    }
}

if (PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    echo "\n== Admin panel\n  " . ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . "/panel/\n";
    echo '  login: admin.password' . (($config['panel']['two_factor'] ?? true) ? ' + a code the bot sends to admin.ids in Bale' : ' only (two_factor is off)') . "\n";
}

if (!empty($_GET['setwebhook']) && PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $base = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $url = $base . '/webhook.php?k=' . rawurlencode((string) $config['bale']['webhook_secret']);
    $r = $bale->call('setWebhook', ['url' => $url]);
    echo "\n== Webhook\n";
    $line(!empty($r['ok']), 'setWebhook → ' . preg_replace('~([?&]k=)[^&]+~', '$1***', $url), (string) ($r['description'] ?? ''));
    echo "\nOptional exact search timeouts: call this every minute from a cron or an external cron service:\n  {$base}/cron/cleanup.php?k=<cron_secret>\n";
}
