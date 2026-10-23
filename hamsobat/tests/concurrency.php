<?php
// Concurrent matching test: N separate PHP processes press "🔎 پیدا کردن هم‌صحبت" at the
// same moment (some users twice), each with its own DB connection, like parallel webhooks.
// Then the invariants are checked: nobody is matched with themselves or twice, nobody is
// both chatting and waiting, at most one compatible user is left waiting, and every match
// cost exactly one free chat per person.
//   php tests/concurrency.php [users=40]
require __DIR__ . '/lib.php';

use Hamsobat\App;
use Hamsobat\BaleApi;
use Hamsobat\Database;
use Hamsobat\Router;
use Hamsobat\Ui;

if (($argv[1] ?? '') === 'worker') {
    [$baleId, $startAt] = [(int) $argv[2], (float) $argv[3]];
    $config = test_config();
    $config['settings'] = ['require_channel' => 0];
    $fake = new FakeBale();
    $app = new App($config, Database::connect($config), new BaleApi('111:TESTTOKEN', 'https://tapi.bale.ai', $fake->transport()));
    while (microtime(true) < $startAt) {
        usleep(200);
    }
    try {
        (new Router($app))->handle(['update_id' => random_int(1, PHP_INT_MAX), 'message' => [
            'message_id' => 1, 'from' => ['id' => $baleId], 'chat' => ['id' => $baleId, 'type' => 'private'], 'text' => Ui::FIND,
        ]]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo 'ERROR ' . $e->getMessage() . "\n";
    }
    exit;
}

$n = (int) ($argv[1] ?? 40);
$w = new World();
$db = $w->app->db;
$now = gmdate('Y-m-d H:i:s');
for ($i = 1; $i <= $n; $i++) {
    $db->insert('users', [
        'bale_user_id' => 100 + $i, 'display_name' => 'کاربر', 'nickname' => "user{$i}", 'birth_date' => '1995-01-01',
        'gender' => $i % 2 ? 'm' : 'f', 'preferred_gender' => 'any', 'referral_code' => "r{$i}", 'inbox_code' => "m{$i}",
        'referral_waived' => 1, 'profile_completed' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

$startAt = microtime(true) + 1.5;
$procs = [];
$jobs = range(101, 100 + $n);
$jobs = array_merge($jobs, array_slice($jobs, 0, intdiv($n, 4))); // a quarter of the users press twice
shuffle($jobs);
foreach ($jobs as $baleId) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $baleId, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outs[] = $pipes;
}
$errors = [];
foreach ($procs as $i => $p) {
    $out = stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
    proc_close($p);
    if (trim($out) !== 'ok') {
        $errors[] = trim($out);
    }
}

echo "Concurrent matching: {$n} users, " . count($jobs) . " parallel requests\n";
test('no request failed (no deadlock or duplicate-key error escaped)', fn () => ok(!$errors, implode(' | ', array_slice($errors, 0, 3))));
test('invariants', function () use ($db, $n) {
    $chats = $db->all("SELECT * FROM {chats} WHERE status = 'active'");
    $active = $db->all('SELECT * FROM {active_chats}');
    ok(count($active) === 2 * count($chats), 'two active rows per chat');
    $inChat = [];
    foreach ($chats as $c) {
        ok($c['user_one_id'] !== $c['user_two_id'], 'not matched with self');
        $inChat[] = (int) $c['user_one_id'];
        $inChat[] = (int) $c['user_two_id'];
    }
    ok(count($inChat) === count(array_unique($inChat)), 'nobody in two chats');
    foreach ($active as $a) {
        $back = $db->value('SELECT partner_user_id FROM {active_chats} WHERE user_id = ?', [$a['partner_user_id']]);
        ok((int) $back === (int) $a['user_id'], 'partners point at each other');
    }
    $waiting = array_map('intval', array_column($db->all("SELECT user_id FROM {chat_queue} WHERE status = 'waiting'"), 'user_id'));
    ok(!array_intersect($waiting, $inChat), 'nobody both waiting and chatting');
    ok(count($waiting) <= 1, 'at most one user left waiting (all are compatible); waiting: ' . count($waiting));
    ok(count($inChat) + count($waiting) === $n, 'everyone is either chatting or waiting');
    $usage = $db->all('SELECT user_id, successful_chats FROM {daily_usage}');
    ok(count($usage) === count($inChat), 'usage only for matched users');
    foreach ($usage as $u) {
        ok((int) $u['successful_chats'] === 1, "user {$u['user_id']} paid exactly once");
    }
    echo '    chats: ' . count($chats) . ', waiting: ' . count($waiting) . "\n";
});

echo "\n" . $GLOBALS['pass'] . ' passed, ' . count($GLOBALS['fail']) . " failed\n";
foreach ($GLOBALS['fail'] as $f) {
    echo "  ✘ {$f}\n";
}
exit($GLOBALS['fail'] ? 1 : 0);
