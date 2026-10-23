<?php
// Shows media from the chats in the panel (photos, voices, videos, stickers, profile photos).
// The file is fetched from Bale with the bot token on the server (the token never reaches the
// browser) and kept 7 days in storage/cache/media, so a page full of avatars stays fast.
//   ?m=<message id>  ?i=<anonymous inbox id>  ?u=<user id> (profile photo)
require dirname(__DIR__) . '/bootstrap.php';

use Hamsobat\App;
use Hamsobat\PanelAuth;

$config = hamsobat_config();
$app = App::fromConfig($config);
PanelAuth::start();
$auth = new PanelAuth($app);
session_write_close();
if (!$auth->loggedIn()) {
    http_response_code(401);
    exit;
}

$fileId = match (true) {
    isset($_GET['m']) => $app->db->value('SELECT bale_file_id FROM {messages} WHERE id = ?', [(int) $_GET['m']]),
    isset($_GET['i']) => $app->db->value('SELECT bale_file_id FROM {anonymous_inbox} WHERE id = ?', [(int) $_GET['i']]),
    isset($_GET['u']) => $app->db->value('SELECT photo_file_id FROM {users} WHERE id = ?', [(int) $_GET['u']]),
    default           => null,
};
if (!$fileId) {
    http_response_code(404);
    exit;
}

const MEDIA_MAX_BYTES = 50 * 1048576;
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'tgs' => 'application/octet-stream'];
$cacheDir = dirname(__DIR__) . '/storage/cache/media';
$key = sha1((string) $fileId);
$cached = "{$cacheDir}/{$key}";

header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");

if (is_file($cached) && is_file("{$cached}.type") && filemtime($cached) > time() - 7 * 86400) {
    header('Content-Type: ' . trim((string) file_get_contents("{$cached}.type")));
    header('Content-Length: ' . filesize($cached));
    readfile($cached);
    exit;
}

$info = $app->bale->call('getFile', ['file_id' => $fileId], 15);
$path = (string) ($info['result']['file_path'] ?? '');
if ($path === '' || str_contains($path, '..')) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $types[$ext] ?? 'application/octet-stream';
$base = rtrim((string) ($config['bale']['api_base'] ?? 'https://tapi.bale.ai'), '/');
$url = $base . '/file/bot' . $config['bale']['bot_token'] . '/' . ltrim($path, '/');

@mkdir($cacheDir, 0750, true);
$tmp = @fopen("{$cached}.part", 'wb');
$size = 0;
header('Content-Type: ' . $type);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($tmp, &$size) {
        $size += strlen($chunk);
        if ($size > MEDIA_MAX_BYTES) {
            return 0;
        }
        echo $chunk;
        if ($tmp) {
            fwrite($tmp, $chunk);
        }
        return strlen($chunk);
    },
]);
$ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
curl_close($ch);
if ($tmp) {
    fclose($tmp);
    if ($ok && $size > 0) {
        rename("{$cached}.part", $cached);
        file_put_contents("{$cached}.type", $type);
    } else {
        @unlink("{$cached}.part");
    }
}
