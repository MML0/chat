<?php
// Fake Bale Bot API for the end-to-end test:  php -S 127.0.0.1:8199 tests/e2e/mock_bale.php
// Every request is appended to state/requests.jsonl.
$dir = __DIR__ . '/state';
@mkdir($dir);
$raw = file_get_contents('php://input');
$isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$body = $isJson ? (json_decode($raw, true) ?? []) : $_POST;
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/file/')) { // file download (getFile path): a PNG (a colourful one when GD exists)
    header('Content-Type: image/png');
    if (!function_exists('imagecreatetruecolor')) {
        exit(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }
    $seed = crc32($path);
    $w = 480;
    $h = 360;
    $im = imagecreatetruecolor($w, $h);
    $hue = $seed % 360;
    $rgb = function (float $h, float $s, float $l): array {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
        return [(int) (($r + $m) * 255), (int) (($g + $m) * 255), (int) (($b + $m) * 255)];
    };
    for ($y = 0; $y < $h; $y++) {
        [$r, $g, $b] = $rgb(fmod($hue + 50 * $y / $h, 360), .65, .55);
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, $r, $g, $b));
    }
    mt_srand($seed);
    for ($i = 0; $i < 7; $i++) {
        [$r, $g, $b] = $rgb(fmod($hue + 180 + $i * 25, 360), .7, .7);
        $d = mt_rand(40, 200);
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $d, $d, imagecolorallocatealpha($im, $r, $g, $b, 70));
    }
    imagepng($im);
    exit;
}
$method = basename($path);
file_put_contents("{$dir}/requests.jsonl", json_encode(['method' => $method, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
$n = (int) @file_get_contents("{$dir}/n") + 1;
file_put_contents("{$dir}/n", $n);
echo json_encode(match ($method) {
    'getMe'          => ['ok' => true, 'result' => ['id' => 1, 'username' => 'hamsohbatbot']],
    'getWebhookInfo' => ['ok' => true, 'result' => ['url' => 'https://example.com/webhook.php?k=SECRET']],
    'getChatMember'  => ['ok' => true, 'result' => ['status' => 'member']],
    'getFile'        => ['ok' => true, 'result' => ['file_id' => $body['file_id'] ?? '', 'file_path' => 'photos/' . preg_replace('~[^A-Za-z0-9-]~', '', (string) ($body['file_id'] ?? 'x')) . '.png']],
    default          => ['ok' => true, 'result' => ['message_id' => $n]],
}, JSON_UNESCAPED_UNICODE);
