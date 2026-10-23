<?php
// Fake Bale Bot API for local end-to-end tests:  php -S 127.0.0.1:8099 starter/tests/e2e/mock_bale.php
// Logs every request to requests.jsonl; scenario.json controls inquireTransaction answers.
$dir = __DIR__ . '/state';
@mkdir($dir);
$uri = $_SERVER['REQUEST_URI'];
$raw = file_get_contents('php://input');
$isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$body = $isJson ? (json_decode($raw, true) ?? []) : $_POST;
$scenario = json_decode(@file_get_contents("$dir/scenario.json") ?: '{}', true);
file_put_contents("$dir/requests.jsonl", json_encode(['uri' => $uri, 'json' => $isJson, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$out = fn ($data, $code = 200) => [http_response_code($code), print(json_encode($data, JSON_UNESCAPED_UNICODE))];
$method = basename(parse_url($uri, PHP_URL_PATH));

switch ($method) {
    case 'getMe':
        $out(['ok' => true, 'result' => ['username' => 'test_bot']]);
        break;
    case 'answerPreCheckoutQuery':
        $ok = $body['ok'] ?? null;
        $out(($ok === true || $ok === '1' || $ok === 'true') ? ['ok' => true, 'result' => true] : ['ok' => false, 'description' => 'ok must be true'], 200);
        break;
    case 'inquireTransaction':
        $seq = $scenario['txn_seq'] ?? ['paid'];
        $n = (int) @file_get_contents("$dir/txn_n");
        file_put_contents("$dir/txn_n", $n + 1);
        $out(['ok' => true, 'result' => ['id' => $body['transaction_id'] ?? null, 'status' => $seq[min($n, count($seq) - 1)]]]);
        break;
    default:
        $out(['ok' => true, 'result' => ['message_id' => 1]]);
}
