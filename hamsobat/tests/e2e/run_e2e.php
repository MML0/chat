<?php
// End-to-end: real webhook.php / migrate.php / check.php / cron over HTTP (php -S),
// a fake Bale server and the hs_e2e database. Run: php tests/e2e/run_e2e.php
require dirname(__DIR__) . '/lib.php';

use Hamsobat\Database;
use Hamsobat\Ui;

$root = dirname(__DIR__, 2);
$state = __DIR__ . '/state';
@mkdir($state);
array_map('unlink', array_filter(glob("{$state}/*") ?: [], 'is_file'));

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
$GLOBALS['db'] = $db;
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
    ok(substr_count((string) $a, '✔') === 23 && str_contains((string) $b, 'completed successfully'), trim((string) $b));
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

echo "\nAdmin panel over HTTP\n";

$jar = "{$state}/cookies.txt";
@unlink($jar);
/** Panel request with a cookie jar. @return array{0:int,1:string,2:string} code, body, Location */
function panel(string $path, ?array $post = null): array
{
    global $jar;
    $ch = curl_init('http://127.0.0.1:8198/panel/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADER => true, CURLOPT_USERAGENT => 'e2e']);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    preg_match('~^Location:\s*(\S+)~mi', $headers, $m);
    return [$code, substr($raw, $hsize), $m[1] ?? '', $headers];
}
function csrf_of(string $html): string
{
    preg_match('~name="_csrf" value="([a-f0-9]+)"~', $html, $m);
    return $m[1] ?? '';
}

test('login: password, then the code the bot sends to the admin', function () {
    [$code, $body] = panel('');
    ok($code === 200 && str_contains($body, 'رمز ادمین'), 'login page');
    [$code, $body] = panel('api.php?a=live');
    ok($code === 401, 'API locked without login');
    ok(panel('media.php?u=1')[0] === 401, 'media locked without login');
    [, $body] = panel('', ['password' => 'wrong']);
    ok(str_contains($body, 'رمز اشتباهه'), 'wrong password refused');
    [, $body] = panel('', ['password' => 'test-admin-password']);
    ok(str_contains($body, 'کد ۶ رقمی'), 'asks for the code');
    $sent = array_values(array_filter(requests(), fn ($r) => $r['method'] === 'sendMessage' && (int) ($r['body']['chat_id'] ?? 0) === 9000 && str_contains((string) ($r['body']['text'] ?? ''), 'کد ورود')));
    preg_match('~\b(\d{6})\b~', (string) (end($sent)['body']['text'] ?? ''), $m);
    ok(isset($m[1]), 'code sent to the admin in Bale');
    [, $body] = panel('', ['code' => '000000']);
    ok(str_contains($body, 'کد اشتباهه'), 'wrong code refused');
    [$code, , $loc] = panel('', ['code' => $m[1] ?? '']);
    ok($code === 303, 'right code logs in');
});

test('every page renders without errors', function () {
    $pages = [
        '' => 'آخرین گفت‌وگوها', '?p=live' => 'پیام‌ها همین حالا', '?p=users' => 'Alpha', '?p=users&q=bet&sort=chats' => 'Beta',
        '?p=user&id=1' => 'هدیه اعتبار', '?p=chats' => 'گفت‌وگو', '?p=chats&status=active' => 'گفت‌وگو', '?p=chat&id=1' => 'سلام از بتا',
        '?p=inbox' => 'پیام‌های ناشناس', '?p=reports' => 'گزارش', '?p=reports&status=resolved' => 'گزارش', '?p=settings' => 'daily_free_chats',
        '?p=server' => 'دیتابیس', '?p=logs' => 'کارهای انجام‌شده', '?p=nope' => 'روند ۳۰ روز', '?p=user&id=999' => 'کاربر پیدا نشد',
    ];
    foreach ($pages as $q => $needle) {
        [$code, $body, , $headers] = panel($q);
        ok($code === 200 && str_contains($body, $needle) && !str_contains($body, 'این صفحه خطا داد'), "page {$q}: {$needle}");
        ok(!preg_match('~(Warning|Notice|Deprecated|Fatal error)</b>|on line \d+~', $body), "no PHP warnings on {$q}");
    }
    ok(str_contains($headers, 'Content-Security-Policy') && str_contains($headers, 'X-Frame-Options: DENY'), 'security headers');
});

test('actions need the CSRF token; with it they work and are logged', function () {
    [, $page] = panel('?p=user&id=1');
    $csrf = csrf_of($page);
    ok(strlen($csrf) === 48, 'token on the page');
    [$code] = panel('', ['action' => 'credit', 'user_id' => 1, 'amount' => 100]);
    ok($code === 403, 'no token → 403');
    [$code, , $loc] = panel('', ['_csrf' => $csrf, 'action' => 'credit', 'user_id' => 1, 'amount' => 100, 'notify' => 1, 'back' => 'p=user&id=1']);
    ok($code === 303 && str_contains($loc, 'p=user&id=1'), 'redirects back');
    [, $body] = panel('?p=user&id=1');
    ok(str_contains($body, '۱۰۰ اعتبار به Alpha داده شد'), 'flash message');
    [, , $loc] = panel('', ['_csrf' => $csrf, 'action' => 'unlimited', 'user_id' => 2, 'days' => 0, 'back' => 'https://evil.example']);
    ok($loc === './', 'no open redirect');
    panel('', ['_csrf' => $csrf, 'action' => 'settings', 's' => ['daily_free_chats' => 9, 'feature_inbox' => 1, 'block_links' => 1, 'store_message_text' => 1, 'require_channel' => 1, 'referral_requires_channel' => 1, 'notify_admin_reports' => 1, 'feature_media' => 1, 'feature_profile_view_notify' => 1, 'allowed_types' => ['text', 'photo', 'voice']]]);
    [, $body] = panel('?p=settings');
    ok(str_contains($body, 'name="s[daily_free_chats]" value="9"'), 'setting saved from the panel');
    [, $body] = panel('?p=logs');
    ok(str_contains($body, '🎁 اعتبار') && str_contains($body, '🔑 ورود'), 'audit log shows login and actions');
});

test('live API, transcript polling and media proxy', function () {
    [$code, $body] = panel('api.php?a=feed&after=0');
    $j = json_decode($body, true);
    ok($code === 200 && isset($j['live']['online']) && ($j['items'][0]['text'] ?? '') === 'سلام از بتا 👋', 'feed JSON');
    $j = json_decode(panel('api.php?a=chat&id=1&after=0')[1], true);
    ok(($j['status'] ?? '') === 'active' && count($j['items'] ?? []) === 1, 'chat polling JSON');
    global $db;
    $db->run("UPDATE {users} SET photo_file_id = 'PHOTO1' WHERE id = 1");
    [$code, $img, , $headers] = panel('media.php?u=1');
    ok($code === 200 && str_starts_with($img, "\x89PNG") && str_contains($headers, 'image/png'), 'profile photo streamed through the panel');
    ok(!str_contains($headers, 'TESTTOKEN'), 'token never sent to the browser');
    [$code2, $img2] = panel('media.php?u=1');
    ok($code2 === 200 && $img2 === $img, 'second time from the disk cache');
    ok(panel('media.php?m=99999')[0] === 404, 'unknown media → 404');
});

test('logout ends the session', function () {
    [, $page] = panel('');
    panel('', ['_csrf' => csrf_of($page), 'action' => 'logout']);
    [, $body] = panel('?p=users');
    ok(str_contains($body, 'رمز ادمین'), 'back to login');
});

echo "\n" . $GLOBALS['pass'] . ' passed, ' . count($GLOBALS['fail']) . " failed\n";
foreach ($GLOBALS['fail'] as $f) {
    echo "  ✘ {$f}\n";
}
exit($GLOBALS['fail'] ? 1 : 0);
