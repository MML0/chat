<?php
// Offline tests (SQLite + fake Bale). Run: php starter/tests/run.php
require __DIR__ . '/../src/bootstrap.php';

use App\Bale;
use App\Payments;
use App\Premium;
use App\UpdateDeduper;

$pass = 0;
$fail = [];
function ok($cond, string $msg): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail[] = $msg;
}
function test(string $name, callable $fn): void
{
    global $fail;
    $before = count($fail);
    try {
        $fn();
    } catch (Throwable $e) {
        $fail[] = "$name: " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
    echo (count($fail) === $before ? '  ✔ ' : '  ✘ ') . $name . "\n";
}

/** Fake Bale: records requests; $answers maps method → callable($params, $isJson, $n) returning an array. */
final class FakeBale
{
    public array $calls = [];
    public function __construct(public array $answers = [])
    {
    }
    public function transport(): callable
    {
        return function (string $url, $body, array $headers, int $timeout) {
            $method = substr($url, strrpos($url, '/') + 1);
            $isJson = in_array('Content-Type: application/json', $headers, true);
            $params = $isJson ? json_decode($body, true) : $body;
            $this->calls[] = ['method' => $method, 'params' => $params, 'json' => $isJson];
            $n = count(array_filter($this->calls, fn ($c) => $c['method'] === $method));
            $fn = $this->answers[$method] ?? fn () => ['ok' => true, 'result' => []];
            $res = $fn($params, $isJson, $n);
            return $res === null ? [0, false] : [200, json_encode($res)];
        };
    }
    public function of(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }
    public function texts($chatId): array
    {
        return array_map(fn ($c) => $c['params']['text'], array_values(array_filter($this->of('sendMessage'), fn ($c) => (string) $c['params']['chat_id'] === (string) $chatId)));
    }
}

function db(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE rc_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, package TEXT, amount INTEGER, currency TEXT, payload TEXT,
        charge_id TEXT UNIQUE, provider_charge_id TEXT, status TEXT, days INTEGER, created_at TEXT)');
    $db->exec("CREATE TABLE rc_premium (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, source TEXT, reference TEXT, status TEXT DEFAULT 'active',
        starts_at TEXT, expires_at TEXT, granted_by INTEGER DEFAULT 0, created_at TEXT)");
    $db->exec('CREATE TABLE rc_updates_seen (update_key TEXT PRIMARY KEY, seen_at TEXT)');
    return $db;
}

const PKGS = ['month' => ['title' => 'اشتراک ۳۰ روزه', 'description' => 'd', 'amount' => 1490000, 'days' => 30]];

function rig(array $answers = [], array $cfg = []): array
{
    $fake = new FakeBale($answers);
    $bale = new Bale('123:TOKEN', 'https://tapi.bale.ai', $fake->transport());
    $db = db();
    $premium = new Premium($db, 'rc_premium');
    $admin = new ArrayObject();
    $pay = new Payments($db, $bale, $cfg + ['provider_token' => 'WALLET-TEST-1111111111111111', 'packages' => PKGS, 'debug' => true], 'rc_payments',
        fn ($uid, $key, $pkg, $charge) => $premium->grant($uid, $pkg['days'], Premium::PAYMENT, $charge),
        function ($t) use ($admin) { $admin[] = $t; },
        fn () => null,
        fn ($c) => $premium->hasReference($c));
    return [$fake, $bale, $db, $premium, $pay, $admin];
}

function paidMsg(int $user, string $payload, string $charge): array
{
    return ['from' => ['id' => $user], 'chat' => ['id' => $user, 'type' => 'private'], 'successful_payment' => [
        'currency' => 'IRR', 'total_amount' => 1490000, 'invoice_payload' => $payload, 'telegram_payment_charge_id' => $charge, 'provider_payment_charge_id' => 'P1']];
}

echo "Bale client\n";
test('form first (like the old working bot), JSON only if Bale rejects it', function () {
    $fake = new FakeBale(['answerPreCheckoutQuery' => fn ($p, $json) => $json ? ['ok' => true] : ['ok' => false, 'description' => 'no form']]);
    $bale = new Bale('t', 'https://tapi.bale.ai', $fake->transport());
    $r = $bale->call('answerPreCheckoutQuery', ['pre_checkout_query_id' => 'q', 'ok' => true]);
    $c = $fake->of('answerPreCheckoutQuery');
    ok(!$c[0]['json'] && $c[0]['params']['ok'] === '1', 'first request: form ok=1');
    ok($c[1]['json'] && $c[1]['params']['ok'] === true, 'fallback: JSON ok:true');
    ok(!empty($r['ok']), 'result ok');
});
test('no retry after a network error (an invoice is never sent twice)', function () {
    $fake = new FakeBale(['sendInvoice' => fn () => null]);
    $bale = new Bale('t', 'https://tapi.bale.ai', $fake->transport());
    $r = $bale->call('sendInvoice', ['chat_id' => 1]);
    ok(count($fake->of('sendInvoice')) === 1 && empty($r['ok']), 'single attempt');
});
test('arrays are sent as JSON text in form mode (prices, reply_markup)', function () {
    $fake = new FakeBale();
    $bale = new Bale('t', 'https://tapi.bale.ai', $fake->transport());
    $bale->sendInlineButtons(5, 'x', [[['text' => 'a', 'callback_data' => 'buy:month']]]);
    $rm = json_decode($fake->of('sendMessage')[0]['params']['reply_markup'], true);
    ok($rm['inline_keyboard'][0][0]['callback_data'] === 'buy:month', 'reply_markup JSON text');
});

echo "Payments\n";
test('invoice: provider token, prices in rial, payload with user id', function () {
    [$fake, , , , $pay] = rig();
    ok($pay->sendInvoice(77, 77, 'month'), 'sent');
    $p = $fake->of('sendInvoice')[0]['params'];
    ok($p['provider_token'] === 'WALLET-TEST-1111111111111111', 'token');
    ok(json_decode($p['prices'], true)[0]['amount'] === 1490000, 'amount');
    ok(Payments::parsePayload($p['payload']) === ['package' => 'month', 'user_id' => 77], 'payload parse');
});
test('invoice refused / token missing → user and admin told', function () {
    [$fake, , , , $pay, $admin] = rig(['sendInvoice' => fn () => ['ok' => false, 'description' => 'PROVIDER_TOKEN_INVALID']]);
    ok(!$pay->sendInvoice(77, 77, 'month'), 'not sent');
    $texts = $fake->texts(77);
    ok(str_contains(end($texts) ?: '', 'ناموفق') && str_contains(implode((array) $admin), 'PROVIDER_TOKEN_INVALID'), 'messages');
    [$fake2, , , , $pay2] = rig([], ['provider_token' => '']);
    ok(!$pay2->sendInvoice(77, 77, 'month') && !$fake2->of('sendInvoice'), 'no invoice without token');
});
test('pre-checkout: accepted with ok=1, admin debug line', function () {
    [$fake, , , , $pay, $admin] = rig();
    $pay->handlePreCheckout(['id' => 'q1', 'from' => ['id' => 77], 'invoice_payload' => 'buy_month_77_1']);
    $c = $fake->of('answerPreCheckoutQuery');
    ok(count($c) === 1 && $c[0]['params']['ok'] === '1' && $c[0]['params']['pre_checkout_query_id'] === 'q1', 'answered');
    ok(str_contains(implode((array) $admin), 'pre-checkout'), 'debug line');
});
test('paid after "pending" → credited once; duplicate ignored', function () {
    [$fake, , $db, $premium, $pay] = rig(['inquireTransaction' => fn ($p, $j, $n) => ['ok' => true, 'result' => ['status' => $n < 3 ? 'pending' : 'PAID']]]);
    $r = $pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_77_1', 'TX1'));
    ok($r === 'credited', "credited ($r)");
    ok(count($fake->of('inquireTransaction')) === 3 && $fake->of('inquireTransaction')[0]['params']['transaction_id'] === 'TX1', 'inquired with transaction_id');
    ok($premium->isActive(77, true), 'paid premium active');
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_77_1', 'TX1')) === 'duplicate', 'duplicate');
    ok($db->query("SELECT COUNT(*) FROM rc_premium")->fetchColumn() == 1, 'one premium row');
});
test('never confirmed → admin gets /grant line; manual grant then blocks double credit', function () {
    [$fake, , , $premium, $pay, $admin] = rig(['inquireTransaction' => fn () => ['ok' => true, 'result' => ['status' => 'pending']]]);
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_77_1', 'TX2')) === 'unconfirmed', 'unconfirmed');
    ok(count($fake->of('inquireTransaction')) === 4, 'asked 4 times');
    ok(str_contains(implode((array) $admin), '/grant 77 30 TX2'), 'ready command');
    ok(!$premium->isActive(77), 'not credited');
    $premium->grant(77, 30, Premium::ADMIN, 'tx2'); // admin typed it (lower case)
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_77_1', 'TX2')) === 'duplicate', 'manual grant recognised');
});
test('failed / foreign payloads', function () {
    [, , , $premium, $pay] = rig(['inquireTransaction' => fn () => ['ok' => true, 'result' => ['status' => 'failed']]]);
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_77_1', 'TX3')) === 'failed', 'failed');
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'buy_month_99_1', 'TX4')) === 'not_ours', 'other user payload');
    ok($pay->handleSuccessfulPayment(paidMsg(77, 'hack', 'TX5')) === 'not_ours', 'garbage payload');
    ok(!$premium->isActive(77), 'nothing credited');
});

echo "Premium\n";
test('referral time is not paid time; receipts count once; stacking', function () {
    $db = db();
    $t = strtotime('2026-01-01 12:00:00');
    $pr = new Premium($db, 'rc_premium', fn () => $t);
    $pr->grant(5, 1, Premium::REFERRAL, 'ref:9');
    ok($pr->isActive(5) && !$pr->isActive(5, true), 'referral ≠ paid');
    $a = $pr->grant(5, 30, Premium::PAYMENT, 'TX9');
    $b = $pr->grant(5, 30, Premium::PAYMENT, 'TX9');
    ok($a === $b, 'same receipt once');
    ok(strtotime($a) - $t === 31 * 86400, 'stacks after referral day');
    ok($pr->remainingText(5) === '31 روز', 'Persian remaining text');
    $pr->revoke(5);
    ok(!$pr->isActive(5), 'revoked');
});
test('fails closed', function () {
    $db = db();
    $db->exec('DROP TABLE rc_premium');
    ok(!(new Premium($db, 'rc_premium'))->isActive(5), 'no table → no access');
});

echo "Update dedupe\n";
test('same update twice → once; same id different body → both', function () {
    $d = new UpdateDeduper(db(), 'rc_updates_seen');
    ok($d->firstSeen(['update_id' => 1], '{"a":1}') && !$d->firstSeen(['update_id' => 1], '{"a":1}'), 'duplicate dropped');
    ok($d->firstSeen(['update_id' => 1], '{"a":2}'), 'different body kept');
});

echo "\n{$pass} assertions passed, " . count($fail) . " failed\n";
foreach ($fail as $f) {
    echo "  FAIL: $f\n";
}
exit($fail ? 1 : 0);
