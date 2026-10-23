<?php
// End-to-end: real webhook.php / migrate.php / check.php / cron over HTTP (php -S),
// a fake Bale server and the hs_e2e database. Run: php tests/e2e/run_e2e.php
require dirname(__DIR__) . '/lib.php';

use Hamsobat\Database;
use Hamsobat\Ui;

$root = dirname(__DIR__, 2);
$state = __DIR__ . '/state';
@mkdir($state);
array_map('unlink', glob("{$state}/*"));

$config = test_config();
$config['database']['dbname'] = getenv('HS_E2E_DB_NAME') ?: 'hs_e2e';
$config['database']['table_prefix'] = 'e_';
$config['bale']['api_base'] = 'http://127.0.0.1:8199';
$config['settings'] = ['required_referrals' => 0, 'require_channel' => 1, 'required_channel' => '@hamsohbat', 'channel_link' => 'https://ble.ir/hamsohbat'];
$config['log_dir'] = "{$state}/logs";
file_put_contents("{$state}/config.php", '<?php return ' . var_export($config, true) . ';');
putenv("HAMSOBAT_CONFIG={$state}/config.php");

// Fresh e_ tables (the database name must contain e2e).
$db = Database::connect($config);
if (!str_contains((string) $db->value('SELECT DATABASE()'), 'e2e')) {
    exit("refusing: not an e2e database\n");
}
$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->all("SHOW TABLES LIKE 'e\\_%'") as $row) {
    $db->pdo->exec('DROP TABLE `' . array_values($row)[0] . '`');
}
$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$env = "HAMSOBAT_CONFIG={$state}/config.php";
$servers = [
    proc_open("exec php -S 127.0.0.1:8199 {$root}/tests/e2e/mock_bale.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1),
    proc_open("{$env} exec php -S 127.0.0.1:8198 -t {$root}", [1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$state}/server.log", 'w']], $p2),
];
register_shutdown_function(function () use ($servers) {
    foreach ($servers as $s) {
        proc_terminate($s);
    }
});
usleep(600000);

function http(string $path, ?string $body = null): array
{
    $ch = curl_init('http://127.0.0.1:8198/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '']);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $out];
}

function requests(): array
{
    $lines = @file(__DIR__ . '/state/requests.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    return array_map(fn ($l) => json_decode($l, true), $lines);
}

function sentTo(int $chatId): array
{
    return array_values(array_filter(requests(), fn ($r) => (int) ($r['body']['chat_id'] ?? 0) === $chatId && isset($r['body']['text'])));
}

$uid = 1;
function update(int $from, string $text): string
{
    global $uid;
    return json_encode(['update_id' => $uid++, 'message' => ['message_id' => $uid, 'from' => ['id' => $from], 'chat' => ['id' => $from, 'type' => 'private'], 'text' => $text]], JSON_UNESCAPED_UNICODE);
}

function hook(string $body): int
{
    return http('webhook.php?k=test-webhook-secret', $body)[0];
}

echo "End-to-end (webhook.php over HTTP)\n";

test('migrate.php creates every table and is safe to run twice', function () use ($root, $env) {
    $a = shell_exec("{$env} php {$root}/migrate.php");
    $b = shell_exec("{$env} php {$root}/migrate.php");
    ok(substr_count((string) $a, '✔') === 20 && str_contains((string) $b, 'completed successfully'), trim((string) $b));
    ok(http('migrate.php')[0] === 403 && http('migrate.php?pass=wrong')[0] === 403, 'password required over HTTP');
});

test('check.php: password, all green', function () {
    ok(http('check.php?pass=nope')[0] === 403, 'wrong password → 403');
    [$code, $out] = http('check.php?pass=test-admin-password');
    ok($code === 200 && str_contains($out, '✔ connected') && str_contains($out, '✔ e_users') && str_contains($out, '✔ bot token'), 'checks pass');
    ok(!str_contains($out, 'test-webhook-secret') && !str_contains($out, 'TESTTOKEN'), 'no secrets printed');
});

test('webhook secret', function () {
    ok(http('webhook.php', update(1, '/start'))[0] === 403, 'no secret → 403');
    ok(http('webhook.php?k=wrong', update(1, '/start'))[0] === 403, 'wrong secret → 403');
    ok(!sentTo(1), 'nothing sent');
});

test('a re-delivered update is processed once', function () {
    $body = update(1, '/start');
    ok(hook($body) === 200 && hook($body) === 200, '200 twice');
    ok(count(sentTo(1)) === 1, 'one welcome, not two');
});

test('two users register, match and chat through the webhook', function () {
    foreach ([1 => 'Alpha', 2 => 'Beta'] as $id => $nick) {
        hook(update($id, '/start'));
        hook(update($id, Ui::COMPLETE_PROFILE));
        hook(update($id, 'کاربر'));
        hook(update($id, $nick));
        hook(update($id, '1378/03/04'));
        foreach (['rg:g:' . ($id === 1 ? 'm' : 'f'), 'rg:p:any', 'rg:skip:photo', 'rg:skip:city', 'rg:skip:bio', 'rg:skip:interests'] as $data) {
            global $uid;
            hook(json_encode(['update_id' => $uid++, 'callback_query' => ['id' => "c{$uid}", 'from' => ['id' => $id], 'data' => $data, 'message' => ['message_id' => 1, 'chat' => ['id' => $id, 'type' => 'private']]]]));
        }
    }
    $texts = implode("\n", array_column(array_column(sentTo(1), 'body'), 'text'));
    ok(str_contains($texts, 'پروفایلت آماده‌ست') && str_contains($texts, 'همه‌چیز آماده‌ست'), 'registered; channel membership checked with getChatMember');
    hook(update(1, Ui::FIND));
    hook(update(2, Ui::FIND));
    $toAlpha = sentTo(1);
    ok(str_contains(end($toAlpha)['body']['text'] ?? '', 'سلام کن'), 'matched');
    hook(update(2, 'سلام از بتا 👋'));
    $toAlpha = sentTo(1);
    ok((end($toAlpha)['body']['text'] ?? '') === 'سلام از بتا 👋', 'message relayed, unchanged, no sender name');
    $markup = json_decode(sentTo(2)[count(sentTo(2)) - 2]['body']['reply_markup'] ?? '{}', true);
    ok(($markup['keyboard'][0][0]['text'] ?? '') === Ui::NEXT || str_contains(json_encode(array_column(sentTo(2), 'body'), JSON_UNESCAPED_UNICODE), Ui::NEXT), 'chat reply keyboard sent as JSON text');
});

test('cron/cleanup.php needs its secret', function () {
    ok(http('cron/cleanup.php')[0] === 403, 'no secret → 403');
    [$code, $out] = http('cron/cleanup.php?k=test-cron-secret');
    ok($code === 200 && str_contains($out, 'expired_searches'), $out);
});

test('internal files are not executable from the web', function () {
    foreach (['services/Users.php', 'handlers/chat.php', 'admin/commands.php', 'database.php', 'bootstrap.php'] as $f) {
        [$code, $out] = http($f);
        ok(trim($out) === '', "{$f} prints nothing");
    }
});

test('errors are logged without secrets', function () use ($state) {
    $log = implode('', array_map('file_get_contents', glob("{$state}/logs/*.log") ?: []));
    ok(!str_contains($log, 'TESTTOKEN') && !str_contains($log, 'test-webhook-secret'), 'no secrets in logs');
    $server = (string) @file_get_contents("{$state}/server.log");
    ok(!preg_match('~PHP (Warning|Notice|Fatal|Deprecated)~', $server), 'no PHP warnings: ' . substr($server, 0, 300));
});

echo "\n" . $GLOBALS['pass'] . ' passed, ' . count($GLOBALS['fail']) . " failed\n";
foreach ($GLOBALS['fail'] as $f) {
    echo "  ✘ {$f}\n";
}
exit($GLOBALS['fail'] ? 1 : 0);
