<?php
// Fake Bale Bot API for the end-to-end test:  php -S 127.0.0.1:8199 tests/e2e/mock_bale.php
// Every request is appended to state/requests.jsonl.
$dir = __DIR__ . '/state';
@mkdir($dir);
$raw = file_get_contents('php://input');
$isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$body = $isJson ? (json_decode($raw, true) ?? []) : $_POST;
$method = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents("{$dir}/requests.jsonl", json_encode(['method' => $method, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
$n = (int) @file_get_contents("{$dir}/n") + 1;
file_put_contents("{$dir}/n", $n);
echo json_encode(match ($method) {
    'getMe'          => ['ok' => true, 'result' => ['id' => 1, 'username' => 'hamsohbatbot']],
    'getWebhookInfo' => ['ok' => true, 'result' => ['url' => 'https://example.com/webhook.php?k=SECRET']],
    'getChatMember'  => ['ok' => true, 'result' => ['status' => 'member']],
    default          => ['ok' => true, 'result' => ['message_id' => $n]],
}, JSON_UNESCAPED_UNICODE);
