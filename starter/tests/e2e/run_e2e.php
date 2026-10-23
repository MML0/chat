<?php
// End-to-end: real migrate.php / check.php / webhook.php + MySQL/MariaDB + fake Bale.
// Setup (see docs/08-testing.md):
//   1. a MySQL database + starter/config.php with  'api_base' => 'http://127.0.0.1:8099'
//   2. php -S 127.0.0.1:8099 starter/tests/e2e/mock_bale.php
//   3. php -S 127.0.0.1:8098 -t starter
//   4. php starter/tests/e2e/run_e2e.php
$base = 'http://127.0.0.1:8098';
$config = require __DIR__ . '/../../config.php';
$state = __DIR__ . '/state';
@mkdir($state);
$pass = 0; $fail = [];
$check = function ($c, $m) use (&$pass, &$fail) { $c ? $pass++ : $fail[] = $m; echo ($c ? '  ✔ ' : '  ✘ ') . "$m\n"; };
$get = fn ($path) => file_get_contents($base . $path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
$uid = random_int(1000, 9_000_000);
$post = function (array $u, string $k = null) use ($base, $config, &$uid) {
    $u = ['update_id' => $u['update_id'] ?? ++$uid] + $u;
    $ch = curl_init($base . '/webhook.php?k=' . rawurlencode($k ?? $config['bale']['webhook_secret']));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($u), CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '']);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
};
$reqs = function () use ($state) { return array_map(fn ($l) => json_decode($l, true), @file("$state/requests.jsonl", FILE_IGNORE_NEW_LINES) ?: []); };
$reset = function (array $scenario = []) use ($state) { @unlink("$state/requests.jsonl"); @unlink("$state/txn_n"); file_put_contents("$state/scenario.json", json_encode($scenario)); };
$calls = fn ($m) => array_values(array_filter($reqs(), fn ($r) => str_ends_with(parse_url($r['uri'], PHP_URL_PATH), "/$m")));
$textsTo = fn ($chat) => array_map(fn ($r) => $r['body']['text'] ?? '', array_values(array_filter($calls('sendMessage'), fn ($r) => (string) $r['body']['chat_id'] === (string) $chat)));
$last = fn (array $a) => $a ? $a[count($a) - 1] : '';
$msg = fn ($from, $text) => ['message' => ['message_id' => 1, 'from' => ['id' => $from, 'first_name' => 'تست'], 'chat' => ['id' => $from, 'type' => 'private'], 'text' => $text]];
$pass2 = $config['admin']['password'];
$admin = (int) $config['admin']['chat_id'];
$U = random_int(100000, 999999);

echo "migrate / check\n";
$check(str_contains($get('/migrate.php?pass=wrong'), 'Wrong'), 'migrate needs the password');
$check(str_contains($get('/migrate.php?pass=' . urlencode($pass2)), 'completed successfully'), 'migrate ok');
$check(str_contains($get('/migrate.php?pass=' . urlencode($pass2)), 'completed successfully'), 'migrate again is harmless');
$c = $get('/check.php?pass=' . urlencode($pass2) . '&setwebhook=1');
$check(!str_contains($c, '❌'), 'check.php all ✔');
$check(str_contains($c, 'setWebhook') && !str_contains($c, $config['bale']['webhook_secret']), 'setWebhook ok, secret hidden');

echo "webhook\n";
$reset();
$check($post($msg($U, '/start'), 'wrong-secret') === 403, 'request without the secret is rejected');
$post($msg($U, '/start'));
$check(str_contains($last($textsTo($U)), 'خوش آمدید'), '/start');
$post($msg($U + 1, "/start ref{$U}"));
$check((bool) array_filter($textsTo($U), fn ($t) => str_contains($t, 'لینک شما')), 'referral reward message');
$post($msg($U, '/buy'));
$kb = json_decode($calls('sendMessage')[count($calls('sendMessage')) - 1]['body']['reply_markup'] ?? '{}', true);
$check(str_starts_with($kb['inline_keyboard'][0][0]['callback_data'] ?? '', 'buy:'), '/buy shows package buttons');

echo "payment\n";
$reset(['txn_seq' => ['pending', 'paid']]);
$key = array_key_first($config['payment']['packages']);
$post(['callback_query' => ['id' => 'cb1', 'data' => "buy:{$key}", 'from' => ['id' => $U], 'message' => ['chat' => ['id' => $U, 'type' => 'private']]]]);
$inv = $calls('sendInvoice');
$check(count($inv) === 1 && !$inv[0]['json'], 'invoice sent (form, like the old working bot)');
$check(count($calls('answerCallbackQuery')) === 1, 'button answered');
$payload = $inv[0]['body']['payload'];
$post(['pre_checkout_query' => ['id' => 'pc1', 'from' => ['id' => $U], 'invoice_payload' => $payload]]);
$pc = $calls('answerPreCheckoutQuery');
$check(count($pc) === 1 && $pc[0]['body']['ok'] === '1', 'pre-checkout accepted (ok=1)');
$pay = ['message' => ['message_id' => 2, 'from' => ['id' => $U], 'chat' => ['id' => $U, 'type' => 'private'], 'successful_payment' => [
    'currency' => 'IRR', 'total_amount' => $config['payment']['packages'][$key]['amount'], 'invoice_payload' => $payload,
    'telegram_payment_charge_id' => 'TX' . $U, 'provider_payment_charge_id' => 'P' . $U]]];
$post($pay);
$check(count($calls('inquireTransaction')) === 2, 'inquired until paid');
$check((bool) array_filter($textsTo($U), fn ($t) => str_contains($t, 'پرداخت با موفقیت تایید شد')), 'receipt sent');
$post($pay); // Bale re-sends with a new update_id
$check(count($calls('inquireTransaction')) === 2, 'duplicate payment not processed again');
$post($msg($U, '/help'));
$check(str_contains($last($textsTo($U)), 'اشتراک ویژه'), '/help shows remaining premium');

echo "admin\n";
$reset();
$post($msg($U, "/grant " . ($U + 5) . " 3"));
$check(!array_filter($textsTo($U), fn ($t) => str_contains($t, '✅')), 'non-admin cannot /grant');
$post($msg($admin, "/grant " . ($U + 5) . " 3 card1"));
$check(str_contains($last($textsTo($admin)), '✅'), 'admin /grant');

echo "\n$pass passed, " . count($fail) . " failed\n";
foreach ($fail as $f) echo "  FAIL: $f\n";
exit($fail ? 1 : 0);
